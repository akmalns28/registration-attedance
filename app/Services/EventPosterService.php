<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventPoster;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class EventPosterService
{
    public function __construct(private readonly EventAssetStorage $assets, private readonly EventInvitationArtwork $artwork) {}

    public static function rules(): array
    {
        $max = config('event_assets.poster_max_count');

        return [
            'posters' => ['sometimes', 'array', 'list', 'max:'.$max],
            'posters.*' => ['bail', 'required', 'file', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp',
                'dimensions:max_width=4000,max_height=4000', 'max:'.config('event_assets.poster_max_kb')],
            'poster_remove' => ['sometimes', 'array', 'list', 'max:100'],
            'poster_remove.*' => ['required', 'uuid', 'distinct'],
            'poster_order' => ['sometimes', 'array', 'list', 'max:100'],
            'poster_order.*' => ['required', 'string', 'max:36', 'distinct'],
            'poster_primary' => ['nullable', 'string', 'max:36'],
        ];
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
            $renditions = [];
            $disk = Storage::disk('local');
            try {
                foreach ($uploads as $upload) {
                    $rendition = EventPoster::invitationPath($upload['file_path']);
                    $renditions[] = $rendition;
                    $image = $this->artwork->preparePoster($disk->get($upload['file_path']));
                    if ($image === null || ! $disk->put($rendition, $image['contents'], 'private')) {
                        throw ValidationException::withMessages(['posters' => 'Poster gagal diproses. Gunakan gambar dengan dimensi lebih kecil.']);
                    }
                }

                return $persist($uploads);
            } catch (Throwable $exception) {
                $this->assets->delete($renditions);

                throw $exception;
            }
        }, hashContents: true);
    }

    /** @param list<string> $paths */
    public function deleteFiles(array $paths): void
    {
        $this->assets->delete([...$paths, ...array_map(EventPoster::invitationPath(...), $paths)]);
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
