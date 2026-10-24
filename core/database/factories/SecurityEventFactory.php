<?php

namespace Database\Factories;

use App\Domain\Eye\ProcessingStatus;
use App\Domain\Eye\Severity;
use App\Models\Organisation;
use App\Models\SecurityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Synthetic security events for tests. Bypasses the ingestion pipeline;
 * tests that exercise ingestion go through the HTTP API instead.
 *
 * @extends Factory<SecurityEvent>
 */
class SecurityEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $occurred = now()->subMinutes(fake()->numberBetween(1, 600));

        return [
            'organisation_id' => Organisation::factory(),
            'event_type' => 'authentication_failure',
            'source' => 'application_server',
            'severity' => fake()->randomElement(Severity::cases()),
            'occurred_at' => $occurred,
            'received_at' => $occurred,
            'source_ip' => '198.51.100.'.fake()->numberBetween(1, 254),
            'metadata' => ['failed_attempts' => fake()->numberBetween(1, 20)],
            'fingerprint' => hash('sha256', (string) Str::uuid()),
            'processing_status' => ProcessingStatus::Processed,
        ];
    }

    public function status(ProcessingStatus $status): static
    {
        return $this->state(fn () => ['processing_status' => $status]);
    }
}
