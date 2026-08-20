<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\TrustDomainMembership;
use App\Services\TrustDomainMembershipService;
use App\Support\RestrictedDomainConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestrictedDomainMembershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('iicp.restricted_domain.enabled', true);
        config()->set('iicp.restricted_domain.domain_id', 'example.internal');
        config()->set('iicp.restricted_domain.authority_id', 'did:key:directory');
        config()->set('iicp.restricted_domain.membership_epoch', 1);
        config()->set('iicp.restricted_domain.max_credential_ttl_seconds', 86400);
    }

    public function test_unknown_client_cannot_discover_or_bootstrap(): void
    {
        $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'restricted_domain_denied');
        $this->getJson('/api/v1/bootstrap')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'restricted_domain_denied');
    }

    public function test_scoped_client_can_discover_but_not_bootstrap(): void
    {
        $issued = app(TrustDomainMembershipService::class)->issue(
            'client',
            'client-a',
            ['discovery'],
            3600,
        );
        $headers = $this->headers($issued['token'], 'client-a');

        $this->withHeaders($headers)
            ->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')
            ->assertOk();
        $this->withHeaders($headers)
            ->getJson('/api/v1/bootstrap')
            ->assertUnauthorized();
    }

    public function test_registration_requires_node_membership_bound_to_requested_node_id(): void
    {
        $memberships = app(TrustDomainMembershipService::class);
        $client = $memberships->issue('client', 'node-a', ['registration'], 3600);
        $node = $memberships->issue('node', 'node-b', ['registration'], 3600);

        $this->withHeaders($this->headers($client['token'], 'node-a'))
            ->postJson('/api/v1/register', ['node_id' => 'node-a'])
            ->assertUnauthorized();
        $this->withHeaders($this->headers($node['token'], 'node-a'))
            ->postJson('/api/v1/register', ['node_id' => 'node-a'])
            ->assertUnauthorized();
        $this->withHeaders($this->headers($node['token'], 'node-b'))
            ->postJson('/api/v1/register', ['node_id' => 'node-b'])
            ->assertUnprocessable();
    }

    public function test_wrong_subject_expired_and_revoked_memberships_share_one_refusal(): void
    {
        $memberships = app(TrustDomainMembershipService::class);
        $issued = $memberships->issue('client', 'client-a', ['discovery'], 3600);

        $this->withHeaders($this->headers($issued['token'], 'client-b'))
            ->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'restricted_domain_denied');

        $issued['membership']->update(['expires_at' => now()->subSecond()]);
        $this->withHeaders($this->headers($issued['token'], 'client-a'))
            ->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'restricted_domain_denied');

        $rotated = $memberships->issue('client', 'client-a', ['discovery'], 3600);
        $this->assertTrue($memberships->revoke('client', 'client-a'));
        $this->withHeaders($this->headers($rotated['token'], 'client-a'))
            ->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'restricted_domain_denied');
    }

    public function test_rotation_invalidates_previous_credential_and_advances_generation(): void
    {
        $memberships = app(TrustDomainMembershipService::class);
        $first = $memberships->issue('client', 'client-a', ['discovery'], 3600);
        $second = $memberships->issue('client', 'client-a', ['discovery'], 3600);

        $this->assertGreaterThan($first['membership']->generation, $second['membership']->generation);
        $this->withHeaders($this->headers($first['token'], 'client-a'))
            ->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')
            ->assertUnauthorized();
        $this->withHeaders($this->headers($second['token'], 'client-a'))
            ->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')
            ->assertOk();
    }

    public function test_epoch_change_invalidates_stale_membership_without_cache_window(): void
    {
        $issued = app(TrustDomainMembershipService::class)->issue(
            'client',
            'client-a',
            ['discovery'],
            3600,
        );
        config()->set('iicp.restricted_domain.membership_epoch', $issued['membership']->generation + 1);

        $this->withHeaders($this->headers($issued['token'], 'client-a'))
            ->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')
            ->assertUnauthorized();
    }

    public function test_token_hash_is_hidden_and_database_never_contains_bearer_value(): void
    {
        $issued = app(TrustDomainMembershipService::class)->issue(
            'client',
            'client-a',
            ['discovery'],
            3600,
        );
        $row = TrustDomainMembership::firstOrFail();

        $this->assertSame(hash('sha256', $issued['token']), $row->getRawOriginal('token_hash'));
        $this->assertStringNotContainsString($issued['token'], $row->toJson());
    }

    public function test_restricted_configuration_fails_closed_before_runtime_start(): void
    {
        config()->set('iicp.restricted_domain.domain_id', '');
        $this->expectException(\LogicException::class);

        RestrictedDomainConfig::assertValid();
    }

    public function test_restricted_configuration_rejects_unimplemented_federation(): void
    {
        config()->set('iicp.replica.enabled', true);
        $this->expectException(\LogicException::class);

        RestrictedDomainConfig::assertValid();
    }

    /** @return array<string, string> */
    private function headers(string $token, string $subject): array
    {
        return [
            'X-IICP-Membership' => $token,
            'X-IICP-Subject-Id' => $subject,
        ];
    }
}
