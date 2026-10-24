<?php

namespace Tests\Unit;

use App\Domain\Tenancy\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use PHPUnit\Framework\TestCase;

class TenantContextTest extends TestCase
{
    private function organisation(string $id): Organisation
    {
        return (new Organisation)->forceFill(['id' => $id]);
    }

    public function test_empty_context_fails_closed(): void
    {
        $context = new TenantContext;

        $this->assertFalse($context->has());
        $this->expectException(MissingTenantContext::class);
        $context->organisationId();
    }

    public function test_membership_must_belong_to_the_organisation(): void
    {
        $membership = (new OrganisationMembership)->forceFill(['organisation_id' => 'org-b']);

        $this->expectException(\InvalidArgumentException::class);
        (new TenantContext)->set($this->organisation('org-a'), $membership);
    }

    public function test_run_as_restores_the_previous_context_even_on_failure(): void
    {
        $context = new TenantContext;
        $context->set($this->organisation('org-a'));

        try {
            $context->runAs($this->organisation('org-b'), function () use ($context) {
                $this->assertSame('org-b', $context->organisationId());
                throw new \RuntimeException('job failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('org-a', $context->organisationId());
    }

    public function test_clear_removes_the_tenant(): void
    {
        $context = new TenantContext;
        $context->set($this->organisation('org-a'));
        $context->clear();

        $this->assertFalse($context->has());
    }
}
