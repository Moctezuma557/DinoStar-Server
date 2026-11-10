<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Limpia tokens de Sanctum vencidos hace mas de 24 h (requiere el scheduler: php artisan schedule:work).
Schedule::command('sanctum:prune-expired --hours=24')->daily();
