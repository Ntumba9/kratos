<?php

namespace App\Domain\Mind;

use App\Models\DetectionResult;
use App\Models\MonitoredAsset;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The detection step of the pipeline: context → KRATOS MIND → evidence.
 *
 * Idempotent: an event's previous detection results are replaced in the same
 * transaction that writes the new ones, so redelivery or replay never
 * duplicates evidence (AC8).
 */
class EventAnalyser
{
    public function __construct(
        private readonly ContextBuilder $context,
        private readonly DetectionRuleCatalog $rules,
        private readonly MindClient $mind,
        private readonly ModelRegistry $models,
    ) {}

    /**
     * Must run inside the event's tenant context.
     *
     * @throws MindUnavailable
     * @throws MindRejected
     */
    public function analyse(SecurityEvent $event): void
    {
        $result = $this->mind->analyse($this->payload($event));
        $modelVersionId = $this->models->idFor($result['model']);

        DB::transaction(function () use ($event, $result, $modelVersionId): void {
            DetectionResult::query()->where('security_event_id', $event->getKey())->delete();

            $evidence = [];
            foreach ($result['rule_hits'] as $hit) {
                $evidence[] = [
                    'detector' => DetectionResult::DETECTOR_RULE,
                    'rule_key' => $hit['key'],
                    'severity' => $hit['severity'],
                    'category' => $hit['category'],
                    'score' => $hit['score'],
                    'explanation' => $hit['explanation'],
                    'details' => ['observed' => $hit['observed']],
                ];
            }

            $anomaly = $result['anomaly'];
            if ($anomaly['is_anomalous']) {
                $drivers = collect($anomaly['top_features'])
                    ->map(fn (array $f) => "{$f['description']} ({$f['value']} vs typical {$f['typical']})")
                    ->implode('; ');
                $evidence[] = [
                    'detector' => DetectionResult::DETECTOR_ANOMALY,
                    'rule_key' => null,
                    'severity' => null,
                    'category' => 'anomalous_activity',
                    'score' => $anomaly['percentile'],
                    'explanation' => "Anomaly percentile {$anomaly['percentile']} (threshold {$anomaly['threshold_percentile']}). Drivers: {$drivers}",
                    'details' => $anomaly,
                ];
            }

            foreach ($result['statistical']['flagged'] as $flag) {
                $evidence[] = [
                    'detector' => DetectionResult::DETECTOR_STATISTICAL,
                    'rule_key' => null,
                    'severity' => null,
                    'category' => 'anomalous_activity',
                    'score' => min(9999, $flag['contribution']),
                    'explanation' => "Statistical outlier: {$flag['description']} = {$flag['value']} (typical {$flag['typical']}, robust z {$flag['contribution']})",
                    'details' => $flag,
                ];
            }

            foreach ($evidence as $row) {
                DetectionResult::create($row + [
                    'organisation_id' => $event->organisation_id,
                    'security_event_id' => $event->getKey(),
                    'model_version_id' => $modelVersionId,
                ]);
            }

            $event->forceFill([
                'risk_score' => $result['risk_score'],
                'verdict' => $result['verdict'],
                'threat_category' => $result['category'] === 'none' ? null : $result['category'],
                'anomaly_percentile' => $anomaly['percentile'],
                'analysis_summary' => Str::limit($result['summary'], 2000),
                'model_version_id' => $modelVersionId,
                'analysed_at' => now(),
            ])->save();
        });
    }

    /**
     * The request body for POST /internal/v1/analyse (contract:
     * contracts/mind/analyse-request.example.json).
     *
     * @return array<string, mixed>
     */
    public function payload(SecurityEvent $event): array
    {
        $asset = $event->monitored_asset_id
            ? MonitoredAsset::withTrashed()->find($event->monitored_asset_id)
            : null;

        return [
            'event' => [
                'id' => $event->getKey(),
                'event_type' => $event->event_type,
                'source' => $event->source,
                'severity' => $event->severity->value,
                'occurred_at' => $event->occurred_at->utc()->toIso8601String(),
                'source_ip' => $event->source_ip,
                'metadata' => (object) ($event->metadata ?? []),
            ],
            'context' => $this->context->for($event),
            'asset' => $asset ? ['name' => $asset->name, 'criticality' => $asset->criticality] : null,
            'rules' => $this->rules->forAnalysis(),
        ];
    }
}
