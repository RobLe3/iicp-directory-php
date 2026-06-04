<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Services\OperatorDelegationVerifier;
use PHPUnit\Framework\TestCase;

/**
 * ADR-045 Phase A (#407) — operator→node delegation verifier.
 * Mirrors the research #406 prototype's 6-case attack matrix; each case must
 * fail closed with the correct reason. A regression here is a security hole.
 */
class OperatorDelegationVerifierTest extends TestCase
{
    private OperatorDelegationVerifier $v;

    /** @var array{0:string,1:string} [base64 pubkey, raw secret] */
    private array $op;

    protected function setUp(): void
    {
        parent::setUp();
        $this->v = new OperatorDelegationVerifier;
        $kp = sodium_crypto_sign_keypair();
        $this->op = [
            base64_encode(sodium_crypto_sign_publickey($kp)),
            sodium_crypto_sign_secretkey($kp),
        ];
    }

    /** Issue a signed delegation token (the operator/wallet side). */
    private function issue(string $nodeId, int $notAfter, ?string $secret = null, ?string $pubB64 = null): array
    {
        $pubB64 ??= $this->op[0];
        $secret ??= $this->op[1];
        $msg = OperatorDelegationVerifier::canonicalBytes($nodeId, $pubB64, $notAfter);

        return [
            'node_id' => $nodeId,
            'operator_pub' => $pubB64,
            'not_after' => $notAfter,
            'sig' => base64_encode(sodium_crypto_sign_detached($msg, $secret)),
        ];
    }

    public function test_valid_delegation_verifies(): void
    {
        $tok = $this->issue('node-1', time() + 3600);
        $this->assertSame([true, 'ok'], $this->v->verify($tok, 'node-1', [$this->op[0]]));
    }

    public function test_expired_rejected(): void
    {
        $tok = $this->issue('node-1', time() - 1);
        $this->assertSame([false, 'expired'], $this->v->verify($tok, 'node-1', [$this->op[0]]));
    }

    public function test_revoked_rejected(): void
    {
        $tok = $this->issue('node-1', time() + 3600);
        $this->assertSame([false, 'revoked'], $this->v->verify($tok, 'node-1', [$this->op[0]], ['node-1']));
    }

    public function test_node_id_mismatch_rejected(): void
    {
        $tok = $this->issue('node-1', time() + 3600);
        $this->assertSame([false, 'node_id_mismatch'], $this->v->verify($tok, 'node-evil', [$this->op[0]]));
    }

    public function test_tampered_token_rejected(): void
    {
        // Sign for node-1, then swap the node_id (claim must match the tampered id,
        // so node_id check passes) → signature no longer covers the bytes.
        $tok = $this->issue('node-1', time() + 3600);
        $tok['node_id'] = 'node-evil';
        $this->assertSame([false, 'bad_signature'], $this->v->verify($tok, 'node-evil', [$this->op[0]]));
    }

    public function test_rogue_operator_not_in_trusted_set_rejected(): void
    {
        $rogue = sodium_crypto_sign_keypair();
        $tok = $this->issue(
            'node-1', time() + 3600,
            sodium_crypto_sign_secretkey($rogue),
            base64_encode(sodium_crypto_sign_publickey($rogue)),
        );
        // Directory trusts only the legit operator → rogue pubkey untrusted.
        $this->assertSame([false, 'untrusted_operator'], $this->v->verify($tok, 'node-1', [$this->op[0]]));
    }

    public function test_malformed_token_rejected(): void
    {
        $this->assertSame([false, 'malformed'], $this->v->verify(
            ['node_id' => 'node-1'], 'node-1', [$this->op[0]]
        ));
    }
}
