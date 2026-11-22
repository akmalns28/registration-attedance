<div class="col-12" data-organization-selects data-organization-filter @if ($filterPrefix === 'candidate') data-candidate-department-filter @endif>
    <div class="row g-2">
        @foreach (['entity' => 'Entitas', 'department' => 'Departemen', 'division' => 'Divisi'] as $level => $label)
            @php
                $field = $level.'_id';
                $value = $organizationSelection[$field] ?? null;
                $name = $filterPrefix === 'report' ? 'report_'.$level : $field;
            @endphp
            <div class="col-12">
                <label for="{{ $filterPrefix }}-{{ $level }}" class="form-label">{{ $label }}</label>
                <select id="{{ $filterPrefix }}-{{ $level }}" @unless ($filterPrefix === 'candidate') name="{{ $name }}" @endunless
                    data-organization-select="{{ $level }}" data-filter-label="{{ $label }}" class="form-select">
                    <option value="">Semua {{ $label }}</option>
                    @foreach ($organizationOptions[$level] as $option)
                        <option value="{{ $option->id }}" @selected(is_scalar($value) && (string) $value === (string) $option->id)
                            data-parent="{{ match ($level) {
                                'department' => $option->entity_id ?? 'legacy',
                                'division' => $option->department_id,
                                default => '',
                            } }}">{{ $option->organizationLabel() }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach
    </div>
</div>
