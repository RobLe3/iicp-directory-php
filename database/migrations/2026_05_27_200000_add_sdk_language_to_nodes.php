<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surface the SDK language + version each node is running. Free-form
 * lowercase string (not an enum) so future C / C++ / Java / Go / WASM SDKs
 * can self-identify without a directory migration.
 *
 * Format: ^[a-z0-9_-]{1,32}$ (validated in RegisterController). The website
 * renders a small pill badge per language; unknown values render as the
 * literal string.
 *
 * Spec: ADR-016 (SDK contract) + ADR-021 (multi-model registration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('sdk_language', 32)->nullable()->after('public_reachable')
                ->comment('SDK language tag: python / typescript / rust / etc. Free-form for future SDKs.');
            $table->string('sdk_version', 32)->nullable()->after('sdk_language')
                ->comment('SDK package version (semver) — surfaced on /v1/discover.');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['sdk_language', 'sdk_version']);
        });
    }
};
