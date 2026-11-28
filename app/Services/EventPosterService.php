<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventPoster;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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
            'poster_assignments' => ['sometimes', 'array', 'max:100'],
            'poster_assignments.*' => ['required', 'array:scope,days,day_count,upload_reference'],
            'poster_assignments.*.scope' => ['required', 'in:all,days'],
            'poster_assignments.*.day_count' => ['sometimes', 'integer', 'min:0'],
            'poster_assignments.*.days' => ['sometimes', 'array', 'list'],
            'poster_assignments.*.days.*' => ['required', 'integer', 'min:1'],
            'poster_assignments.*.upload_reference' => ['sometimes', 'string', 'max:1024'],
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
     * @param  array{poster_remove?: list<string>, poster_order?: list<string>, poster_primary?: string|null, poster_assignments?: array<string, array{scope: string, days?: list<int|string>, day_count?: int|string, upload_reference?: string}>}  $changes
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

        $assignments = $this->resolveAssignments($event, $remaining, $aliases, $changes['poster_assignments'] ?? []);
        $order = collect($changes['poster_order'] ?? [])->map(fn (string $key): string => $aliases[$key] ?? $key)
            ->merge($remaining->keys())->unique()->filter(fn (string $key): bool => $remaining->has($key))->values();
        $previousPrimary = $remaining->firstWhere('is_primary', true);
        $primary = $previousPrimary?->id;
        if (array_key_exists('poster_primary', $changes)) {
            $requestedPrimary = $changes['poster_primary'];
            $primary = $requestedPrimary === null || $requestedPrimary === '' ? null : ($aliases[$requestedPrimary] ?? $requestedPrimary);
            if ($primary !== null && ! $remaining->has($primary)) {
                throw ValidationException::withMessages(['poster_primary' => 'Poster utama yang dipilih tidak tersedia pada event ini.']);
            }
        }

        foreach ($posters->only(array_keys($removed)) as $poster) {
            $poster->delete();
            $discardedPaths[] = $poster->file_path;
        }
        if ($previousPrimary !== null && $previousPrimary->id !== $primary) {
            $previousPrimary->update(['is_primary' => false]);
        }

        $orderChanged = $uploads !== [] || $removed !== [] || isset($changes['poster_order']);
        foreach ($order as $position => $id) {
            $poster = $remaining->get($id);
            $poster->is_primary = $id === $primary;
            if ($orderChanged) {
                $poster->sort_order = $position;
            }
            if (isset($assignments[$id])) {
                $poster->is_event_wide = $assignments[$id] === [];
            }
            $event->posters()->save($poster);
            if (isset($assignments[$id])) {
                $poster->days()->sync($assignments[$id]);
            }
        }

        return $discardedPaths;
    }

    /**
     * @param  Collection<string, EventPoster>  $posters
     * @param  array<string, string>  $aliases
     * @param  array<string, array{scope: string, days?: list<int|string>, day_count?: int|string, upload_reference?: string}>  $requested
     * @return array<string, list<int>>
     */
    private function resolveAssignments(Event $event, Collection $posters, array $aliases, array $requested): array
    {
        $assignments = [];
        $dayIds = [];
        foreach ($requested as $key => $assignment) {
            $id = $aliases[$key] ?? $key;
            if (! $posters->has($id)) {
                throw ValidationException::withMessages(['poster_assignments' => 'Poster yang dipilih tidak tersedia pada event ini.']);
            }

            $submittedDays = $assignment['days'] ?? [];
            if (isset($assignment['day_count']) && (int) $assignment['day_count'] !== count($submittedDays)) {
                throw ValidationException::withMessages(['poster_assignments' => 'Pilihan hari untuk poster tidak lengkap. Pilih kembali hari yang diinginkan.']);
            }

            $days = array_values(array_unique(array_map('intval', $submittedDays)));
            sort($days);
            if (($assignment['scope'] === 'all') !== ($days === [])) {
                throw ValidationException::withMessages(['poster_assignments' => 'Pilih Semua Hari atau centang minimal satu hari untuk setiap poster yang diubah.']);
            }
            if (isset($assignments[$id]) && $assignments[$id] !== $days) {
                throw ValidationException::withMessages(['poster_assignments' => 'Poster yang sama memiliki pilihan hari berbeda. Gunakan satu pilihan untuk setiap poster.']);
            }

            $assignments[$id] = $days;
            $dayIds = [...$dayIds, ...$days];
        }

        $dayIds = array_values(array_unique($dayIds));
        if ($dayIds !== [] && $event->days()->whereKey($dayIds)->count() !== count($dayIds)) {
            throw ValidationException::withMessages(['poster_assignments' => 'Hari pelaksanaan yang dipilih tidak tersedia pada event ini.']);
        }

        return $assignments;
    }
}
