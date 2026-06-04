<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add nullable node_id to iicp_telemetry_probes.
 *
 * Part of issue #373: per-node active reachability probing (post-VPS migration).
 * Directory-infra probes (no target node) keep node_id = null.
 * Per-node reachability probes set node_id to the probed node's id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iicp_telemetry_probes', function (Blueprint $table) {
            $table->string('node_id', 36)->nullable()->after('probe_token_id');
            $table->index(['node_id', 'probed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('iicp_telemetry_probes', function (Blueprint $table) {
            $table->dropIndex(['node_id', 'probed_at']);
            $table->dropColumn('node_id');
        });
    }
};
