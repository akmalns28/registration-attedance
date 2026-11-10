@props([
    'items' => [],
])

<nav aria-label="breadcrumb" {{ $attributes }}>
    <ol class="breadcrumb breadcrumb-arrows">

        @foreach ($items as $item)
            @php
                $isLast = $loop->last;
                $label = $item['label'] ?? '';
                $url = $item['url'] ?? null;
            @endphp

            <li @class(['breadcrumb-item', 'active' => $isLast]) @if ($isLast) aria-current="page" @endif>

                @if (!$isLast && $url)
                    <a href="{{ $url }}">
                        {{ $label }}
                    </a>
                @else
                    {{ $label }}
                @endif

            </li>
        @endforeach

    </ol>
</nav>
