<?php

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase A.2 / ADR-036: reputation rolling window rotation.
 *
 * Verifies the nightly cron rotates per-node windows older than the
 * configured window length (default 90d aligned with credit TTL).
 */
class RotateReputationWindowTest extends TestCase
{
    use RefreshDatabase;

    private function makeNode(array $overrides = []): Node
    {
        static $idx = 0;
        $idx++;

        return Node::create(array_merge([
            'id' => sprintf('550e8400-e29b-41d4-a716-446655%06d', $idx),
            'endpoint' => "https://node{$idx}.test",
            'region' => 'eu-central',
            'node_token_hash' => 'h',
            'max_concurrent' => 1,
            'tokens_per_min' => 100,
            'available' => true,
            'tasks_total_recent' => 100,
            'tasks_failed_recent' => 5,
            'avg_latency_ms_recent' => 250.0,
            'recent_window_start' => now()->subDays(95),  // older than default 90d
        ], $overrides));
    }

    public function test_rotates_node_with_window_older_than_default_90d(): void
    {
        $node = $this->makeNode();
        $this->artisan('iicp:rotate-reputation-window')->assertSuccessful();
        $node->refresh();
        $this->assertSame(0, (int) $node->tasks_total_recent);
        $this->assertSame(0, (int) $node->tasks_failed_recent);
        $this->assertEqualsWithDelta(0.0, (float) $node->avg_latency_ms_recent, 0.0001);
        // Window restarted within last 5 seconds
        $this->assertLessThan(5, abs(now()->diffInSeconds($node->recent_window_start)));
    }

    public function test_does_not_rotate_node_within_window(): void
    {
        $node = $this->makeNode(['recent_window_start' => now()->subDays(30)]);  // within 90d
        $this->artisan('iicp:rotate-reputation-window')->assertSuccessful();
        $node->refresh();
        $this->assertSame(100, (int) $node->tasks_total_recent);
        $this->assertSame(5, (int) $node->tasks_failed_recent);
    }

    public function test_dry_run_does_not_modify(): void
    {
        $node = $this->makeNode();
        $this->artisan('iicp:rotate-reputation-window', ['--dry-run' => true])->assertSuccessful();
        $node->refresh();
        $this->assertSame(100, (int) $node->tasks_total_recent);
        $this->assertEqualsWithDelta(95, $node->recent_window_start->diffInDays(now()), 1.0);
    }

    public function test_skips_archived_nodes(): void
    {
        $node = $this->makeNode(['status' => 'archived']);
        $this->artisan('iicp:rotate-reputation-window')->assertSuccessful();
        $node->refresh();
        $this->assertSame(100, (int) $node->tasks_total_recent, 'archived nodes must not rotate');
    }

    public function test_skips_nodes_with_null_window_start(): void
    {
        $node = $this->makeNode(['recent_window_start' => null]);
        $this->artisan('iicp:rotate-reputation-window')->assertSuccessful();
        $node->refresh();
        $this->assertSame(100, (int) $node->tasks_total_recent);
        $this->assertNull($node->recent_window_start);
    }

    public function test_custom_window_days_flag(): void
    {
        $node = $this->makeNode(['recent_window_start' => now()->subDays(40)]);  // within 90d default
        // With --window-days=30, this node is OUTSIDE the window → should rotate
        $this->artisan('iicp:rotate-reputation-window', ['--window-days' => 30])->assertSuccessful();
        $node->refresh();
        $this->assertSame(0, (int) $node->tasks_total_recent, 'custom window must apply');
    }

    public function test_rotates_multiple_nodes(): void
    {
        $a = $this->makeNode();
        $b = $this->makeNode();
        $c = $this->makeNode(['recent_window_start' => now()->subDays(10)]);  // within window
        $this->artisan('iicp:rotate-reputation-window')->assertSuccessful();
        $a->refresh();
        $b->refresh();
        $c->refresh();
        $this->assertSame(0, (int) $a->tasks_total_recent);
        $this->assertSame(0, (int) $b->tasks_total_recent);
        $this->assertSame(100, (int) $c->tasks_total_recent);
    }
}
