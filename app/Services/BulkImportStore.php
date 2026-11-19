<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BulkImportStore
{
    /** @param array<string, mixed> $payload */
    public function create(User $user, string $module, array $payload): string
    {
        $id = (string) Str::uuid();
        $this->write($id, [...$payload, 'owner' => $user->id, 'module' => $module, 'state' => 'preview',
            'expires_at' => now()->addMinutes(max(1, (int) config('bulk_imports.ttl_minutes')))->timestamp]);

        return $id;
    }

    /** @return array<string, mixed> */
    public function read(string $id, User $user, string $module): array
    {
        abort_unless(Str::isUuid($id), 404);
        $path = $this->path($id);
        abort_unless(File::isFile($path), 404);
        try {
            $payload = json_decode(Crypt::decryptString(File::get($path)), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(422, 'Preview tidak valid. Unggah kembali berkas impor.');
        }
        abort_unless($payload['owner'] === $user->id && $payload['module'] === $module, 404);
        abort_if($payload['expires_at'] < now()->timestamp, 410, 'Preview kedaluwarsa. Unggah kembali berkas impor.');

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function write(string $id, array $payload): void
    {
        abort_unless(Str::isUuid($id), 404);
        $path = $this->path($id);
        File::ensureDirectoryExists(dirname($path), 0700, true);
        File::replace($path, Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    /**
     * Serializes confirmations without using a database-backed cache or new tables.
     *
     * @param  Closure(): mixed  $callback
     */
    public function locked(Closure $callback): mixed
    {
        $path = Storage::disk('local')->path('bulk-imports/confirmation.lock');
        File::ensureDirectoryExists(dirname($path), 0700, true);
        $handle = fopen($path, 'c');
        abort_unless($handle !== false, 503, 'Penyimpanan impor tidak tersedia. Coba kembali nanti.');
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            abort(409, 'Impor lain sedang diproses. Coba kembali setelah selesai.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(string $id): string
    {
        return Storage::disk('local')->path('bulk-imports/'.$id.'.json');
    }
}
