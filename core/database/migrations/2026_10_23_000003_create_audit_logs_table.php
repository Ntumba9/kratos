<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 96);
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at');

            $table->index('action');
        });

        DB::statement('CREATE INDEX audit_logs_org_created_idx ON audit_logs (organisation_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_logs_actor_created_idx ON audit_logs (actor_user_id, created_at DESC)');

        // Append-only for the application role: it may insert and read audit
        // records but never alter or remove them.
        $appRole = (string) config('kratos.database.app_role');
        $exists = DB::selectOne('SELECT 1 AS present FROM pg_roles WHERE rolname = ?', [$appRole]);

        if ($exists !== null) {
            $quoted = '"'.str_replace('"', '""', $appRole).'"';
            DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM {$quoted}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
