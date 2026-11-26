<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventPoster;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EventInvitationArtwork
{
    public function __construct(private readonly EventPosterImages $posters) {}

    /** @param list<int> $dayIds */
    public function resolvePoster(Event $event, array $dayIds): ?EventPoster
    {
        $relevant = $event->loadMissing('posters.days')->posters
            ->filter(fn (EventPoster $poster): bool => $poster->appliesToDays($dayIds));

        return $relevant->firstWhere('is_primary', true) ?? $relevant->first();
    }

    /** @return array{contents: string, mime: string, width: int, height: int, file: string|null}|null */
    public function poster(EventPoster $poster): ?array
    {
        try {
            $disk = Storage::disk('local');

            $variant = EventPoster::variantPath($poster->file_path, 'invitation');
            $path = $disk->path($variant);
            $dimensions = EventPosterImages::dimensions($path);
            $contents = $dimensions !== null && $dimensions[2] === IMAGETYPE_JPEG
                && max($dimensions[0], $dimensions[1]) <= config('event_assets.poster_sizes.invitation')
                && @filesize($path) <= 512 * 1024 ? $disk->get($variant) : null;
            $file = $contents === null ? null : $disk->path($variant);
            $contents ??= $this->posters->legacyInvitation($poster->file_path);
            $dimensions = $contents ? @getimagesizefromstring($contents) : false;
            if ($dimensions === false || $dimensions[2] !== IMAGETYPE_JPEG) {
                return null;
            }

            return ['contents' => $contents, 'mime' => 'image/jpeg', 'width' => $dimensions[0], 'height' => $dimensions[1], 'file' => $file];
        } catch (Throwable $exception) {
            Log::warning('Event invitation poster could not be read.', [
                'event_id' => $poster->event_id,
                'poster_id' => $poster->id,
                'exception_type' => $exception::class,
            ]);

            return null;
        }
    }

    /** @return array{contents: string, mime: string, width: int, height: int}|null */
    public function logo(Event $event): ?array
    {
        try {
            return $this->resize($event->logoImage()['contents'] ?? null, 256, 192);
        } catch (Throwable $exception) {
            Log::warning('Event invitation logo could not be read.', [
                'event_id' => $event->id,
                'exception_type' => $exception::class,
            ]);

            return null;
        }
    }

    /** @param array{contents: string, mime: string}|null $image */
    public function dataUri(?array $image): ?string
    {
        return $image === null ? null : 'data:'.$image['mime'].';base64,'.base64_encode($image['contents']);
    }

    /** @return array{contents: string, mime: string, width: int, height: int}|null */
    private function resize(?string $contents, int $maxWidth, int $maxHeight): ?array
    {
        $dimensions = $contents ? @getimagesizefromstring($contents) : false;
        if ($dimensions === false || ! in_array($dimensions[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return null;
        }

        [$width, $height, $type] = $dimensions;
        if ($width < 1 || $height < 1 || $width > 4000 || $height > 4000) {
            return null;
        }

        $scale = min(1, $maxWidth / $width, $maxHeight / $height);
        if ($scale === 1 && $type !== IMAGETYPE_WEBP) {
            return ['contents' => $contents, 'mime' => $dimensions['mime'], 'width' => $width, 'height' => $height];
        }

        $limit = ini_parse_quantity(ini_get('memory_limit'));
        if ($limit > 0 && $limit - memory_get_usage(false) < $width * $height * 6 + 4 * 1024 * 1024) {
            return null;
        }

        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            return null;
        }

        $scaledWidth = max(1, (int) round($width * $scale));
        $scaledHeight = max(1, (int) round($height * $scale));
        $image = imagecreatetruecolor($scaledWidth, $scaledHeight);
        $keepAlpha = $type === IMAGETYPE_PNG;
        if ($keepAlpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, $keepAlpha ? 127 : 0));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $scaledWidth, $scaledHeight, $width, $height);
        unset($source);

        ob_start();
        try {
            $encoded = $keepAlpha ? imagepng($image) : imagejpeg($image, null, 83);

            return $encoded ? [
                'contents' => ob_get_contents(),
                'mime' => $keepAlpha ? 'image/png' : 'image/jpeg',
                'width' => $scaledWidth,
                'height' => $scaledHeight,
            ] : null;
        } finally {
            ob_end_clean();
        }
    }
}
