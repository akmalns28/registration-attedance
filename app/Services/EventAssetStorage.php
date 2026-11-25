<?php

namespace App\Services;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class EventAssetStorage
{
    public const PRIVATE_HEADERS = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

    public const IMAGE_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const VIDEO_EXTENSIONS = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];

    /**
     * @template TResult
     *
     * @param  array<int, UploadedFile>  $files
     * @param  Closure(array<int, array{file_path: string, original_filename: string, mime_type: string, file_size: int, content_hash?: string}>): TResult  $persist
     * @return TResult
     */
    public function saveUploads(array $files, string $directory, string $field, Closure $persist, bool $hashContents = false): mixed
    {
        $uploads = [];
        try {
            foreach ($files as $index => $file) {
                $mime = $file->getMimeType();
                $extension = self::IMAGE_EXTENSIONS[$mime] ?? self::VIDEO_EXTENSIONS[$mime] ?? null;
                $hash = $hashContents ? hash_file('sha256', $file->getPathname()) : null;
                if ($extension === null || $hash === false) {
                    throw ValidationException::withMessages([$field => 'Berkas tidak dapat dibaca.']);
                }

                $path = $file->storeAs($directory, Str::uuid7().'.'.$extension, ['disk' => 'local', 'visibility' => 'private']);
                if ($path === false) {
                    throw ValidationException::withMessages([$field => 'Berkas gagal disimpan. Silakan coba kembali.']);
                }

                $uploads[$index] = [
                    'file_path' => $path,
                    'original_filename' => Str::substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => $mime,
                    'file_size' => $file->getSize(),
                    ...($hashContents ? ['content_hash' => $hash] : []),
                ];
            }

            return $persist($uploads);
        } catch (Throwable $exception) {
            $this->delete(array_column($uploads, 'file_path'));

            throw $exception;
        }
    }

    /** @param list<string> $paths */
    public function delete(array $paths): void
    {
        $disk = Storage::disk('local');
        foreach ($paths as $path) {
            if (! $disk->delete($path)) {
                Log::warning('Event asset file could not be removed.', ['asset' => basename($path)]);
            }
        }
    }

    public function response(string $path, string $mime, ?string $downloadName = null): BinaryFileResponse
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        $headers = [...self::PRIVATE_HEADERS, 'Content-Type' => $mime];
        if ($downloadName !== null) {
            $downloadName = preg_replace('/[\x00-\x1F\x7F]/', '-', str_replace(['/', '\\'], '-', $downloadName));

            return response()->download($disk->path($path), $downloadName ?: basename($path), $headers);
        }

        return response()->file($disk->path($path), $headers);
    }
}
