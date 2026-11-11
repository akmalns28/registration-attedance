@auth

    <div class="nav-item dropdown">

        <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown">

            <span class="avatar avatar-sm bg-primary-lt">
                {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
            </span>

            <div class="d-none d-xl-block ps-2">

                <div>
                    {{ auth()->user()->name }}
                </div>

                <div class="mt-1 small text-secondary">
                    {{ ucfirst(auth()->user()->role) }}
                </div>

            </div>

        </a>

        <div class="dropdown-menu dropdown-menu-end">

            <div class="dropdown-header">
                {{ auth()->user()->email }}
            </div>

            <div class="dropdown-divider"></div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf

                <button type="submit" class="dropdown-item text-danger">
                    Logout
                </button>

            </form>

        </div>

    </div>

@endauth
