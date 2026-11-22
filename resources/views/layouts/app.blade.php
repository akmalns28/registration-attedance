<!DOCTYPE html>
<html lang="id" data-bs-navbar-position="combined">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>
        @yield('title', 'Dashboard') -
        {{ config('app.name') }}
    </title>

    @include('partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('styles')
</head>

<body>

    <div class="page min-vh-100">

        {{-- Sidebar --}}
        @include('partials.sidebar')

        {{-- Navbar --}}
        @include('partials.navbar')

        {{-- Main Content --}}
        <div class="page-wrapper">

            {{-- Page Content --}}
            <div class="page-body">

                <div class="container-xl">

                    @auth
                        <x-ui.breadcrumb class="app-breadcrumb mb-3 d-print-none"
                            :items="app(\App\Services\NavigationService::class)->getBreadcrumbs(request(), html_entity_decode($__env->yieldContent('title', 'Dashboard'), ENT_QUOTES, 'UTF-8'))" />
                    @endauth

                    @yield('content')

                </div>

            </div>

            {{-- Footer --}}
            @include('partials.footer')

        </div>

    </div>

    @include('partials.contact-us-modal')
    @stack('scripts')
    @include('partials.notifications')
</body>

</html>
