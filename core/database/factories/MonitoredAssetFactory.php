<?php

namespace Database\Factories;

use App\Models\MonitoredAsset;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic assets. IP addresses come from RFC 5737 documentation ranges.
 *
 * @extends Factory<MonitoredAsset>
 */
class MonitoredAssetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->bothify('srv-??##');

        return [
            'organisation_id' => Organisation::factory(),
            'name' => $name,
            'asset_type' => fake()->randomElement(MonitoredAsset::TYPES),
            'hostname' => $name.'.internal.example',
            'ip_address' => '192.0.2.'.fake()->numberBetween(1, 254),
            'environment' => 'production',
            'criticality' => fake()->randomElement(MonitoredAsset::CRITICALITIES),
            'tags' => [],
        ];
    }
}
