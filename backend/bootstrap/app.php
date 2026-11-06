<?php

use App\Http\Middleware\EnsureAccountUsable;
use App\Http\Middleware\EnsureRole;
use App\Support\ControlBlocked;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Login SPA memakai cookie sesi Sanctum (dokumen 12 §2.1, §6).
        $middleware->statefulApi();

        $middleware->alias([
            'role' => EnsureRole::class,
            'account.usable' => EnsureAccountUsable::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Percobaan yang ditolak kontrol wajib dicatat di jejak audit (irisan 9), lalu dijawab 403 seperti biasa.
        $exceptions->render(function (ControlBlocked $e) {
            $e->record();

            return null;
        });
    })->create();
