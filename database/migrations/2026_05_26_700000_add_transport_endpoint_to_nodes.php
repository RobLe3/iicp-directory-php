<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spec/iicp-dir.md v0.7.0 — split HTTP control plane from native binary data plane.
 *
 * Adds `transport_endpoint` column to `nodes`. Optional; URI scheme MUST be
 * `iicp://` (plaintext binary framing per ADR-040) or `iicpsec://` (TLS-wrapped).
 *
 * - `endpoint`            — HTTP/HTTPS control plane; used by directory's assertLive
 *                            (GET /iicp/health) and as HTTP transport fallback.
 * - `transport_endpoint`  — native IICP binary endpoint on default port 9484;
 *                            clients SHOULD prefer this for task CALLs.
 *
 * Nullable for back-compat. Nodes without it keep behaving as v0.6.x (HTTP-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('transport_endpoint', 255)->nullable()
                ->comment('spec v0.7.0: native IICP binary endpoint (iicp:// or iicpsec://, default port 9484)');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('transport_endpoint');
        });
    }
};
