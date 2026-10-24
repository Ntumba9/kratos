<?php

namespace Database\Seeders;

use App\Models\DetectionRule;
use Illuminate\Database\Seeder;

/**
 * Synchronises the built-in detection rules (organisation_id NULL) from
 * config('kratos.detection_rules'). Idempotent; organisation overrides are
 * never touched.
 */
class DetectionRuleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('kratos.detection_rules') as $key => $rule) {
            DetectionRule::query()->updateOrCreate(
                ['organisation_id' => null, 'key' => $key],
                [
                    'name' => $rule['name'],
                    'description' => $rule['description'],
                    'category' => $rule['category'],
                    'severity' => $rule['severity'],
                    'enabled' => true,
                    'parameters' => array_map(fn (array $p) => $p['default'], $rule['parameters']),
                ],
            );
        }

        DetectionRule::query()->builtIn()->whereNotIn('key', array_keys(config('kratos.detection_rules')))->delete();
    }
}
