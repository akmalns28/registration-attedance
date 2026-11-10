<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>
        @yield('title', 'Dashboard') -
        {{ config('app.name') }}
    </title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('styles')
</head>

<body>

    <div class="page">

        {{-- Sidebar --}}
        @include('partials.sidebar')

        {{-- Main Content --}}
        <div class="page-wrapper">

            {{-- Navbar --}}
            @include('partials.navbar')

            {{-- Page Content --}}
            <div class="page-body">

                <div class="container-xl">

                    @yield('content')

                </div>

            </div>

            {{-- Footer --}}
            @include('partials.footer')

        </div>

    </div>

    @stack('scripts')

</body>

</html>
