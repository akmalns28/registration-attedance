<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventPoster;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EventInvitationArtwork
{
    /** @return array{contents: string, mime: string, width: int, height: int}|null */
    public function poster(Event $event): ?array
    {
        try {
            $poster = $event->loadMissing('primaryPoster')->primaryPoster;
            if ($poster === null) {
                return null;
            }

            $disk = Storage::disk('local');

            return $this->preparePoster($disk->get(EventPoster::invitationPath($poster->file_path)) ?? $disk->get($poster->file_path));
        } catch (Throwable $exception) {
            Log::warning('Event invitation poster could not be read.', [
                'event_id' => $event->id,
                'exception_type' => $exception::class,
            ]);

            return null;
        }
    }

    /** @return array{contents: string, mime: string, width: int, height: int}|null */
    public function preparePoster(?string $contents): ?array
    {
        return $this->resize($contents, 680, 420, asJpeg: true);
    }

    /** @return array{contents: string, mime: string, width: int, height: int}|null */
    public function logo(Event $event): ?array
    {
        return $this->resize($event->logoImage()['contents'] ?? null, 256, 192);
    }

    /** @param array{contents: string, mime: string}|null $image */
    public function dataUri(?array $image): ?string
    {
        return $image === null ? null : 'data:'.$image['mime'].';base64,'.base64_encode($image['contents']);
    }

    /** @return array{contents: string, mime: string, width: int, height: int}|null */
    private function resize(?string $contents, int $maxWidth, int $maxHeight, bool $asJpeg = false): ?array
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
        if ($scale === 1 && $type !== IMAGETYPE_WEBP && (! $asJpeg || $type === IMAGETYPE_JPEG)) {
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
        $keepAlpha = $type === IMAGETYPE_PNG && ! $asJpeg;
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
