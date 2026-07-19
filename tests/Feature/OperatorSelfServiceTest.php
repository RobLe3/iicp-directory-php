<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Http\Controllers\OperatorSelfServiceController;
use App\Models\DataSubjectAction;
use App\Models\Node;
use App\Models\Operator;
use App\Models\PolicyKeyLifecycleRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $pub;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $kp = sodium_crypto_sign_keypair();
        $this->pub = base64_encode(sodium_crypto_sign_publickey($kp));
        $this->secret = sodium_crypto_sign_secretkey($kp);
        Operator::create([
            'operator_pubkey' => $this->pub,
            'display_name' => 'Self Service Test',
            'first_seen_ms' => 1,
        ]);
    }

    private function challenge(): string
    {
        return $this->postJson('/api/v1/operator/challenge', ['operator_pub' => $this->pub])
            ->assertOk()
            ->json('nonce');
    }

    /** @param array<string,mixed> $extra */
    private function signedBody(string $action, array $extra = []): array
    {
        $body = [
            'operator_pub' => $this->pub,
            'nonce' => $this->challenge(),
            'ts' => time(),
            ...$extra,
        ];
        $body['sig'] = base64_encode(sodium_crypto_sign_detached(
            OperatorSelfServiceController::canonicalBytes($action, $body),
            $this->secret,
        ));

        return $body;
    }

    public function test_signed_acceptance_updates_current_versions_and_returns_redacted_receipt(): void
    {
        $body = $this->signedBody('accept', [
            'terms_version' => config('app.iicp_operator_terms_version'),
            'dpa_version' => config('app.iicp_operator_dpa_version'),
        ]);

        $response = $this->postJson('/api/v1/operator/acceptance', $body)
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('legal_certification', false);
        $this->assertArrayNotHasKey('operator_pub', $response->json());
        $this->assertArrayNotHasKey('nonce', $response->json());

        $operator = Operator::where('operator_pubkey', $this->pub)->firstOrFail();
        $this->assertSame('operator_key_challenge', $operator->acceptance_method);
        $this->assertSame(hash('sha256', $body['nonce']), $operator->acceptance_nonce_sha256);
    }

    public function test_challenge_is_one_time_and_bad_signature_does_not_mutate(): void
    {
        $body = $this->signedBody('accept', [
            'terms_version' => config('app.iicp_operator_terms_version'),
            'dpa_version' => config('app.iicp_operator_dpa_version'),
        ]);
        $body['sig'] = base64_encode(random_bytes(64));
        $this->postJson('/api/v1/operator/acceptance', $body)->assertUnauthorized();
        $this->postJson('/api/v1/operator/acceptance', $body)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'IICP-E062');
        $this->assertNull(Operator::where('operator_pubkey', $this->pub)->value('terms_version'));
    }

    public function test_self_service_export_is_redacted_and_requires_valid_signature(): void
    {
        $body = $this->signedBody('dsr_export', ['tracking_id' => 'dsr-export-test']);
        $response = $this->postJson('/api/v1/operator/dsr/export', $body)
            ->assertOk()
            ->assertJsonPath('schema', 'iicp.dsr.export.v1')
            ->assertJsonPath('tracking_id', 'dsr-export-test');
        $raw = $response->getContent();
        $this->assertStringNotContainsString($this->pub, $raw);
        $this->assertStringNotContainsString($body['nonce'], $raw);
        $this->assertStringNotContainsString($body['sig'], $raw);
    }

    public function test_restrict_requires_explicit_confirmation(): void
    {
        $body = $this->signedBody('dsr_restrict', ['tracking_id' => 'dsr-restrict-test']);
        $this->postJson('/api/v1/operator/dsr/restrict', $body)->assertUnprocessable();

        $body = $this->signedBody('dsr_restrict', ['tracking_id' => 'dsr-restrict-test', 'confirm' => true]);
        $this->postJson('/api/v1/operator/dsr/restrict', $body)
            ->assertOk()
            ->assertJsonPath('action', 'restrict');
        $this->assertSame(['dsr' => 'restricted'], Operator::where('operator_pubkey', $this->pub)->value('provenance'));
    }

    public function test_duplicate_dsr_tracking_id_is_a_conflict_and_rolls_back(): void
    {
        DataSubjectAction::create([
            'tracking_id' => 'dsr-duplicate-test',
            'action' => 'restrict',
            'subject_hash' => hash('sha256', 'prior-subject'),
            'selector' => ['node_id' => 'redacted'],
        ]);

        $body = $this->signedBody('dsr_restrict', [
            'tracking_id' => 'dsr-duplicate-test',
            'confirm' => true,
        ]);
        $this->postJson('/api/v1/operator/dsr/restrict', $body)
            ->assertConflict()
            ->assertJsonPath('error.code', 'IICP-E060');

        $operator = Operator::where('operator_pubkey', $this->pub)->firstOrFail();
        $this->assertSame(Operator::IDENTITY_ACTIVE, $operator->identity_status);
        $this->assertNull($operator->provenance);
    }

    public function test_anonymize_removes_operator_identity(): void
    {
        $body = $this->signedBody('dsr_anonymize', ['tracking_id' => 'dsr-anonymize-test', 'confirm' => true]);
        $this->postJson('/api/v1/operator/dsr/anonymize', $body)
            ->assertOk()
            ->assertJsonPath('action', 'anonymize');
        $this->assertDatabaseMissing('operators', ['operator_pubkey' => $this->pub]);
    }

    public function test_dual_signed_rotation_preserves_node_and_recognition_continuity_without_exposing_keys(): void
    {
        $old = Operator::where('operator_pubkey', $this->pub)->firstOrFail();
        $old->update(['ordinal' => 3, 'tier' => 'founder', 'badge' => 'early']);
        $node = $this->nodeForOperator($this->pub, true);
        $next = sodium_crypto_sign_keypair();
        $nextPub = base64_encode(sodium_crypto_sign_publickey($next));
        $nonce = $this->challenge();
        $body = [
            'operator_pub' => $this->pub,
            'new_operator_pub' => $nextPub,
            'nonce' => $nonce,
            'ts' => time(),
            'rotation_epoch' => 7,
            'reason_class' => 'operator_rotation',
        ];
        $body['sig'] = base64_encode(sodium_crypto_sign_detached(
            OperatorSelfServiceController::canonicalBytes('key_rotate', $body), $this->secret,
        ));
        $body['new_key_sig'] = base64_encode(sodium_crypto_sign_detached(
            OperatorSelfServiceController::canonicalBytes('key_rotate_successor', [
                'operator_pub' => $this->pub,
                'new_operator_pub' => $nextPub,
                'nonce' => $nonce,
                'ts' => $body['ts'],
                'rotation_epoch' => 7,
            ]), sodium_crypto_sign_secretkey($next),
        ));

        $response = $this->postJson('/api/v1/operator/key/rotate', $body)
            ->assertOk()
            ->assertJsonPath('status', 'rotated')
            ->assertJsonPath('linked_nodes', 1)
            ->assertJsonPath('rotation_epoch', 7);
        $this->assertStringNotContainsString($this->pub, $response->getContent());
        $this->assertStringNotContainsString($nextPub, $response->getContent());

        $this->assertSame(Operator::IDENTITY_ROTATED, $old->fresh()->identity_status);
        $successor = Operator::where('operator_pubkey', $nextPub)->firstOrFail();
        $this->assertSame(Operator::IDENTITY_ACTIVE, $successor->identity_status);
        $this->assertSame(3, $successor->ordinal);
        $this->assertSame($nextPub, $node->fresh()->operator_pubkey);
        $this->assertTrue((bool) $node->fresh()->operator_verified);
        $this->assertDatabaseHas('policy_key_lifecycle_records', [
            'policy_key_sha256' => hash('sha256', base64_decode($this->pub, true)),
            'status' => PolicyKeyLifecycleRecord::STATUS_SUPERSEDED,
            'rotation_epoch' => 7,
        ]);
    }

    public function test_revoke_fails_closed_for_node_bindings_and_policy_key_but_retains_node_record(): void
    {
        $node = $this->nodeForOperator($this->pub, true);
        $body = $this->signedBody('key_revoke', ['confirm' => true, 'reason_class' => 'compromise']);
        $response = $this->postJson('/api/v1/operator/key/revoke', $body)
            ->assertOk()
            ->assertJsonPath('status', 'revoked')
            ->assertJsonPath('linked_nodes', 1);
        $this->assertStringNotContainsString($this->pub, $response->getContent());
        $this->assertSame(Operator::IDENTITY_REVOKED, Operator::where('operator_pubkey', $this->pub)->value('identity_status'));
        $this->assertFalse((bool) $node->fresh()->operator_verified);
        $this->assertSame(PolicyKeyLifecycleRecord::STATUS_REVOKED, PolicyKeyLifecycleRecord::query()
            ->where('policy_key_sha256', hash('sha256', base64_decode($this->pub, true)))->value('status'));

        $this->postJson('/api/v1/operator/challenge', ['operator_pub' => $this->pub])
            ->assertStatus(409)->assertJsonPath('error.code', 'IICP-E063');
    }

    public function test_rotation_rejects_missing_successor_proof_without_creating_successor(): void
    {
        $next = sodium_crypto_sign_keypair();
        $nextPub = base64_encode(sodium_crypto_sign_publickey($next));
        $body = $this->signedBody('key_rotate', [
            'new_operator_pub' => $nextPub,
            'new_key_sig' => base64_encode(random_bytes(64)),
        ]);
        $this->postJson('/api/v1/operator/key/rotate', $body)
            ->assertUnauthorized()->assertJsonPath('error.code', 'IICP-E064');
        $this->assertDatabaseMissing('operators', ['operator_pubkey' => $nextPub]);
        $this->assertSame(Operator::IDENTITY_ACTIVE, Operator::where('operator_pubkey', $this->pub)->value('identity_status'));
    }

    private function nodeForOperator(string $operatorPub, bool $verified): Node
    {
        return Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.test',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 1,
            'tokens_per_min' => 1000,
            'available' => true,
            'status' => 'active',
            'operator_pubkey' => $operatorPub,
            'operator_verified' => $verified,
            'operator_trust_tier' => $verified ? 'did_key' : null,
        ]);
    }
}
