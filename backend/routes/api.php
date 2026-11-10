<?php

use App\Http\Controllers\AlertaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EnfermeraController;
use App\Http\Controllers\LogDispositivoController;
use App\Http\Controllers\PacienteController;
use App\Http\Controllers\SesionController;
use App\Http\Controllers\SesionLecturaController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
| Autenticacion (K3). Siempre activa, con o sin proteccion de las rutas CRUD.
*/
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')->name('auth.login');

Route::middleware(['auth:sanctum', 'activo'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
});

/*
| Proteccion de las rutas CRUD. Con AUTH_PROTEGER_RUTAS=true exigen token Bearer
| (401 sin el) y el rol indicado (403 con otro). Con false se comportan como antes
| para no romper al frontend mientras integra el login.
|
| Roles:  ADMIN              -> usuarios, alta de enfermeras, logs de dispositivos
|         ENFERMERA          -> resolver alertas
|         ADMIN o ENFERMERA  -> el resto (pacientes, sesiones, lecturas, alertas GET)
*/
$protegidas = config('auth.proteger_rutas');
$autenticado = $protegidas ? ['auth:sanctum', 'activo'] : [];
$rol = fn (string $roles): array => $protegidas ? ['rol:'.$roles] : [];

// --- Solo ADMIN -------------------------------------------------------------
Route::middleware([...$autenticado, ...$rol('ADMIN')])->group(function () {
    Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
    Route::post('/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
    Route::patch('/usuarios/{id}', [UsuarioController::class, 'update'])
        ->where('id', '-?[0-9]{1,18}')->name('usuarios.update');
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy'])
        ->where('id', '-?[0-9]{1,18}')->name('usuarios.destroy');

    Route::post('/enfermeras', [EnfermeraController::class, 'store'])->name('enfermeras.store');

    Route::get('/dispositivos/logs', [LogDispositivoController::class, 'index'])
        ->name('dispositivos.logs.index');
});

// --- Solo ENFERMERA ---------------------------------------------------------
Route::middleware([...$autenticado, ...$rol('ENFERMERA')])->group(function () {
    Route::patch('/alertas/{id}', [AlertaController::class, 'update'])
        ->where('id', '[0-9]{1,18}')->name('alertas.update');
});

// --- ADMIN o ENFERMERA ------------------------------------------------------
Route::middleware([...$autenticado, ...$rol('ADMIN,ENFERMERA')])->group(function () {
    Route::get('/pacientes', [PacienteController::class, 'index'])->name('pacientes.index');
    Route::post('/pacientes', [PacienteController::class, 'store'])->name('pacientes.store');
    Route::get('/pacientes/{id}', [PacienteController::class, 'show'])
        ->where('id', '-?[0-9]{1,18}')->name('pacientes.show');
    Route::patch('/pacientes/{id}', [PacienteController::class, 'update'])
        ->where('id', '-?[0-9]{1,18}')->name('pacientes.update');
    Route::delete('/pacientes/{id}', [PacienteController::class, 'destroy'])
        ->where('id', '-?[0-9]{1,18}')->name('pacientes.destroy');

    Route::get('/sesiones/{id}/lecturas', [SesionLecturaController::class, 'index'])
        ->where('id', '-?[0-9]{1,18}')->name('sesiones.lecturas.index');

    Route::get('/enfermeras', [EnfermeraController::class, 'index'])->name('enfermeras.index');
    Route::get('/enfermeras/{id}', [EnfermeraController::class, 'show'])->name('enfermeras.show');

    Route::post('/sesiones', [SesionController::class, 'store'])->name('sesiones.store');
    Route::patch('/sesiones/{id}', [SesionController::class, 'update'])
        ->where('id', '[0-9]{1,18}')->name('sesiones.update');
    Route::post('/sesiones/{id}/finalizar', [SesionController::class, 'finalizar'])
        ->where('id', '[0-9]{1,18}')->name('sesiones.finalizar');

    Route::get('/alertas', [AlertaController::class, 'index'])->name('alertas.index');
});
