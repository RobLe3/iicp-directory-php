<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Services\NodePolicyManifestVerifier;
use PHPUnit\Framework\TestCase;

class NodePolicyManifestVerifierTest extends TestCase
{
    public function test_cross_sdk_known_answer_manifest_verifies(): void
    {
        // Shared by the Python, TypeScript and Rust producer tests. A fixed
        // seed/time makes canonicalization or wire-format drift visible.
        $manifest = [
            'version' => '1',
            'jurisdiction' => 'DE',
            'retention' => ['task_payload' => 'none'],
            'signature' => [
                'algorithm' => 'Ed25519',
                'key_id' => 'fe812c12f3ab',
                'public_key' => '6kpsY+KcUgq+9VB7Ey7F+ZVHdq6+vnuSQh7qaRRG0iw=',
                'signed_at' => '2026-07-10T00:00:00Z',
                'expires_at' => '2026-10-08T00:00:00Z',
                'signature' => 'Horps0SnJ4lenW97Z/vAEEihQ4/ICfBFo//uF4r808FuZzopAXzz2V3vgFXarl1FdPMXwndIo/7qP2/aXMZrAw==',
            ],
        ];

        $verification = NodePolicyManifestVerifier::verify($manifest);

        $this->assertSame(NodePolicyManifestVerifier::STATUS_SIGNED_VALID, $verification['status']);
        $this->assertSame('fe812c12f3ab', $verification['policy_key_fingerprint']);
    }

    public function test_signed_manifest_reports_signed_valid_identity_level(): void
    {
        [$manifest] = $this->signedPolicyManifest();

        $verification = NodePolicyManifestVerifier::verify($manifest);

        $this->assertSame(NodePolicyManifestVerifier::STATUS_SIGNED_VALID, $verification['status']);
        $this->assertSame(NodePolicyManifestVerifier::IDENTITY_SIGNED_VALID, $verification['manifest_identity_level']);
        $this->assertNotNull($verification['policy_key_fingerprint']);
        $this->assertNull($verification['operator_fingerprint']);
    }

    public function test_signed_manifest_becomes_operator_bound_when_key_matches_verified_operator(): void
    {
        [$manifest, $publicKey] = $this->signedPolicyManifest();

        $verification = NodePolicyManifestVerifier::verify($manifest, [
            'operator_pubkey' => $publicKey,
            'operator_verified' => true,
            'operator_trust_tier' => 'did_key',
        ]);

        $this->assertSame(NodePolicyManifestVerifier::STATUS_SIGNED_VALID, $verification['status']);
        $this->assertSame(NodePolicyManifestVerifier::IDENTITY_OPERATOR_BOUND, $verification['manifest_identity_level']);
        $this->assertSame(substr(hash('sha256', $publicKey), 0, 12), $verification['operator_fingerprint']);
    }

    public function test_verified_operator_mismatch_does_not_overclaim_identity_binding(): void
    {
        [$manifest] = $this->signedPolicyManifest();
        $otherKeypair = sodium_crypto_sign_keypair();
        $otherPublicKey = base64_encode(sodium_crypto_sign_publickey($otherKeypair));

        $verification = NodePolicyManifestVerifier::verify($manifest, [
            'operator_pubkey' => $otherPublicKey,
            'operator_verified' => true,
            'operator_trust_tier' => 'did_key',
        ]);

        $this->assertSame(NodePolicyManifestVerifier::STATUS_SIGNED_VALID, $verification['status']);
        $this->assertSame(NodePolicyManifestVerifier::IDENTITY_SIGNED_VALID, $verification['manifest_identity_level']);
        $this->assertNull($verification['operator_fingerprint']);
    }

    public function test_revoked_signed_manifest_fails_closed_with_safe_metadata(): void
    {
        [$manifest] = $this->signedPolicyManifest([
            'revoked_at' => gmdate(DATE_ATOM, time() - 60),
            'revocation_reason_class' => 'operator_request',
            'rotation_epoch' => 2,
        ]);

        $verification = NodePolicyManifestVerifier::verify($manifest);

        $this->assertSame(NodePolicyManifestVerifier::STATUS_SIGNED_REVOKED, $verification['status']);
        $this->assertSame(NodePolicyManifestVerifier::IDENTITY_REVOKED, $verification['manifest_identity_level']);
        $this->assertSame('operator_request', $verification['revocation_reason_class']);
        $this->assertSame(2, $verification['rotation_epoch']);
        $this->assertNotNull($verification['revoked_at']);
    }

    /**
     * @param  array<string,mixed>  $signatureOverrides
     * @return array{0: array<string,mixed>, 1: string}
     */
    private function signedPolicyManifest(array $signatureOverrides = []): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKeyB64 = base64_encode($publicKey);
        $manifest = [
            'version' => '2026-07-02',
            'jurisdiction' => 'DE',
            'remote_executor_can_read_prompt' => true,
            'training_use' => 'none',
            'retention' => ['task_payload' => 'none', 'logs_days' => 3],
            'subprocessors' => ['self-hosted'],
            'unsupported_intents' => [],
        ];
        $manifest['signature'] = array_merge([
            'algorithm' => 'Ed25519',
            'key_id' => 'policy-key-1',
            'public_key' => $publicKeyB64,
            'signed_at' => gmdate(DATE_ATOM, time() - 60),
            'expires_at' => gmdate(DATE_ATOM, time() + 86400),
        ], $signatureOverrides);
        $manifest['signature']['signature'] = base64_encode(sodium_crypto_sign_detached(
            NodePolicyManifestVerifier::canonicalPayload($manifest),
            $secretKey,
        ));

        return [$manifest, $publicKeyB64];
    }
}
