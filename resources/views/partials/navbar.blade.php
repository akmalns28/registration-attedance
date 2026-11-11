<header class="navbar navbar-expand-md d-print-none" aria-label="Account navigation">
    <div class="container-xl">
        <div class="navbar-nav">
            <span class="navbar-text fw-medium">@yield('title', 'Dashboard')</span>
        </div>

        @auth
            @php
                $user = auth()->user();
                $initials = \Illuminate\Support\Str::of($user->name)->squish()->explode(' ')
                    ->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('');
                $roleNames = $user->getRoleNames()
                    ->map(fn ($name) => \Illuminate\Support\Str::headline($name))->join(', ');
            @endphp

            <div class="navbar-nav flex-row ms-auto">
                <div class="nav-item dropdown" aria-label="Account navigation">
                    <button type="button" id="account-menu-toggle"
                        class="nav-link d-flex align-items-center text-reset p-0 border-0 bg-transparent"
                        data-bs-toggle="dropdown" aria-expanded="false" aria-label="Buka menu akun">
                        <span class="avatar avatar-sm bg-primary-lt flex-shrink-0" aria-hidden="true">
                            {{ mb_strtoupper($initials) }}
                        </span>
                        <span class="account-details d-none d-sm-block ps-2 text-start">
                            <span class="d-block text-truncate">{{ $user->name }}</span>
                            <span class="d-block text-truncate small text-secondary mt-1">
                                {{ $roleNames ?: 'Pengguna' }}
                            </span>
                        </span>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end account-menu" aria-labelledby="account-menu-toggle">
                        <div class="dropdown-header">
                            <div class="fw-semibold text-reset">{{ $user->name }}</div>
                            <div class="mt-1">{{ $user->email }}</div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger">Logout</button>
                        </form>
                    </div>
                </div>
            </div>
        @endauth
    </div>
</header>
