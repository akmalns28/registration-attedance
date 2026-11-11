@php
    $navigation = app(\App\Services\NavigationService::class)->getMenu(auth()->user());
@endphp

<aside class="navbar navbar-vertical navbar-expand-lg" aria-label="Main navigation" data-bs-theme="dark">

    <div class="container-fluid">

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu"
            aria-controls="sidebar-menu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <h1 class="navbar-brand navbar-brand-autodark">
            <a href="{{ route('dashboard') }}">
                Registration Attendance
            </a>
        </h1>

        <div class="collapse navbar-collapse" id="sidebar-menu">

            <ul class="navbar-nav pt-lg-3">

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

                                <span class="nav-link-icon d-md-none d-lg-inline-block">
                                    <i class="{{ $item['icon'] }}" aria-hidden="true"></i>
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
