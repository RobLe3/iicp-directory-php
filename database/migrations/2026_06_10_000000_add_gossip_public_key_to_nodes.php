<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add gossip_public_key (hex Ed25519 public key) to nodes table.
 *
 * Used by PEER_EXCHANGE auth (iicp-dir.md §3.6 errata v1.5.1, #495):
 * the adapter registers its Ed25519 gossip signing key via the `public_key`
 * field in REGISTER. Peers resolve it here via GET /api/v1/node/{id} to
 * verify incoming gossip signatures without holding any directory credential.
 *
 * Separate from:
 * - `did_public_key` — Phase 3 DID identity key (different purpose)
 * - `cx_public_key` — X25519 ECDH key for confidential inference (different algorithm)
 * - `operator_pubkey` — operator identity key from iicp-node init
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('gossip_public_key', 128)->nullable()->after('cx_public_key');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('gossip_public_key');
        });
    }
};
