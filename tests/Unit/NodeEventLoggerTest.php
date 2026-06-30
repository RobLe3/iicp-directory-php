<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Models\Node;
use App\Services\NodeEventLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NodeEventLogger — Ed25519 signing and event structure tests.
 * Spec: spec/iicp-federated-directory.md §3.4
 */
class NodeEventLoggerTest extends TestCase
{
    use RefreshDatabase;

    private NodeEventLogger $logger;

    private string $nodeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = app(NodeEventLogger::class);
        $this->nodeId = (string) Str::uuid();

        Node::create([
            'id' => $this->nodeId,
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.1',
        ]);
    }

    public function test_log_creates_event_with_required_fields(): void
    {
        $event = $this->logger->log('REGISTER', $this->nodeId, ['endpoint' => 'https://node.test']);

        $this->assertNotEmpty($event->event_id);
        $this->assertSame(1, $event->seq);
        $this->assertSame('REGISTER', $event->event_type);
        $this->assertSame($this->nodeId, $event->node_id);
        $this->assertGreaterThan(0, $event->ts_ms);
        $this->assertSame(['endpoint' => 'https://node.test'], $event->payload);
    }

    public function test_seq_increments_monotonically(): void
    {
        $e1 = $this->logger->log('REGISTER', $this->nodeId, []);
        $e2 = $this->logger->log('HEARTBEAT', $this->nodeId, []);
        $e3 = $this->logger->log('DEREGISTER', $this->nodeId, []);

        $this->assertSame(1, $e1->seq);
        $this->assertSame(2, $e2->seq);
        $this->assertSame(3, $e3->seq);
    }

    public function test_signature_is_null_when_no_key_configured(): void
    {
        config(['app.genesis_ed25519_secret_key' => null]);
        $event = $this->logger->log('REGISTER', $this->nodeId, []);
        $this->assertNull($event->signature);
    }

    public function test_signature_is_null_when_key_wrong_length(): void
    {
        config(['app.genesis_ed25519_secret_key' => 'tooshort']);
        $event = $this->logger->log('REGISTER', $this->nodeId, []);
        $this->assertNull($event->signature);
    }

    public function test_ed25519_signature_is_produced_when_key_configured(): void
    {
        [$publicKey, $secretKey] = $this->generateKeypair();
        config(['app.genesis_ed25519_secret_key' => bin2hex($secretKey)]);

        $event = $this->logger->log('REGISTER', $this->nodeId, ['endpoint' => 'https://node.test']);

        $this->assertNotNull($event->signature);
        $this->assertSame(128, strlen($event->signature), '64-byte signature = 128 hex chars');
    }

    public function test_ed25519_signature_verifies_correctly(): void
    {
        [$publicKey, $secretKey] = $this->generateKeypair();
        config(['app.genesis_ed25519_secret_key' => bin2hex($secretKey)]);

        $payload = ['endpoint' => 'https://node.test', 'region' => 'eu-central'];
        $event = $this->logger->log('REGISTER', $this->nodeId, $payload);

        // Re-derive the signing input per spec §3.4 (no PRESERVE_ZERO_FRACTION — mirrors
        // the implementation, so a whole-number float survives the canonical round-trip).
        $canonical = json_encode(
            $this->sortedPayload($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $payloadHash = hash('sha256', $canonical);
        $message = hash('sha256',
            implode(':', [$event->event_id, $event->event_type, $event->seq, $event->ts_ms, $payloadHash, $event->prev_hash]),
            true
        );

        $valid = sodium_crypto_sign_verify_detached(
            sodium_hex2bin($event->signature),
            $message,
            $publicKey
        );

        $this->assertTrue($valid, 'Ed25519 signature must verify against genesis public key');
    }

    public function test_signature_verifies_after_wire_roundtrip(): void
    {
        // Regression (DIR-FED-01): a whole-number float in the payload must not break
        // verification after the storage/transmission round-trip. With
        // JSON_PRESERVE_ZERO_FRACTION the seed signed credit_cost_multiplier as "1.0" but
        // the wire form is integer 1 → canonical "1" → signature unverifiable. Dropping
        // the flag makes both sign-time and wire-time canonical forms identical ("1").
        [$publicKey, $secretKey] = $this->generateKeypair();
        config(['app.genesis_ed25519_secret_key' => bin2hex($secretKey)]);

        $payload = ['region' => 'eu', 'pricing' => ['credit_cost_multiplier' => 1.0]];
        $event = $this->logger->log('REGISTER', $this->nodeId, $payload);

        // Simulate exactly what /api/v1/events transmits: encode (no PRESERVE) → decode.
        // The float 1.0 becomes integer 1 here — the heart of the bug.
        $wirePayload = json_decode(json_encode($payload), true);

        $canonical = json_encode(
            $this->sortedPayload($wirePayload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $message = hash('sha256',
            implode(':', [$event->event_id, $event->event_type, $event->seq, $event->ts_ms, hash('sha256', $canonical), $event->prev_hash]),
            true
        );

        $this->assertTrue(
            sodium_crypto_sign_verify_detached(sodium_hex2bin($event->signature), $message, $publicKey),
            'signature must verify against the canonical form of the WIRE payload'
        );
    }

    public function test_prev_hash_chains_events_and_is_cross_impl_with_rust(): void
    {
        // #458: GENESIS_ROOT + prevHash() must equal iicp-directory-rs federation:: exactly.
        $this->assertSame(
            'c44802bedf3e63b5a3f1634c5d19263634f92f26dd15401b09b06dd53a80cf9d',
            NodeEventLogger::GENESIS_ROOT
        );
        $this->assertSame(NodeEventLogger::GENESIS_ROOT, $this->logger->prevHash(null));
        $this->assertSame(NodeEventLogger::GENESIS_ROOT, $this->logger->prevHash(''));
        $sig = str_repeat('ab', 64);
        $this->assertSame(hash('sha256', $sig), $this->logger->prevHash($sig));

        // The first signed event chains from GENESIS_ROOT; the next chains on its signature.
        [, $secretKey] = $this->generateKeypair();
        config(['app.genesis_ed25519_secret_key' => bin2hex($secretKey)]);
        $e1 = $this->logger->log('REGISTER', $this->nodeId, ['endpoint' => 'https://n.test']);
        $e2 = $this->logger->log('HEARTBEAT', $this->nodeId, []);
        $this->assertSame(NodeEventLogger::GENESIS_ROOT, $e1->prev_hash, 'first event chains from genesis');
        $this->assertSame(hash('sha256', $e1->signature), $e2->prev_hash, 'event #2 links to #1 signature');
    }

    public function test_kat_signature_matches_rust_byte_for_byte(): void
    {
        // #458 cross-impl pin: NodeEventLogger::sign over the fixed KAT event with
        // prev_hash = GENESIS_ROOT must produce the SAME signature as iicp-directory-rs
        // federation::sign_event (KAT_SIG) — proving a PHP replica can federate from a Rust
        // seed and vice-versa. Fixed libsodium key = seed(0x11*32) ‖ KAT pubkey.
        $key = str_repeat('11', 32).'d04ab232742bb4ab3a1368bd4615e4e6d0224ab71a016baf8520a332c9778737';
        config(['app.genesis_ed25519_secret_key' => $key]);

        $sign = (new \ReflectionMethod(NodeEventLogger::class, 'sign'));
        $sign->setAccessible(true);
        $sig = $sign->invoke(
            $this->logger,
            '474a7713-85c8-4d61-bea5-0ab16f3825a0',
            'REGISTER',
            1080,
            1779195794150,
            ['endpoint' => 'http://localhost:8090', 'region' => 'eu-central'],
            NodeEventLogger::GENESIS_ROOT,
        );

        $this->assertSame(
            'dab46d8578fd741b4d6109351968dacc7560caf78cd0df7e9573c3a0537acdbe6f36be9e7280a60ea49dcc8020470471b1f01828e1ab5c820762d08849032a03',
            $sig,
            'PHP libsodium signature must match the Rust ed25519-compact KAT byte-for-byte'
        );
    }

    public function test_different_events_produce_different_signatures(): void
    {
        [$publicKey, $secretKey] = $this->generateKeypair();
        config(['app.genesis_ed25519_secret_key' => bin2hex($secretKey)]);

        $e1 = $this->logger->log('REGISTER', $this->nodeId, ['x' => 1]);
        $e2 = $this->logger->log('HEARTBEAT', $this->nodeId, ['x' => 1]);

        $this->assertNotSame($e1->signature, $e2->signature);
    }

    /** Returns [$publicKey, $secretKey] — sodium full secret key (64 bytes). */
    private function generateKeypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        return [$publicKey, $secretKey];
    }

    /** Recursively sort array keys (mirrors NodeEventLogger::canonicalJson). */
    private function sortedPayload(array $data): array
    {
        ksort($data);
        foreach ($data as &$v) {
            if (is_array($v)) {
                $v = $this->sortedPayload($v);
            }
        }

        return $data;
    }
}
