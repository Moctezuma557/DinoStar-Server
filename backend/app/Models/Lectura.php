<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lectura extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected $table = 'lecturas';

    protected $fillable = ['sesion_id', 'gotas_por_min', 'vol_restante', 'tiempo_restante_min', 'timestamp_dispositivo'];

    protected function casts(): array
    {
        return ['gotas_por_min' => 'float', 'vol_restante' => 'decimal:2', 'tiempo_restante_min' => 'integer', 'timestamp_dispositivo' => 'immutable_datetime'];
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(Sesion::class, 'sesion_id');
    }
}
