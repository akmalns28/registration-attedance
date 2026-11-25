<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventPoster;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class EventPosterService
{
    public function __construct(private readonly EventAssetStorage $assets, private readonly EventPosterImages $images) {}

    public static function rules(): array
    {
        $max = config('event_assets.poster_max_count');

        return [
            'posters' => ['sometimes', 'array', 'list', 'max:'.$max],
            'posters.*' => [
                'bail', 'required', 'file', 'max:'.config('event_assets.poster_max_kb'),
                'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp',
                function (string $attribute, UploadedFile $file, Closure $fail): void {
                    $dimensions = EventPosterImages::dimensions($file->getPathname());
                    if ($dimensions === null) {
                        $fail('Metadata poster tidak dapat dibaca. Unggah gambar JPG, PNG, atau WebP yang valid.');
                    } elseif (! EventPosterImages::fitsDimensions($dimensions[0], $dimensions[1])) {
                        $side = number_format(config('event_assets.poster_max_side'), 0, ',', '.');
                        $pixels = config('event_assets.poster_max_pixels') / 1000000;
                        $fail("Dimensi poster terlalu besar. Maksimal {$side} piksel pada sisi terpanjang dan {$pixels} megapiksel.");
                    }
                },
            ],
            'poster_remove' => ['sometimes', 'array', 'list', 'max:100'],
            'poster_remove.*' => ['required', 'uuid', 'distinct'],
            'poster_order' => ['sometimes', 'array', 'list', 'max:100'],
            'poster_order.*' => ['required', 'string', 'max:36', 'distinct'],
            'poster_primary' => ['nullable', 'string', 'max:36'],
        ];
    }

    public static function uploadLimitLabel(): string
    {
        $kilobytes = (int) config('event_assets.poster_max_kb');

        return $kilobytes % 1024 === 0 ? ($kilobytes / 1024).' MB' : $kilobytes.' KB';
    }

    /**
     * @template TResult
     *
     * @param  list<UploadedFile>  $files
     * @param  Closure(array<int, array{file_path: string, original_filename: string, mime_type: string, content_hash: string, file_size: int}>): TResult  $persist
     * @return TResult
     */
    public function saveUploads(array $files, Closure $persist): mixed
    {
        if ($files === []) {
            return $persist([]);
        }

        return $this->assets->saveUploads($files, 'event-posters', 'posters', function (array $uploads) use ($persist): mixed {
            try {
                foreach ($uploads as $index => $upload) {
                    try {
                        $this->images->generate($upload['file_path']);
                    } catch (Throwable $exception) {
                        Log::warning('Event poster processing failed.', [
                            'file_index' => $index,
                            'mime_type' => $upload['mime_type'],
                            'file_size' => $upload['file_size'],
                            'exception_type' => $exception::class,
                            'reason' => $exception::class === RuntimeException::class ? $exception->getMessage() : 'Unexpected image processing error.',
                        ]);

                        throw ValidationException::withMessages([
                            "posters.{$index}" => 'Poster gagal diproses. Simpan ulang gambar sebagai JPG atau PNG, lalu unggah kembali.',
                        ]);
                    }
                }

                return $persist($uploads);
            } catch (Throwable $exception) {
                $this->assets->delete($this->variants(array_column($uploads, 'file_path')));

                throw $exception;
            }
        }, hashContents: true);
    }

    /** @param list<string> $paths */
    public function deleteFiles(array $paths): void
    {
        $this->assets->delete([...$paths, ...$this->variants($paths)]);
    }

    /** @param list<string> $paths
     * @return list<string>
     */
    private function variants(array $paths): array
    {
        $variants = [];
        foreach ($paths as $path) {
            foreach (array_keys(config('event_assets.poster_sizes')) as $variant) {
                $variants[] = EventPoster::variantPath($path, $variant);
            }
        }

        return $variants;
    }

    /**
     * Called inside the transaction holding the event row lock.
     *
     * @param  array<int, array{file_path: string, original_filename: string, mime_type: string, content_hash: string, file_size: int}>  $uploads
     * @param  array{poster_remove?: list<string>, poster_order?: list<string>, poster_primary?: string|null}  $changes
     * @return list<string>
     */
    public function sync(Event $event, array $uploads, array $changes): array
    {
        $posters = $event->posters()->get()->toBase()->keyBy('id');
        $removed = array_fill_keys($changes['poster_remove'] ?? [], true);
        $remaining = $posters->except(array_keys($removed));
        $hashes = $remaining->pluck('id', 'content_hash')->all();
        $aliases = [];
        $discardedPaths = [];

        foreach ($uploads as $index => $upload) {
            $existing = $hashes[$upload['content_hash']] ?? null;
            if ($existing !== null) {
                $aliases["upload:$index"] = $existing;
                $discardedPaths[] = $upload['file_path'];

                continue;
            }

            $poster = new EventPoster(Arr::except($upload, ['file_size']));
            $poster->setAttribute('id', $poster->newUniqueId());
            $remaining->put($poster->id, $poster);
            $aliases["upload:$index"] = $hashes[$upload['content_hash']] = $poster->id;
        }

        if ($remaining->count() > config('event_assets.poster_max_count') && $remaining->count() > $posters->count()) {
            throw ValidationException::withMessages(['posters' => 'Jumlah poster melebihi batas event.']);
        }

        $order = collect($changes['poster_order'] ?? [])->map(fn (string $key): string => $aliases[$key] ?? $key)
            ->merge($remaining->keys())->unique()->filter(fn (string $key): bool => $remaining->has($key))->values();
        $requestedPrimary = $changes['poster_primary'] ?? '';
        $primary = $aliases[$requestedPrimary] ?? $requestedPrimary;
        if (! $remaining->has($primary)) {
            $primary = $remaining->firstWhere('is_primary', true)?->id ?? $order->first();
        }

        foreach ($posters->only(array_keys($removed)) as $poster) {
            $poster->delete();
            $discardedPaths[] = $poster->file_path;
        }
        $previousPrimary = $remaining->firstWhere('is_primary', true);
        if ($previousPrimary !== null && $previousPrimary->id !== $primary) {
            $previousPrimary->update(['is_primary' => false]);
        }

        foreach ($order as $position => $id) {
            $poster = $remaining->get($id);
            $poster->fill(['is_primary' => $id === $primary, 'sort_order' => $position]);
            $event->posters()->save($poster);
        }

        return $discardedPaths;
    }
}
