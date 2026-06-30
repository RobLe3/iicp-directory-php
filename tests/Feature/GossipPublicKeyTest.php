<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Behavior tests for gossip_public_key registration and exposure (#495).
 *
 * Each test fails if the fix is reverted:
 * - REGISTER with public_key: gossip_public_key not stored → assertSame fails
 * - Node detail exposes public_key: field missing from response → assertArrayHasKey fails
 */
class GossipPublicKeyTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://gossip-node.example.com';

    protected function setUp(): void
    {
        parent::setUp();
        // Stub liveness check so the directory doesn't dial back the test endpoint.
        Http::fake([self::ENDPOINT.'/iicp/health' => Http::response('ok', 200)]);
    }

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'endpoint' => self::ENDPOINT,
            'region' => 'eu-central',
            'capabilities' => [
                [
                    'intent' => 'urn:iicp:intent:llm:chat:v1',
                    'models' => ['llama3'],
                    'max_tokens' => 4096,
                ],
            ],
            'limits' => [
                'max_concurrent' => 4,
                'tokens_per_min' => 10000,
            ],
        ], $overrides);
    }

    public function test_register_with_public_key_stores_gossip_public_key(): void
    {
        $pubKey = str_repeat('ab', 32); // 64 hex chars = 32-byte ed25519 pubkey

        $resp = $this->postJson('/api/v1/register', $this->registerPayload([
            'public_key' => $pubKey,
        ]));

        $resp->assertStatus(201);
        $nodeId = $resp->json('node_id');

        $node = Node::findOrFail($nodeId);
        $this->assertSame($pubKey, $node->gossip_public_key);
    }

    public function test_register_without_public_key_stores_null(): void
    {
        $resp = $this->postJson('/api/v1/register', $this->registerPayload());
        $resp->assertStatus(201);
        $node = Node::findOrFail($resp->json('node_id'));
        $this->assertNull($node->gossip_public_key);
    }

    public function test_node_detail_exposes_public_key_field(): void
    {
        $pubKey = str_repeat('cd', 32);

        $reg = $this->postJson('/api/v1/register', $this->registerPayload([
            'public_key' => $pubKey,
        ]));
        $nodeId = $reg->json('node_id');

        $resp = $this->getJson("/api/v1/node/{$nodeId}")->assertStatus(200);

        $this->assertArrayHasKey('public_key', $resp->json());
        $this->assertSame($pubKey, $resp->json('public_key'));
    }

    public function test_node_detail_public_key_is_null_when_not_registered(): void
    {
        $reg = $this->postJson('/api/v1/register', $this->registerPayload());
        $nodeId = $reg->json('node_id');

        $resp = $this->getJson("/api/v1/node/{$nodeId}")->assertStatus(200);

        $this->assertArrayHasKey('public_key', $resp->json());
        $this->assertNull($resp->json('public_key'));
    }

    public function test_re_register_updates_gossip_public_key(): void
    {
        $pubKey1 = str_repeat('11', 32);
        $pubKey2 = str_repeat('22', 32);

        $reg = $this->postJson('/api/v1/register', $this->registerPayload([
            'public_key' => $pubKey1,
        ]));
        $nodeId = $reg->json('node_id');
        $nodeToken = $reg->json('node_token');

        // Re-register with new public key (supply current_node_token only if cx_public_key changes)
        $this->postJson('/api/v1/register', $this->registerPayload([
            'node_id' => $nodeId,
            'public_key' => $pubKey2,
        ]))->assertStatus(201);

        $node = Node::findOrFail($nodeId);
        $this->assertSame($pubKey2, $node->gossip_public_key);
    }
}
