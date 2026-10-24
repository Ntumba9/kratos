<?php

namespace Database\Seeders;

use App\Domain\Eye\EventData;
use App\Domain\Eye\ProcessingStatus;
use App\Domain\Eye\Severity;
use App\Domain\Tenancy\OrganisationManager;
use App\Domain\Tenancy\TenantContext;
use App\Models\MonitoredAsset;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * DEMONSTRATION DATA — synthetic accounts, assets and 24 hours of security
 * events for local development and demos.
 *
 * - Refuses to run in production.
 * - Demo organisations carry settings.demo = true; KRATOS COMMAND shows a
 *   "Demonstration data" banner for them.
 * - Every event carries metadata.synthetic = true, uses RFC 5737
 *   documentation IP ranges, and is inserted already processed (it did not
 *   travel through the ingestion pipeline — use tools/kratos-send for that).
 * - Passwords are generated per run and printed once; never hardcoded.
 */
class DemoSeeder extends Seeder
{
    public function run(OrganisationManager $organisations, TenantContext $tenant): void
    {
        if (App::isProduction()) {
            $this->command?->error('DemoSeeder refuses to run in production.');

            return;
        }

        $this->call(RbacSeeder::class);

        $password = Str::password(20);
        $make = fn (string $name, string $email) => User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password, 'email_verified_at' => now()],
        );

        $admin = $make('Demo Administrator', 'admin@demo.kratos.test');
        $analyst = $make('Demo Analyst', 'analyst@demo.kratos.test');
        $viewer = $make('Demo Viewer', 'viewer@demo.kratos.test');
        $outsider = $make('Other Tenant Admin', 'admin@other.kratos.test');

        $admin->forceFill(['platform_role_id' => Role::findBySlug(Role::PLATFORM_ADMIN)->getKey()])->save();

        $demo = $admin->organisations()->where('slug', 'like', 'demo-organisation%')->first();
        if ($demo === null) {
            $demo = $organisations->create($admin, 'Demo Organisation');
            $organisations->addMember($demo, $analyst, Role::findBySlug(Role::ANALYST), $admin);
            $organisations->addMember($demo, $viewer, Role::findBySlug(Role::VIEWER), $admin);
            $other = $organisations->create($outsider, 'Other Demo Tenant');
            $other->forceFill(['settings' => ['demo' => true]])->save();
        }
        $demo->forceFill(['settings' => array_merge($demo->settings ?? [], ['demo' => true])])->save();

        $count = $tenant->runAs($demo, fn () => $this->seedTelemetry($demo));

        $this->command?->warn('Demonstration accounts (synthetic data, local use only):');
        $this->command?->table(['Email', 'Role'], [
            ['admin@demo.kratos.test', 'Platform Administrator + Organisation Administrator'],
            ['analyst@demo.kratos.test', 'Security Analyst'],
            ['viewer@demo.kratos.test', 'Read-Only Viewer'],
            ['admin@other.kratos.test', 'Organisation Administrator (separate tenant)'],
        ]);
        $this->command?->warn("Password for all demo accounts (this run only): {$password}");
        $this->command?->info("Seeded {$count} synthetic security events for the last 24 hours.");
    }

    private function seedTelemetry(Organisation $organisation): int
    {
        $assets = [
            ['name' => 'identity-provider', 'asset_type' => 'application', 'hostname' => 'idp.demo.example', 'ip_address' => '192.0.2.5', 'criticality' => 'critical', 'tags' => ['auth', 'tier:0']],
            ['name' => 'billing-api', 'asset_type' => 'application', 'hostname' => 'billing.demo.example', 'ip_address' => '192.0.2.20', 'criticality' => 'high', 'tags' => ['pci']],
            ['name' => 'edge-firewall', 'asset_type' => 'network_device', 'hostname' => 'fw01.demo.example', 'ip_address' => '192.0.2.1', 'criticality' => 'high', 'tags' => ['perimeter']],
            ['name' => 'db-primary', 'asset_type' => 'database', 'hostname' => 'db01.demo.example', 'ip_address' => '192.0.2.30', 'criticality' => 'critical', 'tags' => ['pii']],
            ['name' => 'build-runner', 'asset_type' => 'server', 'hostname' => 'ci01.demo.example', 'ip_address' => '192.0.2.40', 'criticality' => 'medium', 'tags' => []],
        ];
        $ids = [];
        foreach ($assets as $attributes) {
            $ids[$attributes['name']] = MonitoredAsset::query()->firstOrCreate(['name' => $attributes['name']], $attributes)->getKey();
        }

        if (SecurityEvent::query()->exists()) {
            return 0; // idempotent: telemetry is only seeded once
        }

        mt_srand(20261023); // reproducible demo data
        $now = CarbonImmutable::now()->utc();
        $doc = fn () => ['192.0.2.', '198.51.100.', '203.0.113.'][mt_rand(0, 2)].mt_rand(1, 254);
        $count = 0;

        $insert = function (string $type, string $source, Severity $severity, CarbonImmutable $at, string $asset, array $metadata) use ($ids, &$count) {
            $metadata['synthetic'] = true;
            $data = new EventData($type, $source, $severity, $at, $metadata, $asset, null);
            SecurityEvent::query()->create([
                'monitored_asset_id' => $ids[$asset],
                'event_type' => $type,
                'source' => $source,
                'severity' => $severity,
                'occurred_at' => $at,
                'received_at' => $at->addSeconds(mt_rand(0, 3)),
                'source_ip' => $data->sourceIp(),
                'metadata' => $metadata,
                'fingerprint' => $data->fingerprint(),
                'processing_status' => ProcessingStatus::Processed,
            ])->forceFill(['processed_at' => $at->addSeconds(mt_rand(1, 5)), 'processing_attempts' => 1])->save();
            $count++;
        };

        // Background activity across the day.
        for ($minutes = 24 * 60; $minutes > 0; $minutes -= mt_rand(4, 14)) {
            $at = $now->subMinutes($minutes)->subSeconds(mt_rand(0, 59));
            match (mt_rand(1, 10)) {
                1, 2, 3, 4, 5 => $insert('authentication_success', 'identity_provider', Severity::Info, $at, 'identity-provider', ['source_ip' => $doc(), 'username' => 'user'.mt_rand(1, 40)]),
                6, 7 => $insert('authentication_failure', 'identity_provider', Severity::Low, $at, 'identity-provider', ['source_ip' => $doc(), 'username' => 'user'.mt_rand(1, 40), 'failed_attempts' => 1]),
                8 => $insert('firewall_block', 'edge_firewall', Severity::Low, $at, 'edge-firewall', ['source_ip' => $doc(), 'destination_port' => [22, 23, 445, 3389][mt_rand(0, 3)], 'protocol' => 'tcp']),
                9 => $insert('permission_denied', 'billing_service', Severity::Medium, $at, 'billing-api', ['source_ip' => $doc(), 'username' => 'user'.mt_rand(1, 40), 'resource' => '/admin/invoices', 'action' => 'export']),
                default => $insert('api_request_anomaly', 'billing_service', Severity::Medium, $at, 'billing-api', ['source_ip' => $doc(), 'endpoint' => '/v1/invoices', 'request_count' => mt_rand(400, 900), 'window_seconds' => 60]),
            };
        }

        // A credential-stuffing burst six hours ago, ending in a lockout.
        $attacker = '203.0.113.66';
        $burstStart = $now->subHours(6);
        for ($i = 1; $i <= 30; $i++) {
            $insert('authentication_failure', 'identity_provider', $i < 15 ? Severity::Medium : Severity::High, $burstStart->addSeconds($i * 7), 'identity-provider',
                ['source_ip' => $attacker, 'username' => 'finance.lead', 'failed_attempts' => $i, 'auth_method' => 'password']);
        }
        $insert('account_lockout', 'identity_provider', Severity::High, $burstStart->addSeconds(220), 'identity-provider', ['source_ip' => $attacker, 'username' => 'finance.lead']);

        // A suspicious process on the build runner two hours ago.
        $insert('suspicious_process', 'endpoint_agent', Severity::Critical, $now->subHours(2), 'build-runner',
            ['process_name' => 'xmrig', 'pid' => 4242, 'hostname' => 'ci01.demo.example', 'username' => 'ci']);
        $insert('data_exfiltration_suspected', 'network_monitor', Severity::Critical, $now->subMinutes(95), 'db-primary',
            ['source_ip' => '192.0.2.30', 'destination_ip' => '198.51.100.200', 'bytes_transferred' => 1_843_200_000]);

        foreach ($ids as $assetId) {
            MonitoredAsset::query()->whereKey($assetId)->update([
                'last_seen_at' => SecurityEvent::query()->where('monitored_asset_id', $assetId)->max('occurred_at'),
            ]);
        }

        return $count;
    }
}
