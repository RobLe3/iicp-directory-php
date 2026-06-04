<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Credit;
use App\Models\CreditIpGate;
use App\Models\Node;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RT-02/RT-02b regression tests (#376, #380).
 * Verifies that free credits cannot be harvested by deregister→re-register cycling,
 * including the bypass via a new node_id from the same IP address.
 */
class CreditHarvestRegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $nodeId = '550e8400-dead-beef-a716-446655440099';

    private string $plainToken = 'harvest-test-token-40-chars-exactly!!!';

    private function makeNode(?string $nodeId = null): Node
    {
        return Node::create([
            'id' => $nodeId ?? $this->nodeId,
            'endpoint' => 'https://harvest-test.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash($this->plainToken, PASSWORD_BCRYPT),
            'node_hmac_key' => bin2hex(random_bytes(32)),
            'status' => 'active',
            'available' => true,
            'max_concurrent' => 1,
            'tokens_per_min' => 1000,
        ]);
    }

    /** Free credits awarded on first registration — baseline. */
    public function test_free_credits_awarded_on_first_registration(): void
    {
        $this->makeNode();
        $service = app(CreditService::class);

        $awarded = $service->maybeAllocateFreeCredits($this->nodeId);
        $this->assertNotNull($awarded, 'First-time node should receive free credits');
        $this->assertEquals(5.0, $awarded);
    }

    /**
     * RT-02: CreditService blocks re-award when a credits row with recent
     * free_credit_last_allocation_at exists for the node_id.
     *
     * This tests the gate that the MySQL migration makes reachable: once CASCADE
     * is removed in production, the credits row survives deregister, and this
     * check blocks the harvest on re-registration with the same node_id.
     */
    public function test_existing_credits_row_blocks_free_credit_re_award(): void
    {
        $service = app(CreditService::class);

        // Create a node and inject a credits row with a recent allocation timestamp
        // directly — simulating the state after: register → earn credits → deregister
        // → credits row preserved (production MySQL after RT-02 migration).
        $this->makeNode();

        Credit::create([
            'node_id' => $this->nodeId,
            'balance' => 0,
            'free_credit_last_allocation_at' => now()->subMinutes(10), // recent — within 6h gate
        ]);

        // CreditService must find the existing credits row and block the award
        $reAwarded = $service->maybeAllocateFreeCredits($this->nodeId);
        $this->assertNull($reAwarded,
            'mustAllocateFreeCredits must return null when a recent allocation record exists (RT-02 gate)');
    }

    /** Ensure that a genuinely new node_id still earns free credits normally. */
    public function test_different_node_id_earns_free_credits_normally(): void
    {
        $node2Id = '550e8400-dead-beef-a716-44665544009a';
        $this->makeNode($node2Id);

        $service = app(CreditService::class);
        $awarded = $service->maybeAllocateFreeCredits($node2Id);
        $this->assertNotNull($awarded, 'Genuinely new node must earn free credits');
    }

    /**
     * RT-02b: IP-level gate blocks harvest via new node_id from the same IP (#380).
     *
     * Attack: register node_id A from IP X → earn credits → deregister/abandon →
     * register node_id B from same IP X → new credits row → bypass 6h gate.
     *
     * Mitigation: credit_ip_gates tracks per-IP last_allocation_at.
     * A fresh node_id from an IP that already allocated within 6h must be blocked.
     */
    public function test_new_node_id_from_same_ip_is_blocked_by_ip_gate(): void
    {
        $nodeIdA = '550e8400-dead-beef-a716-44665544009b';
        $nodeIdB = '550e8400-dead-beef-a716-44665544009c';
        $ip = '203.0.113.42';

        $this->makeNode($nodeIdA);
        $this->makeNode($nodeIdB);

        // Pre-seed IP gate as if node_id A already allocated from this IP recently
        CreditIpGate::create([
            'ip_address' => $ip,
            'last_allocation_at' => now()->subMinutes(30), // 30 min ago — within 6h gate
            'allocation_count' => 1,
        ]);

        $service = app(CreditService::class);

        // Node B has never allocated — node_id gate would pass — but IP gate must block it
        $awarded = $service->maybeAllocateFreeCredits($nodeIdB, $ip);
        $this->assertNull($awarded, 'IP gate must block free credit award when same IP allocated recently (RT-02b)');
    }

    /** IP gate allows a new node_id from a fresh (never-seen) IP to allocate. */
    public function test_fresh_ip_allows_new_node_to_allocate(): void
    {
        $nodeId = '550e8400-dead-beef-a716-44665544009d';
        $this->makeNode($nodeId);

        $service = app(CreditService::class);
        $awarded = $service->maybeAllocateFreeCredits($nodeId, '203.0.113.99');
        $this->assertNotNull($awarded, 'New IP with new node_id must earn free credits');
        $this->assertEquals(5.0, $awarded);

        // IP gate row must have been created
        $ipGate = CreditIpGate::find('203.0.113.99');
        $this->assertNotNull($ipGate, 'IP gate row must be created after allocation');
        $this->assertEquals(1, $ipGate->allocation_count);
    }

    /** IP gate allows allocation again once the 6h window has expired. */
    public function test_expired_ip_gate_allows_reallocation(): void
    {
        $nodeId = '550e8400-dead-beef-a716-44665544009e';
        $ip = '203.0.113.55';

        $this->makeNode($nodeId);

        CreditIpGate::create([
            'ip_address' => $ip,
            'last_allocation_at' => now()->subHours(8), // 8 hours ago — gate expired
            'allocation_count' => 1,
        ]);

        $service = app(CreditService::class);
        $awarded = $service->maybeAllocateFreeCredits($nodeId, $ip);
        $this->assertNotNull($awarded, 'Expired IP gate must allow reallocation');
        $this->assertEquals(5.0, $awarded);
    }
}
