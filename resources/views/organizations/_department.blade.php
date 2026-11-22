@php
    $id = is_scalar($record['id'] ?? null) ? $record['id'] : null;
    $prefix = "departments[$index]";
    $path = "departments.$index.";
@endphp
<fieldset id="{{ $id ? 'department-'.$id : 'department-draft-'.$index }}" class="border rounded p-2 p-sm-3"
    data-department data-department-index="{{ $index }}" @if ($legacy) data-legacy-id="{{ $id }}" @endif>
    <legend class="float-none w-auto h5 mb-1 px-1" data-department-label>Departemen</legend>
    <input type="hidden" name="{{ $prefix }}[id]" value="{{ $id }}" data-department-id>
    <div class="row g-2">
        <div class="col-12 col-xl">
            @include('organizations._fields', ['codeRequired' => !$id || filled($record['code'] ?? null)])
        </div>
        @if (auth()->user()->can('departments.create') || !$id || $legacy)
            <div class="col-12 col-xl-auto mt-xl-4">
                <div class="d-flex flex-wrap gap-2">
                    @can('departments.create')
                        <x-ui.button size="sm" outline data-add-division>Tambah Divisi</x-ui.button>
                    @endcan
                    <x-ui.button variant="secondary" size="sm" outline data-remove-department :hidden="$id && !$legacy">
                        {{ $legacy ? 'Lepas dari formulir' : 'Hapus Departemen' }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </div>
    <div class="vstack gap-2" data-divisions>
        @foreach (is_array($record['divisions'] ?? null) ? $record['divisions'] : [] as $divisionIndex => $division)
            @if (is_array($division))
                @include('organizations._division', ['record' => $division, 'departmentIndex' => $index, 'index' => $divisionIndex])
            @endif
        @endforeach
    </div>
    <details class="mt-2" @if ($errors->has($path.'description')) open @endif>
        <summary class="small">Deskripsi (opsional)</summary>
        <label for="department-{{ $index }}-description" class="visually-hidden">Deskripsi departemen</label>
        <textarea id="department-{{ $index }}-description" name="{{ $prefix }}[description]" rows="2" maxlength="2000"
            @class(['form-control mt-2', 'is-invalid' => $errors->has($path.'description')])
            aria-describedby="department-{{ $index }}-description-error"
            data-department-description>{{ is_string($record['description'] ?? null) ? $record['description'] : '' }}</textarea>
        <div id="department-{{ $index }}-description-error" class="invalid-feedback">{{ $errors->first($path.'description') }}</div>
    </details>
</fieldset>
