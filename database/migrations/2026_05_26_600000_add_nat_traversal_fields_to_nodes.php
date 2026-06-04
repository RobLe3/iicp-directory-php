<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #331 Phase A.1 / ADR-041: surface NAT-traversal details per node.
 *
 * Adds three optional columns to `nodes`:
 *   - transport_method  enum-like string ('direct', 'upnp_mapped', 'stun_hole_punch',
 *                                          'turn_relay', 'external_tunnel', 'unknown')
 *   - nat_type          observability hint ('full_cone', 'restricted_cone',
 *                                            'port_restricted', 'symmetric', 'unknown')
 *   - transport_metadata JSON; forward-compat slot for ADR-041 transport_candidates[]
 *
 * All three are nullable for back-compat — existing nodes and adapters that
 * don't supply these fields continue to register normally.
 *
 * Spec lineage: spec/iicp-dir.md §3.1 REGISTER (ADR-041 additions block).
 * Surfaced in: GET /v1/discover responses + GET /api/v1/stats aggregate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('transport_method', 32)->nullable()
                ->comment('#331 Phase A.1 / ADR-041: NAT-traversal tier method');
            $table->string('nat_type', 32)->nullable()
                ->comment('#331 Phase A.1 / ADR-041: observed NAT type (full_cone, symmetric, etc.)');
            $table->json('transport_metadata')->nullable()
                ->comment('#331 Phase A.1 / ADR-041: forward-compat for transport_candidates[] + relay_endpoint');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['transport_method', 'nat_type', 'transport_metadata']);
        });
    }
};
