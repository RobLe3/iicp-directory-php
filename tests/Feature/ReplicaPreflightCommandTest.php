<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReplicaPreflightCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('IICP_REPLICA_MODE=false');
        putenv('IICP_SEED_URL');
        putenv('IICP_REPLICA_ED25519_SECRET_KEY');
    }

    protected function tearDown(): void
    {
        putenv('IICP_REPLICA_MODE=false');
        putenv('IICP_SEED_URL');
        putenv('IICP_REPLICA_ED25519_SECRET_KEY');
        parent::tearDown();
    }

    public function test_missing_required_options_returns_invalid(): void
    {
        $exit = $this->artisan('iicp:replica-preflight')->run();
        $this->assertSame(2, $exit);
    }

    public function test_fails_when_replica_mode_not_set(): void
    {
        Http::fake(); // not reached in this test
        $exit = $this->artisan('iicp:replica-preflight', [
            '--seed-url' => 'https://iicp.network',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'https://replica.test',
        ])->run();
        $this->assertSame(1, $exit);  // FAILURE — IICP_REPLICA_MODE not true
    }

    public function test_fails_when_secret_key_wrong_length(): void
    {
        putenv('IICP_REPLICA_MODE=true');
        putenv('IICP_SEED_URL=https://iicp.network');
        putenv('IICP_REPLICA_ED25519_SECRET_KEY=deadbeef');  // too short

        Http::fake([
            'https://replica.test/iicp/health' => Http::response('ok', 200),
            'https://iicp.network/api/v1/events*' => Http::response(['genesis_hash' => 'abc'.str_repeat('0', 61)], 200),
            'https://replica.test/.well-known/did.json' => Http::response(['verificationMethod' => []], 200),
        ]);

        $exit = $this->artisan('iicp:replica-preflight', [
            '--seed-url' => 'https://iicp.network',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'https://replica.test',
        ])->run();
        $this->assertSame(1, $exit);
    }

    public function test_fails_when_seed_unreachable(): void
    {
        $kp = sodium_crypto_sign_keypair();
        putenv('IICP_REPLICA_MODE=true');
        putenv('IICP_SEED_URL=https://iicp.network');
        putenv('IICP_REPLICA_ED25519_SECRET_KEY='.bin2hex(sodium_crypto_sign_secretkey($kp)));

        Http::fake([
            'https://iicp.network/api/v1/events*' => Http::response('upstream down', 503),
            'https://replica.test/iicp/health' => Http::response('ok', 200),
            'https://replica.test/.well-known/did.json' => Http::response(['verificationMethod' => []], 200),
        ]);

        $exit = $this->artisan('iicp:replica-preflight', [
            '--seed-url' => 'https://iicp.network',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'https://replica.test',
        ])->run();
        $this->assertSame(1, $exit);
    }

    public function test_fails_when_did_doc_key_mismatches_secret(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($kp);
        putenv('IICP_REPLICA_MODE=true');
        putenv('IICP_SEED_URL=https://iicp.network');
        putenv('IICP_REPLICA_ED25519_SECRET_KEY='.bin2hex($secret));

        // Publish a DID doc with a DIFFERENT public key
        $other_kp = sodium_crypto_sign_keypair();
        $other_pub = sodium_crypto_sign_publickey($other_kp);
        $other_b64 = rtrim(strtr(base64_encode($other_pub), '+/', '-_'), '=');

        Http::fake([
            'https://iicp.network/api/v1/events*' => Http::response(['genesis_hash' => 'abc'.str_repeat('0', 61)], 200),
            'https://replica.test/iicp/health' => Http::response('ok', 200),
            'https://replica.test/.well-known/did.json' => Http::response([
                'verificationMethod' => [['publicKeyJwk' => ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $other_b64]]],
            ], 200),
        ]);

        $exit = $this->artisan('iicp:replica-preflight', [
            '--seed-url' => 'https://iicp.network',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'https://replica.test',
        ])->run();
        $this->assertSame(1, $exit, 'public-key-mismatch must be a FAILURE not warning');
    }

    public function test_passes_when_everything_configured_correctly(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($kp);
        $pub = sodium_crypto_sign_publickey($kp);
        $pub_b64 = rtrim(strtr(base64_encode($pub), '+/', '-_'), '=');

        putenv('IICP_REPLICA_MODE=true');
        putenv('IICP_SEED_URL=https://iicp.network');
        putenv('IICP_REPLICA_ED25519_SECRET_KEY='.bin2hex($secret));

        Http::fake([
            'https://iicp.network/api/v1/events*' => Http::response([
                'genesis_hash' => 'abc'.str_repeat('0', 61),
                'events' => [],
            ], 200),
            'https://replica.test/iicp/health' => Http::response('ok', 200),
            'https://replica.test/.well-known/did.json' => Http::response([
                'verificationMethod' => [['publicKeyJwk' => ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $pub_b64]]],
            ], 200),
        ]);

        $exit = $this->artisan('iicp:replica-preflight', [
            '--seed-url' => 'https://iicp.network',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'https://replica.test',
        ])->run();
        $this->assertSame(0, $exit, 'fully-configured replica must pass preflight');
    }

    public function test_non_https_endpoint_is_failure(): void
    {
        $kp = sodium_crypto_sign_keypair();
        putenv('IICP_REPLICA_MODE=true');
        putenv('IICP_SEED_URL=https://iicp.network');
        putenv('IICP_REPLICA_ED25519_SECRET_KEY='.bin2hex(sodium_crypto_sign_secretkey($kp)));

        Http::fake([
            'https://iicp.network/api/v1/events*' => Http::response(['genesis_hash' => 'x'], 200),
        ]);

        $exit = $this->artisan('iicp:replica-preflight', [
            '--seed-url' => 'https://iicp.network',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'http://insecure.test',
        ])->run();
        $this->assertSame(1, $exit);
    }
}
