<?php

namespace Tests\Feature\Mind;

use App\Domain\Eye\RequestSigner;
use App\Domain\Mind\MindClient;
use App\Domain\Mind\MindRejected;
use App\Domain\Mind\MindUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The real HTTP client (transport faked): signing and error classification.
 */
class MindClientTest extends TestCase
{
    private function client(): MindClient
    {
        config(['kratos.mind.url' => 'http://mind.test', 'kratos.mind.shared_secret' => str_repeat('s', 40)]);

        return new MindClient(app(Factory::class));
    }

    public function test_requests_are_signed_with_the_service_secret(): void
    {
        Http::fake(['mind.test/internal/v1/analyse' => Http::response(['risk_score' => 10])]);

        $this->client()->analyse(['event' => ['id' => 'x']]);

        Http::assertSent(function (Request $request) {
            $body = $request->body();

            return $request->url() === 'http://mind.test/internal/v1/analyse'
                && $request->header('X-Kratos-Service')[0] === 'core'
                && RequestSigner::verify(str_repeat('s', 40), 'POST', '/internal/v1/analyse', $request->header('X-Kratos-Timestamp')[0], $body, $request->header('X-Kratos-Signature')[0])
                && json_decode($body, true) === ['event' => ['id' => 'x']];
        });
    }

    public function test_connection_failures_and_5xx_are_transient(): void
    {
        Http::fake(['mind.test/*' => fn () => throw new ConnectionException('Connection refused')]);
        $this->expectException(MindUnavailable::class);
        $this->client()->analyse([]);
    }

    public function test_server_errors_are_transient(): void
    {
        Http::fake(['mind.test/*' => Http::response(['error' => ['code' => 'boom']], 503)]);
        $this->expectException(MindUnavailable::class);
        $this->client()->analyse([]);
    }

    public function test_contract_violations_are_permanent(): void
    {
        Http::fake(['mind.test/*' => Http::response(['error' => ['code' => 'contract_violation']], 422)]);
        $this->expectException(MindRejected::class);
        $this->client()->analyse([]);
    }

    public function test_missing_secret_is_treated_as_unavailable_not_sent_unsigned(): void
    {
        Http::fake();
        config(['kratos.mind.shared_secret' => '']);

        try {
            (new MindClient(app(Factory::class)))->analyse([]);
            $this->fail('expected MindUnavailable');
        } catch (MindUnavailable) {
        }

        Http::assertNothingSent();
    }
}
