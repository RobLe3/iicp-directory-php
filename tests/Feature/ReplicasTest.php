<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Replica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /v1/replicas/register — Phase 6 replica registration handshake
 * (S.13 §7.1, DIR-FED-11..14).
 *
 * These tests cover the validation-rejection paths that return BEFORE any
 * external network call (DID resolution + endpoint reachability). The
 * happy path (DID actually resolves + endpoint actually returns 200) is
 * exercised by P6-3.1 end-to-end federation integration test, where a real
 * testbed replica boots and registers.
 */
class ReplicasTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload = [
        'did' => 'did:web:replica.example.com',
        'endpoint' => 'https://replica.example.com',
    ];

    public function test_register_requires_did(): void
    {
        $payload = $this->validPayload;
        unset($payload['did']);
        $response = $this->postJson('/api/v1/replicas/register', $payload);
        $response->assertStatus(422);
    }

    public function test_register_requires_endpoint(): void
    {
        $payload = $this->validPayload;
        unset($payload['endpoint']);
        $response = $this->postJson('/api/v1/replicas/register', $payload);
        $response->assertStatus(422);
    }

    public function test_register_rejects_invalid_did_format(): void
    {
        $response = $this->postJson('/api/v1/replicas/register', [
            'did' => 'did:key:z6MkpTHR8VNsBxYAAW',  // valid DID shape but not did:web
            'endpoint' => 'https://replica.example.com',
        ]);
        // Validation regex restricts to did:web:<domain>
        $response->assertStatus(422);
    }

    public function test_register_rejects_non_https_endpoint(): void
    {
        $response = $this->postJson('/api/v1/replicas/register', [
            'did' => 'did:web:replica.example.com',
            'endpoint' => 'http://replica.example.com',
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E043');
    }

    public function test_register_rejects_localhost_endpoint(): void
    {
        $response = $this->postJson('/api/v1/replicas/register', [
            'did' => 'did:web:replica.example.com',
            'endpoint' => 'https://localhost',
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E043');
    }

    public function test_register_rejects_private_ipv4_endpoint(): void
    {
        $response = $this->postJson('/api/v1/replicas/register', [
            'did' => 'did:web:replica.example.com',
            'endpoint' => 'https://10.0.0.1',
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E043');
    }

    /*
     * P6-3.1c — IICP_DEV_ALLOW_HTTP_DID extends the testbed escape hatch to
     * the seed-side validateEndpointScheme. Hard-gated to non-production.
     */
    public function test_register_accepts_http_endpoint_with_dev_flag_in_non_production(): void
    {
        config(['app.env' => 'local']);
        putenv('IICP_DEV_ALLOW_HTTP_DID=true');
        try {
            // We expect to reach validateDidDocument (which will still fail
            // because did:web:replica-directory does not resolve in the test
            // environment) — but the SCHEME check must NOT short-circuit to
            // IICP-E043 anymore.
            $response = $this->postJson('/api/v1/replicas/register', [
                'did' => 'did:web:replica.example.com',
                'endpoint' => 'http://replica-directory:8080',
            ]);
            $code = $response->json('error.code');
            $this->assertNotSame(
                'IICP-E043',
                $code,
                'dev flag must let http:// pass the scheme check in local env',
            );
        } finally {
            putenv('IICP_DEV_ALLOW_HTTP_DID');
        }
    }

    public function test_register_rejects_http_endpoint_with_dev_flag_in_production(): void
    {
        config(['app.env' => 'production']);
        putenv('IICP_DEV_ALLOW_HTTP_DID=true');
        try {
            $response = $this->postJson('/api/v1/replicas/register', [
                'did' => 'did:web:replica.example.com',
                'endpoint' => 'http://replica-directory:8080',
            ]);
            $response->assertStatus(422)
                ->assertJsonPath('error.code', 'IICP-E043');
        } finally {
            putenv('IICP_DEV_ALLOW_HTTP_DID');
            config(['app.env' => 'testing']);
        }
    }

    /*
     * P6-3.1d — did:web port encoding. did:web:host%3A8080 must pass the
     * input-validation regex and reach validateDidDocument. The DID document
     * fetch will still fail in the unit-test environment (no DNS), but the
     * VALIDATION layer must not be the gatekeeper.
     */
    public function test_register_accepts_did_with_percent_encoded_port(): void
    {
        config(['app.env' => 'local']);
        putenv('IICP_DEV_ALLOW_HTTP_DID=true');
        try {
            $response = $this->postJson('/api/v1/replicas/register', [
                'did' => 'did:web:replica-directory%3A8080',
                'endpoint' => 'http://replica-directory:8080',
            ]);
            $code = $response->json('error.code');
            // Should NOT be a Laravel validation error (422 on `did` regex fail)
            // — the regex now accepts the port-encoded form. Expected error is
            // IICP-E040 (DID document not resolvable from the test runner).
            $this->assertNotNull($code, 'expected an IICP-Exxx error code');
            $this->assertStringStartsWith(
                'IICP-E',
                $code,
                "got {$code} — did regex should now accept %3A8080 port suffix",
            );
        } finally {
            putenv('IICP_DEV_ALLOW_HTTP_DID');
        }
    }

    public function test_register_rejects_malformed_did_port(): void
    {
        // Trailing port without %3A separator → still a regex failure.
        $response = $this->postJson('/api/v1/replicas/register', [
            'did' => 'did:web:replica-directory:8080',
            'endpoint' => 'https://replica.test',
        ]);
        $response->assertStatus(422); // Laravel validation rejects malformed regex
    }

    public function test_register_rejects_192_168_block(): void
    {
        $response = $this->postJson('/api/v1/replicas/register', [
            'did' => 'did:web:replica.example.com',
            'endpoint' => 'https://192.168.1.1',
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E043');
    }

    public function test_register_rejects_loopback_127_block(): void
    {
        $response = $this->postJson('/api/v1/replicas/register', [
            'did' => 'did:web:replica.example.com',
            'endpoint' => 'https://127.0.0.1',
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E043');
    }

    public function test_register_rejects_172_16_block(): void
    {
        $response = $this->postJson('/api/v1/replicas/register', [
            'did' => 'did:web:replica.example.com',
            'endpoint' => 'https://172.16.0.1',
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E043');
    }

    public function test_register_rejects_link_local_169_254(): void
    {
        $response = $this->postJson('/api/v1/replicas/register', [
            'did' => 'did:web:replica.example.com',
            'endpoint' => 'https://169.254.169.254',  // AWS metadata IP — common SSRF target
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E043');
    }

    public function test_register_rejects_did_with_private_domain_target(): void
    {
        // did:web:<domain> where domain resolves to a private IP must also be rejected
        // (extension of SSRF guard to the DID-resolution path)
        $response = $this->postJson('/api/v1/replicas/register', [
            'did' => 'did:web:127.0.0.1',
            'endpoint' => 'https://public-replica.example.com',
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'IICP-E040');
    }

    public function test_register_rejects_invalid_trust_tier(): void
    {
        $response = $this->postJson('/api/v1/replicas/register', [
            'did' => 'did:web:replica.example.com',
            'endpoint' => 'https://replica.example.com',
            'trust_tier_request' => 'godmode',
        ]);
        $response->assertStatus(422);  // Laravel `in:` validation triggers 422
    }

    public function test_register_endpoint_exists_and_is_routed(): void
    {
        // Smoke: just confirm the route is wired. Any 422 (validation) or 5xx is fine
        // as long as it's not 404 (route missing) or 405 (method not allowed).
        $response = $this->postJson('/api/v1/replicas/register', []);
        $this->assertNotEquals(404, $response->status());
        $this->assertNotEquals(405, $response->status());
    }
}
