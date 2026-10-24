<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitored_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('asset_type', 32);
            $table->string('hostname', 253)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('environment', 16)->default('production');
            $table->string('criticality', 16)->default('medium');
            $table->jsonb('tags')->default('[]');
            $table->text('description')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organisation_id', 'criticality']);
        });

        DB::statement("ALTER TABLE monitored_assets ADD CONSTRAINT monitored_assets_type_check CHECK (asset_type IN ('server','application','database','network_device','endpoint','cloud_service'))");
        DB::statement("ALTER TABLE monitored_assets ADD CONSTRAINT monitored_assets_environment_check CHECK (environment IN ('production','staging','development'))");
        DB::statement("ALTER TABLE monitored_assets ADD CONSTRAINT monitored_assets_criticality_check CHECK (criticality IN ('low','medium','high','critical'))");

        // Names are unique per organisation among live (non-retired) assets.
        DB::statement('CREATE UNIQUE INDEX monitored_assets_org_name_unique ON monitored_assets (organisation_id, lower(name)) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX monitored_assets_org_hostname_idx ON monitored_assets (organisation_id, lower(hostname)) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('monitored_assets');
    }
};
