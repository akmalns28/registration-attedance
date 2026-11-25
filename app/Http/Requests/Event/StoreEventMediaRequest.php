<?php

namespace App\Http\Requests\Event;

use App\Models\EventMedia;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreEventMediaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('events.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'files' => ['required', 'array', 'list', 'min:1', 'max:'.config('event_assets.gallery_max_files')],
            'files.*' => [
                'bail', 'required', 'file',
                'extensions:jpg,jpeg,png,webp,mp4,webm,mov',
                'mimetypes:'.implode(',', [...EventMedia::IMAGE_MIMES, ...EventMedia::VIDEO_MIMES]),
                function (string $attribute, UploadedFile $file, Closure $fail): void {
                    $isImage = in_array($file->getMimeType(), EventMedia::IMAGE_MIMES, true);
                    $limit = (int) config($isImage ? 'event_assets.gallery_image_max_kb' : 'event_assets.gallery_video_max_kb');
                    if ($file->getSize() > $limit * 1024) {
                        $fail("Ukuran berkas melebihi batas {$limit} KB.");
                    }

                    if ($isImage && @getimagesize($file->getPathname()) === false) {
                        $fail('Berkas gambar tidak dapat dibaca.');
                    }
                },
            ],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('events.show', $this->route('event')).'#event-gallery';
    }
}
