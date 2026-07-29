<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Services\DeploymentProvenanceService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DeploymentProvenanceTest extends TestCase
{
    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $keypair = sodium_crypto_sign_seed_keypair(str_repeat("\x42", 32));
        $secret = sodium_crypto_sign_secretkey($keypair);
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
        config([
            'app.genesis_ed25519_secret_key' => bin2hex($secret),
            'app.iicp_version' => 'v1.10.81.2',
            'app.iicp_build_id' => 'sha256:'.str_repeat('b', 64),
            'app.iicp_deployment' => [
                'kind' => 'shared_hosting',
                'release_tag' => 'v1.10.81.2',
                'source_commit' => str_repeat('a', 40),
                'deployed_at' => '2026-07-29T16:54:13Z',
                'openapi_version' => '1.6.0',
                'protocol_min' => '1.9.0',
                'protocol_max' => '1.9.0',
                'root_key_id' => 'did:web:iicp.network#key-1',
                'image_digest' => null,
                'sbom_digest' => null,
            ],
        ]);
    }

    public function test_endpoint_emits_verifiable_content_free_record(): void
    {
        $record = $this->getJson('/.well-known/iicp-deployment.json')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonPath('schema', 'iicp.deployment-record.v1')
            ->assertJsonPath('directory.flavor', 'php')
            ->assertJsonPath('artifact.image_digest', null)
            ->json();

        $this->assertTrue(DeploymentProvenanceService::verify($record, $this->publicKey));
        $rendered = json_encode($record);
        $this->assertStringNotContainsString('hostname', $rendered);
        $this->assertStringNotContainsString('database', $rendered);
        $this->assertStringNotContainsString('credential', $rendered);
    }

    public function test_tamper_wrong_purpose_key_rotation_and_staleness_fail(): void
    {
        $record = app(DeploymentProvenanceService::class)->record();
        $this->assertIsArray($record);

        $tampered = $record;
        $tampered['artifact']['build_digest'] = 'sha256:'.str_repeat('c', 64);
        $this->assertFalse(DeploymentProvenanceService::verify($tampered, $this->publicKey));

        $wrongPurpose = $record;
        $wrongPurpose['signature']['purpose'] = 'iicp-event-v1';
        $this->assertFalse(DeploymentProvenanceService::verify($wrongPurpose, $this->publicKey));

        $rotated = $record;
        $rotated['signature']['key_id'] = 'did:web:iicp.network#key-2';
        $this->assertFalse(DeploymentProvenanceService::verify($rotated, $this->publicKey));

        $this->assertFalse(DeploymentProvenanceService::verify(
            $record,
            $this->publicKey,
            CarbonImmutable::parse('2026-08-05T16:54:14Z'),
            604800
        ));
    }

    public function test_shared_fixture_matches_php_output(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('parity/iicp-deployment-record-v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame($fixture['valid_record'], app(DeploymentProvenanceService::class)->record());
    }

    public function test_endpoint_fails_closed_when_release_metadata_is_missing(): void
    {
        config(['app.iicp_deployment.source_commit' => null]);
        $this->getJson('/.well-known/iicp-deployment.json')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'deployment_record_unavailable');
    }

    public function test_endpoint_fails_closed_for_invalid_deployment_kind(): void
    {
        config(['app.iicp_deployment.kind' => 'private-hostname']);
        $this->getJson('/.well-known/iicp-deployment.json')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'deployment_record_unavailable');
    }
}
