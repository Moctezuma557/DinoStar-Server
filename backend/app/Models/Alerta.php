<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Alerta extends Model
{
    use HasFactory;

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

    public function atenciones(): HasMany
    {
        return $this->hasMany(AtencionAlerta::class, 'alerta_id');
    }
}
