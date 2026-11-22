@php
    $id = is_scalar($record['id'] ?? null) ? $record['id'] : null;
    $prefix = "departments[$departmentIndex][divisions][$index]";
    $path = "departments.$departmentIndex.divisions.$index.";
@endphp
<fieldset class="bg-body-tertiary border-start border-2 rounded-end ms-2 ms-sm-3 mt-2 p-2"
    data-division data-division-index="{{ $index }}">
    <legend class="float-none w-auto h6 px-1 mb-0">Divisi</legend>
    <input type="hidden" name="{{ $prefix }}[id]" value="{{ $id }}" data-division-id>
    <div class="row g-2">
        <div class="col-12 col-xl">
            @include('organizations._fields', ['codeRequired' => !$id || filled($record['code'] ?? null)])
        </div>
        <div class="col-12 col-xl-auto mt-xl-4" data-division-actions @if ($id) hidden @endif>
            <x-ui.button variant="secondary" size="sm" outline data-remove-division>Hapus Divisi</x-ui.button>
        </div>
    </div>
</fieldset>
