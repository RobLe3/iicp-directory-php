<?php

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CIP-D1 (#73): Provider opt-in policy block — spec S.12 §2.1.
 *
 * Tests that REGISTER accepts + stores the policy block and that
 * DISCOVER returns cip_policy in node entries.
 */
class CipPolicyTest extends TestCase
{
    use RefreshDatabase;

    private array $basePayload;

    protected function setUp(): void
    {
        parent::setUp();
        // DiscoverController caches results briefly; flush so a prior test's cached
        // discover for this intent can't bleed into this test's assertions (the local
        // array cache store survives RefreshDatabase). Keeps the discover test deterministic.
        Cache::flush();
        Http::fake(['https://node.example.com/iicp/health' => Http::response('ok', 200)]);

        $this->basePayload = [
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'capabilities' => [['intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['phi3:mini'], 'max_tokens' => 4096]],
            'limits' => ['max_concurrent' => 4, 'tokens_per_min' => 10000],
        ];
    }

    public function test_register_without_policy_uses_safe_defaults(): void
    {
        $response = $this->postJson('/api/v1/register', $this->basePayload);

        $response->assertStatus(201);
        $nodeId = $response->json('node_id');
        $node = Node::findOrFail($nodeId);

        $this->assertFalse((bool) $node->allow_remote_inference);
        $this->assertFalse((bool) $node->allow_tool_execution);
        $this->assertFalse((bool) $node->allow_file_access);
        $this->assertNull($node->pricing_credits_per_1000);
    }

    public function test_register_with_policy_stores_flags(): void
    {
        $payload = array_merge($this->basePayload, [
            'policy' => [
                'allow_remote_inference' => true,
                'allow_tool_execution' => false,
                'allow_file_access' => false,
                'pricing_credits_per_1000' => 0.5,
            ],
        ]);

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertStatus(201);
        $node = Node::findOrFail($response->json('node_id'));

        $this->assertTrue((bool) $node->allow_remote_inference);
        $this->assertFalse((bool) $node->allow_tool_execution);
        $this->assertFalse((bool) $node->allow_file_access);
        $this->assertEqualsWithDelta(0.5, (float) $node->pricing_credits_per_1000, 0.0001);
    }

    public function test_discover_includes_cip_policy_in_response(): void
    {
        $payload = array_merge($this->basePayload, [
            'policy' => ['allow_remote_inference' => true, 'pricing_credits_per_1000' => 1.5],
        ]);

        $this->postJson('/api/v1/register', $payload)->assertStatus(201);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');
        $response->assertStatus(200)->assertJsonPath('count', 1);

        $policy = $response->json('nodes.0.cip_policy');
        $this->assertNotNull($policy);
        $this->assertTrue($policy['allow_remote_inference']);
        $this->assertFalse($policy['allow_tool_execution']);
        $this->assertFalse($policy['allow_file_access']);
        $this->assertEqualsWithDelta(1.5, (float) $policy['pricing_credits_per_1000'], 0.0001);
    }

    public function test_discover_includes_default_cip_policy_when_no_policy_registered(): void
    {
        $this->postJson('/api/v1/register', $this->basePayload)->assertStatus(201);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');
        $response->assertStatus(200);

        $policy = $response->json('nodes.0.cip_policy');
        $this->assertFalse($policy['allow_remote_inference']);
        $this->assertFalse($policy['allow_tool_execution']);
        $this->assertFalse($policy['allow_file_access']);
        $this->assertNull($policy['pricing_credits_per_1000']);
    }

    public function test_discover_returns_cip_provider_conformance_level_when_allow_remote_inference_true(): void
    {
        $payload = array_merge($this->basePayload, [
            'policy' => ['allow_remote_inference' => true],
        ]);

        $this->postJson('/api/v1/register', $payload)->assertStatus(201);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');
        $response->assertStatus(200)->assertJsonPath('count', 1);
        $response->assertJsonPath('nodes.0.cip_conformance_level', 'CIP-Provider');
    }

    public function test_discover_returns_cip_none_conformance_level_when_no_policy_registered(): void
    {
        $this->postJson('/api/v1/register', $this->basePayload)->assertStatus(201);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');
        $response->assertStatus(200);
        $response->assertJsonPath('nodes.0.cip_conformance_level', 'CIP-None');
    }

    public function test_policy_pricing_validation_rejects_negative(): void
    {
        $payload = array_merge($this->basePayload, [
            'policy' => ['pricing_credits_per_1000' => -1.0],
        ]);

        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }

    public function test_policy_pricing_validation_rejects_over_max(): void
    {
        $payload = array_merge($this->basePayload, [
            'policy' => ['pricing_credits_per_1000' => 1001.0],
        ]);

        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }
}
