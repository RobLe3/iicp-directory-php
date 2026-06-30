<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Operator;
use App\Services\OperatorDelegationVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #463/#310/#464 — the operators record (keyed by operator_id == operator_pubkey) + the
 * public display_name surfaced on node detail. A verified delegation upserts the operator;
 * display_name is public + mutable; operator_pubkey is NEVER exposed publicly.
 */
class OperatorRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Make the directory's liveness probe (assertLive) succeed for the test endpoint.
        Http::fake(['https://node.example.com/iicp/health' => Http::response('ok', 200)]);
    }

    private array $validPayload = [
        'endpoint' => 'https://node.example.com',
        'region' => 'eu-central',
        'capabilities' => [
            ['intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['llama-3-8b'], 'max_tokens' => 4096],
        ],
        'limits' => ['max_concurrent' => 4, 'tokens_per_min' => 10000],
    ];

    /** Register a node with a valid operator delegation + the given operator fields. */
    private function registerWithOperator(string $nodeId, string $pub, string $secret, array $opFields): void
    {
        $notAfter = time() + 3600;
        $msg = OperatorDelegationVerifier::canonicalBytes($nodeId, $pub, $notAfter);
        $sig = base64_encode(sodium_crypto_sign_detached($msg, $secret));
        $payload = array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'operator_delegation' => ['node_id' => $nodeId, 'operator_pub' => $pub, 'not_after' => $notAfter, 'sig' => $sig],
        ], $opFields);
        $this->postJson('/api/v1/register', $payload)->assertStatus(201);
    }

    public function test_verified_delegation_upserts_operator_record_and_pins_integrity(): void
    {
        $nodeId = (string) Str::uuid();
        $kp = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($kp));
        $secret = sodium_crypto_sign_secretkey($kp);
        $createdAt = '2026-06-05T12:00:00Z';
        $hash = hash('sha256', "{$pub}:{$createdAt}");

        $this->registerWithOperator($nodeId, $pub, $secret, [
            'operator_display_name' => 'Rebel One',
            'operator_created_at' => $createdAt,
            'operator_integrity_hash' => $hash,
        ]);

        $op = Operator::where('operator_pubkey', $pub)->first();
        $this->assertNotNull($op);
        $this->assertSame('Rebel One', $op->display_name);
        $this->assertSame($hash, $op->operator_integrity_hash);   // pinned on first register
        $this->assertGreaterThan(0, $op->first_seen_ms);          // directory-observed
    }

    public function test_node_detail_serves_display_name_but_never_operator_pubkey(): void
    {
        $nodeId = (string) Str::uuid();
        $kp = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($kp));
        $this->registerWithOperator($nodeId, $pub, sodium_crypto_sign_secretkey($kp), [
            'operator_display_name' => 'Mesh Pioneer',
        ]);

        $resp = $this->getJson("/api/v1/registry/nodes/{$nodeId}")->assertStatus(200);
        $resp->assertJsonPath('operator_display_name', 'Mesh Pioneer');
        $resp->assertJsonPath('operator_fingerprint', Operator::publicFingerprint($pub));
        // operator_pubkey is directory-private — MUST NOT appear anywhere in the response.
        $this->assertStringNotContainsString($pub, $resp->getContent());
        $this->assertStringNotContainsString('operator_pubkey', $resp->getContent());
    }

    public function test_display_name_cannot_be_claimed_by_a_different_verified_operator(): void
    {
        $nodeA = (string) Str::uuid();
        $kpA = sodium_crypto_sign_keypair();
        $pubA = base64_encode(sodium_crypto_sign_publickey($kpA));
        $this->registerWithOperator($nodeA, $pubA, sodium_crypto_sign_secretkey($kpA), [
            'operator_display_name' => 'Mesh Pioneer',
        ]);

        $nodeB = (string) Str::uuid();
        $kpB = sodium_crypto_sign_keypair();
        $pubB = base64_encode(sodium_crypto_sign_publickey($kpB));
        $notAfter = time() + 3600;
        $msg = OperatorDelegationVerifier::canonicalBytes($nodeB, $pubB, $notAfter);
        $sig = base64_encode(sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($kpB)));

        $payload = array_merge($this->validPayload, [
            'node_id' => $nodeB,
            'endpoint' => 'https://node-two.example.com',
            'operator_display_name' => ' mesh   pioneer ',
            'operator_delegation' => ['node_id' => $nodeB, 'operator_pub' => $pubB, 'not_after' => $notAfter, 'sig' => $sig],
        ]);

        Http::fake([
            'https://node.example.com/iicp/health' => Http::response('ok', 200),
            'https://node-two.example.com/iicp/health' => Http::response('ok', 200),
        ]);

        $this->postJson('/api/v1/register', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.fields.operator_display_name.0', 'operator_display_name is already claimed by another verified operator (IICP-E051)');
    }

    public function test_display_name_is_mutable_via_reregister(): void
    {
        $nodeId = (string) Str::uuid();
        $kp = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($kp));
        $secret = sodium_crypto_sign_secretkey($kp);

        $this->registerWithOperator($nodeId, $pub, $secret, ['operator_display_name' => 'Old Name']);
        $this->registerWithOperator($nodeId, $pub, $secret, ['operator_display_name' => 'New Name']);

        $this->assertSame('New Name', Operator::where('operator_pubkey', $pub)->value('display_name'));
        // Exactly one operator row (keyed by operator_id, not per-node/per-register).
        $this->assertSame(1, Operator::where('operator_pubkey', $pub)->count());
    }

    public function test_first_seen_ms_is_pinned_and_never_reset_by_reregister(): void
    {
        // Spec §5.4.2 no-reset semantics: the founder 30-day clock anchors to first_seen_ms,
        // which is set once at first registration. A re-register (node reboot, Windows
        // update, sleep recovery) MUST NOT move it — otherwise reboots would reset the
        // founder clock, which is exactly what we promise self-hosters does not happen.
        $nodeId = (string) Str::uuid();
        $kp = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($kp));
        $secret = sodium_crypto_sign_secretkey($kp);

        $this->registerWithOperator($nodeId, $pub, $secret, ['operator_display_name' => 'HomeLab']);
        $pinned = Operator::where('operator_pubkey', $pub)->value('first_seen_ms');
        $this->assertGreaterThan(0, $pinned);

        // Backdate the pin so a reset would be detectable as a larger value.
        Operator::where('operator_pubkey', $pub)->update(['first_seen_ms' => $pinned - 86_400_000]);

        $this->registerWithOperator($nodeId, $pub, $secret, ['operator_display_name' => 'HomeLab']);

        $this->assertSame(
            $pinned - 86_400_000,
            (int) Operator::where('operator_pubkey', $pub)->value('first_seen_ms'),
            're-registration must never touch the pinned first_seen_ms'
        );
    }
}
