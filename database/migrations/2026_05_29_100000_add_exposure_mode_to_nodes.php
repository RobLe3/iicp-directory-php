<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-043 §9 — Add exposure_mode (8-category enum) to nodes table.
 *
 * Populated by SDK qualify_service() during registration; the directory surfaces
 * it in /v1/discover responses so consumers can prefer direct vs relay paths.
 *
 * Enum values: outbound_only | ipv4_public_direct | ipv4_cgnat_blocked |
 *   ipv6_direct_firewall_required | ipv6_direct_pinhole_available |
 *   relay_required | tunnel_required | dual_stack_available
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('exposure_mode', 36)->nullable()->after('sdk_language');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('exposure_mode');
        });
    }
};
