<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        @yield('title', 'Login')
        - {{ config('app.name') }}
    </title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

</head>

<body class="d-flex flex-column">

    <div class="page page-center">

        <div class="container container-tight py-4">

            @yield('content')

        </div>

    </div>

    @include('partials.notifications')
</body>

</html>
