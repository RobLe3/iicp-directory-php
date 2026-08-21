<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\TrustDomainMembership;
use App\Services\RestrictedDomainMembershipAssertionService;
use App\Services\TrustDomainMembershipService;
use App\Support\RestrictedDomainConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_peer_assertion_is_signed_without_exposing_the_bearer_credential(): void
    {
        $authority = sodium_crypto_sign_keypair();
        $subject = sodium_crypto_sign_keypair();
        config()->set('app.genesis_ed25519_secret_key', sodium_bin2hex(sodium_crypto_sign_secretkey($authority)));
        config()->set('iicp.restricted_domain.authority_key_id', 'did:key:directory#key-1');
        $subjectPublic = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($subject)), '+/', '-_'), '=');

        $issued = app(TrustDomainMembershipService::class)->issueWithAssertion(
            'node',
            'did:key:node-a',
            ['bootstrap', 'peers'],
            3600,
            'did:key:node-a#key-1',
            $subjectPublic,
        );

        $this->assertSame('example.internal', $issued['assertion']['assertion']['domain_id']);
        $this->assertSame(['bootstrap', 'peers'], $issued['assertion']['assertion']['scopes']);
        $this->assertStringNotContainsString($issued['token'], json_encode($issued['assertion'], JSON_THROW_ON_ERROR));
        $authorityPublic = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($authority)), '+/', '-_'), '=');
        $this->assertTrue(app(RestrictedDomainMembershipAssertionService::class)->verify(
            $issued['assertion'],
            $authorityPublic,
        ));
        $this->assertSame($issued['assertion'], $issued['membership']->fresh()->membership_envelope);
    }

    public function test_restricted_bootstrap_projects_only_current_signed_node_memberships(): void
    {
        $authority = sodium_crypto_sign_keypair();
        $subject = sodium_crypto_sign_keypair();
        config()->set('app.genesis_ed25519_secret_key', sodium_bin2hex(sodium_crypto_sign_secretkey($authority)));
        config()->set('iicp.restricted_domain.authority_key_id', 'did:key:directory#key-1');
        $subjectPublic = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($subject)), '+/', '-_'), '=');
        $nodeId = 'did:key:node-a';
        Node::create([
            'id' => $nodeId,
            'endpoint' => 'https://node-a.example',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('node-token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.1',
        ]);
        Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://unsigned.example',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('node-token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'observed_source_ip' => '127.0.0.1',
        ]);
        $memberships = app(TrustDomainMembershipService::class);
        $peer = $memberships->issueWithAssertion('node', $nodeId, ['bootstrap', 'peers'], 3600, "$nodeId#key-1", $subjectPublic);
        $caller = $memberships->issue('client', 'client-a', ['bootstrap'], 3600);

        $response = $this->withHeaders($this->headers($caller['token'], 'client-a'))
            ->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonCount(1, 'peers');

        $this->assertSame($nodeId, $response->json('peers.0.node_id'));
        $this->assertSame($peer['assertion'], $response->json('peers.0.membership'));
    }

    public function test_failed_assertion_issue_does_not_rotate_the_existing_membership(): void
    {
        $first = app(TrustDomainMembershipService::class)->issue('node', 'node-a', ['peers'], 3600);

        try {
            app(TrustDomainMembershipService::class)->issueWithAssertion(
                'node',
                'node-a',
                ['peers'],
                3600,
                'node-a#key-1',
                str_repeat('A', 43),
            );
            $this->fail('missing directory signing key must fail');
        } catch (\LogicException) {
            $row = TrustDomainMembership::firstOrFail();
            $this->assertSame($first['membership']->generation, $row->generation);
            $this->assertSame(hash('sha256', $first['token']), $row->getRawOriginal('token_hash'));
        }
    }

    public function test_membership_issue_command_supports_bearer_only_and_signed_assertion_paths(): void
    {
        $this->artisan('iicp:membership-issue', [
            'kind' => 'client',
            'subject' => 'client-cli',
            '--scope' => ['discovery'],
            '--ttl' => 3600,
        ])->assertSuccessful();

        $authority = sodium_crypto_sign_keypair();
        $subject = sodium_crypto_sign_keypair();
        config()->set('app.genesis_ed25519_secret_key', sodium_bin2hex(sodium_crypto_sign_secretkey($authority)));
        $publicKey = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($subject)), '+/', '-_'), '=');
        $this->artisan('iicp:membership-issue', [
            'kind' => 'node',
            'subject' => 'node-cli',
            '--scope' => ['peers'],
            '--ttl' => 3600,
            '--key-id' => 'node-cli#key-1',
            '--public-key' => $publicKey,
        ])->expectsOutputToContain('"schema":"iicp.restricted-trust-domain.membership-assertion.v0"')
            ->assertSuccessful();
    }

    public function test_membership_issue_command_rejects_an_incomplete_subject_key_pair(): void
    {
        $this->artisan('iicp:membership-issue', [
            'kind' => 'node',
            'subject' => 'node-cli',
            '--scope' => ['peers'],
            '--key-id' => 'node-cli#key-1',
        ])->expectsOutputToContain('--key-id and --public-key must be supplied together')
            ->assertFailed();
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
