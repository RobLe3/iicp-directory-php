<?php

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PricingDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private array $basePayload = [
        'endpoint' => 'https://node.example.com',
        'region' => 'eu-central',
        'capabilities' => [
            [
                'intent' => 'urn:iicp:intent:llm:chat:v1',
                'models' => ['llama-3-8b'],
                'max_tokens' => 4096,
            ],
        ],
        'limits' => [
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://node.example.com/iicp/health' => Http::response('ok', 200)]);
    }

    // -------------------------------------------------------------------------
    // REGISTER with pricing block
    // -------------------------------------------------------------------------

    public function test_register_with_pricing_block_stores_values(): void
    {
        $payload = array_merge($this->basePayload, [
            'pricing' => [
                'credit_cost_multiplier' => 1.5,
                'pricing_model' => 'per_token',
            ],
        ]);

        $this->postJson('/api/v1/register', $payload)->assertStatus(201);

        $node = Node::first();
        $this->assertEquals(1.5, $node->credit_cost_multiplier);
        $this->assertEquals('per_token', $node->pricing_model);
        $this->assertFalse((bool) $node->attested);
    }

    public function test_register_without_pricing_block_defaults_to_1x(): void
    {
        $this->postJson('/api/v1/register', $this->basePayload)->assertStatus(201);

        $node = Node::first();
        $this->assertEquals(1.0, $node->credit_cost_multiplier);
        $this->assertFalse((bool) $node->attested);
    }

    public function test_register_with_signed_pricing_sets_attested(): void
    {
        $key = 'test-signing-key-for-ph5-attestation';
        $body = json_encode(['credit_cost_multiplier' => 2.0, 'pricing_model' => 'per_token']);
        $sig = hash_hmac('sha256', $body, $key);

        $payload = array_merge($this->basePayload, [
            'node_hmac_key' => $key,
            'pricing' => [
                'credit_cost_multiplier' => 2.0,
                'pricing_model' => 'per_token',
                'declaration_signature' => $sig,
            ],
        ]);

        $this->postJson('/api/v1/register', $payload)->assertStatus(201);

        $node = Node::first();
        $this->assertTrue((bool) $node->attested);
        $this->assertEquals(2.0, $node->credit_cost_multiplier);
    }

    public function test_register_with_invalid_signature_returns_422(): void
    {
        $payload = array_merge($this->basePayload, [
            'node_hmac_key' => 'correct-key',
            'pricing' => [
                'credit_cost_multiplier' => 1.0,
                'pricing_model' => 'per_token',
                'declaration_signature' => 'bad-signature-value',
            ],
        ]);

        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // DISCOVER with pricing sub-object and filters
    // -------------------------------------------------------------------------

    private function createNodeWithPricing(float $multiplier, float $score = 0.85): Node
    {
        $node = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'load' => 0.2,
            'active_jobs' => 1,
            'last_seen' => now(),
            'public_reachable' => true,
            'credit_cost_multiplier' => $multiplier,
            'pricing_model' => 'per_token',
            'attested' => false,
        ]);
        $node->capabilities()->create([
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['llama-3-8b'],
            'max_tokens' => 4096,
        ]);

        return $node;
    }

    public function test_discover_includes_pricing_sub_object(): void
    {
        $this->createNodeWithPricing(1.5);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');

        $response->assertStatus(200)
            ->assertJsonPath('count', 1)
            ->assertJsonStructure(['nodes' => [['pricing' => ['credit_cost_multiplier', 'pricing_model', 'attested']]]]);

        $this->assertEquals(1.5, $response->json('nodes.0.pricing.credit_cost_multiplier'));
        $this->assertEquals('per_token', $response->json('nodes.0.pricing.pricing_model'));
    }

    public function test_discover_max_multiplier_excludes_expensive_nodes(): void
    {
        $this->createNodeWithPricing(1.0);  // cheap — should appear
        $this->createNodeWithPricing(3.0);  // expensive — should be filtered

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&max_multiplier=2.0');

        $response->assertStatus(200)->assertJsonPath('count', 1);
        $this->assertEquals(1.0, $response->json('nodes.0.pricing.credit_cost_multiplier'));
    }

    // -------------------------------------------------------------------------
    // HEARTBEAT re-verifies pricing
    // -------------------------------------------------------------------------

    public function test_heartbeat_updates_pricing_and_sets_attested(): void
    {
        $key = 'heartbeat-test-signing-key';
        $node = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token123', PASSWORD_BCRYPT),
            'node_hmac_key' => $key,
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'load' => 0.1,
            'active_jobs' => 0,
            'last_seen' => now(),
            'public_reachable' => true,
            'credit_cost_multiplier' => 1.0,
            'attested' => false,
        ]);

        $pricingBody = json_encode(['credit_cost_multiplier' => 1.8, 'pricing_model' => 'per_token']);
        $sig = hash_hmac('sha256', $pricingBody, $key);

        $response = $this->postJson('/api/v1/heartbeat', [
            'node_id' => $node->id,
            'pricing' => [
                'credit_cost_multiplier' => 1.8,
                'pricing_model' => 'per_token',
                'declaration_signature' => $sig,
            ],
        ], ['Authorization' => 'Bearer token123']);

        $response->assertStatus(200);
        $node->refresh();
        $this->assertTrue((bool) $node->attested);
        $this->assertEquals(1.8, $node->credit_cost_multiplier);
    }
}
