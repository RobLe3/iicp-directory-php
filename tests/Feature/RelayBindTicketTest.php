<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Services\JwtService;
use App\Services\RelayBindTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RelayBindTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://worker.example.com/iicp/health' => Http::response('ok', 200)]);
    }

    private function registerWorker(): array
    {
        $resp = $this->postJson('/api/v1/register', [
            'endpoint' => 'https://worker.example.com',
            'region' => 'eu-central',
            'capabilities' => [[
                'intent' => 'urn:iicp:intent:llm:chat:v1',
                'models' => ['llama3'],
                'max_tokens' => 4096,
            ]],
            'limits' => ['max_concurrent' => 4, 'tokens_per_min' => 10000],
        ]);
        $resp->assertStatus(201);

        return [$resp->json('node_id'), app(JwtService::class)->issue($resp->json('node_id'))];
    }

    public function test_relay_ticket_requires_node_auth(): void
    {
        $this->postJson('/api/v1/relay/ticket')
            ->assertStatus(401);
    }

    public function test_relay_ticket_returns_503_when_directory_key_missing(): void
    {
        config(['app.genesis_ed25519_secret_key' => null]);
        [, $token] = $this->registerWorker();

        $this->withToken($token)
            ->postJson('/api/v1/relay/ticket')
            ->assertStatus(503)
            ->assertJson(['error' => ['code' => 'not_configured']]);
    }

    public function test_relay_ticket_is_worker_scoped_and_directory_signed(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        config(['app.genesis_ed25519_secret_key' => bin2hex(sodium_crypto_sign_secretkey($keypair))]);
        [$workerId, $token] = $this->registerWorker();

        $resp = $this->withToken($token)
            ->postJson('/api/v1/relay/ticket', ['relay_node_id' => 'relay-1']);

        $resp->assertStatus(201)
            ->assertJsonStructure(['ticket', 'expires_at', 'worker_node_id', 'relay_node_id', 'algorithm'])
            ->assertJson([
                'worker_node_id' => $workerId,
                'relay_node_id' => 'relay-1',
                'algorithm' => 'ed25519',
            ]);

        $payload = app(RelayBindTicketService::class)
            ->verify($resp->json('ticket'), $workerId, 'relay-1');

        $this->assertIsArray($payload);
        $this->assertSame('relay-bind-ticket', $payload['typ']);
        $this->assertSame($workerId, $payload['sub']);
        $this->assertSame('relay-1', $payload['aud']);
        $this->assertGreaterThan(time(), $payload['exp']);
        $this->assertNull(app(RelayBindTicketService::class)->verify($resp->json('ticket'), 'attacker', 'relay-1'));
        $this->assertNull(app(RelayBindTicketService::class)->verify($resp->json('ticket'), $workerId, 'other-relay'));
    }
}
