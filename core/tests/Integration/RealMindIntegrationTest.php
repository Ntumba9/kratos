<?php

namespace Tests\Integration;

use App\Domain\Eye\ProcessingStatus;
use App\Domain\Mind\MindClient;
use App\Domain\Mind\MindRejected;
use App\Models\DetectionResult;
use App\Models\ModelVersion;
use App\Models\SecurityEvent;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\SendsSignedEvents;
use Tests\TestCase;
use Throwable;

/**
 * CORE ⇄ KRATOS MIND against the real FastAPI service (docker compose).
 * Skipped when MIND is not reachable (e.g. the API-only CI job).
 */
#[Group('integration')]
class RealMindIntegrationTest extends TestCase
{
    use SendsSignedEvents;

    private MindClient $real;

    protected function setUp(): void
    {
        parent::setUp();

        $url = getenv('MIND_URL') ?: '';
        $secret = getenv('MIND_SHARED_SECRET') ?: '';
        try {
            $healthy = $url !== '' && $secret !== '' && (new Factory)->timeout(2)->get("{$url}/health")->ok();
        } catch (Throwable) {
            $healthy = false;
        }
        if (! $healthy) {
            $this->markTestSkipped('KRATOS MIND is not reachable (run inside docker compose).');
        }

        config(['kratos.mind.url' => $url, 'kratos.mind.shared_secret' => $secret]);
        // A fresh HTTP factory: not affected by the suite's default fakes.
        $this->real = new MindClient(new Factory);
        $this->app->instance(MindClient::class, $this->real);
    }

    public function test_a_brute_force_burst_is_scored_and_explained_by_the_real_service(): void
    {
        ['credential' => $c, 'secret' => $s] = $this->ingestionClient();
        $ids = [];
        foreach (range(1, 14) as $i) {
            $ids[] = $this->sendSigned($c, $s, $this->eventPayload([
                'severity' => 'medium',
                'timestamp' => now()->subSeconds((14 - $i) * 15)->utc()->format('Y-m-d\TH:i:s\Z'),
                'metadata' => ['source_ip' => '203.0.113.66', 'username' => 'finance.lead', 'failed_attempts' => $i],
            ]))->assertStatus(202)->json('data.id');
        }

        $first = SecurityEvent::withoutTenancy()->findOrFail($ids[0]);
        $last = SecurityEvent::withoutTenancy()->findOrFail(end($ids));

        $this->assertSame(ProcessingStatus::Processed, $last->processing_status);
        $this->assertSame('malicious', $last->verdict);
        $this->assertSame('credential_attack', $last->threat_category);
        $this->assertGreaterThanOrEqual(70, (float) $last->risk_score);
        $this->assertLessThan((float) $last->risk_score, (float) $first->risk_score);
        $this->assertStringContainsString('14 failed logins from 203.0.113.66 in 15 minutes', $last->analysis_summary);

        $rules = DetectionResult::withoutTenancy()->where('security_event_id', $last->id)->where('detector', 'rule')->pluck('rule_key')->all();
        $this->assertContains('brute_force_source', $rules);
        $this->assertContains('targeted_account_attack', $rules);

        $model = ModelVersion::query()->sole();
        $this->assertTrue($model->training_dataset['synthetic']);
        $this->assertNotEmpty($model->metrics['risk_score']);
        $this->assertSame(64, strlen($model->artifact_sha256));
    }

    public function test_mind_rejects_a_request_that_breaks_the_contract(): void
    {
        $this->expectException(MindRejected::class);

        $this->real->analyse(['event' => ['id' => 'x'], 'context' => [], 'unexpected' => true]);
    }

    public function test_mind_rejects_requests_signed_with_the_wrong_secret(): void
    {
        config(['kratos.mind.shared_secret' => str_repeat('w', 40)]);

        $this->expectException(MindRejected::class); // 401 from MIND

        (new MindClient(new Factory))->activeModel();
    }
}
