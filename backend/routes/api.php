<?php

use App\Http\Controllers\PacienteController;
use App\Http\Controllers\SesionLecturaController;
use Illuminate\Support\Facades\Route;

Route::get('/pacientes', [PacienteController::class, 'index'])->name('pacientes.index');
Route::post('/pacientes', [PacienteController::class, 'store'])->name('pacientes.store');
Route::get('/pacientes/{id}', [PacienteController::class, 'show'])
    ->where('id', '-?[0-9]{1,18}')->name('pacientes.show');

Route::get('/sesiones/{id}/lecturas', [SesionLecturaController::class, 'index'])
    ->where('id', '-?[0-9]{1,18}')->name('sesiones.lecturas.index');
