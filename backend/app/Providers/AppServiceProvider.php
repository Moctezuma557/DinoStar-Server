<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Freno contra fuerza bruta: 10 intentos por minuto por correo e IP.
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');

            // Un email no textual (arreglo, objeto) lo rechaza LoginRequest con 422; aqui solo se evita un 500.
            return Limit::perMinute(10)->by(
                (is_string($email) ? mb_strtolower(trim($email)) : '').'|'.$request->ip(),
            );
        });
    }
}
