<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Http\Middleware\LoadRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Feature tests for LoadRedirect middleware (ADR-013 / iicp-federated-directory.md §6).
 *
 * Verifies the no-op-when-disabled and no-op-when-no-replicas behaviors that make
 * this safe to deploy in front of read-heavy endpoints before any replicas exist.
 */
class LoadRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Register a probe route guarded by the middleware so we don't depend
        // on the production route table (which may grow / change over time).
        Route::middleware(LoadRedirect::class)->get('/api/v1/_load-redirect-probe', function () {
            return response()->json(['ok' => true]);
        });
    }

    protected function tearDown(): void
    {
        config(['iicp.replica.redirect.enabled' => false]);
        config(['iicp.replica.redirect.urls' => '']);
        config(['iicp.replica.redirect.trust_tier' => 'low']);
        config(['iicp.replica.redirect.retry_after' => 5]);
        parent::tearDown();
    }

    public function test_passes_through_when_disabled(): void
    {
        config(['iicp.replica.redirect.enabled' => false]);
        config(['iicp.replica.redirect.urls' => 'https://r1.example.com']);

        $this->getJson('/api/v1/_load-redirect-probe')
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_passes_through_when_enabled_but_no_replicas(): void
    {
        config(['iicp.replica.redirect.enabled' => true]);
        config(['iicp.replica.redirect.urls' => '']);

        $this->getJson('/api/v1/_load-redirect-probe')
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_returns_307_with_required_headers_when_enabled_and_replica_present(): void
    {
        config(['iicp.replica.redirect.enabled' => true]);
        config(['iicp.replica.redirect.urls' => 'https://r1.example.com']);
        config(['iicp.replica.redirect.trust_tier' => 'medium']);
        config(['iicp.replica.redirect.retry_after' => 7]);

        $response = $this->getJson('/api/v1/_load-redirect-probe');
        $response->assertStatus(307);
        $response->assertHeader('Location', 'https://r1.example.com/api/v1/_load-redirect-probe');
        $response->assertHeader('X-IICP-Seed-Redirect', 'true');
        $response->assertHeader('X-IICP-Replica-Trust', 'medium');
        $response->assertHeader('X-IICP-Redirect-Reason', 'load');
        $response->assertHeader('Retry-After', '7');
    }

    public function test_picks_from_multiple_replicas(): void
    {
        config(['iicp.replica.redirect.enabled' => true]);
        config(['iicp.replica.redirect.urls' => 'https://r1.example.com,https://r2.example.com,https://r3.example.com']);

        $response = $this->getJson('/api/v1/_load-redirect-probe');
        $response->assertStatus(307);
        $location = $response->headers->get('Location');
        $this->assertContains(
            $location,
            [
                'https://r1.example.com/api/v1/_load-redirect-probe',
                'https://r2.example.com/api/v1/_load-redirect-probe',
                'https://r3.example.com/api/v1/_load-redirect-probe',
            ],
        );
    }

    public function test_default_trust_tier_is_low(): void
    {
        config(['iicp.replica.redirect.enabled' => true]);
        config(['iicp.replica.redirect.urls' => 'https://r1.example.com']);

        $response = $this->getJson('/api/v1/_load-redirect-probe');
        $response->assertStatus(307);
        $response->assertHeader('X-IICP-Replica-Trust', 'low');
        $response->assertHeader('Retry-After', '5');
    }

    public function test_preserves_query_string_in_location(): void
    {
        config(['iicp.replica.redirect.enabled' => true]);
        config(['iicp.replica.redirect.urls' => 'https://r1.example.com']);

        $response = $this->getJson('/api/v1/_load-redirect-probe?intent=urn:iicp:intent:llm:chat:v1&region=eu');
        $response->assertStatus(307);
        $response->assertHeader(
            'Location',
            'https://r1.example.com/api/v1/_load-redirect-probe?intent=urn:iicp:intent:llm:chat:v1&region=eu',
        );
    }

    public function test_rejects_non_https_replica_urls(): void
    {
        // bug-281: open-redirect-via-misconfig — http:// (no TLS) and javascript: must be dropped.
        config(['iicp.replica.redirect.enabled' => true]);
        config(['iicp.replica.redirect.urls' => 'http://r1.example.com,javascript:alert(1),https://r2.example.com']);

        $response = $this->getJson('/api/v1/_load-redirect-probe');
        $response->assertStatus(307);
        // Only the https:// replica is eligible → Location MUST point to r2.
        $response->assertHeader('Location', 'https://r2.example.com/api/v1/_load-redirect-probe');
    }

    public function test_passes_through_when_all_replicas_are_non_https(): void
    {
        // bug-281: if filter leaves no replicas, behave like the "no replicas" case.
        config(['iicp.replica.redirect.enabled' => true]);
        config(['iicp.replica.redirect.urls' => 'http://r1.example.com,ftp://r2.example.com']);

        $this->getJson('/api/v1/_load-redirect-probe')
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_invalid_trust_tier_falls_back_to_low(): void
    {
        // bug-281: prevent header injection via env misconfig — anything outside
        // the allowlist {low, medium, high} falls back to "low".
        config(['iicp.replica.redirect.enabled' => true]);
        config(['iicp.replica.redirect.urls' => 'https://r1.example.com']);
        config(['iicp.replica.redirect.trust_tier' => "bogus\r\nX-Injected: yes"]);

        $response = $this->getJson('/api/v1/_load-redirect-probe');
        $response->assertStatus(307);
        $response->assertHeader('X-IICP-Replica-Trust', 'low');
        $this->assertNull($response->headers->get('X-Injected'));
    }

    public function test_retry_after_clamped_to_minimum_one(): void
    {
        // bug-281: Retry-After=0 or negative would invite tight retry loops.
        config(['iicp.replica.redirect.enabled' => true]);
        config(['iicp.replica.redirect.urls' => 'https://r1.example.com']);
        config(['iicp.replica.redirect.retry_after' => 0]);

        $response = $this->getJson('/api/v1/_load-redirect-probe');
        $response->assertHeader('Retry-After', '1');
    }
}
