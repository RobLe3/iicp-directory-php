<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EffectiveCapabilityProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_fixture_is_exactly_pinned(): void
    {
        $path = base_path('parity/effective-capability-v1/fixture.json');
        $this->assertSame(
            'cdc8fa5131525ba5ef49cbea2aba02c9183411e01b1ead7ea3bd1503ba528d88',
            hash_file('sha256', $path),
        );
        $this->assertSame(
            'urn:iicp:profile:effective-capability:v1',
            json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['profile_id'],
        );
    }

    public function test_effective_variant_survives_registration_and_node_projection(): void
    {
        Http::fake(['https://capability-node.example/iicp/health' => Http::response('ok', 200)]);
        $response = $this->postJson('/api/v1/register', $this->payload([[
            'intent' => 'urn:iicp:intent:tool:invoke:v1',
            'variant_id' => 'sandboxed-tool',
            'execution_capabilities' => ['tool_execution'],
            'limits' => ['payload_bytes' => ['value' => 1048576, 'unit' => 'bytes']],
            'claim_provenance' => ['source' => 'conformance_probe'],
            'extensions' => [
                'org.example.optional-batching' => ['required' => false, 'value' => ['enabled' => true]],
            ],
        ]]))->assertCreated();

        $detail = $this->getJson('/api/v1/node/'.$response->json('node_id'))
            ->assertOk()
            ->assertJsonPath('capabilities.0.variant_id', 'sandboxed-tool')
            ->assertJsonPath('capabilities.0.execution_capabilities.0', 'tool_execution')
            ->assertJsonPath('capabilities.0.limits.payload_bytes.unit', 'bytes')
            ->assertJsonPath('capabilities.0.claim_provenance.source', 'conformance_probe');
        $this->assertFalse(
            $detail->json('capabilities.0.extensions')['org.example.optional-batching']['required']
        );
    }

    public function test_exact_duplicates_and_duplicate_variant_ids_are_rejected(): void
    {
        $capability = [
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'variant_id' => 'text',
            'models' => ['fixture'],
            'max_tokens' => 1024,
        ];
        $this->postJson('/api/v1/register', $this->payload([$capability, $capability]))
            ->assertUnprocessable();

        $changed = $capability;
        $changed['features'] = ['structured_output'];
        $this->postJson('/api/v1/register', $this->payload([$capability, $changed]))
            ->assertUnprocessable();
    }

    private function payload(array $capabilities): array
    {
        return [
            'endpoint' => 'https://capability-node.example',
            'region' => 'eu-central',
            'capabilities' => $capabilities,
            'limits' => ['max_concurrent' => 1, 'tokens_per_min' => 1000],
        ];
    }
}
