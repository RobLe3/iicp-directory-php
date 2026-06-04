<?php

namespace Tests\Feature;

use App\Services\ReplicaEventApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P6-1.3b-iv — Ed25519 signature verification path.
 *
 * Verifies the applier rejects tampered events when given a verifyKey, and
 * gracefully accepts unsigned events (sig=null) for back-compat with Phase
 * 6A events emitted before the genesis key was provisioned.
 */
class ReplicaEventApplierSigVerifyTest extends TestCase
{
    use RefreshDatabase;

    private ReplicaEventApplier $applier;

    private string $secretKey;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applier = new ReplicaEventApplier;
        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
    }

    private function signedEvent(array $overrides = []): array
    {
        $ev = array_merge([
            'event_id' => 'evt-'.str_repeat('a', 32),
            'seq' => 1,
            'event_type' => 'REGISTER',
            'node_id' => '550e8400-e29b-41d4-a716-446655440001',
            'ts_ms' => 1700000000000,
            'payload' => ['endpoint' => 'https://n.test', 'region' => 'eu'],
        ], $overrides);

        $payloadHash = hash('sha256', $this->canonicalJson($ev['payload']));
        $signingInput = implode(':', [
            $ev['event_id'], $ev['event_type'], (string) $ev['seq'], (string) $ev['ts_ms'], $payloadHash,
        ]);
        $message = hash('sha256', $signingInput, true);
        $ev['sig'] = bin2hex(sodium_crypto_sign_detached($message, $this->secretKey));

        return $ev;
    }

    private function canonicalJson(array $data): string
    {
        ksort($data);
        foreach ($data as &$v) {
            if (is_array($v)) {
                $v = json_decode($this->canonicalJson($v), true);
            }
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function test_valid_signature_accepts_event(): void
    {
        $r = $this->applier->apply($this->signedEvent(), $this->publicKey);
        $this->assertSame(ReplicaEventApplier::RESULT_APPLIED, $r['status'], 'valid sig + valid payload → applied');
    }

    public function test_invalid_signature_rejects_event(): void
    {
        $ev = $this->signedEvent();
        // #329 root-cause fix (iter-1458): XOR the last hex digit with 0xF so
        // the mutation is GUARANTEED to flip a bit regardless of RNG state.
        // The previous `substr($sig, 0, -2) . '00'` was a no-op ~1/256 runs
        // when the random sig already ended in '00' — caused intermittent
        // 'applied' instead of 'rejected' in the full test suite (iter-1377
        // CORC sweep observed it).
        $hex = $ev['sig'];
        $flipped = dechex(hexdec($hex[-1]) ^ 0xF);
        $ev['sig'] = substr($hex, 0, -1).$flipped;

        $r = $this->applier->apply($ev, $this->publicKey);
        $this->assertSame(ReplicaEventApplier::RESULT_REJECTED, $r['status']);
        $this->assertStringContainsString('signature verification failed', $r['detail']);
    }

    public function test_tampered_payload_rejects_event(): void
    {
        $ev = $this->signedEvent();
        // Mutate payload after signing
        $ev['payload']['endpoint'] = 'https://attacker.test';

        $r = $this->applier->apply($ev, $this->publicKey);
        $this->assertSame(ReplicaEventApplier::RESULT_REJECTED, $r['status']);
    }

    public function test_wrong_pubkey_rejects_event(): void
    {
        $otherKey = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $r = $this->applier->apply($this->signedEvent(), $otherKey);
        $this->assertSame(ReplicaEventApplier::RESULT_REJECTED, $r['status']);
    }

    public function test_null_sig_passes_through_with_verify_key(): void
    {
        $ev = $this->signedEvent();
        $ev['sig'] = null;

        $r = $this->applier->apply($ev, $this->publicKey);
        // Phase 6B opt-in: unsigned events still apply (back-compat with pre-genesis-key history)
        $this->assertSame(ReplicaEventApplier::RESULT_APPLIED, $r['status']);
    }

    public function test_malformed_sig_rejects(): void
    {
        $ev = $this->signedEvent();
        $ev['sig'] = 'not-hex-and-wrong-length';

        $r = $this->applier->apply($ev, $this->publicKey);
        $this->assertSame(ReplicaEventApplier::RESULT_REJECTED, $r['status']);
    }

    public function test_no_verify_key_skips_sig_check(): void
    {
        // verifyKey=null → applier trusts the caller; sig field ignored.
        $ev = $this->signedEvent();
        $ev['sig'] = 'deadbeef'; // would fail verification if checked

        $r = $this->applier->apply($ev, null);
        $this->assertSame(ReplicaEventApplier::RESULT_APPLIED, $r['status']);
    }
}
