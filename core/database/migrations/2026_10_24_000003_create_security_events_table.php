<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('monitored_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('api_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('source', 128);
            $table->string('severity', 16);
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->ipAddress('source_ip')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->string('idempotency_key', 128)->nullable();
            $table->char('fingerprint', 64);
            $table->string('processing_status', 16)->default('received');
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->decimal('risk_score', 5, 2)->nullable(); // set by KRATOS MIND (Phase 3)
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE security_events ADD CONSTRAINT security_events_severity_check CHECK (severity IN ('info','low','medium','high','critical'))");
        DB::statement("ALTER TABLE security_events ADD CONSTRAINT security_events_status_check CHECK (processing_status IN ('received','queued','processing','processed','failed','dead_lettered'))");
        DB::statement('ALTER TABLE security_events ADD CONSTRAINT security_events_risk_range CHECK (risk_score IS NULL OR risk_score BETWEEN 0 AND 100)');

        // Deduplication (ARCHITECTURE §5).
        DB::statement('CREATE UNIQUE INDEX security_events_org_idempotency_unique ON security_events (organisation_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX security_events_org_fingerprint_unique ON security_events (organisation_id, fingerprint)');

        // Explorer and dashboard access paths; every index leads with the tenant.
        DB::statement('CREATE INDEX security_events_org_occurred_idx ON security_events (organisation_id, occurred_at DESC)');
        DB::statement('CREATE INDEX security_events_org_received_idx ON security_events (organisation_id, received_at DESC)');
        DB::statement('CREATE INDEX security_events_org_type_occurred_idx ON security_events (organisation_id, event_type, occurred_at DESC)');
        DB::statement('CREATE INDEX security_events_org_severity_occurred_idx ON security_events (organisation_id, severity, occurred_at DESC)');
        DB::statement('CREATE INDEX security_events_org_asset_occurred_idx ON security_events (organisation_id, monitored_asset_id, occurred_at DESC)');
        DB::statement('CREATE INDEX security_events_org_source_ip_idx ON security_events (organisation_id, source_ip) WHERE source_ip IS NOT NULL');
        DB::statement("CREATE INDEX security_events_pending_idx ON security_events (processing_status, received_at) WHERE processing_status <> 'processed'");
        DB::statement('CREATE INDEX security_events_metadata_gin ON security_events USING GIN (metadata jsonb_path_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
