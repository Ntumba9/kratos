<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 64)->unique();
            $table->jsonb('settings')->default('{}');
            $table->unsignedSmallInteger('event_retention_days')->default(90);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("ALTER TABLE organisations ADD CONSTRAINT organisations_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement('ALTER TABLE organisations ADD CONSTRAINT organisations_retention_range CHECK (event_retention_days BETWEEN 1 AND 3650)');

        Schema::create('organisation_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('role_id');
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $table->unique(['organisation_id', 'user_id']);
            $table->index('user_id');
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_memberships');
        Schema::dropIfExists('organisations');
    }
};
