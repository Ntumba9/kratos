<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Built-in rules have organisation_id NULL (synced from config by
        // DetectionRuleSeeder); an organisation's row with the same key is its
        // override (enabled flag and tuned parameters).
        Schema::create('detection_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name', 120);
            $table->text('description');
            $table->string('category', 32);
            $table->string('severity', 16);
            $table->boolean('enabled')->default(true);
            $table->jsonb('parameters')->default('{}');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX detection_rules_org_key_unique ON detection_rules (organisation_id, key) NULLS NOT DISTINCT');
        DB::statement("ALTER TABLE detection_rules ADD CONSTRAINT detection_rules_severity_check CHECK (severity IN ('info','low','medium','high','critical'))");

        Schema::create('model_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 64);
            $table->string('version', 64);
            $table->string('algorithm', 64);
            $table->char('artifact_sha256', 64);
            $table->jsonb('feature_schema');
            $table->jsonb('hyperparameters');
            $table->jsonb('training_dataset');
            $table->jsonb('metrics');
            $table->jsonb('limitations');
            $table->text('model_card')->nullable();
            $table->timestampTz('trained_at');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();
            $table->unique(['name', 'version']);
        });

        Schema::create('detection_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('security_event_id')->constrained()->cascadeOnDelete();
            $table->string('detector', 24);
            $table->string('rule_key', 64)->nullable();
            $table->foreignUuid('model_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('severity', 16)->nullable();
            $table->string('category', 32)->nullable();
            $table->decimal('score', 6, 2);
            $table->text('explanation');
            $table->jsonb('details')->default('{}');
            $table->timestampTz('created_at');

            $table->index('security_event_id');
        });
        DB::statement("ALTER TABLE detection_results ADD CONSTRAINT detection_results_detector_check CHECK (detector IN ('rule','anomaly_model','statistical'))");
        DB::statement('CREATE INDEX detection_results_org_created_idx ON detection_results (organisation_id, created_at DESC)');
        DB::statement('CREATE INDEX detection_results_org_rule_created_idx ON detection_results (organisation_id, rule_key, created_at DESC) WHERE rule_key IS NOT NULL');

        Schema::table('security_events', function (Blueprint $table) {
            $table->string('verdict', 16)->nullable();
            $table->string('threat_category', 32)->nullable();
            $table->decimal('anomaly_percentile', 5, 2)->nullable();
            $table->text('analysis_summary')->nullable();
            $table->foreignUuid('model_version_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('analysed_at')->nullable();
        });
        DB::statement("ALTER TABLE security_events ADD CONSTRAINT security_events_verdict_check CHECK (verdict IS NULL OR verdict IN ('benign','suspicious','malicious'))");
        DB::statement('CREATE INDEX security_events_org_verdict_received_idx ON security_events (organisation_id, verdict, received_at DESC) WHERE verdict IS NOT NULL');
        DB::statement('CREATE INDEX security_events_org_risk_idx ON security_events (organisation_id, risk_score DESC) WHERE risk_score IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('model_version_id');
            $table->dropColumn(['verdict', 'threat_category', 'anomaly_percentile', 'analysis_summary', 'analysed_at']);
        });
        Schema::dropIfExists('detection_results');
        Schema::dropIfExists('model_versions');
        Schema::dropIfExists('detection_rules');
    }
};
