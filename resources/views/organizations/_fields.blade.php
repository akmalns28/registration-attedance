@php
    $inputNames = [
        'code' => $prefix ? $prefix.'[code]' : 'code',
        'name' => $prefix ? $prefix.'[name]' : 'name',
        'is_active' => $prefix ? $prefix.'[is_active]' : 'is_active',
    ];
    $idPrefix = $path ? str_replace('.', '-', $path).'-' : 'entity-';
    $compact = $prefix !== '';
@endphp
<div class="row g-2">
    <div class="col-12 col-sm-3">
        <label for="{{ $idPrefix }}code" @class(['form-label', 'small mb-1' => $compact, 'required' => $codeRequired])>Kode</label>
        <input id="{{ $idPrefix }}code" name="{{ $inputNames['code'] }}" maxlength="20"
            @class(['form-control text-uppercase', 'form-control-sm' => $compact, 'is-invalid' => $errors->has($path.'code')])
            value="{{ is_scalar($record['code'] ?? null) ? $record['code'] : '' }}" data-organization-code
            aria-describedby="{{ $idPrefix }}code-error" @if ($errors->has($path.'code')) aria-invalid="true" @endif @required($codeRequired)>
        <div id="{{ $idPrefix }}code-error" class="invalid-feedback">{{ $errors->first($path.'code') }}</div>
    </div>
    <div class="col-12 col-sm-6">
        <label for="{{ $idPrefix }}name" @class(['form-label required', 'small mb-1' => $compact])>Nama</label>
        <input id="{{ $idPrefix }}name" name="{{ $inputNames['name'] }}" maxlength="150"
            @class(['form-control', 'form-control-sm' => $compact, 'is-invalid' => $errors->has($path.'name')])
            value="{{ is_scalar($record['name'] ?? null) ? $record['name'] : '' }}" data-organization-name
            aria-describedby="{{ $idPrefix }}name-error" @if ($errors->has($path.'name')) aria-invalid="true" @endif required>
        <div id="{{ $idPrefix }}name-error" class="invalid-feedback">{{ $errors->first($path.'name') }}</div>
    </div>
    <div class="col-12 col-sm-3">
        <label for="{{ $idPrefix }}status" @class(['form-label required', 'small mb-1' => $compact])>Status</label>
        <select id="{{ $idPrefix }}status" name="{{ $inputNames['is_active'] }}"
            @class(['form-select', 'form-select-sm' => $compact, 'is-invalid' => $errors->has($path.'is_active')]) data-organization-status
            aria-describedby="{{ $idPrefix }}status-error" @if ($errors->has($path.'is_active')) aria-invalid="true" @endif required>
            <option value="1" @selected($record['is_active'] ?? true)>Aktif</option>
            <option value="0" @selected(!($record['is_active'] ?? true))>Tidak Aktif</option>
        </select>
        <div id="{{ $idPrefix }}status-error" class="invalid-feedback">{{ $errors->first($path.'is_active') }}</div>
    </div>
</div>
