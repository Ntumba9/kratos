<?php

namespace Tests;

use App\Domain\Mind\MindClient;
use App\Domain\Mind\ModelRegistry;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeMindClient;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Seed the RBAC catalogue and built-in detection rules after every refresh. */
    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;

    protected FakeMindClient $mind;

    protected function setUp(): void
    {
        parent::setUp();

        // KRATOS MIND is faked by default; MindClientTest and the
        // `integration` group exercise the real HTTP client and service.
        ModelRegistry::forget();
        $this->mind = new FakeMindClient;
        $this->app->instance(MindClient::class, $this->mind);
        Http::fake(['*/health' => Http::response(['status' => 'ok', 'service' => 'kratos-mind'])]);
    }

    /** The SPA origin; Sanctum treats requests from it as stateful. */
    protected const SPA_ORIGIN = 'http://localhost:8080';

    /**
     * In production every request boots a fresh application. In tests the
     * auth manager persists across requests, and Sanctum's request guard
     * caches the previously resolved user, so reset guards when switching
     * actors to keep each simulated request independent.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::actingAs($user, $guard);
    }

    /**
     * Send requests as the first-party SPA (session + CSRF semantics).
     */
    protected function fromSpa(): static
    {
        return $this->withHeaders(['Origin' => self::SPA_ORIGIN, 'Referer' => self::SPA_ORIGIN.'/']);
    }

    /**
     * @return array{0: User, 1: Organisation, 2: OrganisationMembership}
     */
    protected function memberWithRole(string $role, ?Organisation $organisation = null, array $userAttributes = []): array
    {
        $organisation ??= Organisation::factory()->create();
        $user = User::factory()->create($userAttributes);

        $membership = OrganisationMembership::factory()
            ->role($role)
            ->create(['organisation_id' => $organisation->id, 'user_id' => $user->id]);

        return [$user, $organisation, $membership];
    }

    protected function orgAdmin(?Organisation $organisation = null): array
    {
        return $this->memberWithRole(Role::ORG_ADMIN, $organisation);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function assertErrorEnvelope(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)
            ->assertJsonPath('error.code', $code)
            ->assertJsonStructure(['error' => ['code', 'message'], 'request_id']);
    }
}
