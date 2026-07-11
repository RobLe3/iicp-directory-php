<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Operator;
use App\Models\PolicyKeyLifecycleRecord;
use App\Services\NodePolicyManifestVerifier;
use App\Services\PolicyKeyLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class PolicyManifestLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://node.example.com/iicp/health' => Http::response('ok', 200)]);
    }

    public function test_registry_detail_reflects_directory_revoked_policy_key_without_reregister(): void
    {
        [$manifest, $policyKeySha] = $this->signedPolicyManifest();
        $node = $this->createNodeWithManifest($manifest);

        $this->getJson('/api/v1/registry/nodes/'.substr($node->id, 0, 8))
            ->assertOk()
            ->assertJsonPath('node_policy_manifest.verification.status', NodePolicyManifestVerifier::STATUS_SIGNED_VALID);

        PolicyKeyLifecycleRecord::create([
            'policy_key_sha256' => $policyKeySha,
            'status' => PolicyKeyLifecycleRecord::STATUS_REVOKED,
            'revoked_at' => now()->subMinute(),
            'revocation_reason_class' => 'operator_request',
            'rotation_epoch' => 3,
            'evidence_ref' => 'private-ticket-123',
        ]);

        $response = $this->getJson('/api/v1/registry/nodes/'.substr($node->id, 0, 8))
            ->assertOk()
            ->assertJsonPath('node_policy_manifest.verification.status', NodePolicyManifestVerifier::STATUS_SIGNED_REVOKED)
            ->assertJsonPath('node_policy_manifest.manifest_identity_level', NodePolicyManifestVerifier::IDENTITY_REVOKED)
            ->assertJsonPath('node_policy_manifest.revocation_reason_class', 'operator_request')
            ->assertJsonPath('node_policy_manifest.rotation_epoch', 3);

        $this->assertStringNotContainsString('private-ticket-123', $response->getContent());
    }

    public function test_register_rejects_directory_revoked_policy_key(): void
    {
        [$manifest, $policyKeySha] = $this->signedPolicyManifest();
        app(PolicyKeyLifecycleService::class)->markRevoked(
            policyKeySha256: $policyKeySha,
            revokedAt: now()->subMinute(),
            reasonClass: 'compromise',
        );

        $response = $this->postJson('/api/v1/register', $this->registerPayload($manifest));

        $response->assertStatus(422);
        $this->assertStringContainsString(NodePolicyManifestVerifier::STATUS_SIGNED_REVOKED, $response->getContent());
        $this->assertDatabaseCount('nodes', 0);
    }

    public function test_policy_key_lifecycle_service_validates_hash_inputs(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PolicyKeyLifecycleService::class)->markRevoked('not-a-sha256');
    }

    public function test_registry_detail_reflects_superseded_policy_key_without_reregister(): void
    {
        [$manifest, $policyKeySha] = $this->signedPolicyManifest();
        $node = $this->createNodeWithManifest($manifest);

        PolicyKeyLifecycleRecord::create([
            'policy_key_sha256' => $policyKeySha,
            'status' => PolicyKeyLifecycleRecord::STATUS_SUPERSEDED,
            'rotation_epoch' => 4,
            'revocation_reason_class' => 'rotation',
            'superseded_by_policy_key_sha256' => str_repeat('a', 64),
        ]);

        $response = $this->getJson('/api/v1/registry/nodes/'.substr($node->id, 0, 8))
            ->assertOk()
            ->assertJsonPath('node_policy_manifest.verification.status', NodePolicyManifestVerifier::STATUS_SIGNED_SUPERSEDED)
            ->assertJsonPath('node_policy_manifest.manifest_identity_level', NodePolicyManifestVerifier::IDENTITY_ROTATED)
            ->assertJsonPath('node_policy_manifest.revocation_reason_class', 'rotation')
            ->assertJsonPath('node_policy_manifest.rotation_epoch', 4);

        $this->assertStringNotContainsString(str_repeat('a', 64), $response->getContent());
    }

    public function test_register_rejects_superseded_and_malformed_lifecycle_records(): void
    {
        [$supersededManifest, $supersededSha] = $this->signedPolicyManifest();
        PolicyKeyLifecycleRecord::create([
            'policy_key_sha256' => $supersededSha,
            'status' => PolicyKeyLifecycleRecord::STATUS_SUPERSEDED,
            'rotation_epoch' => 2,
        ]);

        $supersededResponse = $this->postJson('/api/v1/register', $this->registerPayload($supersededManifest));
        $supersededResponse->assertStatus(422);
        $this->assertStringContainsString(NodePolicyManifestVerifier::STATUS_SIGNED_SUPERSEDED, $supersededResponse->getContent());

        [$malformedManifest, $malformedSha] = $this->signedPolicyManifest();
        PolicyKeyLifecycleRecord::create([
            'policy_key_sha256' => $malformedSha,
            'status' => 'not-a-valid-status',
        ]);

        $response = $this->postJson('/api/v1/register', $this->registerPayload($malformedManifest));
        $response->assertStatus(422);
        $this->assertStringContainsString(NodePolicyManifestVerifier::STATUS_SIGNED_INVALID, $response->getContent());
        $this->assertDatabaseCount('nodes', 0);
    }

    public function test_known_operator_requires_current_terms_and_dpa_acceptance(): void
    {
        config([
            'app.iicp_operator_terms_version' => 'terms-2026-07-09',
            'app.iicp_operator_dpa_version' => 'dpa-2026-07-09',
        ]);
        [$manifest, , $operatorPubkey] = $this->signedPolicyManifest();
        Operator::create([
            'operator_pubkey' => $operatorPubkey,
            'display_name' => 'Known Operator',
            'terms_version' => 'terms-2026-07-09',
            'terms_accepted_at' => now(),
            'dpa_version' => 'dpa-2026-07-09',
            'dpa_accepted_at' => now(),
            'acceptance_method' => 'operator_key_challenge',
            'acceptance_nonce_sha256' => hash('sha256', 'nonce'),
        ]);
        $node = $this->createNodeWithManifest($manifest, [
            'operator_pubkey' => $operatorPubkey,
            'operator_verified' => true,
            'operator_trust_tier' => 'did_key',
        ]);

        $response = $this->getJson('/api/v1/registry/nodes/'.substr($node->id, 0, 8))
            ->assertOk()
            ->assertJsonPath('node_policy_manifest.manifest_identity_level', NodePolicyManifestVerifier::IDENTITY_KNOWN_OPERATOR)
            ->assertJsonPath('node_policy_manifest.operator_governance.known_operator', true)
            ->assertJsonPath('node_policy_manifest.operator_governance.terms_status', 'current')
            ->assertJsonPath('node_policy_manifest.operator_governance.dpa_status', 'current')
            ->assertJsonPath('node_policy_manifest.operator_governance.acceptance_method', 'operator_key_challenge');

        $this->assertStringNotContainsString($operatorPubkey, $response->getContent());
        $this->assertStringNotContainsString(hash('sha256', 'nonce'), $response->getContent());
    }

    public function test_outdated_terms_drop_known_operator_back_to_operator_bound(): void
    {
        config([
            'app.iicp_operator_terms_version' => 'terms-current',
            'app.iicp_operator_dpa_version' => 'dpa-current',
        ]);
        [$manifest, , $operatorPubkey] = $this->signedPolicyManifest();
        Operator::create([
            'operator_pubkey' => $operatorPubkey,
            'display_name' => 'Formerly Known Operator',
            'terms_version' => 'terms-old',
            'terms_accepted_at' => now(),
            'dpa_version' => 'dpa-current',
            'dpa_accepted_at' => now(),
            'acceptance_method' => 'operator_key_challenge',
        ]);
        $node = $this->createNodeWithManifest($manifest, [
            'operator_pubkey' => $operatorPubkey,
            'operator_verified' => true,
            'operator_trust_tier' => 'did_key',
        ]);

        $this->getJson('/api/v1/registry/nodes/'.substr($node->id, 0, 8))
            ->assertOk()
            ->assertJsonPath('node_policy_manifest.manifest_identity_level', NodePolicyManifestVerifier::IDENTITY_OPERATOR_BOUND)
            ->assertJsonPath('node_policy_manifest.operator_governance.known_operator', false)
            ->assertJsonPath('node_policy_manifest.operator_governance.terms_status', 'outdated')
            ->assertJsonPath('node_policy_manifest.operator_governance.dpa_status', 'current');
    }

    /** @return array{0: array<string,mixed>, 1: string, 2: string} */
    private function signedPolicyManifest(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $manifest = [
            'version' => '2026-07-09',
            'jurisdiction' => 'DE',
            'policy_url' => 'https://node.example.com/policy',
            'remote_executor_can_read_prompt' => true,
            'training_use' => 'none',
            'retention' => ['task_payload' => 'none', 'logs_days' => 7],
            'subprocessors' => ['self-hosted'],
            'unsupported_intents' => [],
        ];
        $manifest['signature'] = [
            'algorithm' => 'Ed25519',
            'key_id' => 'policy-key-1',
            'public_key' => base64_encode($publicKey),
            'signed_at' => now()->subMinute()->toIso8601String(),
            'expires_at' => now()->addDay()->toIso8601String(),
        ];
        $manifest['signature']['signature'] = base64_encode(sodium_crypto_sign_detached(
            NodePolicyManifestVerifier::canonicalPayload($manifest),
            $secretKey,
        ));

        return [$manifest, hash('sha256', $publicKey), base64_encode($publicKey)];
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $overrides */
    private function createNodeWithManifest(array $manifest, array $overrides = []): Node
    {
        $node = Node::create(array_merge([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'public_reachable' => true,
            'status' => 'active',
            'load' => 0.2,
            'active_jobs' => 1,
            'last_seen' => now(),
            'policy_manifest' => $manifest,
        ], $overrides));
        $node->capabilities()->create([
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['qwen2.5:0.5b'],
            'max_tokens' => 4096,
        ]);

        return $node;
    }

    /** @param array<string,mixed> $manifest */
    private function registerPayload(array $manifest): array
    {
        return [
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'capabilities' => [[
                'intent' => 'urn:iicp:intent:llm:chat:v1',
                'models' => ['qwen2.5:0.5b'],
                'max_tokens' => 4096,
            ]],
            'limits' => [
                'max_concurrent' => 4,
                'tokens_per_min' => 10000,
            ],
            'policy_manifest' => $manifest,
        ];
    }
}
