<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LogDispositivo extends Model
{
    use HasFactory;

    protected $table = 'log_dispositivos';

    // Desactivamos updated_at ya que la tabla solo usa created_at (TIMESTAMPTZ)
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'tipo',
        'categoria',
        'mensaje',
        'paciente_id',
        'numero_cama',
        'sesion_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function paciente()
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    public function sesion()
    {
        return $this->belongsTo(Sesion::class, 'sesion_id');
    }
}