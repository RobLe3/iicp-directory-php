<?php

namespace Tests\Feature;

use App\Services\NodeRegistry;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NodeEndpointVerifierCharacterizationTest extends TestCase
{
    public function test_old_endpoint_is_alive_only_for_successful_health_response(): void
    {
        Http::fake([
            'https://node.test/iicp/health' => Http::response([], 204),
            'https://down.test/iicp/health' => Http::response([], 503),
        ]);

        $registry = app(NodeRegistry::class);

        $this->assertTrue($registry->isEndpointAlive('https://node.test'));
        $this->assertFalse($registry->isEndpointAlive('https://down.test'));
    }

    public function test_old_endpoint_connection_failure_is_treated_as_gone(): void
    {
        Http::fake([
            'https://gone.test/iicp/health' => Http::failedConnection(),
        ]);

        $this->assertFalse(app(NodeRegistry::class)->isEndpointAlive('https://gone.test'));
    }

    public function test_skip_liveness_treats_old_endpoint_as_gone_without_dialing(): void
    {
        config(['iicp.registry.skip_liveness_check' => true]);
        Http::fake();

        $this->assertFalse(app(NodeRegistry::class)->isEndpointAlive('https://node.test'));
        Http::assertNothingSent();
    }
}
