<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Services\NodeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // #489 — classifyModelTier: verify parameter-count parsing and tier boundaries.
    #[DataProvider('modelTierProvider')]
    public function test_classify_model_tier(array $models, string $expectedTier): void
    {
        $this->assertEquals(
            $expectedTier,
            NodeRegistry::classifyModelTier($models)
        );
    }

    public static function modelTierProvider(): array
    {
        return [
            'sub_1b: qwen0.5b' => [['qwen2.5:0.5b'], 'sub_1b'],
            '7b: tinyllama-1b' => [['tinyllama:1b-instruct'], '7b'],  // 1.0 is not < 1 → 7b default
            '7b: llama3-8b' => [['llama3.1:8b'], '7b'],
            '7b: mistral-7b' => [['mistral:7b'], '7b'],
            '13b: codellama-13b' => [['codellama:13b'], '13b'],
            '7b: mixtral-8x7b' => [['mixtral:8x7b'], '7b'],  // "8" extracted → 7b (first match wins)
            '70b: llama-70b' => [['llama3.1:70b'], '70b'],
            '70b: qwen72b' => [['qwen:72b'], '70b'],
            '100b+: llama-175b' => [['llama:175b'], '100b_plus'],
            'no_match → 7b' => [['gpt-4o'], '7b'],
            'multi: pick max' => [['qwen2.5:0.5b', 'llama3.1:70b'], '70b'],
        ];
    }

    // #489 — compute ceiling: a 0.5B node declaring multiplier=100 is clamped to 0.05*3=0.15.
    public function test_register_clamps_multiplier_to_tier_ceiling(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $payload = $this->basePayload;
        $payload['capabilities'][0]['models'] = ['qwen2.5:0.5b'];
        $payload['pricing'] = ['credit_cost_multiplier' => 100.0, 'pricing_model' => 'per_token'];

        $response = $this->postJson('/api/v1/register', $payload);
        $response->assertSuccessful();

        $node = Node::first();
        // sub_1b tier: 0.05 * 3 = 0.15
        $this->assertEqualsWithDelta(0.15, (float) $node->credit_cost_multiplier, 0.001);
    }

    // #489 — a legitimate 7B multiplier (=2.5) is within the 1.0*3=3.0 ceiling → no clamp.
    public function test_register_allows_multiplier_within_tier_ceiling(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $payload = $this->basePayload;
        $payload['pricing'] = ['credit_cost_multiplier' => 2.5, 'pricing_model' => 'per_token'];

        $response = $this->postJson('/api/v1/register', $payload);
        $response->assertSuccessful();

        $node = Node::first();
        $this->assertEqualsWithDelta(2.5, (float) $node->credit_cost_multiplier, 0.001);
    }
}
