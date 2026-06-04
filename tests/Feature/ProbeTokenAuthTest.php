<?php

namespace Tests\Feature;

use App\Models\ProbeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProbeTokenAuthTest extends TestCase
{
    use RefreshDatabase;

    // A controlled raw token we can hash predictably in tests
    private const RAW_TOKEN = 'test-probe-token-32-bytes-secret';

    private array $validPayload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validPayload = [
            'run_id' => (string) Str::uuid(),
            'probed_at' => now()->toISOString(),
            'results' => [
                [
                    'probe_id' => 'reach_tcp_01',
                    'probe_type' => 'reachability',
                    'test_id' => 'REACH-01',
                    'level' => 'MUST',
                    'passed' => true,
                    'latency_ms' => 12.4,
                    'detail' => 'TCP connect ok',
                ],
            ],
        ];
    }

    private function makeToken(array $overrides = []): ProbeToken
    {
        return ProbeToken::create(array_merge([
            'token_hash' => hash('sha256', self::RAW_TOKEN),
            'label' => 'test-probe',
            'region' => 'eu-de',
            'expires_at' => null,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // 1. Valid token passes through to the handler
    // -------------------------------------------------------------------------
    public function test_valid_bearer_token_returns_202(): void
    {
        $this->makeToken();

        $response = $this->postJson('/api/v1/telemetry/probe', $this->validPayload, [
            'Authorization' => 'Bearer '.self::RAW_TOKEN,
        ]);

        $response->assertStatus(202);
    }

    // -------------------------------------------------------------------------
    // 2. Missing Authorization header → 401
    // -------------------------------------------------------------------------
    public function test_missing_authorization_header_returns_401(): void
    {
        $this->makeToken();

        $response = $this->postJson('/api/v1/telemetry/probe', $this->validPayload);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    // -------------------------------------------------------------------------
    // 3. Non-Bearer scheme (e.g. Basic) → 401
    // -------------------------------------------------------------------------
    public function test_non_bearer_scheme_returns_401(): void
    {
        $this->makeToken();

        $response = $this->postJson('/api/v1/telemetry/probe', $this->validPayload, [
            'Authorization' => 'Basic '.base64_encode('user:pass'),
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    // -------------------------------------------------------------------------
    // 4. Wrong (but well-formed) bearer token → 401
    // -------------------------------------------------------------------------
    public function test_wrong_bearer_token_returns_401(): void
    {
        $this->makeToken();

        $response = $this->postJson('/api/v1/telemetry/probe', $this->validPayload, [
            'Authorization' => 'Bearer totally-wrong-token-value',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    // -------------------------------------------------------------------------
    // 5. Empty bearer value ("Bearer " with nothing after) → 401
    // -------------------------------------------------------------------------
    public function test_empty_bearer_value_returns_401(): void
    {
        $this->makeToken();

        // Laravel's bearerToken() returns null when value is empty
        $response = $this->call('POST', '/api/v1/telemetry/probe', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($this->validPayload));

        $this->assertEquals(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // 6. Expired probe token → 401
    // -------------------------------------------------------------------------
    public function test_expired_token_returns_401(): void
    {
        $this->makeToken(['expires_at' => now()->subDay()]);

        $response = $this->postJson('/api/v1/telemetry/probe', $this->validPayload, [
            'Authorization' => 'Bearer '.self::RAW_TOKEN,
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    // -------------------------------------------------------------------------
    // 7. Token with future expiry is accepted
    // -------------------------------------------------------------------------
    public function test_token_with_future_expiry_returns_202(): void
    {
        $this->makeToken(['expires_at' => now()->addDays(30)]);

        $response = $this->postJson('/api/v1/telemetry/probe', $this->validPayload, [
            'Authorization' => 'Bearer '.self::RAW_TOKEN,
        ]);

        $response->assertStatus(202);
    }

    // -------------------------------------------------------------------------
    // 8. Oversized bearer token (> 512 bytes) → 401, no DB query issued
    // -------------------------------------------------------------------------
    public function test_oversized_bearer_token_returns_401_without_db_query(): void
    {
        $this->makeToken();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $response = $this->postJson('/api/v1/telemetry/probe', $this->validPayload, [
            'Authorization' => 'Bearer '.str_repeat('x', 600),
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');

        // The oversized guard must fire before any DB lookup
        $this->assertSame(0, $queryCount, 'Expected zero DB queries for oversized token');
    }

    // -------------------------------------------------------------------------
    // 9. Constant-time: middleware stores the SHA-256 hash, not raw token
    // -------------------------------------------------------------------------
    public function test_token_hash_stored_in_db_is_sha256_not_raw(): void
    {
        $token = $this->makeToken();

        $this->assertSame(hash('sha256', self::RAW_TOKEN), $token->token_hash);
        $this->assertNotSame(self::RAW_TOKEN, $token->token_hash);
    }

    // -------------------------------------------------------------------------
    // 10. Successful auth updates last_seen_at on the probe token row
    // -------------------------------------------------------------------------
    public function test_successful_auth_touches_last_seen_at(): void
    {
        $token = $this->makeToken();
        $this->assertNull($token->last_seen_at);

        $this->postJson('/api/v1/telemetry/probe', $this->validPayload, [
            'Authorization' => 'Bearer '.self::RAW_TOKEN,
        ])->assertStatus(202);

        $token->refresh();
        $this->assertNotNull($token->last_seen_at);
    }
}
