<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConfigureMqttDemo extends Command
{
    protected $signature = 'demo:configurar-mqtt {--dry-run : Mostrar los modos sin modificar las sesiones}';

    protected $description = 'Alinea las dos sesiones demo: cama-01 MICRO y cama-02 NORMAL; conserva el historial';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('La configuración demo solo se permite en PostgreSQL local/testing.');

            return self::FAILURE;
        }

        try {
            $rows = DB::transaction(function (): array {
                DB::select('SELECT pg_advisory_xact_lock(350035)');
                $modes = [-35001 => 'MICRO_GOTEO', -35002 => 'NORMAL_GOTEO'];
                $patients = DB::table('pacientes')->whereIn('id', array_keys($modes))->lockForUpdate()->get()->keyBy('id');
                $sessions = DB::table('sesiones')->whereIn('id', array_keys($modes))->lockForUpdate()->get()->keyBy('id');
                if (DB::table('sesiones')->where('estado', 'ACTIVA')->whereNotIn('id', array_keys($modes))->exists()) {
                    throw new RuntimeException('Hay otras sesiones activas. Revisarlas antes de preparar la demo; no se modificó ninguna sesión.');
                }
                $rows = [];
                foreach ($modes as $id => $mode) {
                    $bed = $id === -35001 ? 'cama-01' : 'cama-02';
                    $patient = $patients->get($id);
                    $session = $sessions->get($id);
                    if ($patient === null || $patient->numero_cama !== $bed || ! $patient->activo
                        || $session === null || $session->paciente_id !== $id || $session->enfermera_id !== -35001
                        || $session->estado !== 'ACTIVA' || $session->fin !== null) {
                        throw new RuntimeException('La demo no coincide con los registros esperados de '.$bed.'. Revisar pacientes y sesiones; no se reactivan ni reemplazan registros.');
                    }
                    $rows[] = [$bed, $id, $session->modo_goteo, $mode];
                }
                if (! $this->option('dry-run')) {
                    foreach ($rows as [$bed, $id, $previous, $mode]) {
                        if ($previous !== $mode) {
                            DB::table('sesiones')->where('id', $id)->update(['modo_goteo' => $mode, 'updated_at' => now()]);
                        }
                    }
                }

                return $rows;
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Cama', 'Sesión demo', 'Modo anterior', 'Modo requerido'], $rows);
        $this->info($this->option('dry-run') ? 'Solo consulta: no se modificaron datos.' : 'Configuración demo lista; lecturas, alertas y volúmenes conservados.');

        return self::SUCCESS;
    }
}
