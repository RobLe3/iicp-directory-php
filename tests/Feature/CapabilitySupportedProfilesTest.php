<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CapabilitySupportedProfilesTest extends TestCase
{
    use RefreshDatabase;

    private const PROFILE = 'urn:iicp:profile:service-lifecycle:v1';

    public function test_register_persists_and_node_detail_projects_supported_profiles(): void
    {
        Http::fake(['https://profile-node.example/iicp/health' => Http::response('ok', 200)]);
        $response = $this->postJson('/api/v1/register', [
            'endpoint' => 'https://profile-node.example',
            'region' => 'eu-central',
            'capabilities' => [[
                'intent' => 'urn:iicp:intent:llm:chat:v1',
                'models' => ['qwen'],
                'max_tokens' => 4096,
                'supported_profiles' => [self::PROFILE],
            ]],
            'limits' => ['max_concurrent' => 1, 'tokens_per_min' => 1000],
        ])->assertCreated();

        $nodeId = $response->json('node_id');
        $this->assertSame(
            [self::PROFILE],
            Node::findOrFail($nodeId)->capabilities()->firstOrFail()->supported_profiles
        );
        $this->getJson('/api/v1/node/'.$nodeId)
            ->assertOk()
            ->assertJsonPath('capabilities.0.supported_profiles.0', self::PROFILE);
    }

    public function test_discover_projects_profiles_for_the_requested_intent_only(): void
    {
        $node = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://profile-node.example',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 1,
            'tokens_per_min' => 1000,
            'available' => true,
            'load' => 0.0,
            'active_jobs' => 0,
            'last_seen' => now(),
            'public_reachable' => true,
        ]);
        $node->capabilities()->create([
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['qwen'],
            'max_tokens' => 4096,
            'supported_profiles' => [self::PROFILE],
        ]);
        $node->capabilities()->create([
            'intent' => 'urn:iicp:intent:embedding:v1',
            'models' => ['embed'],
            'max_tokens' => 4096,
            'supported_profiles' => ['urn:iicp:profile:other:v1'],
        ]);

        $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')
            ->assertOk()
            ->assertJsonPath('nodes.0.supported_profiles', [self::PROFILE]);
    }

    public function test_register_rejects_malformed_duplicate_and_oversized_profile_lists(): void
    {
        $base = [
            'endpoint' => 'https://profile-node.example',
            'region' => 'eu-central',
            'capabilities' => [[
                'intent' => 'urn:iicp:intent:llm:chat:v1',
                'models' => ['qwen'],
                'max_tokens' => 4096,
            ]],
            'limits' => ['max_concurrent' => 1, 'tokens_per_min' => 1000],
        ];
        foreach ([
            ['not-a-profile'],
            [self::PROFILE, self::PROFILE],
            array_map(fn (int $i) => "urn:iicp:profile:test{$i}:v1", range(0, 16)),
        ] as $profiles) {
            $payload = $base;
            $payload['capabilities'][0]['supported_profiles'] = $profiles;
            $this->postJson('/api/v1/register', $payload)->assertUnprocessable();
        }
    }
}
