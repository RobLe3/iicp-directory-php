<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #458 — hash-chain the signed event log (prev_hash). Rust-directory parity (#385):
 * iicp-directory-rs/migrations/006_add_prev_hash_to_node_events.sql.
 *
 * Each signed event binds its predecessor's signature into its own signing input via
 * prev_hash = SHA256_hex(ascii(previous signed event's signature)), seeding from
 * GENESIS_ROOT = SHA256_hex("iicp:dir:event-log:genesis:v1") when there is no signed
 * predecessor (spec/iicp-federated-directory.md §5.1). Altering any event cascades into
 * every later signature, making insert/delete/reorder detectable — tamper-evident
 * ordering for federation and founder ordinal badges (#310). 64 lowercase-hex chars;
 * null for legacy rows written before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_events', function (Blueprint $table) {
            $table->char('prev_hash', 64)->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('node_events', function (Blueprint $table) {
            $table->dropColumn('prev_hash');
        });
    }
};
