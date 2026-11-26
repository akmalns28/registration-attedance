@props(['label', 'value' => null, 'hint'])

@php
    $text = old('dress_code', $value);
@endphp

<div class="mb-3" data-dress-code-editor>
    <label for="dress_code" class="form-label">{{ $label }}</label>
    <div class="flex-wrap gap-2 mb-2" role="group" aria-label="Format Dress Code" data-dress-code-toolbar hidden>
        <button type="button" class="btn btn-outline-secondary btn-sm" aria-controls="dress_code" data-list-format="bullet">
            Daftar poin
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" aria-controls="dress_code" data-list-format="number">
            Daftar angka
        </button>
    </div>
    <textarea id="dress_code" name="dress_code" rows="4" maxlength="{{ \App\Services\DressCode::MAX_LENGTH }}"
        class="form-control @error('dress_code') is-invalid @enderror"
        aria-describedby="dress_code_hint{{ $errors->has('dress_code') ? ' dress_code_error' : '' }}"
        @error('dress_code') aria-invalid="true" @enderror>{{ is_string($text) ? $text : '' }}</textarea>
    @error('dress_code')
        <div id="dress_code_error" class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div id="dress_code_hint" class="form-hint">{{ $hint }}</div>
</div>
