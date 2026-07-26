<?php

namespace Tests\Feature;

use App\Models\NodeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignReplicaResponseTest extends TestCase
{
    use RefreshDatabase;

    private string $secretHex;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        // Generate a real Ed25519 keypair so the sig can be verified
        $kp = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($kp);
        $this->publicKey = sodium_crypto_sign_publickey($kp);
        $this->secretHex = bin2hex($secret);

        config(['iicp.replica.enabled' => false]);
        config(['iicp.replica.seed_url' => '']);
        putenv('IICP_REPLICA_ED25519_SECRET_KEY');
    }

    protected function tearDown(): void
    {
        config(['iicp.replica.enabled' => false]);
        config(['iicp.replica.seed_url' => '']);
        putenv('IICP_REPLICA_ED25519_SECRET_KEY');
        parent::tearDown();
    }

    public function test_seed_mode_omits_replica_sig_headers(): void
    {
        // IICP_REPLICA_MODE=false → middleware no-op
        $resp = $this->getJson('/api/v1/events');
        $this->assertNull($resp->headers->get('X-IICP-Replica-Sig'));
        $this->assertNull($resp->headers->get('X-IICP-Replica-DID'));
    }

    public function test_replica_mode_adds_sig_headers_on_discover(): void
    {
        config(['iicp.replica.enabled' => true]);
        config(['iicp.replica.seed_url' => 'https://iicp.network']);
        putenv('IICP_REPLICA_ED25519_SECRET_KEY='.$this->secretHex);

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');
        $resp->assertStatus(200);
        $this->assertNotNull($resp->headers->get('X-IICP-Replica-DID'));
        $this->assertNotNull($resp->headers->get('X-IICP-Replica-Sig'));
        $this->assertNotNull($resp->headers->get('X-IICP-Snapshot-Seq'));
        $this->assertSame(128, strlen($resp->headers->get('X-IICP-Replica-Sig')), 'sig is 128 hex chars');
        $this->assertTrue(ctype_xdigit($resp->headers->get('X-IICP-Replica-Sig')), 'sig is valid hex');
    }

    public function test_replica_mode_does_not_sign_non_discovery_paths(): void
    {
        config(['iicp.replica.enabled' => true]);
        config(['iicp.replica.seed_url' => 'https://iicp.network']);
        putenv('IICP_REPLICA_ED25519_SECRET_KEY='.$this->secretHex);

        // /v1/events is not a discovery endpoint — should NOT be signed
        $resp = $this->getJson('/api/v1/events');
        $this->assertNull($resp->headers->get('X-IICP-Replica-Sig'));
    }

    public function test_replica_mode_missing_key_returns_503(): void
    {
        config(['iicp.replica.enabled' => true]);
        config(['iicp.replica.seed_url' => 'https://iicp.network']);
        putenv('IICP_REPLICA_ED25519_SECRET_KEY');  // unset

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');
        $resp->assertStatus(503);
        $resp->assertJsonPath('error.code', 'IICP-E048');
    }

    public function test_replica_mode_wrong_length_key_returns_503(): void
    {
        config(['iicp.replica.enabled' => true]);
        config(['iicp.replica.seed_url' => 'https://iicp.network']);
        putenv('IICP_REPLICA_ED25519_SECRET_KEY=deadbeef');  // too short

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');
        $resp->assertStatus(503);
        $resp->assertJsonPath('error.code', 'IICP-E048');
    }

    public function test_replica_sig_verifies_against_canonical_input(): void
    {
        // Critical round-trip test: server signs, we verify with the same key.
        // Any divergence in the canonical signing input between PHP and the
        // documented spec/proxy verifier surfaces here.
        config(['iicp.replica.enabled' => true]);
        config(['iicp.replica.seed_url' => 'https://iicp.network']);
        putenv('IICP_REPLICA_ED25519_SECRET_KEY='.$this->secretHex);

        // Use a query with order that needs canonicalization (b before a)
        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&limit=5');
        $resp->assertStatus(200);

        $sigHex = $resp->headers->get('X-IICP-Replica-Sig');
        $snapshotSeq = (int) $resp->headers->get('X-IICP-Snapshot-Seq');
        $body = $resp->getContent();

        // Re-compute canonical input — must match what the middleware signed
        $pairs = [
            ['intent', 'urn:iicp:intent:llm:chat:v1'],
            ['limit', '5'],
        ];
        usort($pairs, fn ($a, $b) => $a[0] <=> $b[0]);
        $queryCanonical = implode('&', array_map(
            fn ($p) => rawurlencode($p[0]).'='.rawurlencode($p[1]),
            $pairs
        ));
        $bodyHash = hash('sha256', $body);
        $canonical = "GET:/api/v1/discover:{$queryCanonical}:{$snapshotSeq}:{$bodyHash}";
        $message = hash('sha256', $canonical, true);

        $valid = sodium_crypto_sign_verify_detached(
            sodium_hex2bin($sigHex), $message, $this->publicKey
        );
        $this->assertTrue($valid, 'Sig must verify against re-computed canonical input');
    }

    public function test_snapshot_seq_matches_max_event_seq(): void
    {
        config(['iicp.replica.enabled' => true]);
        config(['iicp.replica.seed_url' => 'https://iicp.network']);
        putenv('IICP_REPLICA_ED25519_SECRET_KEY='.$this->secretHex);

        // Seed the event log
        NodeEvent::create([
            'event_id' => 'evt-test-1',
            'seq' => 99,
            'event_type' => 'REGISTER',
            'node_id' => '550e8400-e29b-41d4-a716-446655440099',
            'ts_ms' => 1700000000000,
            'payload' => [],
        ]);

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');
        $resp->assertStatus(200);
        $this->assertSame('99', $resp->headers->get('X-IICP-Snapshot-Seq'),
            'X-IICP-Snapshot-Seq MUST equal max event.seq');
    }
}
