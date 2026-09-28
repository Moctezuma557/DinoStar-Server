<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Paciente extends Model
{
    protected $table = 'pacientes';

    protected $fillable = ['nombre', 'numero_cama', 'sala', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function sesiones(): HasMany
    {
        return $this->hasMany(Sesion::class, 'paciente_id');
    }
}
