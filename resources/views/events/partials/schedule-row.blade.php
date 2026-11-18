@php
    $isEdit = $schedule !== null;
    $formId = $isEdit ? 'schedule-'.$schedule->id : 'schedule-new-'.$day->id;
    $hasOldInput = old('schedule_form') === $formId;
    $fieldValue = fn (string $field, mixed $default = null): mixed => $hasOldInput ? old($field, $default) : $default;
    $fieldHasError = fn (string $field): bool => $hasOldInput && $errors->has($field);
    $nextSortOrder = min(65535, ($day->schedules->max('sort_order') ?? -1) + 1);
@endphp

<tr @class(['table-light' => ! $isEdit])>
    <td class="align-top schedule-time" data-label="Waktu" data-search="{{ $schedule?->start_time }} {{ $schedule?->end_time }}" data-order="{{ $schedule?->start_time }}">
        <div class="schedule-time-inputs">
            @foreach (['start_time' => 'Jam mulai', 'end_time' => 'Jam selesai'] as $field => $label)
                <div>
                    <label for="{{ $formId }}-{{ $field }}" class="visually-hidden">{{ $label }}</label>
                    <input type="time" id="{{ $formId }}-{{ $field }}" name="{{ $field }}"
                        form="{{ $formId }}" @class(['form-control form-control-sm', 'is-invalid' => $fieldHasError($field)])
                        value="{{ $fieldValue($field, $isEdit ? substr($schedule->{$field}, 0, 5) : '') }}"
                        title="{{ $label }}" step="60" required>
                    @if ($fieldHasError($field))
                        <div class="invalid-feedback">{{ $errors->first($field) }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </td>
    <td class="align-top" data-label="Kegiatan" data-search="{{ $schedule?->title }} {{ $schedule?->description }}" data-order="{{ $schedule?->title }}">
        <label for="{{ $formId }}-title" class="visually-hidden">Nama kegiatan</label>
        <input type="text" id="{{ $formId }}-title" name="title" form="{{ $formId }}"
            @class(['form-control form-control-sm', 'is-invalid' => $fieldHasError('title')])
            value="{{ $fieldValue('title', $schedule?->title ?? '') }}" placeholder="Nama kegiatan"
            maxlength="200" required>
        @if ($fieldHasError('title'))
            <div class="invalid-feedback">{{ $errors->first('title') }}</div>
        @endif

        <label for="{{ $formId }}-description" class="visually-hidden">Deskripsi kegiatan</label>
        <textarea id="{{ $formId }}-description" name="description" form="{{ $formId }}"
            @class(['form-control form-control-sm mt-2', 'is-invalid' => $fieldHasError('description')])
            rows="1" maxlength="5000" placeholder="Deskripsi (opsional)">{{ $fieldValue('description', $schedule?->description ?? '') }}</textarea>
        @if ($fieldHasError('description'))
            <div class="invalid-feedback">{{ $errors->first('description') }}</div>
        @endif
    </td>
    <td class="align-top schedule-pic" data-label="PIC" data-search="{{ $schedule?->pic }}" data-order="{{ $schedule?->pic }}">
        <label for="{{ $formId }}-pic" class="visually-hidden">PIC kegiatan</label>
        <input type="text" id="{{ $formId }}-pic" name="pic" form="{{ $formId }}"
            @class(['form-control form-control-sm', 'is-invalid' => $fieldHasError('pic')])
            value="{{ $fieldValue('pic', $schedule?->pic ?? '') }}" placeholder="Nama PIC (opsional)"
            maxlength="255">
        @if ($fieldHasError('pic'))
            <div class="invalid-feedback">{{ $errors->first('pic') }}</div>
        @endif
    </td>
    <td class="align-top schedule-actions text-end">
        <form id="{{ $formId }}" method="POST"
            action="{{ $isEdit ? route('events.days.schedules.update', [$event, $day, $schedule]) : route('events.days.schedules.store', [$event, $day]) }}"
            data-form data-loading-text="Menyimpan...">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif
            <input type="hidden" name="schedule_form" value="{{ $formId }}">
            <input type="hidden" name="sort_order" value="{{ $fieldValue('sort_order', $schedule?->sort_order ?? $nextSortOrder) }}">

            <div class="btn-list flex-nowrap justify-content-end">
                <button type="submit" class="btn btn-sm {{ $isEdit ? 'btn-outline-primary' : 'btn-primary' }}">
                    <x-ui.icon :name="$isEdit ? 'device-floppy' : 'plus'" class="me-1" />
                    {{ $isEdit ? 'Simpan' : 'Tambah' }}
                </button>

                @if ($isEdit)
                    <x-ui.action icon="trash" :label="'Hapus '.$schedule->title" variant="danger"
                        data-bs-toggle="modal" data-bs-target="#deleteScheduleModal"
                        data-delete-url="{{ route('events.days.schedules.destroy', [$event, $day, $schedule]) }}"
                        data-delete-name="{{ $schedule->title }}" />
                @endif
            </div>
            @if ($fieldHasError('sort_order'))
                <div class="text-danger small mt-2">{{ $errors->first('sort_order') }}</div>
            @endif
        </form>
    </td>
</tr>
