<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #609 — safe operator governance acceptance metadata.
 *
 * This is not a login/account system and not legal certification. It records only
 * the minimal current-version acceptance evidence needed for strict routing profiles
 * to distinguish operator_bound from known_operator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->string('terms_version', 64)->nullable()->after('provenance');
            $table->timestamp('terms_accepted_at')->nullable()->after('terms_version');
            $table->string('dpa_version', 64)->nullable()->after('terms_accepted_at');
            $table->timestamp('dpa_accepted_at')->nullable()->after('dpa_version');
            $table->string('acceptance_method', 64)->nullable()->after('dpa_accepted_at');
            $table->char('acceptance_nonce_sha256', 64)->nullable()->after('acceptance_method');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->dropColumn([
                'terms_version',
                'terms_accepted_at',
                'dpa_version',
                'dpa_accepted_at',
                'acceptance_method',
                'acceptance_nonce_sha256',
            ]);
        });
    }
};
