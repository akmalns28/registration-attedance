<?php

use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,

            'active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->routeIs('events.reports.data')
            || $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
            if (! $request->routeIs('attendance.scanner.validate', 'attendance.check-in', 'attendance.manual.*', 'events.days.attendance')) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => collect($exception->errors())->flatten()->first(),
                    'errors' => $exception->errors(),
                ], 422)->header('Cache-Control', 'no-store, private');
            }

            $status = $exception instanceof AuthenticationException ? 401
                : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500);

            return response()->json([
                'success' => false,
                'message' => match ($status) {
                    401 => 'Sesi Anda telah berakhir. Silakan login kembali.',
                    403 => 'Anda tidak memiliki izin untuk menggunakan QR Scanner.',
                    404 => 'Event atau hari pelaksanaan tidak ditemukan. Muat ulang halaman.',
                    419 => 'Sesi telah berakhir. Muat ulang halaman sebelum memindai kembali.',
                    429 => 'Terlalu banyak pemindaian. Tunggu sebentar lalu coba lagi.',
                    default => 'Validasi tidak dapat diproses. Silakan coba lagi.',
                },
            ], $status, $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [])
                ->header('Cache-Control', 'no-store, private');
        });
    })
    ->create();
