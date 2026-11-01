<?php

use App\Http\Controllers\PacienteController;
use App\Http\Controllers\SesionLecturaController;
use App\Http\Controllers\EnfermeraController;
use App\Http\Controllers\LogDispositivoController;
use Illuminate\Support\Facades\Route;

Route::get('/pacientes', [PacienteController::class, 'index'])->name('pacientes.index');
Route::post('/pacientes', [PacienteController::class, 'store'])->name('pacientes.store');
Route::get('/pacientes/{id}', [PacienteController::class, 'show'])
    ->where('id', '-?[0-9]{1,18}')->name('pacientes.show');

Route::get('/sesiones/{id}/lecturas', [SesionLecturaController::class, 'index'])
    ->where('id', '-?[0-9]{1,18}')->name('sesiones.lecturas.index');

Route::get('/enfermeras', [EnfermeraController::class, 'index'])->name('enfermeras.index');
Route::get('/enfermeras/{id}', [EnfermeraController::class, 'show'])->name('enfermeras.show');
Route::post('/enfermeras', [EnfermeraController::class, 'store'])->name('enfermeras.store');

//Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/dispositivos/logs', [LogDispositivoController::class, 'index']);
  //      ->name('dispositivos.logs.index');
//});