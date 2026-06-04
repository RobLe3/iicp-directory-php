<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #326 — separate "externally-reachable" from "internal-only" nodes.
 *
 * The directory needs an explicit data-model distinction so:
 *   1. The default /v1/discover query returns ONLY public-reachable nodes
 *      (mesh-joiner SDK never gets back a node it can't actually reach).
 *   2. Operators running internal-only stacks (docker compose dev) can still
 *      register + see their nodes via `?include_internal=true`.
 *   3. /api/v1/stats can split `active_nodes` (public) from `internal_nodes`,
 *      letting the homepage stop overcounting unreachable nodes.
 *
 * Set by:
 *   - RegisterController active reachability probe (#325 Layer 2)
 *   - Periodic re-verification command (#325 Layer 3)
 *
 * For NATted nodes (per #334 binary model), `public_reachable` defaults to
 * `true` because the operator's traversal claim means they intend the node
 * to be publicly reachable via UPnP / STUN / TURN-relay. Periodic dial-back
 * via the declared transport_method confirms whether that claim holds.
 *
 * Default: false (conservative — until proven reachable, treat as internal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('public_reachable')->default(false)->after('transport_endpoint')
                ->comment('#326: true = appears in default /v1/discover; false = needs ?include_internal=true');
            // Compound index supports the default `WHERE public_reachable=1 AND last_seen >= ?`
            // discover query path without re-scanning the whole table.
            $table->index(['public_reachable', 'last_seen'], 'nodes_public_reachable_last_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropIndex('nodes_public_reachable_last_seen_idx');
            $table->dropColumn('public_reachable');
        });
    }
};
