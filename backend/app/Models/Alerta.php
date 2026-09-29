<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alerta extends Model
{
    protected $table = 'alertas';

    protected $fillable = ['sesion_id', 'tipo', 'mensaje', 'resuelta'];

    protected function casts(): array
    {
        return ['resuelta' => 'boolean'];
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(Sesion::class, 'sesion_id');
    }
}
