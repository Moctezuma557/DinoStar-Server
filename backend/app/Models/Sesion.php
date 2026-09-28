<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sesion extends Model
{
    protected $table = 'sesiones';

    protected $fillable = ['paciente_id', 'enfermera_id', 'vol_total', 'modo_goteo', 'estado', 'inicio', 'fin'];

    protected function casts(): array
    {
        return ['vol_total' => 'decimal:2', 'inicio' => 'immutable_datetime', 'fin' => 'immutable_datetime'];
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    public function enfermera(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enfermera_id');
    }

    public function lecturas(): HasMany
    {
        return $this->hasMany(Lectura::class, 'sesion_id');
    }

    public function alertas(): HasMany
    {
        return $this->hasMany(Alerta::class, 'sesion_id');
    }
}
