<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DomainSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DomainMigrationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DINOSTAR_MIGRATION_TEST') !== '1') {
            $this->markTestSkipped('Opt-in PostgreSQL test: requires the isolated issue22 test database.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '55432',
            'database' => 'dinostar_issue22_test', 'username' => 'postgres',
            'password' => 'issue22-test-only', 'charset' => 'utf8',
            'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
        ]]);
        DB::purge('pgsql');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (getenv('DINOSTAR_MIGRATION_TEST') === '1') {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        parent::tearDown();
    }

    private function migrate(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
    }

    private function importSql(): void
    {
        $sql = file_get_contents(base_path('../database/init.sql'));
        $sql = preg_replace('/^\s*(BEGIN|COMMIT);\s*$/m', '', $sql);
        DB::unprepared($sql);
    }

    private function verifyIntegrity(): void
    {
        $sql = file_get_contents(base_path('../database/verify.sql'));
        $sql = preg_replace('/^\\\\.*$/m', '', $sql);
        $sql = preg_replace('/^\s*(BEGIN|ROLLBACK);\s*$/m', '', $sql);
        DB::beginTransaction();
        DB::unprepared($sql);
        DB::rollBack();
    }

    public function test_fresh_migrations_integrity_repeat_and_rollback(): void
    {
        $this->migrate();
        foreach (DomainSchema::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('sessions'));
        $this->verifyIntegrity();
        $this->migrate();
        $this->assertStringContainsString('Nothing to migrate', Artisan::output());
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true]));
        foreach (DomainSchema::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->migrate();
        $this->verifyIntegrity();
    }

    public function test_existing_sql_is_adopted_without_losing_rows_and_rollback_is_blocked(): void
    {
        $this->importSql();
        $user = User::factory()->create();
        $this->migrate();
        $this->assertSame($user->nombre, $user->fresh()->nombre);
        $this->assertSame(8, DB::table('dinostar_schema_adoptions')->count());
        $this->verifyIntegrity();
        $this->migrate();
        try {
            Artisan::call('migrate:rollback', ['--force' => true]);
            $this->fail('Rollback must refuse to drop adopted tables.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rollback bloqueado', $exception->getMessage());
        }
        $this->assertNotNull($user->fresh());
        foreach (DomainSchema::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }

    public function test_incompatible_schema_is_rejected_and_data_is_preserved(): void
    {
        $this->importSql();
        $user = User::factory()->create();
        DB::statement('ALTER TABLE usuarios DROP CONSTRAINT chk_usuarios_turno');
        try {
            $this->migrate();
            $this->fail('Must reject a missing constraint.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('difiere del esquema', $exception->getMessage());
        }
        $this->assertNotNull($user->fresh());
        $this->assertFalse(Schema::hasTable('dinostar_schema_adoptions'));
    }

    public function test_model_and_factory_use_domain_users(): void
    {
        $this->migrate();
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->assertSame('usuarios', $user->getTable());
        $this->assertSame('ENFERMERA', $user->rol);
        $this->assertNull($admin->turno);
        $this->assertSame('', $user->getRememberTokenName());
        $this->assertTrue(password_verify('password', $user->password));
        $this->assertSame('usuarios', config('auth.providers.users.model')::make()->getTable());
    }

    public function test_existing_legacy_tables_are_not_deleted_or_used_by_the_model(): void
    {
        DB::statement('CREATE TABLE users (id bigint PRIMARY KEY, name text)');
        DB::statement('CREATE TABLE sessions (id text PRIMARY KEY, payload text)');
        DB::table('users')->insert(['id' => 1, 'name' => 'Legacy account']);
        DB::table('sessions')->insert(['id' => 'legacy', 'payload' => 'keep']);
        $this->migrate();
        $user = User::factory()->create();
        $this->assertSame(1, DB::table('users')->count());
        $this->assertNotNull($user->fresh());
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true]));
        $this->assertSame('Legacy account', DB::table('users')->value('name'));
        $this->assertSame('keep', DB::table('sessions')->value('payload'));
    }

    public function test_changed_column_or_partial_index_is_rejected(): void
    {
        $this->importSql();
        foreach ([
            'ALTER TABLE usuarios ALTER COLUMN nombre TYPE varchar(200)',
            'DROP INDEX uq_pacientes_cama_activa; CREATE UNIQUE INDEX uq_pacientes_cama_activa ON pacientes (lower(sala), lower(numero_cama))',
        ] as $sql) {
            DB::beginTransaction();
            DB::unprepared($sql);
            try {
                $this->migrate();
                $this->fail('Must reject structural drift.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('difiere del esquema', $exception->getMessage());
            } finally {
                DB::rollBack();
            }
        }
    }
}
