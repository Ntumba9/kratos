<?php

namespace Tests\Feature\Mind;

use App\Domain\Mind\EventAnalyser;
use App\Domain\Tenancy\TenantContext;
use App\Models\MonitoredAsset;
use App\Models\Organisation;
use App\Models\SecurityEvent;
use Tests\TestCase;

/**
 * CORE and MIND are separate codebases; these tests pin CORE's side of the
 * contract to the shared fixtures in contracts/mind/, which MIND's pytest
 * suite validates against its Pydantic schema.
 */
class MindContractTest extends TestCase
{
    private const CONTRACTS = __DIR__.'/../../../../contracts/mind';

    /**
     * @return array<string, mixed>
     */
    private function contract(string $file): array
    {
        $path = self::CONTRACTS.'/'.$file;
        $this->assertFileExists($path, 'contracts/ must be mounted into the container (docker-compose.yml)');

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<string>
     */
    private function shape(array $value, string $prefix = ''): array
    {
        $keys = [];
        foreach ($value as $key => $item) {
            if (in_array($key, ['metadata', 'parameters'], true)) { // free-form maps
                $keys[] = $prefix.$key;

                continue;
            }
            $keys[] = $prefix.$key;
            if (is_array($item) && ! array_is_list($item)) {
                array_push($keys, ...$this->shape($item, $prefix.$key.'.'));
            }
            if (is_array($item) && array_is_list($item) && isset($item[0]) && is_array($item[0])) {
                array_push($keys, ...$this->shape($item[0], $prefix.$key.'[].'));
            }
        }
        sort($keys);

        return array_values(array_unique($keys));
    }

    public function test_the_analysis_request_matches_the_shared_contract(): void
    {
        $organisation = Organisation::factory()->create();
        $asset = MonitoredAsset::factory()->create(['organisation_id' => $organisation->id, 'criticality' => 'critical']);
        $event = SecurityEvent::factory()->create([
            'organisation_id' => $organisation->id,
            'monitored_asset_id' => $asset->id,
            'metadata' => ['username' => 'finance.lead', 'failed_attempts' => 3],
        ]);

        $payload = app(TenantContext::class)->runAs($organisation, fn () => app(EventAnalyser::class)->payload($event));
        $payload = json_decode(json_encode($payload), true);

        $this->assertSame($this->shape($this->contract('analyse-request.example.json')), $this->shape($payload));
    }

    public function test_rule_defaults_match_kratos_mind(): void
    {
        $shared = $this->contract('rule-defaults.json');
        $configured = array_map(
            fn (array $rule) => array_map(fn (array $p) => $p['default'], $rule['parameters']),
            config('kratos.detection_rules'),
        );

        ksort($shared);
        ksort($configured);
        $this->assertEquals($shared, $configured, 'config/kratos.php detection rule defaults differ from mind/kratos_mind/rules.py');
    }
}
