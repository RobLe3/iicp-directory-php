<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NodeEventChainHeadMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_the_existing_committed_tip(): void
    {
        $signature = str_repeat('ab', 64);
        DB::table('node_events')->insert([
            'event_id' => (string) Str::uuid(),
            'seq' => 42,
            'event_type' => 'REGISTER',
            'service_id' => null,
            'node_id' => null,
            'ts_ms' => 1,
            'payload' => '{}',
            'prev_hash' => null,
            'signature' => $signature,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_07_26_090000_create_node_event_chain_heads_table.php',
        );
        $migration->down();
        $migration->up();

        $head = DB::table('node_event_chain_heads')->where('chain_id', 'genesis')->first();
        $this->assertNotNull($head);
        $this->assertSame(42, (int) $head->last_seq);
        $this->assertSame($signature, $head->last_signature);
    }
}
