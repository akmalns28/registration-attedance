<aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">

    <div class="container-fluid">

        {{-- Brand --}}
        <h1 class="navbar-brand">

            <a href="{{ route('dashboard') }}" class="text-decoration-none text-white">

                Registration Attendance

            </a>

        </h1>

        {{-- Mobile Toggle --}}
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu"
            aria-controls="sidebar-menu" aria-expanded="false" aria-label="Toggle navigation">

            <span class="navbar-toggler-icon"></span>

        </button>

        {{-- Navigation --}}
        <div class="collapse navbar-collapse" id="sidebar-menu">

            <ul class="navbar-nav pt-lg-3">
                @if (app()->environment('local'))
                    <li class="nav-item">

                        <a class="nav-link {{ request()->routeIs('components.preview') ? 'active' : '' }}"
                            href="{{ route('components.preview') }}">

                            <span class="nav-link-title">
                                UI Components
                            </span>

                            <span class="badge bg-yellow-lt ms-auto">
                                DEV
                            </span>

                        </a>

                    </li>
                @endif

                <li class="nav-item">

                    <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                        href="{{ route('dashboard') }}">

                        <span class="nav-link-title">
                            Dashboard
                        </span>

                    </a>

                </li>

                <li class="nav-item">

                    <a class="nav-link disabled" href="#">

                        <span class="nav-link-title">
                            Event Management
                        </span>

                    </a>

                </li>

                <li class="nav-item">

                    <a class="nav-link disabled" href="#">

                        <span class="nav-link-title">
                            Registration
                        </span>

                    </a>

                </li>

                <li class="nav-item">

                    <a class="nav-link disabled" href="#">

                        <span class="nav-link-title">
                            Attendance
                        </span>

                    </a>

                </li>

                <li class="nav-item">

                    <a class="nav-link disabled" href="#">

                        <span class="nav-link-title">
                            Master Data
                        </span>

                    </a>

                </li>

                <li class="nav-item">

                    <a class="nav-link disabled" href="#">

                        <span class="nav-link-title">
                            Reports
                        </span>

                    </a>

                </li>

            </ul>

        </div>

    </div>

</aside>
