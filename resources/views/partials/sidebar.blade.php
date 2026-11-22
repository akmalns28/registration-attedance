@php
    $navigation = app(\App\Services\NavigationService::class)->getMenu(auth()->user());
@endphp

<aside class="navbar navbar-vertical navbar-expand-lg app-sidebar d-print-none" aria-label="Main navigation" data-bs-theme="dark">

    <div class="container-fluid">

        <h1 class="navbar-brand navbar-brand-autodark text-wrap text-break d-none d-lg-flex">
            <a href="{{ route('dashboard') }}">
                {{ config('app.name') }}
            </a>
        </h1>

        <div class="navbar-collapse offcanvas-lg offcanvas-start" id="sidebar-menu" tabindex="-1" aria-labelledby="sidebar-menu-title">
            <div class="offcanvas-header">
                <h2 class="offcanvas-title text-break" id="sidebar-menu-title">{{ config('app.name') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebar-menu" aria-label="Tutup navigasi"></button>
            </div>

            <ul class="navbar-nav pt-lg-3 app-sidebar-links">

                @foreach ($navigation as $section)
                    <li class="nav-item mt-3 mb-1">
                        <span class="nav-link disabled text-uppercase small">
                            {{ $section['title'] }}
                        </span>
                    </li>

                    @foreach ($section['items'] as $item)
                        <li class="nav-item {{ $item['is_active'] ? 'active' : '' }}">

                            <a href="{{ $item['url'] }}" class="nav-link {{ $item['is_active'] ? 'active' : '' }}"
                                @if ($item['is_active']) aria-current="page" @endif>

                                <span class="nav-link-icon">
                                    <x-ui.icon :name="$item['icon']" />
                                </span>

                                <span class="nav-link-title">
                                    {{ $item['label'] }}
                                </span>

                            </a>

                        </li>
                    @endforeach
                @endforeach

            </ul>

        </div>

    </div>

</aside>
