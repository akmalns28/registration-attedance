<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\StoreEventMediaRequest;
use App\Models\Event;
use App\Models\EventMedia;
use App\Services\EventAssetStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EventMediaController extends Controller
{
    public function __construct(private readonly EventAssetStorage $assets) {}

    public function store(StoreEventMediaRequest $request, Event $event): RedirectResponse
    {
        $details = $request->safe()->only(['title', 'description']);
        $uploader = $request->user()->id;
        try {
            $this->assets->saveUploads($request->file('files'), 'event-gallery', 'files', function (array $uploads) use ($event, $details, $uploader): void {
                DB::transaction(function () use ($event, $uploads, $details, $uploader): void {
                    $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                    foreach ($uploads as $upload) {
                        $lockedEvent->media()->create([
                            ...$upload,
                            ...$details,
                            'uploaded_by' => $uploader,
                            'media_type' => in_array($upload['mime_type'], EventMedia::IMAGE_MIMES, true) ? 'image' : 'video',
                        ]);
                    }
                }, attempts: 3);
            });
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('events.show', $event).'#event-gallery');
        }

        return redirect()->to(route('events.show', $event).'#event-gallery')->with('success', 'Dokumentasi berhasil diunggah.');
    }

    public function show(Event $event, EventMedia $media): BinaryFileResponse
    {
        return $this->assets->response($media->file_path, $media->mime_type);
    }

    public function download(Event $event, EventMedia $media): BinaryFileResponse
    {
        return $this->assets->response($media->file_path, $media->mime_type, $media->original_filename);
    }

    public function destroy(Event $event, EventMedia $media): RedirectResponse
    {
        if (! $media->deleteOrFail()) {
            throw ValidationException::withMessages(['files' => 'Dokumentasi gagal dihapus. Silakan coba kembali.'])
                ->redirectTo(route('events.show', $event).'#event-gallery');
        }

        $this->assets->delete([$media->file_path]);

        return redirect()->to(route('events.show', $event).'#event-gallery')->with('success', 'Dokumentasi berhasil dihapus.');
    }
}
