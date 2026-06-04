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

        // Re-derive the signing input per spec §3.4
        $canonical = json_encode(
            $this->sortedPayload($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        $payloadHash = hash('sha256', $canonical);
        $message = hash('sha256',
            implode(':', [$event->event_id, $event->event_type, $event->seq, $event->ts_ms, $payloadHash]),
            true
        );

        $valid = sodium_crypto_sign_verify_detached(
            sodium_hex2bin($event->signature),
            $message,
            $publicKey
        );

        $this->assertTrue($valid, 'Ed25519 signature must verify against genesis public key');
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
