<?php

namespace App\Http\Requests\BulkImport;

use App\Services\BulkImportService;
use Illuminate\Foundation\Http\FormRequest;

class UploadImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(BulkImportService::module($this->route('module'))['permission']) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:'.max(1, (int) config('bulk_imports.max_upload_kb'))],
            'module' => ['prohibited'], 'rows' => ['prohibited'], 'summary' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['file.required' => 'Pilih berkas impor terlebih dahulu.', 'file.extensions' => 'Unggah berkas .xlsx, .xls, atau .csv.',
            'file.max' => 'Berkas melebihi batas ukuran impor.'];
    }
}
