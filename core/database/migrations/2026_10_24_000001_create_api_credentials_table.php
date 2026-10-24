<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('key_id', 40)->unique();
            // AES-256-GCM ciphertext (APP_KEY); never plaintext. See ADR-0006.
            $table->text('secret_encrypted');
            $table->jsonb('scopes')->default('["events:ingest"]');
            $table->timestampTz('last_used_at')->nullable();
            $table->ipAddress('last_used_ip')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organisation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_credentials');
    }
};
