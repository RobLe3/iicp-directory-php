<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Services\DispatchRouteTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class DispatchRouteTicketTest extends TestCase
{
    use RefreshDatabase;

    private function createNode(array $overrides = [], array $capabilities = []): Node
    {
        $node = Node::create(array_merge([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'load' => 0.2,
            'active_jobs' => 1,
            'last_seen' => now(),
            'public_reachable' => true,
        ], $overrides));

        foreach ($capabilities as $capability) {
            $node->capabilities()->create($capability);
        }

        return $node;
    }

    public function test_dispatch_ticket_returns_503_when_directory_key_missing(): void
    {
        config(['app.genesis_ed25519_secret_key' => null]);
        $this->createNode([], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['qwen2.5:0.5b'],
            'max_tokens' => 4096,
        ]]);

        $this->postJson('/api/v1/dispatch/ticket', [
            'intent' => 'urn:iicp:intent:llm:chat:v1',
        ])->assertStatus(503)
            ->assertJson(['error' => ['code' => 'not_configured']]);
    }

    public function test_dispatch_ticket_is_intent_and_node_scoped_and_returns_route_material(): void
    {
        Cache::flush();
        $keypair = sodium_crypto_sign_keypair();
        config(['app.genesis_ed25519_secret_key' => bin2hex(sodium_crypto_sign_secretkey($keypair))]);
        $node = $this->createNode([
            'endpoint' => 'https://associated-green-levy-lesser.trycloudflare.com',
            'transport_endpoint' => 'iicpsec://associated-green-levy-lesser.trycloudflare.com',
            'transport_method' => 'external_tunnel',
            'transport_metadata' => ['detection_log_tail' => ['rung 5: quick tunnel']],
            'cx_public_key' => ['algorithm' => 'X25519', 'key' => 'abc'],
            'policy_manifest' => [
                'version' => '1',
                'jurisdiction' => 'EU',
                'remote_executor_can_read_prompt' => true,
            ],
        ], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['qwen2.5:0.5b'],
            'max_tokens' => 4096,
        ]]);

        $resp = $this->postJson('/api/v1/dispatch/ticket', [
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'node_id_prefix' => substr($node->id, 0, 8),
        ])->assertStatus(201)
            ->assertHeader('X-IICP-Discover-Data-Class', 'ticketed_route_dispatch')
            ->assertJsonPath('data_class', 'ticketed_route_dispatch')
            ->assertJsonPath('route_fields_present', true)
            ->assertJsonPath('prompt_payload_accepted', false)
            ->assertJsonPath('node_id', $node->id)
            ->assertJsonPath('route.endpoint', 'https://associated-green-levy-lesser.trycloudflare.com')
            ->assertJsonPath('route.transport_endpoint', 'iicpsec://associated-green-levy-lesser.trycloudflare.com')
            ->assertJsonPath('route.cx_public_key.algorithm', 'X25519');
        $resp->assertJsonPath('route.node_policy_manifest.manifest_identity_level', 'self_attested');
        $this->assertArrayNotHasKey('public_key', $resp->json('route'));

        $this->assertStringContainsString('no-store', $resp->headers->get('Cache-Control'));

        $payload = app(DispatchRouteTicketService::class)
            ->verify($resp->json('ticket'), $node->id, 'urn:iicp:intent:llm:chat:v1');

        $this->assertIsArray($payload);
        $this->assertSame('dispatch-route-ticket', $payload['typ']);
        $this->assertSame($node->id, $payload['node_id']);
        $this->assertSame('urn:iicp:intent:llm:chat:v1', $payload['intent']);
        $this->assertSame('iicp.directory.dispatch', $payload['aud']);
        $this->assertGreaterThan(time(), $payload['exp']);
        $this->assertArrayNotHasKey('prompt', $payload);
        $this->assertArrayNotHasKey('messages', $payload);
        $this->assertArrayNotHasKey('endpoint', $payload);
        $this->assertArrayNotHasKey('node_token', $payload);
        $this->assertNull(app(DispatchRouteTicketService::class)->verify($resp->json('ticket'), $node->id, 'urn:iicp:intent:code:review:v1'));
        $this->assertNull(app(DispatchRouteTicketService::class)->verify($resp->json('ticket'), (string) Str::uuid(), 'urn:iicp:intent:llm:chat:v1'));
    }

    public function test_dispatch_ticket_verify_returns_null_for_expired_ticket(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        config(['app.genesis_ed25519_secret_key' => bin2hex(sodium_crypto_sign_secretkey($keypair))]);
        $sk = sodium_crypto_sign_secretkey($keypair);
        $payloadJson = json_encode([
            'v' => 1,
            'typ' => 'dispatch-route-ticket',
            'iss' => config('app.url'),
            'aud' => 'iicp.directory.dispatch',
            'jti' => 'expired-test',
            'node_id' => 'node-a',
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'iat' => 1,
            'exp' => 1,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $b64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
        $sig = bin2hex(sodium_crypto_sign_detached('iicp:dispatch-route-ticket:v1
'.$b64, $sk));

        $this->assertNull(app(DispatchRouteTicketService::class)->verify(
            "{$b64}.{$sig}",
            'node-a',
            'urn:iicp:intent:llm:chat:v1',
        ));
    }

    public function test_dispatch_ticket_refuses_high_risk_intent(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        config(['app.genesis_ed25519_secret_key' => bin2hex(sodium_crypto_sign_secretkey($keypair))]);
        $this->createNode([], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['qwen2.5:0.5b'],
            'max_tokens' => 4096,
        ]]);

        $this->postJson('/api/v1/dispatch/ticket', [
            'intent' => 'urn:iicp:intent:credit:decision:v1',
        ])->assertStatus(422);
    }

    public function test_dispatch_ticket_rejects_prompt_payload_fields(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        config(['app.genesis_ed25519_secret_key' => bin2hex(sodium_crypto_sign_secretkey($keypair))]);
        $this->createNode([], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['qwen2.5:0.5b'],
            'max_tokens' => 4096,
        ]]);

        $resp = $this->postJson('/api/v1/dispatch/ticket', [
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'prompt' => 'GDPR_CANARY_PROMPT_DO_NOT_LOG_20260701',
        ]);

        $resp->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.fields.prompt.0', 'Dispatch ticket issuance is control-plane only; send task payloads directly to the selected node.');
        $this->assertStringNotContainsString('GDPR_CANARY_PROMPT_DO_NOT_LOG_20260701', $resp->getContent());
    }

    public function test_dispatch_ticket_reports_ambiguous_or_missing_node_selector(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        config(['app.genesis_ed25519_secret_key' => bin2hex(sodium_crypto_sign_secretkey($keypair))]);
        $prefix = 'abcd';
        $this->createNode(['id' => $prefix.'0000-0000-4000-8000-000000000001'], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['qwen2.5:0.5b'],
            'max_tokens' => 4096,
        ]]);
        $this->createNode(['id' => $prefix.'0000-0000-4000-8000-000000000002'], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['qwen2.5:0.5b'],
            'max_tokens' => 4096,
        ]]);

        $this->postJson('/api/v1/dispatch/ticket', [
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'node_id_prefix' => $prefix,
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'ambiguous_node_prefix');

        $this->postJson('/api/v1/dispatch/ticket', [
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'node_id_prefix' => 'ffff',
        ])->assertStatus(404)
            ->assertJsonPath('error.code', 'no_route_available');
    }

    public function test_dispatch_ticket_excludes_failed_node_prefixes_and_counts_anonymously(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        config(['app.genesis_ed25519_secret_key' => bin2hex(sodium_crypto_sign_secretkey($keypair))]);
        $first = $this->createNode(['id' => '11111111-0000-4000-8000-000000000001'], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['qwen2.5:0.5b'],
            'max_tokens' => 4096,
        ]]);
        $second = $this->createNode(['id' => '22222222-0000-4000-8000-000000000002'], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['qwen2.5:0.5b'],
            'max_tokens' => 4096,
        ]]);

        $firstResponse = $this->postJson('/api/v1/dispatch/ticket', [
            'intent' => 'urn:iicp:intent:llm:chat:v1',
        ])->assertCreated();
        $failedPrefix = $firstResponse->json('node_id_prefix');

        $retry = $this->postJson('/api/v1/dispatch/ticket', [
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'exclude_node_id_prefixes' => [$failedPrefix],
        ])->assertCreated();

        $this->assertNotSame($firstResponse->json('node_id'), $retry->json('node_id'));
        $this->assertContains($retry->json('node_id'), [$first->id, $second->id]);
        $this->assertDatabaseHas('dispatch_usage_daily', [
            'mode' => 'ticketed_dispatch',
            'request_count' => 2,
        ]);
    }

    public function test_dispatch_ticket_rejects_invalid_exclusion_prefixes(): void
    {
        $this->postJson('/api/v1/dispatch/ticket', [
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'exclude_node_id_prefixes' => ['abc'],
        ])->assertStatus(422);
    }
}
