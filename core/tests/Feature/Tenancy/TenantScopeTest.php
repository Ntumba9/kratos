<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use App\Models\Organisation;
use Tests\TestCase;

/**
 * Tenant isolation layer 2: the query scope on tenant-owned models.
 */
class TenantScopeTest extends TestCase
{
    public function test_querying_tenant_data_without_a_context_fails_closed(): void
    {
        $this->expectException(MissingTenantContext::class);

        AuditLog::query()->count();
    }

    public function test_queries_are_constrained_to_the_current_tenant(): void
    {
        $a = Organisation::factory()->create();
        $b = Organisation::factory()->create();
        $logger = app(AuditLogger::class);
        $logger->record('scope.a', organisation: $a);
        $logger->record('scope.b', organisation: $b);

        $tenant = app(TenantContext::class);

        $this->assertSame(['scope.a'], $tenant->runAs($a, fn () => AuditLog::query()->pluck('action')->all()));
        $this->assertSame(['scope.b'], $tenant->runAs($b, fn () => AuditLog::query()->pluck('action')->all()));

        // Lookup by primary key cannot escape the scope either.
        $bId = AuditLog::withoutTenancy()->where('action', 'scope.b')->value('id');
        $this->assertNull($tenant->runAs($a, fn () => AuditLog::query()->find($bId)));
    }

    public function test_the_previous_context_is_restored_after_run_as(): void
    {
        $a = Organisation::factory()->create();
        $b = Organisation::factory()->create();
        $tenant = app(TenantContext::class);

        $tenant->set($a);
        $tenant->runAs($b, fn () => null);

        $this->assertSame($a->id, $tenant->organisationId());
    }

    public function test_without_tenancy_is_an_explicit_cross_tenant_escape_hatch(): void
    {
        $logger = app(AuditLogger::class);
        $logger->record('x', organisation: Organisation::factory()->create());
        $logger->record('y', organisation: Organisation::factory()->create());

        $this->assertSame(2, AuditLog::withoutTenancy()->whereIn('action', ['x', 'y'])->count());
    }

    public function test_audit_logs_are_append_only_at_the_model_layer(): void
    {
        $entry = app(AuditLogger::class)->record('immutable', organisation: Organisation::factory()->create());

        $this->expectException(\LogicException::class);
        $entry->action = 'tampered';
        $entry->save();
    }

    public function test_audit_metadata_secrets_are_redacted(): void
    {
        $entry = app(AuditLogger::class)->record('redaction', metadata: [
            'password' => 'hunter2',
            'nested' => ['token' => 'abc', 'safe' => 'kept'],
        ]);

        $this->assertSame('[redacted]', $entry->metadata['password']);
        $this->assertSame('[redacted]', $entry->metadata['nested']['token']);
        $this->assertSame('kept', $entry->metadata['nested']['safe']);
    }
}
