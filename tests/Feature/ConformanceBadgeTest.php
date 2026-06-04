<?php

namespace Tests\Feature;

use App\Models\ConformanceBadge;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConformanceBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function validBadge(array $overrides = []): array
    {
        return array_merge([
            'badge_id' => '550e8400-e29b-41d4-a716-446655440000',
            'tier' => 'iicp:core:v1',
            'subject_did' => 'did:web:node.example.com',
            'subject_component' => 'adapter',
            'suite_version' => '1.0.0',
            'passed_at' => '2026-05-18T00:00:00Z',
            'expires_at' => '2026-08-15T00:00:00Z',
            'test_results_url' => 'https://example.com/results.json',
            'issuer_did' => 'did:web:node.example.com',
            'verification_mode' => 'self-attested',
            'sig' => 'dGVzdHNpZ25hdHVyZXZhbHVl',
        ], $overrides);
    }

    // BADGE-SUBMIT-01: Valid self-attested badge is accepted
    public function test_submit_valid_self_attested_badge(): void
    {
        $response = $this->postJson('/api/v1/conformance/submit', $this->validBadge());

        $response->assertStatus(201)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('badge_id', '550e8400-e29b-41d4-a716-446655440000');

        $this->assertDatabaseHas('conformance_badges', [
            'badge_id' => '550e8400-e29b-41d4-a716-446655440000',
            'tier' => 'iicp:core:v1',
            'status' => 'active',
        ]);
    }

    // BADGE-SUBMIT-02: Genesis-verified mode returns 503 (not yet implemented)
    public function test_submit_genesis_verified_returns_503(): void
    {
        $badge = $this->validBadge([
            'verification_mode' => 'genesis-verified',
            'issuer_did' => 'did:web:iicp.network',
        ]);

        $response = $this->postJson('/api/v1/conformance/submit', $badge);

        $response->assertStatus(503)
            ->assertJsonPath('error', 'BADGE-GENESIS-UNAVAILABLE');
    }

    // BADGE-SUBMIT-03: Missing required fields returns 422
    public function test_submit_missing_fields_returns_422(): void
    {
        $response = $this->postJson('/api/v1/conformance/submit', [
            'badge_id' => '550e8400-e29b-41d4-a716-446655440000',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'BADGE-INVALID');
    }

    // BADGE-SUBMIT-04: Invalid tier returns 422
    public function test_submit_unknown_tier_returns_422(): void
    {
        $response = $this->postJson('/api/v1/conformance/submit',
            $this->validBadge(['tier' => 'iicp:unknown:v1']));

        $response->assertStatus(422);
    }

    // BADGE-SUBMIT-05: Duplicate badge_id returns 409
    public function test_submit_duplicate_badge_id_returns_409(): void
    {
        $this->postJson('/api/v1/conformance/submit', $this->validBadge());
        $response = $this->postJson('/api/v1/conformance/submit', $this->validBadge());

        $response->assertStatus(409)
            ->assertJsonPath('error', 'BADGE-DUPLICATE');
    }

    // BADGE-SUBMIT-06: Expiry more than 91 days after passed_at returns 422 (BADGE-03)
    public function test_submit_expiry_too_far_future_returns_422(): void
    {
        $badge = $this->validBadge([
            'passed_at' => '2026-05-18T00:00:00Z',
            'expires_at' => '2030-01-01T00:00:00Z', // 4+ years
        ]);

        $response = $this->postJson('/api/v1/conformance/submit', $badge);

        $response->assertStatus(422);
        $this->assertStringContainsString('91 days', $response->json('details.expires_at'));
    }

    // BADGE-VERIFY-01: Verify returns is_valid=true for an active badge
    public function test_verify_returns_true_for_active_badge(): void
    {
        $this->postJson('/api/v1/conformance/submit', $this->validBadge());

        $response = $this->getJson(
            '/api/v1/conformance/verify?did=did:web:node.example.com&tier=iicp:core:v1'
        );

        $response->assertStatus(200)
            ->assertJsonPath('is_valid', true)
            ->assertJsonPath('tier', 'iicp:core:v1')
            ->assertJsonPath('badge_id', '550e8400-e29b-41d4-a716-446655440000');
    }

    // BADGE-VERIFY-02: Verify returns is_valid=false when no badge exists
    public function test_verify_returns_false_when_no_badge(): void
    {
        $response = $this->getJson(
            '/api/v1/conformance/verify?did=did:web:unknown.example.com&tier=iicp:core:v1'
        );

        $response->assertStatus(200)
            ->assertJsonPath('is_valid', false)
            ->assertJsonPath('reason', 'not_found');
    }

    // BADGE-VERIFY-03: Verify returns 422 when did or tier missing
    public function test_verify_missing_params_returns_422(): void
    {
        $response = $this->getJson('/api/v1/conformance/verify?did=did:web:example.com');
        $response->assertStatus(422);
    }

    // BADGE-VERIFY-04: Expired badge is not returned as valid
    public function test_verify_expired_badge_returns_invalid(): void
    {
        ConformanceBadge::create([
            'badge_id' => '550e8400-e29b-41d4-a716-446655440001',
            'tier' => 'iicp:core:v1',
            'subject_did' => 'did:web:expired.example.com',
            'subject_component' => 'adapter',
            'suite_version' => '1.0.0',
            'passed_at' => Carbon::now()->subDays(100)->format('Y-m-d H:i:s'),
            'expires_at' => Carbon::now()->subDays(10)->format('Y-m-d H:i:s'),
            'test_results_url' => 'https://example.com/r.json',
            'issuer_did' => 'did:web:expired.example.com',
            'verification_mode' => 'self-attested',
            'sig' => 'dGVzdA',
            'status' => 'expired',
        ]);

        $response = $this->getJson(
            '/api/v1/conformance/verify?did=did:web:expired.example.com&tier=iicp:core:v1'
        );

        $response->assertStatus(200)->assertJsonPath('is_valid', false);
    }

    // BADGE-LIST-01: List badges returns all active badges for a DID
    public function test_list_badges_returns_active_badges(): void
    {
        $this->postJson('/api/v1/conformance/submit', $this->validBadge());
        $this->postJson('/api/v1/conformance/submit', $this->validBadge([
            'badge_id' => '550e8400-e29b-41d4-a716-446655440002',
            'tier' => 'iicp:mesh:v1',
        ]));

        $response = $this->getJson(
            '/api/v1/conformance/badges?did=did:web:node.example.com'
        );

        $response->assertStatus(200)
            ->assertJsonPath('count', 2)
            ->assertJsonPath('did', 'did:web:node.example.com');
    }

    // BADGE-LIST-02: List badges returns 422 without did param
    public function test_list_badges_missing_did_returns_422(): void
    {
        $response = $this->getJson('/api/v1/conformance/badges');
        $response->assertStatus(422);
    }

    // BADGE-LIST-03: List badges returns empty array for unknown DID
    public function test_list_badges_empty_for_unknown_did(): void
    {
        $response = $this->getJson(
            '/api/v1/conformance/badges?did=did:web:nobody.example.com'
        );

        $response->assertStatus(200)
            ->assertJsonPath('count', 0)
            ->assertJsonPath('badges', []);
    }

    // --- Genesis-verified signing (BADGE-SUBMIT-01 genesis path) ---

    private function genesisTestKeypair(): array
    {
        $kp = sodium_crypto_sign_keypair();

        return [
            'secret' => sodium_crypto_sign_secretkey($kp),
            'public' => sodium_crypto_sign_publickey($kp),
        ];
    }

    public function test_genesis_verified_signs_badge_when_key_configured(): void
    {
        $kp = $this->genesisTestKeypair();
        putenv('GENESIS_SEED_SECRET_KEY='.base64_encode($kp['secret']));

        $payload = $this->validBadge([
            'badge_id' => '660e8400-e29b-41d4-a716-446655440001',
            'issuer_did' => 'did:web:iicp.network',
            'verification_mode' => 'genesis-verified',
            'sig' => '',
        ]);

        $response = $this->postJson('/api/v1/conformance/submit', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonStructure(['badge_id', 'status', 'expires_at', 'sig']);

        // Verify signature is a non-empty base64url string
        $sig = $response->json('sig');
        $this->assertNotEmpty($sig);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $sig);

        putenv('GENESIS_SEED_SECRET_KEY=');
    }

    public function test_genesis_verified_returns_503_when_key_not_configured(): void
    {
        putenv('GENESIS_SEED_SECRET_KEY=');

        $payload = $this->validBadge([
            'badge_id' => '770e8400-e29b-41d4-a716-446655440002',
            'issuer_did' => 'did:web:iicp.network',
            'verification_mode' => 'genesis-verified',
            'sig' => '',
        ]);

        $response = $this->postJson('/api/v1/conformance/submit', $payload);

        $response->assertStatus(503)
            ->assertJsonPath('error', 'BADGE-GENESIS-UNAVAILABLE');
    }

    public function test_genesis_verified_rejects_wrong_issuer_did(): void
    {
        $kp = $this->genesisTestKeypair();
        putenv('GENESIS_SEED_SECRET_KEY='.base64_encode($kp['secret']));

        $payload = $this->validBadge([
            'badge_id' => '880e8400-e29b-41d4-a716-446655440003',
            'issuer_did' => 'did:web:attacker.example.com',
            'verification_mode' => 'genesis-verified',
            'sig' => 'fakesig',
        ]);

        $response = $this->postJson('/api/v1/conformance/submit', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'BADGE-ISSUER-INVALID');

        putenv('GENESIS_SEED_SECRET_KEY=');
    }

    // --- SVG badge endpoint (BADGE-04) ---

    public function test_svg_badge_returns_svg_for_active_badge(): void
    {
        ConformanceBadge::create([
            'badge_id' => '990e8400-e29b-41d4-a716-446655440004',
            'tier' => 'iicp:core:v1',
            'subject_did' => 'did:web:svg.example.com',
            'subject_component' => 'adapter',
            'suite_version' => '1.0.0',
            'passed_at' => '2026-05-18 00:00:00',
            'expires_at' => '2026-08-15 00:00:00',
            'test_results_url' => 'https://example.com/r.json',
            'issuer_did' => 'did:web:svg.example.com',
            'verification_mode' => 'self-attested',
            'sig' => 'testsig',
            'status' => 'active',
        ]);

        $response = $this->get('/api/v1/badge/iicp:core:v1?did=did:web:svg.example.com');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $response->getContent());
        $this->assertStringContainsString('self-attested', $response->getContent());
    }

    public function test_svg_badge_returns_not_found_for_unknown_did(): void
    {
        $response = $this->get('/api/v1/badge/iicp:core:v1?did=did:web:nobody.example.com');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('not found', $response->getContent());
    }

    public function test_svg_badge_returns_unknown_for_invalid_tier(): void
    {
        $response = $this->get('/api/v1/badge/iicp:unknown:v99?did=did:web:example.com');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('unknown', $response->getContent());
    }
}
