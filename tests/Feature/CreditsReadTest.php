<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Capability;
use App\Models\Credit;
use App\Models\Node;
use App\Models\Reputation;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Credits read endpoints: balance, transactions, quote.
 *
 * IICP-E027 path (award HMAC) is covered by CreditsAwardTest.
 * This suite covers the three read-only credit endpoints that require
 * NodeTokenAuth but do not require HMAC receipt validation.
 * Auth uses JWT (NodeTokenAuth Phase 2 path) — GET endpoints carry no node_id body.
 */
class CreditsReadTest extends TestCase
{
    use RefreshDatabase;

    private Node $node;

    private string $jwt;

    private static string $intent = 'urn:iicp:intent:llm:chat:v1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->node = Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440002',
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('unused-plain-token', PASSWORD_BCRYPT),
            'node_hmac_key' => bin2hex(random_bytes(32)),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.1',
        ]);

        Credit::create(['node_id' => $this->node->id, 'balance' => 2.5]);
        // D2-READ: production dual-writes both; tests must seed nodes.credit_balance
        // too or post-D2-READ reads return 0 (the column default).
        $this->node->update(['credit_balance' => 2.5]);

        // JWT auth: GET endpoints carry no node_id body; JWT is the primary path.
        $this->jwt = app(JwtService::class)->issue($this->node->id);
    }

    // ── balance ──────────────────────────────────────────────────────────────

    public function test_balance_returns_credit_balance(): void
    {
        $response = $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/balance');

        $response->assertStatus(200)
            ->assertJsonPath('node_id', $this->node->id)
            ->assertJsonPath('balance', 2.5)
            ->assertJsonPath('unit', 'credit')
            ->assertJsonPath('tokens_per_credit', 1000);
    }

    public function test_balance_requires_auth(): void
    {
        $this->getJson('/api/v1/credits/balance')
            ->assertStatus(401);
    }

    // ── transactions ─────────────────────────────────────────────────────────

    public function test_transactions_returns_list(): void
    {
        $response = $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/transactions');

        $response->assertStatus(200)
            ->assertJsonPath('node_id', $this->node->id)
            ->assertJsonStructure(['node_id', 'transactions']);
    }

    public function test_transactions_requires_auth(): void
    {
        $this->getJson('/api/v1/credits/transactions')
            ->assertStatus(401);
    }

    // ── quote ─────────────────────────────────────────────────────────────────

    public function test_quote_returns_estimate(): void
    {
        $response = $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/quote?intent='.urlencode(self::$intent).'&max_tokens=2000');

        $response->assertStatus(200)
            ->assertJsonPath('estimated_credits', 2)
            ->assertJsonPath('price_per_1000_tokens', 1)
            ->assertJsonPath('currency', 'iicp_credits')
            ->assertJsonStructure(['quote_id', 'estimated_credits', 'nodes_quoted', 'quote_expires_at']);
    }

    public function test_quote_counts_capable_nodes(): void
    {
        Capability::create([
            'node_id' => $this->node->id,
            'intent' => self::$intent,
            'models' => ['gpt-4o'],
            'max_tokens' => 8192,
        ]);

        $response = $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/quote?intent='.urlencode(self::$intent).'&max_tokens=1000');

        $response->assertStatus(200)
            ->assertJsonPath('nodes_quoted', 1)
            ->assertJsonPath('estimated_credits', 1);
    }

    public function test_quote_requires_intent_and_max_tokens(): void
    {
        $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/quote')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_quote_requires_max_tokens_when_intent_present(): void
    {
        $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/quote?intent='.urlencode(self::$intent))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_quote_rejects_zero_max_tokens(): void
    {
        $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/quote?intent='.urlencode(self::$intent).'&max_tokens=0')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_quote_requires_auth(): void
    {
        $this->getJson('/api/v1/credits/quote?intent='.urlencode(self::$intent).'&max_tokens=1000')
            ->assertStatus(401);
    }

    // ADR-019: per-node pricing — min/max_credits reflect actual credit_cost_multiplier
    public function test_quote_includes_min_max_credits_in_response(): void
    {
        Capability::create([
            'node_id' => $this->node->id,
            'intent' => self::$intent,
            'models' => ['gpt-4o'],
            'max_tokens' => 8192,
        ]);
        $this->node->update(['credit_cost_multiplier' => 2.0]);

        $response = $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/quote?intent='.urlencode(self::$intent).'&max_tokens=1000');

        $response->assertStatus(200)
            ->assertJsonStructure(['min_credits', 'max_credits', 'estimated_credits']);

        // 1 block × 2.0 multiplier = 2 credits
        $this->assertEqualsWithDelta(2.0, $response->json('min_credits'), 0.001);
        $this->assertEqualsWithDelta(2.0, $response->json('max_credits'), 0.001);
        $this->assertEqualsWithDelta(2.0, $response->json('estimated_credits'), 0.001);
    }

    // D4: min_reputation filter in quote — consistent with discover endpoint behaviour.
    // Nodes below the threshold must not be included in the cost estimate.
    public function test_quote_min_reputation_excludes_low_reputation_nodes(): void
    {
        $intent = self::$intent;

        // Low-reputation node — should be excluded when min_reputation=0.7
        Capability::create(['node_id' => $this->node->id, 'intent' => $intent, 'models' => ['m'], 'max_tokens' => 4096]);
        Reputation::create(['node_id' => $this->node->id, 'score' => 0.3]);

        // High-reputation node — should be included
        $goodNode = Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440011',
            'endpoint' => 'https://good.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('unused', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.3',
            'credit_cost_multiplier' => 1.0,
        ]);
        Capability::create(['node_id' => $goodNode->id, 'intent' => $intent, 'models' => ['m'], 'max_tokens' => 4096]);
        Reputation::create(['node_id' => $goodNode->id, 'score' => 0.9]);

        $response = $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/quote?intent='.urlencode($intent).'&max_tokens=1000&min_reputation=0.7');

        $response->assertStatus(200)->assertJsonPath('nodes_quoted', 1);
    }

    // ADR-019: with two nodes at different multipliers, estimated = average, min/max reflect range
    public function test_quote_estimated_credits_is_average_with_heterogeneous_pricing(): void
    {
        // Node from setUp has credit_cost_multiplier = 1.0 (default).
        // Add a second node with 2.0 multiplier.
        Capability::create([
            'node_id' => $this->node->id,
            'intent' => self::$intent,
            'models' => ['gpt-4o'],
            'max_tokens' => 8192,
        ]);
        $this->node->update(['credit_cost_multiplier' => 0.5]);

        $node2 = Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440099',
            'endpoint' => 'https://node2.example.com',
            'region' => 'eu-west',
            'node_token_hash' => password_hash('unused', PASSWORD_BCRYPT),
            'max_concurrent' => 2,
            'tokens_per_min' => 5000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.2',
            'credit_cost_multiplier' => 1.5,
        ]);
        Capability::create([
            'node_id' => $node2->id,
            'intent' => self::$intent,
            'models' => ['llama-3'],
            'max_tokens' => 4096,
        ]);

        $response = $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/quote?intent='.urlencode(self::$intent).'&max_tokens=2000');

        $response->assertStatus(200)->assertJsonPath('nodes_quoted', 2);

        // 2 blocks × 0.5 = 1.0 min; 2 blocks × 1.5 = 3.0 max; avg = 2.0
        $this->assertEqualsWithDelta(1.0, $response->json('min_credits'), 0.001);
        $this->assertEqualsWithDelta(3.0, $response->json('max_credits'), 0.001);
        $this->assertEqualsWithDelta(2.0, $response->json('estimated_credits'), 0.001);
    }

    // S.12 §2.1: quote includes consumer_balance and balance_sufficient for pre-flight check
    public function test_quote_includes_consumer_balance_and_balance_sufficient(): void
    {
        // Consumer node has sufficient balance (default starting balance > 0)
        $response = $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/quote?intent='.urlencode(self::$intent).'&max_tokens=1000');

        $response->assertStatus(200)
            ->assertJsonStructure(['consumer_balance', 'balance_sufficient']);

        $this->assertIsFloat($response->json('consumer_balance'));
        $this->assertIsBool($response->json('balance_sufficient'));
    }

    public function test_quote_balance_sufficient_false_when_balance_below_estimated(): void
    {
        // Set consumer's balance to 0 (below any positive estimate)
        Credit::where('node_id', $this->node->id)->delete();
        // D2-READ: also clear the canonical denormalized column
        $this->node->update(['credit_balance' => 0.0]);

        Capability::create([
            'node_id' => $this->node->id,
            'intent' => self::$intent,
            'models' => ['gpt-4o'],
            'max_tokens' => 8192,
        ]);
        $this->node->update(['credit_cost_multiplier' => 5.0]); // 5 credits per 1k tokens

        // max_tokens=2000 → 2 blocks × 5.0 = 10 credits; balance = 0 → insufficient
        $response = $this->withToken($this->jwt)
            ->getJson('/api/v1/credits/quote?intent='.urlencode(self::$intent).'&max_tokens=2000');

        $response->assertStatus(200);
        $this->assertEqualsWithDelta(0.0, $response->json('consumer_balance'), 0.001);
        $this->assertFalse($response->json('balance_sufficient'));
    }
}
