<?php

namespace Tests\Feature;

use App\Models\Credit;
use App\Models\Node;
use App\Models\NodeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * IICP-E027: CreditsController::award() HMAC-SHA256 validation.
 *
 * The directory must verify a CIPWorkerReceipt HMAC before crediting tokens.
 * Returns 422 + IICP-E027 error code when the receipt is absent or invalid.
 */
class CreditsAwardTest extends TestCase
{
    use RefreshDatabase;

    private Node $node;

    private string $hmacKey;

    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hmacKey = bin2hex(random_bytes(32));
        $this->plainToken = 'test-plain-token-40-characters-exactly!!';

        $this->node = Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440001',
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash($this->plainToken, PASSWORD_BCRYPT),
            'node_hmac_key' => $this->hmacKey,
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.1',
        ]);

        Credit::create(['node_id' => $this->node->id, 'balance' => 0.0]);
    }

    private function validReceipt(array $overrides = []): array
    {
        $taskId = $overrides['task_id'] ?? 'task-abc-123';
        $tokens = $overrides['tokens_used'] ?? 500;
        $parent = $overrides['cip_parent_task_id'] ?? null;
        $session = $overrides['cip_session_key'] ?? null;
        $nonce = $overrides['nonce'] ?? bin2hex(random_bytes(16));
        $expiresAt = $overrides['expires_at'] ?? now()->addSeconds(60)->toISOString();
        $key = $overrides['_key'] ?? $this->hmacKey;
        $responseHash = $overrides['response_hash'] ?? hash('sha256', '{"result":"ok"}');

        // Canonical string matches CreditsController::award() — includes response_hash (W-031)
        $canonical = implode(':', [$taskId, (string) $tokens, $parent ?? '', $session ?? '', $nonce, $responseHash]);
        $sig = hash_hmac('sha256', $canonical, $key);

        return array_merge([
            'node_id' => $this->node->id,
            'task_id' => $taskId,
            'tokens_used' => $tokens,
            'nonce' => $nonce,
            'expires_at' => $expiresAt,
            'signature' => $sig,
            'response_hash' => $responseHash,
            'cip_parent_task_id' => $parent,
            'cip_session_key' => $session,
            'amount' => 0.5,
        ], array_diff_key($overrides, ['_key' => null]));
    }

    public function test_award_with_valid_hmac_credits_balance(): void
    {
        $payload = $this->validReceipt();
        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('node_id', $this->node->id)
            ->assertJsonPath('awarded', 0.5);
    }

    // Regression: a custom-named node (not a UUID) registers + heartbeats fine,
    // so it must also be creditable. The award validator previously required
    // 'uuid' and 422'd custom-named nodes (bug-hunt iter-1515).
    public function test_award_accepts_custom_named_node(): void
    {
        $plainToken = 'custom-node-plain-token-40-characters!!!!';
        $node = Node::create([
            'id' => 'my-home-rust',
            'endpoint' => 'https://node2.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash($plainToken, PASSWORD_BCRYPT),
            'node_hmac_key' => $this->hmacKey,
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.1',
        ]);
        Credit::create(['node_id' => $node->id, 'balance' => 0.0]);

        $payload = $this->validReceipt(['node_id' => $node->id]);
        $response = $this->withToken($plainToken)
            ->postJson('/api/v1/credits/award', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('node_id', 'my-home-rust')
            ->assertJsonPath('awarded', 0.5);
    }

    public function test_award_rejects_wrong_signature(): void
    {
        $payload = $this->validReceipt(['signature' => str_repeat('0', 64)]);
        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E027');
    }

    public function test_award_rejects_tampered_tokens_used(): void
    {
        $payload = $this->validReceipt();
        $payload['tokens_used'] = 9999;  // canonical message still signed with 500

        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E027');
    }

    public function test_award_rejects_wrong_hmac_key(): void
    {
        $payload = $this->validReceipt(['_key' => bin2hex(random_bytes(32))]);
        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E027');
    }

    public function test_award_requires_receipt_fields(): void
    {
        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', [
                'node_id' => $this->node->id,
                'amount' => 0.5,
            ]);

        $response->assertStatus(422);
    }

    public function test_award_with_parent_and_session_fields(): void
    {
        $payload = $this->validReceipt([
            'cip_parent_task_id' => 'parent-xyz',
            'cip_session_key' => 'sess-abc',
        ]);
        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload);

        $response->assertStatus(200);
    }

    public function test_award_rejects_tampered_parent_task_id(): void
    {
        $payload = $this->validReceipt(['cip_parent_task_id' => 'parent-xyz']);
        $payload['cip_parent_task_id'] = 'parent-TAMPERED';

        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E027');
    }

    // D8 / Phase 6 prereq: CREDIT_AWARD event is emitted in node_events after successful award
    public function test_award_emits_credit_award_event(): void
    {
        $payload = $this->validReceipt(['task_id' => 'task-event-test', 'tokens_used' => 800]);

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload)
            ->assertStatus(200);

        $event = NodeEvent::where('event_type', 'CREDIT_AWARD')
            ->where('node_id', $this->node->id)
            ->first();

        $this->assertNotNull($event, 'CREDIT_AWARD event must be logged on successful award');
        $this->assertSame('task-event-test', $event->payload['task_id']);
        $this->assertSame(800, $event->payload['tokens_used']);
        $this->assertSame(0.5, (float) $event->payload['amount']);
    }

    // Spec §10.3: reject replayed nonce (IICP-E027)
    public function test_award_rejects_replayed_nonce(): void
    {
        Cache::flush();
        $nonce = bin2hex(random_bytes(16));
        $payload = $this->validReceipt(['nonce' => $nonce]);

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload)
            ->assertStatus(200);

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E027');
    }

    // Spec §10.3: reject expired receipt (IICP-E027)
    public function test_award_rejects_expired_receipt(): void
    {
        $payload = $this->validReceipt([
            'expires_at' => now()->subSeconds(1)->toISOString(),
        ]);

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E027');
    }

    // Spec §10.3: expires_at is required
    public function test_award_requires_expires_at(): void
    {
        $payload = $this->validReceipt();
        unset($payload['expires_at']);

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload)
            ->assertStatus(422);
    }

    // TC-9c: amount must not exceed ceil(tokens_used/1000) × credit_cost_multiplier × 1.1
    public function test_award_rejects_amount_exceeding_token_ceiling(): void
    {
        // 500 tokens → 1 block × 1.0 multiplier = 1.0 ceiling; 1.5 > 1.1 → rejected
        $payload = $this->validReceipt(['tokens_used' => 500]);
        $payload['amount'] = 1.5;

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E027');
    }

    // TC-9d: inflated receipt must not burn the nonce — the same nonce resubmitted with a
    // valid amount must still succeed after an earlier inflation rejection.
    public function test_inflated_amount_does_not_consume_nonce(): void
    {
        Cache::flush();
        $nonce = bin2hex(random_bytes(16));

        // First request: valid HMAC, inflated amount → 422, nonce must NOT be consumed
        $inflated = $this->validReceipt(['nonce' => $nonce, 'tokens_used' => 500]);
        $inflated['amount'] = 5.0;  // far above 1.1 ceiling

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $inflated)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E027');

        // Second request: same nonce, valid amount → must succeed (nonce not burned)
        $valid = $this->validReceipt(['nonce' => $nonce, 'tokens_used' => 500]);

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $valid)
            ->assertStatus(200);
    }

    // TC-9b: credit laundering rate limit — max 1000 credits/hour per node_id.
    // Colluding nodes that earn unusually fast should be blocked.

    public function test_award_blocked_when_hourly_limit_exceeded(): void
    {
        $hourKey = 'award_hourly:'.$this->node->id.':'.now()->format('YmdH');
        Cache::put($hourKey, 999.9, 7200);

        $payload = $this->validReceipt(['amount' => 0.2, 'tokens_used' => 200]);

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E027')
            ->assertJsonPath('error.message', 'Credit award rate limit exceeded (max 1000 credits/hour per node)');
    }

    public function test_award_increments_hourly_counter_on_success(): void
    {
        $hourKey = 'award_hourly:'.$this->node->id.':'.now()->format('YmdH');

        $payload = $this->validReceipt(['amount' => 5.0, 'tokens_used' => 5000]);
        $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload)
            ->assertStatus(200);

        $this->assertEqualsWithDelta(5.0, (float) Cache::get($hourKey, 0), 0.001);
    }
}
