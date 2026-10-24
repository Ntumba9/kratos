<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transactional outbox (ADR-0005): written in the same transaction as the
     * event, so an acknowledged event can never be lost between PostgreSQL
     * and Redis. The relay publishes rows that are still unpublished.
     */
    public function up(): void
    {
        Schema::create('event_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('security_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('available_at');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('publish_attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at');
        });

        DB::statement('CREATE INDEX event_outbox_unpublished_idx ON event_outbox (available_at, id) WHERE published_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('event_outbox');
    }
};
