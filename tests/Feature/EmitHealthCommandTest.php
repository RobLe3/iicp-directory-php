<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-048 / #374 — iicp:emit-health publishes one signed HEALTH event per active node,
 * closing the seed→log half of the federation-aware mesh_health pipeline.
 */
class EmitHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveNode(): Node
    {
        return Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://emit.test',
            'region' => 'eu',
            'node_token_hash' => 'h',
            'max_concurrent' => 1,
            'tokens_per_min' => 100,
            'available' => true,
            'last_seen' => now(),
            'public_reachable' => true,
        ]);
    }

    public function test_emits_one_health_event_per_active_node(): void
    {
        $node = $this->makeActiveNode();

        $this->artisan('iicp:emit-health')
            ->expectsOutputToContain('Emitted 1 HEALTH event')
            ->assertExitCode(0);

        $event = NodeEvent::where('event_type', 'HEALTH')->where('node_id', $node->id)->first();
        $this->assertNotNull($event);
        $this->assertArrayHasKey('evaluator_did', $event->payload);
    }

    public function test_no_events_when_no_active_nodes(): void
    {
        $this->artisan('iicp:emit-health')
            ->expectsOutputToContain('Emitted 0 HEALTH event')
            ->assertExitCode(0);

        $this->assertSame(0, NodeEvent::where('event_type', 'HEALTH')->count());
    }
}
