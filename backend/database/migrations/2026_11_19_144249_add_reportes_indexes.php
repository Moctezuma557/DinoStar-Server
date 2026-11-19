<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sesiones', function (Blueprint $table): void {
            $table->index(['inicio', 'id'], 'idx_reportes_sesiones_inicio');
        });
        Schema::table('alertas', function (Blueprint $table): void {
            $table->index(['created_at', 'sesion_id'], 'idx_reportes_alertas_fecha');
        });
        Schema::table('atenciones_alerta', function (Blueprint $table): void {
            $table->index(['created_at', 'alerta_id'], 'idx_reportes_atenciones_fecha');
        });
        Schema::table('pacientes', function (Blueprint $table): void {
            $table->index(['sala', 'id'], 'idx_reportes_pacientes_sala');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pacientes', function (Blueprint $table): void {
            $table->dropIndex('idx_reportes_pacientes_sala');
        });
        Schema::table('atenciones_alerta', function (Blueprint $table): void {
            $table->dropIndex('idx_reportes_atenciones_fecha');
        });
        Schema::table('alertas', function (Blueprint $table): void {
            $table->dropIndex('idx_reportes_alertas_fecha');
        });
        Schema::table('sesiones', function (Blueprint $table): void {
            $table->dropIndex('idx_reportes_sesiones_inicio');
        });
    }
};
