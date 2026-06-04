<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow directory-internal probes (iicp:probe-nodes, #373 Phase B) to record
 * results without a probe_token_id. REACH conformance probes always supply a token;
 * directory-active-reachability probes are token-less (sourced internally).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iicp_telemetry_probes', function (Blueprint $table): void {
            $table->unsignedBigInteger('probe_token_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('iicp_telemetry_probes', function (Blueprint $table): void {
            $table->unsignedBigInteger('probe_token_id')->nullable(false)->change();
        });
    }
};
