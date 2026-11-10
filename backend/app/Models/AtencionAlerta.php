<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtencionAlerta extends Model
{
    protected $table = 'atenciones_alerta';

    protected $fillable = ['alerta_id', 'enfermera_id', 'notas', 'resolvio'];

    protected function casts(): array
    {
        return ['resolvio' => 'boolean'];
    }

    public function alerta(): BelongsTo
    {
        return $this->belongsTo(Alerta::class, 'alerta_id');
    }

    public function enfermera(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enfermera_id');
    }
}
