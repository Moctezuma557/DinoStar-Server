<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoSeeder extends Seeder
{
    /** Negative IDs reserve demo records without advancing normal identity sequences. */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Los datos demo solo se permiten en APP_ENV=local o testing.');
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('El seeder DinoStar requiere PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::select('SELECT pg_advisory_xact_lock(350035)');
            $now = now()->startOfSecond();
            
            // 1. Insertar usuario demo (enfermera)
            $this->insertDemo('usuarios', -35001, [
                'email' => 'enfermera@dinostar.com', 
                'nombre' => 'Enfermera Demo',
            ], [
                'password' => Hash::driver('bcrypt')->make('password'),
                'rol' => 'ENFERMERA', 
                'turno' => 'MATUTINO', 
                'activo' => true,
                'created_at' => $now, 
                'updated_at' => $now,
            ]);

            // 2. Insertar los 10 pacientes y sus camas
            for ($number = 1; $number <= 10; $number++) {
                $suffix = sprintf('%02d', $number);
                $this->insertDemo('pacientes', -35000 - $number, [
                    'nombre' => 'Paciente '.$suffix,
                    'numero_cama' => 'cama-'.$suffix, 
                    'sala' => 'Sala A',
                ], [
                    'activo' => true, 
                    'created_at' => $now, 
                    'updated_at' => $now,
                ]);
            }

            // 3. Generar sesiones y lecturas para TODAS las camas (del 1 al 10)
            for ($number = 1; $number <= 10; $number++) {
                $sessionId = -35000 - $number;
                $patientId = -35000 - $number;

                // Alternar modo: impares NORMAL_GOTEO, pares MICRO_GOTEO
                $isNormal = $number % 2 !== 0;
                $mode = $isNormal ? 'NORMAL_GOTEO' : 'MICRO_GOTEO';
                $volume = $isNormal ? 500 : 250;
                $dropFactor = $isNormal ? 20 : 60;

                // Insertar sesión activa vinculada a su respectivo paciente
                $this->insertDemo('sesiones', $sessionId, [
                    'paciente_id' => $patientId, 
                    'enfermera_id' => -35001,
                    'modo_goteo' => $mode,
                ], [
                    'vol_total' => $volume, 
                    'estado' => 'ACTIVA', 
                    'inicio' => $now,
                    'fin' => null, 
                    'created_at' => $now, 
                    'updated_at' => $now,
                ]);

                // Insertar lecturas iniciales de prueba para esta sesión
                $session = DB::table('sesiones')->where('id', $sessionId)->first();
                for ($index = 0; $index < 5; $index++) {
                    $drops = 20 + ($index * 10);
                    $remaining = round((float) ($session->vol_total - ($index * 0.05)), 2);

                    $this->insertDemo('lecturas', -35000 - (($number - 1) * 5 + $index + 1), [
                        'sesion_id' => $sessionId,
                    ], [
                        'gotas_por_min' => $drops, 
                        'vol_restante' => $remaining,
                        'tiempo_restante_min' => (int) ceil(($remaining * $dropFactor) / $drops),
                        'timestamp_dispositivo' => CarbonImmutable::parse($session->inicio)->addSeconds($index),
                        'created_at' => $now,
                    ]);
                }
            }
        });
    }

    /**
     * Preserve existing demo data; reject IDs belonging to another record.
     *
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $values
     */
    private function insertDemo(string $table, int $id, array $identity, array $values): void
    {
        $existing = DB::table($table)->where('id', $id)->first();
        if ($existing !== null) {
            foreach ($identity as $column => $value) {
                if ((string) $existing->{$column} !== (string) $value) {
                    throw new RuntimeException("Conflicto de datos demo en {$table}, id {$id}. No se sobrescribieron registros.");
                }
            }

            return;
        }
        DB::table($table)->insert(['id' => $id, ...$identity, ...$values]);
    }
}