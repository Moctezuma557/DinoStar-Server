<?php

use App\Http\Controllers\EnfermeraController;
use App\Http\Controllers\LogDispositivoController;
use App\Http\Controllers\PacienteController;
use App\Http\Controllers\SesionController;
use App\Http\Controllers\SesionLecturaController;
use Illuminate\Support\Facades\Route;

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
Route::post('/enfermeras', [EnfermeraController::class, 'store'])->name('enfermeras.store');

// Route::middleware(['auth:sanctum', 'admin'])->group(function () {
Route::get('/dispositivos/logs', [LogDispositivoController::class, 'index']);
//      ->name('dispositivos.logs.index');
// });

Route::post('/sesiones', [SesionController::class, 'store'])->name('sesiones.store');
Route::patch('/sesiones/{id}', [SesionController::class, 'update'])
    ->where('id', '[0-9]{1,18}')->name('sesiones.update');
Route::post('/sesiones/{id}/finalizar', [SesionController::class, 'finalizar'])
    ->where('id', '[0-9]{1,18}')->name('sesiones.finalizar');
