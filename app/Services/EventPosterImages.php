<?php

namespace App\Services;

use App\Models\EventPoster;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class EventPosterImages
{
    /** @return array{0: int, 1: int, 2: int}|null */
    public static function dimensions(string $path): ?array
    {
        $dimensions = @getimagesize($path);
        if ($dimensions === false || ! in_array($dimensions[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return null;
        }

        return [$dimensions[0], $dimensions[1], $dimensions[2]];
    }

    public static function fitsDimensions(int $width, int $height): bool
    {
        return $width > 0 && $height > 0 && max($width, $height) <= config('event_assets.poster_max_side')
            && $width * $height <= config('event_assets.poster_max_pixels');
    }

    public function generate(string $posterPath): void
    {
        $disk = Storage::disk('local');
        $source = $this->open($disk->path($posterPath));
        foreach (config('event_assets.poster_sizes') as $variant => $maxEdge) {
            $bytes = $this->jpeg($source, $maxEdge);
            if (! $disk->put(EventPoster::variantPath($posterPath, $variant), $bytes, 'private')) {
                throw new RuntimeException('Could not write poster derivative '.$variant.'.');
            }
        }
    }

    public function legacyInvitation(string $posterPath): ?string
    {
        $disk = Storage::disk('local');
        $maxEdge = config('event_assets.poster_sizes.invitation');
        foreach (['detail', 'invitation'] as $variant) {
            $path = $disk->path(EventPoster::variantPath($posterPath, $variant));
            if (is_file($path) && self::dimensions($path) !== null) {
                return $this->jpeg($this->open($path), $maxEdge);
            }
        }

        return $disk->exists($posterPath) ? $this->jpeg($this->open($disk->path($posterPath)), $maxEdge) : null;
    }

    private function open(string $path): GdImage
    {
        $dimensions = self::dimensions($path);
        if ($dimensions === null || ! self::fitsDimensions($dimensions[0], $dimensions[1])) {
            throw new RuntimeException('Poster metadata is unreadable or exceeds the pixel limit.');
        }

        [$width, $height, $type] = $dimensions;
        $limit = ini_parse_quantity(ini_get('memory_limit'));
        $largestEdge = max(config('event_assets.poster_sizes'));
        $estimatedBytes = ($width * $height * 6) + ($largestEdge ** 2 * 5) + 24 * 1024 * 1024;
        if ($limit > 0 && $limit - memory_get_usage(false) < $estimatedBytes) {
            throw new RuntimeException('Poster exceeds the available decoded image memory budget.');
        }

        if (! extension_loaded('gd') || ($type === IMAGETYPE_WEBP && ! function_exists('imagecreatefromwebp'))) {
            throw new RuntimeException('GD image decoder is unavailable.');
        }

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        };
        if ($source === false) {
            throw new RuntimeException('GD could not decode the poster.');
        }

        return $source;
    }

    private function jpeg(GdImage $source, int $maxEdge): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxEdge / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) {
            throw new RuntimeException('GD could not allocate a poster derivative.');
        }

        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        try {
            if (! imagejpeg($target, null, 82) || ! ($jpeg = ob_get_contents())) {
                throw new RuntimeException('GD could not encode the poster derivative.');
            }

            return $jpeg;
        } finally {
            ob_end_clean();
        }
    }
}
