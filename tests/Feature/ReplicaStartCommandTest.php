<?php

namespace Tests\Feature;

use App\Services\SeedDidResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReplicaStartCommandTest extends TestCase
{
    public function test_missing_required_options_returns_invalid(): void
    {
        $exit = $this->artisan('iicp:replica-start')->run();
        $this->assertSame(2, $exit); // Command::INVALID
    }

    public function test_non_https_seed_url_rejected(): void
    {
        $exit = $this->artisan('iicp:replica-start', [
            '--seed-url' => 'http://insecure.example',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'https://replica.test',
        ])->run();
        $this->assertSame(2, $exit);
    }

    public function test_non_https_endpoint_rejected(): void
    {
        $exit = $this->artisan('iicp:replica-start', [
            '--seed-url' => 'https://iicp.network',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'http://insecure.test',
        ])->run();
        $this->assertSame(2, $exit);
    }

    /**
     * IICP_DEV_ALLOW_HTTP_DID flag — testbed escape hatch (P6-3.1b-ii).
     * Lets the docker-compose.federation.yml testbed wire seed↔replica over
     * plain HTTP without nginx + self-signed certs.
     */
    public function test_dev_flag_allows_http_in_non_production(): void
    {
        config(['app.env' => 'local']);
        config(['iicp.replica.dev_allow_http_did' => true]);
        try {
            Http::fake();
            $exit = $this->artisan('iicp:replica-start', [
                '--seed-url' => 'http://seed-directory:8030',
                '--did' => 'did:web:replica.test',
                '--endpoint' => 'http://replica-directory:8040',
                '--dry-run' => true,
            ])->run();
            $this->assertSame(0, $exit, 'dev flag should let http:// pass in local env');
        } finally {
            config(['iicp.replica.dev_allow_http_did' => false]);
        }
    }

    public function test_dev_flag_rejected_in_production(): void
    {
        config(['app.env' => 'production']);
        config(['iicp.replica.dev_allow_http_did' => true]);
        try {
            $exit = $this->artisan('iicp:replica-start', [
                '--seed-url' => 'http://insecure.example',
                '--did' => 'did:web:replica.test',
                '--endpoint' => 'https://replica.test',
            ])->run();
            $this->assertSame(
                2,
                $exit,
                'IICP_DEV_ALLOW_HTTP_DID must NOT bypass HTTPS check in production',
            );
        } finally {
            config(['iicp.replica.dev_allow_http_did' => false]);
            config(['app.env' => 'testing']);
        }
    }

    public function test_dry_run_skips_network_and_succeeds(): void
    {
        Http::fake(); // any request would fail-loud if attempted
        $exit = $this->artisan('iicp:replica-start', [
            '--seed-url' => 'https://iicp.network',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'https://replica.test',
            '--dry-run' => true,
        ])->run();
        $this->assertSame(0, $exit);
        Http::assertNothingSent();
    }

    public function test_once_mode_registers_and_polls_once(): void
    {
        Storage::fake();
        Http::fake([
            'https://iicp.network/api/v1/replicas/register' => Http::response([
                'replica_id' => 'rep-'.str_repeat('a', 32),
                'replica_token' => 'jwt.test.token',
                'since_seq' => 42,
                'genesis_hash' => 'abc123def456'.str_repeat('0', 52),
            ], 200),
            'https://iicp.network/api/v1/snapshot' => Http::response([
                'schema_version' => 'v0.3.0', 'snapshot_seq' => 42, 'snapshot_ts_ms' => 1700000000,
                'genesis_hash' => 'abc123def456'.str_repeat('0', 52),
                'nodes' => [], 'capabilities' => [],
            ], 200),
            'https://iicp.network/api/v1/events*' => Http::response([
                'events' => [
                    ['seq' => 43, 'event_type' => 'REGISTER', 'ts_ms' => 1700000000, 'signer_did' => 'did:web:iicp.network', 'event_id' => 'e1'],
                    ['seq' => 44, 'event_type' => 'CREDIT_AWARD', 'ts_ms' => 1700000001, 'signer_did' => 'did:web:iicp.network', 'event_id' => 'e2'],
                ],
                'genesis_hash' => 'abc123def456'.str_repeat('0', 52),
            ], 200),
        ]);

        $exit = $this->artisan('iicp:replica-start', [
            '--seed-url' => 'https://iicp.network',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'https://replica.test',
            '--state-file' => 'replica-state-test.json',
            '--once' => true,
        ])->run();

        $this->assertSame(0, $exit);
        $this->assertTrue(Storage::exists('replica-state-test.json'));
        $state = json_decode(Storage::get('replica-state-test.json'), true);
        $this->assertSame(42, $state['since_seq']);
        $this->assertSame(44, $state['last_seq']);
        $this->assertSame('jwt.test.token', $state['replica_token']);
    }

    public function test_production_apply_refuses_to_mutate_without_seed_verification_key(): void
    {
        config(['app.env' => 'production', 'iicp.replica.dev_allow_unsigned_events' => true]);
        $this->mock(SeedDidResolver::class, function ($mock): void {
            $mock->shouldReceive('publicKey')->once()->andReturnNull();
        });
        Http::fake();

        $exit = $this->artisan('iicp:replica-start', [
            '--seed-url' => 'https://seed.test',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'https://replica.test',
            '--state-file' => 'replica-strict-no-key.json',
            '--apply' => true,
            '--once' => true,
        ])->run();

        $this->assertSame(1, $exit);
        $this->assertFalse(Storage::exists('replica-strict-no-key.json'));
        Http::assertNothingSent();
    }

    public function test_strict_apply_skips_unsigned_snapshot_and_preserves_cursor_on_rejected_event(): void
    {
        Storage::fake();
        config(['app.env' => 'production', 'iicp.replica.dev_allow_unsigned_events' => true]);
        $this->mock(SeedDidResolver::class, function ($mock): void {
            $mock->shouldReceive('publicKey')->once()->andReturn(str_repeat("\x01", 32));
        });
        Http::fake([
            'https://seed.test/api/v1/replicas/register' => Http::response([
                'replica_id' => 'rep-'.str_repeat('a', 32),
                'replica_token' => 'jwt.test.token',
                'since_seq' => 42,
                'genesis_hash' => str_repeat('a', 64),
            ], 200),
            'https://seed.test/api/v1/events*' => Http::response([
                'events' => [[
                    'seq' => 1,
                    'event_type' => 'REGISTER',
                    'event_id' => 'unsigned-event',
                    'node_id' => '550e8400-e29b-41d4-a716-446655440001',
                    'ts_ms' => 1700000000,
                    'signer_did' => 'did:web:seed.test',
                    'payload' => ['endpoint' => 'https://node.test'],
                    'sig' => null,
                ]],
                'genesis_hash' => str_repeat('a', 64),
            ], 200),
        ]);

        $exit = $this->artisan('iicp:replica-start', [
            '--seed-url' => 'https://seed.test',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'https://replica.test',
            '--state-file' => 'replica-strict-cursor.json',
            '--apply' => true,
            '--once' => true,
        ])->run();

        $this->assertSame(0, $exit);
        $state = json_decode(Storage::get('replica-strict-cursor.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $state['since_seq']);
        $this->assertSame(0, $state['last_seq']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v1/snapshot'));
    }

    public function test_registration_failure_returns_failure(): void
    {
        Storage::fake();
        Http::fake([
            'https://iicp.network/api/v1/replicas/register' => Http::response([
                'error' => ['code' => 'IICP-E040', 'message' => 'invalid_did_document'],
            ], 400),
        ]);

        $exit = $this->artisan('iicp:replica-start', [
            '--seed-url' => 'https://iicp.network',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'https://replica.test',
            '--state-file' => 'replica-state-fail.json',
            '--once' => true,
        ])->run();

        $this->assertSame(1, $exit); // Command::FAILURE
        $this->assertFalse(Storage::exists('replica-state-fail.json'));
    }

    public function test_resumes_from_saved_state(): void
    {
        Storage::fake();
        Storage::put('replica-state-resume.json', json_encode([
            'replica_id' => 'rep-existing',
            'replica_token' => 'jwt.saved.token',
            'since_seq' => 100,
            'genesis_hash' => 'gh-saved',
            'last_seq' => 105,
            'seed_url' => 'https://iicp.network',
        ]));

        Http::fake([
            'https://iicp.network/api/v1/events*' => Http::response([
                'events' => [],
                'genesis_hash' => 'gh-saved',
            ], 200),
        ]);

        $exit = $this->artisan('iicp:replica-start', [
            '--seed-url' => 'https://iicp.network',
            '--did' => 'did:web:replica.test',
            '--endpoint' => 'https://replica.test',
            '--state-file' => 'replica-state-resume.json',
            '--once' => true,
        ])->run();

        $this->assertSame(0, $exit);
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/api/v1/replicas/register');
        });
        // Verify event poll used since_seq=105 from saved state
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'since_seq=105');
        });
    }

    public function test_no_register_tails_events_without_handshake_or_token(): void
    {
        // FED-READY-1 read-only tail (matches the Rust replica): --no-register skips the
        // join handshake + snapshot and polls the public event log token-less from seq 0.
        Storage::delete('replica-state-noreg.json');

        Http::fake([
            'https://seed.test/api/v1/events*' => Http::response([
                'events' => [],
                'genesis_hash' => 'gh-noreg',
            ], 200),
        ]);

        $exit = $this->artisan('iicp:replica-start', [
            '--seed-url' => 'https://seed.test',
            '--no-register' => true,
            '--state-file' => 'replica-state-noreg.json',
            '--once' => true,
        ])->run();

        $this->assertSame(0, $exit);
        // No join handshake and no snapshot fetch in read-only mode.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/v1/replicas/register'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/v1/snapshot'));
        // Polls the public event log from seq 0 with NO bearer token.
        Http::assertSent(function ($r) {
            return str_contains($r->url(), '/api/v1/events')
                && str_contains($r->url(), 'since_seq=0')
                && ! $r->hasHeader('Authorization');
        });
    }
}
