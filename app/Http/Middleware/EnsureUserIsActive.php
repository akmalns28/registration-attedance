<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->routeIs('events.reports.data')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun Anda telah dinonaktifkan.',
                ], 403);
            }

            return redirect()
                ->route('login')
                ->with(
                    'error',
                    'Akun Anda telah dinonaktifkan.'
                );
        }

        return $next($request);
    }
}
