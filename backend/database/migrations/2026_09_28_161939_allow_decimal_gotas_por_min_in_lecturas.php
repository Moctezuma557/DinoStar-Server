<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE lecturas ALTER COLUMN gotas_por_min TYPE numeric USING gotas_por_min::numeric');
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE lecturas IN ACCESS EXCLUSIVE MODE');
        if (DB::table('lecturas')->whereRaw('gotas_por_min <> trunc(gotas_por_min)')->exists()) {
            throw new RuntimeException('Rollback bloqueado: existen gotas_por_min decimales. No se redondearon ni eliminaron lecturas.');
        }
        DB::statement('ALTER TABLE lecturas ALTER COLUMN gotas_por_min TYPE integer USING gotas_por_min::integer');
    }
};
