@props(['value'])

@php
    $blocks = \App\Services\DressCode::blocks($value);
@endphp

@if ($blocks !== [])
    <div class="dress-code-content" data-dress-code-content style="overflow-wrap: break-word;">
        @foreach ($blocks as $block)
            @php($spacing = $loop->last ? '0' : '0.45em')
            @if ($block['type'] === 'ul')
                <ul style="margin: 0 0 {{ $spacing }}; padding-left: 1.5em; list-style-type: disc;">
                    @foreach ($block['lines'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            @elseif ($block['type'] === 'ol')
                <ol start="{{ $block['start'] }}" style="margin: 0 0 {{ $spacing }}; padding-left: 1.5em; list-style-type: decimal;">
                    @foreach ($block['lines'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ol>
            @else
                <p style="margin: 0 0 {{ $spacing }};">
                    @foreach ($block['lines'] as $line)
                        {{ $line }}@unless ($loop->last)<br>@endunless
                    @endforeach
                </p>
            @endif
        @endforeach
    </div>
@endif
