<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        @yield('title', 'Error') - Registration Attendance
    </title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="d-flex flex-column bg-body-tertiary">

    <div class="page page-center min-vh-100">

        <div class="container container-tight py-4">

            <div class="text-center mb-4">
                <span class="fw-bold fs-2">
                    Registration Attendance
                </span>
            </div>

            @yield('content')

            <div class="text-center text-secondary small mt-4">
                &copy; {{ now()->year }} PT Ari Utsaha Nusantara
            </div>

        </div>

    </div>

</body>

</html>
