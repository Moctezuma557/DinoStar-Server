<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API pura: no hay pantalla de login en Laravel a la que redirigir (evita un 500 sin Accept: json).
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'activo' => EnsureUserIsActive::class,
            'rol' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'No autenticado. Inicia sesión para continuar.',
                    'code' => 'no_autenticado',
                ], 401);
            }
        });
    })->create();
