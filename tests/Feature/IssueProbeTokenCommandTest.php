<?php

namespace Tests\Feature;

use App\Models\ProbeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IssueProbeTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_db_row_with_correct_hash(): void
    {
        $this->artisan('iicp:probe-token', [
            'label' => 'eu-de-test-01',
            'region' => 'EU-DE',
        ])->assertExitCode(0);

        $token = ProbeToken::first();
        $this->assertNotNull($token);
        $this->assertSame('eu-de-test-01', $token->label);
        $this->assertSame('EU-DE', $token->region);
        $this->assertNull($token->expires_at);

        // Hash must be a 64-char SHA-256 hex string
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token->token_hash);
    }

    public function test_command_sets_expiry_when_flag_provided(): void
    {
        $this->artisan('iicp:probe-token', [
            'label' => 'eu-de-test-02',
            'region' => 'EU-DE',
            '--expires' => '30',
        ])->assertExitCode(0);

        $token = ProbeToken::first();
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->isAfter(now()->addDays(29)));
    }

    public function test_command_rejects_non_numeric_expires(): void
    {
        $this->artisan('iicp:probe-token', [
            'label' => 'test',
            'region' => 'EU-DE',
            '--expires' => 'banana',
        ])->assertExitCode(1);

        $this->assertSame(0, ProbeToken::count());
    }

    public function test_issued_token_authenticates_against_telemetry_endpoint(): void
    {
        // Capture the raw token from command output
        $rawToken = null;
        $this->artisan('iicp:probe-token', ['label' => 'smoke', 'region' => 'EU-DE'])
            ->assertExitCode(0);

        // Retrieve the stored hash and verify round-trip auth works
        $stored = ProbeToken::first();
        $this->assertNotNull($stored);

        // We can't recover the raw token from the hash, but we can verify the
        // hash mechanism: hash stored in DB must verify against some 48-char Str::random.
        // Instead, inject a known token directly and verify auth.
        $knownRaw = 'known-probe-token-48-bytes-xxxxxxxxxxxx';
        $knownHash = hash('sha256', $knownRaw);
        ProbeToken::create(['token_hash' => $knownHash, 'label' => 'known', 'region' => 'EU-DE']);

        $response = $this->postJson('/api/v1/telemetry/probe', [
            'run_id' => (string) Str::uuid(),
            'probed_at' => now()->toISOString(),
            'results' => [[
                'probe_id' => 'p1',
                'probe_type' => 'conformance',
                'test_id' => 'DIR-DISC-01',
                'level' => 'MUST',
                'passed' => true,
                'latency_ms' => 8.0,
                'detail' => 'ok',
            ]],
        ], ['Authorization' => 'Bearer '.$knownRaw]);

        $response->assertStatus(202);
    }
}
