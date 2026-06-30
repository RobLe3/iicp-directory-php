<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReplicaModeRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('IICP_REPLICA_MODE=false');
        putenv('IICP_SEED_URL');
    }

    protected function tearDown(): void
    {
        putenv('IICP_REPLICA_MODE=false');
        putenv('IICP_SEED_URL');
        putenv('IICP_DEV_ALLOW_HTTP_DID');
        parent::tearDown();
    }

    public function test_replica_mode_allows_http_seed_url_with_dev_flag(): void
    {
        // Testbed (docker-compose.federation.yml): seed↔replica over plain http inside the
        // bridge network. IICP_DEV_ALLOW_HTTP_DID (non-production) permits http:// → 307,
        // instead of the 503 a bare http URL gets in production.
        putenv('IICP_REPLICA_MODE=true');
        putenv('IICP_SEED_URL=http://seed-directory:8080');
        putenv('IICP_DEV_ALLOW_HTTP_DID=true');

        $resp = $this->postJson('/api/v1/register', ['endpoint' => 'https://x.test']);

        $resp->assertStatus(307);
        $this->assertSame('http://seed-directory:8080/api/v1/register', $resp->headers->get('Location'));
        $this->assertSame('replica_mode', $resp->headers->get('X-IICP-Redirect-Reason'));
    }

    public function test_seed_mode_passes_writes_through(): void
    {
        // IICP_REPLICA_MODE=false → middleware no-op → request reaches controller
        // (validation fails, but with 422, not 307 — proving the middleware did not intercept)
        $resp = $this->postJson('/api/v1/register', []);
        $this->assertNotSame(307, $resp->status());
    }

    public function test_replica_mode_redirects_post_to_seed(): void
    {
        putenv('IICP_REPLICA_MODE=true');
        putenv('IICP_SEED_URL=https://iicp.network');

        $resp = $this->postJson('/api/v1/register', ['endpoint' => 'https://x.test']);

        $resp->assertStatus(307);
        $this->assertSame('https://iicp.network/api/v1/register', $resp->headers->get('Location'));
        $this->assertSame('true', $resp->headers->get('X-IICP-Replica-Redirect'));
        $this->assertSame('replica_mode', $resp->headers->get('X-IICP-Redirect-Reason'));
        $this->assertSame('0', $resp->headers->get('Retry-After'));
    }

    public function test_replica_mode_redirects_delete(): void
    {
        putenv('IICP_REPLICA_MODE=true');
        putenv('IICP_SEED_URL=https://iicp.network');

        $resp = $this->deleteJson('/api/v1/register');
        $resp->assertStatus(307);
        $this->assertSame('https://iicp.network/api/v1/register', $resp->headers->get('Location'));
    }

    public function test_replica_mode_passes_gets_through(): void
    {
        putenv('IICP_REPLICA_MODE=true');
        putenv('IICP_SEED_URL=https://iicp.network');

        // GET /v1/events → middleware no-op → reaches EventsController → 200 with empty list
        $resp = $this->getJson('/api/v1/events');
        $this->assertNotSame(307, $resp->status());
    }

    public function test_replica_mode_preserves_query_string(): void
    {
        putenv('IICP_REPLICA_MODE=true');
        putenv('IICP_SEED_URL=https://iicp.network');

        $resp = $this->postJson('/api/v1/heartbeat?node_id=abc&foo=bar');
        $resp->assertStatus(307);
        $this->assertSame(
            'https://iicp.network/api/v1/heartbeat?node_id=abc&foo=bar',
            $resp->headers->get('Location')
        );
    }

    public function test_replica_mode_returns_iicp_e046_when_seed_url_missing(): void
    {
        putenv('IICP_REPLICA_MODE=true');
        putenv('IICP_SEED_URL');

        $resp = $this->postJson('/api/v1/register', ['endpoint' => 'https://x.test']);
        $resp->assertStatus(503);
        $resp->assertJsonPath('error.code', 'IICP-E047');
    }

    public function test_replica_mode_returns_iicp_e046_when_seed_url_non_https(): void
    {
        putenv('IICP_REPLICA_MODE=true');
        putenv('IICP_SEED_URL=http://insecure.example');

        $resp = $this->postJson('/api/v1/credits/award', []);
        $resp->assertStatus(503);
        $resp->assertJsonPath('error.code', 'IICP-E047');
    }
}
