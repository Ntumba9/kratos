<?php

namespace Tests\Fakes;

use App\Domain\Mind\MindClient;
use App\Domain\Mind\MindUnavailable;
use Closure;
use Illuminate\Http\Client\Factory;

/**
 * Deterministic stand-in for KRATOS MIND in feature tests. Records every
 * payload so tests can assert on what CORE sends; can be switched to
 * "unavailable" to exercise outage handling. Real-service behaviour is
 * covered by MindClientTest (HTTP-level) and the `integration` group.
 */
class FakeMindClient extends MindClient
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public bool $unavailable = false;

    /** @var (Closure(array<string, mixed>): array<string, mixed>)|null */
    public ?Closure $responder = null;

    public const MODEL = [
        'name' => 'isolation_forest',
        'version' => 'fs1-test',
        'algorithm' => 'IsolationForest',
        'artifact_sha256' => '0000000000000000000000000000000000000000000000000000000000000001',
        'feature_schema_version' => '1',
    ];

    public function __construct()
    {
        parent::__construct(new Factory);
    }

    public function analyse(array $payload): array
    {
        if ($this->unavailable) {
            throw new MindUnavailable('KRATOS MIND is unreachable: connection refused (fake)');
        }

        // Round-trip through JSON exactly like the real client.
        $payload = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true);
        $this->payloads[] = $payload;

        return $this->responder ? ($this->responder)($payload) : self::response($payload);
    }

    public function activeModel(): array
    {
        if ($this->unavailable) {
            throw new MindUnavailable('KRATOS MIND is unreachable (fake)');
        }

        return [
            'metadata' => self::MODEL + [
                'feature_schema' => ['version' => '1', 'features' => ['log_src_ip_events_5m']],
                'hyperparameters' => ['n_estimators' => 200],
                'training_dataset' => ['synthetic' => true, 'seed' => 42],
                'metrics' => ['anomaly_model' => ['roc_auc' => 0.9], 'risk_score' => ['roc_auc' => 0.95]],
                'limitations' => ['Synthetic data only.'],
                'trained_at' => '2026-10-24T00:00:00+00:00',
            ],
            'model_card' => "# Model card\n\n> **Synthetic evaluation.**",
        ];
    }

    /**
     * A plausible response: brute-force rule hit when the context says so,
     * benign otherwise.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function response(array $payload, ?float $risk = null): array
    {
        $failures = $payload['context']['src_ip_auth_failures_15m'] ?? 0;
        $hits = $failures >= 10 ? [[
            'key' => 'brute_force_source',
            'severity' => 'high',
            'score' => 80.0,
            'category' => 'credential_attack',
            'explanation' => "{$failures} failed logins from {$payload['event']['source_ip']} in 15 minutes (threshold 10)",
            'observed' => ['failures_15m' => $failures, 'threshold' => 10],
        ]] : [];
        $risk ??= $hits ? 86.0 : 12.0;

        return [
            'event_id' => $payload['event']['id'],
            'risk_score' => $risk,
            'verdict' => $risk >= 70 ? 'malicious' : ($risk >= 40 ? 'suspicious' : 'benign'),
            'category' => $hits ? 'credential_attack' : 'none',
            'rule_hits' => $hits,
            'anomaly' => [
                'score' => 0.6, 'percentile' => $hits ? 99.8 : 40.0, 'is_anomalous' => (bool) $hits, 'threshold_percentile' => 99.0,
                'top_features' => $hits ? [[
                    'feature' => 'log_src_ip_auth_failures_15m', 'description' => 'failed logins from this source IP in 15 minutes',
                    'value' => 2.7, 'typical' => 0.0, 'contribution' => 0.12,
                ]] : [],
            ],
            'statistical' => ['flagged' => [], 'max_robust_z' => 1.0],
            'risk_components' => ['severity' => 30.0, 'rules' => $hits ? 80.0 : 0.0, 'anomaly' => 0.0, 'statistical' => 0.0],
            'summary' => "Risk {$risk} (fake).",
            'model' => self::MODEL,
        ];
    }
}
