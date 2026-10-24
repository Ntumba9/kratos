<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    public function test_demo_data_is_labelled_synthetic_and_idempotent(): void
    {
        $this->seed(DemoSeeder::class);
        $firstCount = SecurityEvent::withoutTenancy()->count();
        $this->seed(DemoSeeder::class);

        $this->assertGreaterThan(100, $firstCount);
        $this->assertSame($firstCount, SecurityEvent::withoutTenancy()->count(), 'second run adds nothing');

        $demo = Organisation::query()->where('slug', 'like', 'demo-organisation%')->sole();
        $this->assertTrue($demo->settings['demo']);

        $events = SecurityEvent::withoutTenancy()->get();
        $this->assertTrue($events->every(fn ($e) => ($e->metadata['synthetic'] ?? false) === true));
        $this->assertTrue($events->every(fn ($e) => $e->organisation_id === $demo->id), 'telemetry only in the demo tenant');

        foreach ($events->pluck('source_ip')->filter() as $ip) {
            $this->assertMatchesRegularExpression('/^(192\.0\.2|198\.51\.100|203\.0\.113)\./', $ip, 'RFC 5737 documentation ranges only');
        }
    }

    public function test_demo_flag_is_exposed_to_the_ui(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = User::query()->where('email', 'admin@demo.kratos.test')->sole();

        $this->actingAs($admin, 'web')->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.memberships.0.organisation.is_demo', true);
    }

    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->seed(DemoSeeder::class);

        $this->assertSame(0, User::query()->where('email', 'like', '%@demo.kratos.test')->count());
    }
}
