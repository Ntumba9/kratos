<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the least-privilege database role created by
 * infra/postgres/init/01-roles.sh and the migrations' REVOKE statements.
 */
class DatabasePrivilegesTest extends TestCase
{
    private string $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->role = (string) config('kratos.database.app_role');

        if (DB::selectOne('SELECT 1 AS present FROM pg_roles WHERE rolname = ?', [$this->role]) === null) {
            $this->markTestSkipped("Database role [{$this->role}] does not exist; run infra/postgres/init/01-roles.sh.");
        }
    }

    private function can(string $table, string $privilege): bool
    {
        return (bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS allowed', [$this->role, $table, $privilege])->allowed;
    }

    public function test_application_role_cannot_alter_or_remove_audit_records(): void
    {
        $this->assertTrue($this->can('audit_logs', 'SELECT'));
        $this->assertTrue($this->can('audit_logs', 'INSERT'));
        $this->assertFalse($this->can('audit_logs', 'UPDATE'));
        $this->assertFalse($this->can('audit_logs', 'DELETE'));
        $this->assertFalse($this->can('audit_logs', 'TRUNCATE'));
    }

    public function test_application_role_has_dml_but_no_ddl(): void
    {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            $this->assertTrue($this->can('users', $privilege), "kratos_app should have {$privilege} on users");
        }

        $schemaCreate = DB::selectOne("SELECT has_schema_privilege(?, 'public', 'CREATE') AS allowed", [$this->role])->allowed;
        $this->assertFalse((bool) $schemaCreate, 'kratos_app must not be able to create objects');

        $owner = DB::selectOne("SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'users'")->tableowner;
        $this->assertNotSame($this->role, $owner);
    }
}
