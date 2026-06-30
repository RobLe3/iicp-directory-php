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

        // #490 — canonical string includes querying_node_id when present (prevents spoofing).
        $queryingNodeId = $overrides['querying_node_id'] ?? null;
        $canonicalParts = [$taskId, (string) $tokens, $parent ?? '', $session ?? '', $nonce, $responseHash];
        if ($queryingNodeId !== null) {
            $canonicalParts[] = $queryingNodeId;
        }
        $canonical = implode(':', $canonicalParts);
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
        $this->assertSame('legacy_unattributed', $event->payload['attribution']);
        $this->assertSame(0.0, (float) $event->payload['trust_weight']);
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

    // #488 — self-query neutrality: same operator_pubkey → award excluded, not an error.
    public function test_award_excluded_when_querying_node_shares_operator_pubkey(): void
    {
        $operatorPubkey = 'ed25519:'.bin2hex(random_bytes(32));
        $this->node->update(['operator_pubkey' => $operatorPubkey]);

        // Querying node has the SAME operator — self-query scenario.
        $queryingNode = Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440099',
            'endpoint' => 'https://querying.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('querying-token-40-characters-exactly!', PASSWORD_BCRYPT),
            'node_hmac_key' => bin2hex(random_bytes(32)),
            'max_concurrent' => 2,
            'tokens_per_min' => 5000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.2',
            'operator_pubkey' => $operatorPubkey,
        ]);

        $payload = $this->validReceipt(['querying_node_id' => $queryingNode->id]);
        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('excluded', true)
            ->assertJsonPath('reason', 'self_query_excluded')
            ->assertJsonPath('attribution', 'self_operator')
            ->assertJsonPath('trust_weight', 0)
            ->assertJsonPath('awarded', 0);

        // Balance must not change.
        $this->assertEqualsWithDelta(0.0, Credit::where('node_id', $this->node->id)->value('balance'), 0.001);
        $this->assertDatabaseMissing('node_events', [
            'event_type' => 'CREDIT_AWARD',
            'node_id' => $this->node->id,
        ]);
    }

    // WQ-098 — exact same serving/querying node is excluded even without operator identity.
    public function test_award_excluded_when_querying_node_is_serving_node(): void
    {
        $payload = $this->validReceipt(['querying_node_id' => $this->node->id]);
        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('excluded', true)
            ->assertJsonPath('reason', 'self_query_excluded')
            ->assertJsonPath('attribution', 'self_node')
            ->assertJsonPath('trust_weight', 0)
            ->assertJsonPath('awarded', 0);

        $this->assertEqualsWithDelta(0.0, Credit::where('node_id', $this->node->id)->value('balance'), 0.001);
    }

    // #488 — different operator_pubkey → award proceeds normally.
    public function test_award_proceeds_when_querying_node_has_different_operator(): void
    {
        $this->node->update(['operator_pubkey' => 'ed25519:aaaa']);

        $queryingNode = Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440098',
            'endpoint' => 'https://foreign.example.com',
            'region' => 'us-west',
            'node_token_hash' => password_hash('foreign-token-40-characters-exactly!!', PASSWORD_BCRYPT),
            'node_hmac_key' => bin2hex(random_bytes(32)),
            'max_concurrent' => 2,
            'tokens_per_min' => 5000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.3',
            'operator_pubkey' => 'ed25519:bbbb',  // different operator
        ]);

        $payload = $this->validReceipt(['querying_node_id' => $queryingNode->id]);
        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload);

        $response->assertStatus(200)
            ->assertJsonMissing(['excluded'])
            ->assertJsonPath('awarded', 0.5)
            ->assertJsonPath('attribution', 'attributed_cross_operator')
            ->assertJsonPath('trust_weight', 1);
    }

    // #488 — absent querying_node_id → normal award path (backwards compatible).
    public function test_award_without_querying_node_id_proceeds_normally(): void
    {
        $this->node->update(['operator_pubkey' => 'ed25519:aaaa']);

        $payload = $this->validReceipt();  // no querying_node_id
        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('awarded', 0.5)
            ->assertJsonPath('attribution', 'legacy_unattributed')
            ->assertJsonPath('trust_weight', 0);
    }

    // WQ-098 — a signed but unknown querying_node_id is rejected, not treated as attributable.
    public function test_award_rejects_unknown_querying_node_id_even_when_signed(): void
    {
        $payload = $this->validReceipt(['querying_node_id' => '550e8400-e29b-41d4-a716-44665544dead']);

        $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E027')
            ->assertJsonPath('error.message', 'querying_node_id does not identify a registered node');
    }

    // #490 — spend: querying node balance debited on award when different operator.
    public function test_award_debits_querying_node_when_different_operator(): void
    {
        $this->node->update(['operator_pubkey' => 'ed25519:serve-op']);

        $queryingNode = Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440099',
            'endpoint' => 'https://querying.example.com',
            'region' => 'us-east',
            'node_token_hash' => password_hash('querying-token-40-characters-exact!!', PASSWORD_BCRYPT),
            'node_hmac_key' => bin2hex(random_bytes(32)),
            'max_concurrent' => 2,
            'tokens_per_min' => 5000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.4',
            'operator_pubkey' => 'ed25519:query-op',  // different operator
            'credit_balance' => 10.0,
        ]);
        // Seed querying node's ledger so debit has a record to decrement.
        Credit::create(['node_id' => $queryingNode->id, 'balance' => 10.0]);

        $payload = $this->validReceipt([
            'querying_node_id' => $queryingNode->id,
            'amount' => 0.5,
            'tokens_used' => 500,
        ]);
        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('awarded', 0.5)
            ->assertJsonPath('spent', 0.5);

        // Querying node balance reduced by the award amount.
        $queryingBalance = Credit::where('node_id', $queryingNode->id)->value('balance');
        $this->assertEqualsWithDelta(9.5, (float) $queryingBalance, 0.001,
            'querying node balance must be debited by award amount');
    }

    // #490 — spend: award still succeeds when querying node has insufficient balance (best-effort).
    public function test_award_succeeds_when_querying_node_has_insufficient_balance(): void
    {
        $this->node->update(['operator_pubkey' => 'ed25519:serve-op']);

        $queryingNode = Node::create([
            'id' => '550e8400-e29b-41d4-a716-44665544009a',
            'endpoint' => 'https://broke.example.com',
            'region' => 'us-east',
            'node_token_hash' => password_hash('broke-token-40-characters-exactly!!!!', PASSWORD_BCRYPT),
            'node_hmac_key' => bin2hex(random_bytes(32)),
            'max_concurrent' => 2,
            'tokens_per_min' => 5000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.5',
            'operator_pubkey' => 'ed25519:broke-op',
            'credit_balance' => 0.0,
        ]);
        // No credit record for querying node (insufficient balance case).

        $payload = $this->validReceipt([
            'querying_node_id' => $queryingNode->id,
            'amount' => 0.5,
            'tokens_used' => 500,
        ]);
        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', $payload);

        // Server still gets credited — spend is best-effort.
        $response->assertStatus(200)
            ->assertJsonPath('awarded', 0.5)
            ->assertJsonPath('spend_scope', 'operator_wallet')
            ->assertJsonPath('spend_reason', 'insufficient_operator_wallet_balance');
        $this->assertEqualsWithDelta(0.0, $response->json('spent'), 0.001);

        $serverBalance = Credit::where('node_id', $this->node->id)->value('balance');
        $this->assertEqualsWithDelta(0.5, (float) $serverBalance, 0.001,
            'serving node must still be credited even when querying node has insufficient balance');
    }

    // #490 — HMAC includes querying_node_id to prevent spoofing: wrong QNI fails verification.
    public function test_award_rejects_fabricated_querying_node_id(): void
    {
        // Sign WITHOUT querying_node_id in canonical, then send WITH querying_node_id.
        // This simulates an attacker adding querying_node_id after signing.
        $nonce = bin2hex(random_bytes(16));
        $responseHash = hash('sha256', '{"result":"ok"}');
        $taskId = 'task-spoof-test';
        $tokens = 500;

        // Sign with old formula (no querying_node_id) — canonical mismatch.
        $canonicalOld = implode(':', [$taskId, (string) $tokens, '', '', $nonce, $responseHash]);
        $sigOld = hash_hmac('sha256', $canonicalOld, $this->hmacKey);

        $response = $this->withToken($this->plainToken)
            ->postJson('/api/v1/credits/award', [
                'node_id' => $this->node->id,
                'task_id' => $taskId,
                'tokens_used' => $tokens,
                'nonce' => $nonce,
                'expires_at' => now()->addSeconds(60)->toISOString(),
                'signature' => $sigOld,
                'response_hash' => $responseHash,
                'amount' => 0.5,
                'querying_node_id' => 'some-foreign-node-id',  // injected after signing
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E027');
    }
}
