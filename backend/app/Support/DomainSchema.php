<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** PostgreSQL baseline shared by the eight immutable issue #22 migrations. */
class DomainSchema
{
    public const TABLES = ['usuarios', 'pacientes', 'asignaciones', 'sesiones', 'lecturas', 'alertas', 'atenciones_alerta', 'logs_auditoria'];

    public static function apply(string $table, string $sql): void
    {
        self::assertPostgres();
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            DB::unprepared($sql);

            return;
        }
        self::validateExisting($table);
        DB::statement('CREATE TABLE IF NOT EXISTS public.dinostar_schema_adoptions (table_name varchar(63) PRIMARY KEY)');
        DB::table('public.dinostar_schema_adoptions')->insertOrIgnore(['table_name' => $table]);
    }

    public static function revert(string $table): void
    {
        self::assertPostgres();
        if (DB::getSchemaBuilder()->hasTable('public.dinostar_schema_adoptions')
            && DB::table('public.dinostar_schema_adoptions')->where('table_name', $table)->exists()) {
            throw new RuntimeException("La tabla {$table} existia antes de las migraciones. Rollback bloqueado para conservar sus datos; utilice una nueva migracion de cambio.");
        }
        DB::getSchemaBuilder()->drop($table);
    }

    private static function assertPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('El esquema DinoStar requiere PostgreSQL. Ejecute las migraciones en el contenedor backend.');
        }
        if (DB::selectOne('SELECT current_schema() AS name')->name !== 'public') {
            throw new RuntimeException('Las migraciones DinoStar requieren public como esquema actual.');
        }
    }

    /** Build a disposable reference in this transaction; never infer compatibility from names alone. */
    private static function validateExisting(string $table): void
    {
        $reference = 'dinostar_reference_'.bin2hex(random_bytes(8));
        $path = DB::selectOne('SHOW search_path')->search_path;
        DB::statement('CREATE SCHEMA "'.$reference.'"');
        DB::select("SELECT set_config('search_path', ?, true)", [$reference]);
        foreach (self::TABLES as $name) {
            $files = glob(database_path('migrations/2026_09_20_*_create_'.$name.'_table.php'));
            if (count($files) !== 1) {
                throw new RuntimeException('No se encuentra la migracion inicial de '.$name);
            }
            $migration = require $files[0];
            DB::unprepared($migration->sql());
        }
        DB::select("SELECT set_config('search_path', ?, true)", [$path]);
        $actual = self::signature('public', $table);
        $expected = self::signature($reference, $table);
        DB::statement('DROP SCHEMA "'.$reference.'" CASCADE');
        if ($actual != $expected) {
            throw new RuntimeException("La tabla {$table} difiere del esquema #18. No se adopto ni se borraron datos. Revise columnas, restricciones e indices antes de migrar.");
        }
    }

    /** @return array<string, array<int, object>> */
    public static function signature(string $schema, string $table): array
    {
        $relation = $schema.'.'.$table;
        $columns = DB::select(<<<'SQL'
            SELECT a.attname, format_type(a.atttypid, a.atttypmod) AS type,
                a.attnotnull, a.attidentity, a.attgenerated,
                pg_get_expr(d.adbin, d.adrelid) AS default_value
            FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum
            WHERE a.attrelid=to_regclass(?) AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum
            SQL, [$relation]);
        $constraints = DB::select(<<<'SQL'
            SELECT conname, contype, convalidated, pg_get_constraintdef(oid) AS definition
            FROM pg_constraint WHERE conrelid=to_regclass(?) ORDER BY conname
            SQL, [$relation]);
        $indexes = DB::select(<<<'SQL'
            SELECT c.relname, i.indisvalid, pg_get_indexdef(i.indexrelid) AS definition
            FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid
            WHERE i.indrelid=to_regclass(?) ORDER BY c.relname
            SQL, [$relation]);
        foreach ([$constraints, $indexes] as $items) {
            foreach ($items as $item) {
                $item->definition = str_replace([$schema.'.', 'public.'], '', $item->definition);
            }
        }

        return ['columns' => $columns, 'constraints' => $constraints, 'indexes' => $indexes];
    }
}
