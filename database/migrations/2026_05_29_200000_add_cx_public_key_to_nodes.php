<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IICP-CX §3.1 — Add cx_public_key (JSON) to nodes table.
 *
 * Stores the node's X25519 public key advertised for E2E payload confidentiality
 * (#360 iicp-confidentiality.md). Separate from `did_public_key` (Phase 3 DID identity).
 *
 * JSON shape: { algorithm, encoding, key, key_id, not_after, hybrid_pq }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->json('cx_public_key')->nullable()->after('exposure_mode');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('cx_public_key');
        });
    }
};
