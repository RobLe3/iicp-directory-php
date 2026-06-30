<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\TelemetryProbe;
use App\Services\NodeEventLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * #508 — GET /v1/compliance-attestation (spec/iicp-dir.md §12).
 *
 * The attestation must be Ed25519-verifiable against the genesis public key,
 * fail closed without a key, and reflect ONLY the most recent probe run.
 */
class ComplianceAttestationTest extends TestCase
{
    use RefreshDatabase;

    private string $secretHex;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        // The endpoint caches for 60s; the array store persists across tests
        // in one process, so flush to keep each test's run isolation honest.
        Cache::forget('compliance.attestation');
        $kp = sodium_crypto_sign_keypair();
        $this->secretHex = bin2hex(sodium_crypto_sign_secretkey($kp));
        $this->publicKey = sodium_crypto_sign_publickey($kp);
    }

    private function seedProbe(string $runId, string $testId, bool $passed, int $minutesAgo = 0): void
    {
        TelemetryProbe::create([
            'probe_token_id' => null,
            'node_id' => null,
            'run_id' => $runId,
            'probe_id' => 'reach',
            'probe_type' => 'conformance',
            'test_id' => $testId,
            'level' => 'MUST',
            'passed' => $passed,
            'latency_ms' => 12,
            'detail' => 'test',
            'probed_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_fails_closed_without_signing_key(): void
    {
        config(['app.genesis_ed25519_secret_key' => null]);
        $this->seedProbe('run-1', 'DIR-DISC-01', true);

        $r = $this->getJson('/api/v1/compliance-attestation');

        $r->assertStatus(503)->assertJsonPath('error.code', 'attestation_unavailable');
    }

    public function test_503_when_no_probe_run_recorded(): void
    {
        config(['app.genesis_ed25519_secret_key' => $this->secretHex]);

        $r = $this->getJson('/api/v1/compliance-attestation');

        $r->assertStatus(503)->assertJsonPath('error.code', 'no_probe_data');
    }

    /**
     * Behavior test: the signature must verify against the genesis public key
     * over the canonical attestation document. Fails if signing input, field
     * set, or canonicalization rule drifts from spec §12.
     */
    public function test_attestation_signature_verifies(): void
    {
        config(['app.genesis_ed25519_secret_key' => $this->secretHex]);
        $this->seedProbe('run-1', 'DIR-DISC-01', true);
        $this->seedProbe('run-1', 'DIR-TRUST-01', false);

        $r = $this->getJson('/api/v1/compliance-attestation');
        $r->assertOk();
        $body = $r->json();

        $this->assertSame(['DIR-DISC-01'], $body['passed_probes']);
        $this->assertSame(['DIR-TRUST-01'], $body['failed_probes']);
        $this->assertSame('compliance-attestation', $body['purpose']);
        $this->assertSame('did:web:iicp.network', $body['signer_did']);

        // Recompute the canonical document exactly as an external verifier would:
        // every field EXCEPT attestation_hash / signature / signer_did.
        $document = array_diff_key($body, array_flip(['attestation_hash', 'signature', 'signer_did']));
        $canonical = NodeEventLogger::canonicalJson($document);

        $this->assertSame(hash('sha256', $canonical), $body['attestation_hash']);
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            sodium_hex2bin($body['signature']),
            hash('sha256', $canonical, true),
            $this->publicKey,
        ), 'attestation signature must verify against the genesis public key');
    }

    /** Only the most recent run is attested — stale runs must not leak in. */
    public function test_attests_latest_run_only(): void
    {
        config(['app.genesis_ed25519_secret_key' => $this->secretHex]);
        $this->seedProbe('run-old', 'DIR-OLD-99', true, minutesAgo: 30);
        $this->seedProbe('run-new', 'DIR-DISC-01', true, minutesAgo: 1);

        $body = $this->getJson('/api/v1/compliance-attestation')->assertOk()->json();

        $this->assertSame('run-new', $body['probe_run_id']);
        $this->assertSame(['DIR-DISC-01'], $body['passed_probes']);
        $this->assertNotContains('DIR-OLD-99', $body['passed_probes']);
    }

    /** valid_until is 15 minutes after generated_at (#508 Q3 freshness window). */
    public function test_validity_window_is_15_minutes(): void
    {
        config(['app.genesis_ed25519_secret_key' => $this->secretHex]);
        $this->seedProbe('run-1', 'DIR-DISC-01', true);

        $body = $this->getJson('/api/v1/compliance-attestation')->assertOk()->json();

        $delta = strtotime($body['valid_until']) - strtotime($body['generated_at']);
        $this->assertSame(900, $delta);
    }
}
