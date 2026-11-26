<?php

namespace App\Http\Requests\Event;

use App\Models\Event;
use App\Services\EventPosterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('events.create') ?? false;
    }

    public function rules(): array
    {
        return [
            ...EventPosterService::rules(),
            'title' => ['required', 'string', 'max:200'],

            'description' => ['nullable', 'string'],
            'dress_code' => ['nullable', 'string', 'max:150'],

            'location' => ['nullable', 'string', 'max:255'],
            'google_maps_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048', 'dimensions:max_width=3000,max_height=3000'],

            'start_date' => ['required', 'date_format:Y-m-d'],

            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],

            'auto_create_event_days' => ['sometimes', 'boolean'],

            'registration_start_at' => ['nullable', 'date_format:Y-m-d H:i', 'required_with:registration_end_at'],

            'registration_end_at' => ['nullable', 'date_format:Y-m-d H:i', 'required_with:registration_start_at', 'after_or_equal:registration_start_at'],

            'capacity' => ['nullable', 'integer', 'min:1'],

            'status' => ['required', Rule::in(Event::statuses())],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ((int) $this->server('CONTENT_LENGTH', 0) > config('event_assets.post_max_bytes')) {
            throw new PostTooLargeException;
        }

        $registrationStart = $this->input('registration_start_at');
        $registrationEnd = $this->input('registration_end_at');

        $this->merge([
            'title' => trim((string) $this->input('title')),

            'description' => $this->normalizeOptional('description'),

            'location' => $this->normalizeOptional('location'),

            'registration_start_at' => is_string($registrationStart) ? $this->normalizeDateTime($registrationStart) : null,

            'registration_end_at' => is_string($registrationEnd) ? $this->normalizeDateTime($registrationEnd) : null,

            'capacity' => $this->normalizeOptional('capacity'),

            'status' => strtolower(trim((string) $this->input('status', 'draft'))),
        ]);
    }

    protected function normalizeDateTime(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : str_replace('T', ' ', $value);
    }

    protected function normalizeOptional(string $field): ?string
    {
        $value = $this->input($field);

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    public function messages(): array
    {
        $posterLimit = EventPosterService::uploadLimitLabel();

        return [
            'posters.max' => 'Jumlah poster melebihi batas per event.',
            'posters.*.uploaded' => "Poster gagal diunggah. Batas ukuran satu berkas adalah {$posterLimit}.",
            'posters.*.max' => "Ukuran setiap poster maksimal {$posterLimit}.",
            'posters.*.mimes' => 'Poster harus berupa gambar JPG, JPEG, PNG, atau WebP.',
            'posters.*.extensions' => 'Gunakan ekstensi JPG, JPEG, PNG, atau WebP untuk poster.',
            'posters.*.file' => 'Pilih berkas gambar untuk poster.',
            'poster_assignments.*' => 'Pilihan hari untuk poster tidak valid.',
            'google_maps_url.url' => 'URL Google Maps harus menggunakan HTTP atau HTTPS.',
            'logo.image' => 'Logo event harus berupa gambar.',
            'logo.mimes' => 'Logo event harus berformat PNG atau JPEG.',
            'logo.max' => 'Ukuran logo event maksimal 2 MB.',
            'logo.dimensions' => 'Dimensi logo event maksimal 3000 × 3000 piksel.',
            'title.required' => 'Nama event wajib diisi.',
            'title.max' => 'Nama event maksimal 200 karakter.',
            'dress_code.max' => 'Dress code maksimal 150 karakter.',

            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'start_date.date_format' => 'Format tanggal mulai tidak valid.',

            'end_date.required' => 'Tanggal selesai wajib diisi.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',

            'auto_create_event_days.boolean' => 'Pilihan pembuatan Event Day otomatis tidak valid.',

            'registration_start_at.required_with' => 'Tanggal mulai registrasi wajib diisi jika tanggal selesai registrasi ditentukan.',

            'registration_end_at.required_with' => 'Tanggal selesai registrasi wajib diisi jika tanggal mulai registrasi ditentukan.',

            'registration_end_at.after_or_equal' => 'Tanggal selesai registrasi tidak boleh sebelum tanggal mulai registrasi.',

            'capacity.integer' => 'Kapasitas harus berupa angka.',
            'capacity.min' => 'Kapasitas minimal 1 peserta.',

            'status.in' => 'Status event tidak valid.',
        ];
    }
}
