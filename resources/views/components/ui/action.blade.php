@props(['icon', 'label', 'href' => null, 'variant' => 'secondary', 'type' => 'button', 'disabled' => false])

@if ($href)
    <a @if (! $disabled) href="{{ $href }}" @else aria-disabled="true" tabindex="-1" @endif
        aria-label="{{ $label }}" title="{{ $label }}" data-row-action
        {{ $attributes->class(['btn', 'btn-sm', 'btn-icon', 'ui-action', 'btn-outline-'.$variant, 'disabled' => $disabled]) }}>
        <x-ui.icon :name="$icon" />
    </a>
@else
    <button type="{{ $type }}" @disabled($disabled) aria-label="{{ $label }}" title="{{ $label }}" data-row-action
        {{ $attributes->class(['btn', 'btn-sm', 'btn-icon', 'ui-action', 'btn-outline-'.$variant]) }}>
        <x-ui.icon :name="$icon" />
    </button>
@endif
