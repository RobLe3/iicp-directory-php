<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Serialize signed event-log appends through a durable chain-head row.
 *
 * The row is intentionally separate from node_events: retention may remove
 * historical events, but sequence allocation must remain monotonic and the next
 * event must still bind the last committed signature (or an unsigned span).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_event_chain_heads', function (Blueprint $table) {
            $table->string('chain_id', 32)->primary();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->string('last_signature', 128)->nullable();
            $table->timestamps();
        });

        $tip = DB::table('node_events')
            ->orderByDesc('seq')
            ->first(['seq', 'signature']);

        DB::table('node_event_chain_heads')->insert([
            'chain_id' => 'genesis',
            'last_seq' => (int) ($tip->seq ?? 0),
            'last_signature' => $tip->signature ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('node_event_chain_heads');
    }
};
