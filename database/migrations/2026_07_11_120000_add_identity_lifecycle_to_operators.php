<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #618 — operator identity lifecycle is distinct from policy-signing-key
 * lifecycle.  The original public key remains private historical evidence;
 * inactive identities are excluded from public recognition and cannot make new
 * self-service or delegation claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->string('identity_status', 16)->default('active')->after('operator_pubkey');
            $table->char('successor_operator_pubkey_sha256', 64)->nullable()->after('identity_status');
            $table->unsignedInteger('rotation_epoch')->nullable()->after('successor_operator_pubkey_sha256');
            $table->timestamp('identity_revoked_at')->nullable()->after('rotation_epoch');
            $table->string('identity_reason_class', 64)->nullable()->after('identity_revoked_at');
            $table->index(['identity_status', 'updated_at'], 'operators_identity_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->dropIndex('operators_identity_status_idx');
            $table->dropColumn([
                'identity_status',
                'successor_operator_pubkey_sha256',
                'rotation_epoch',
                'identity_revoked_at',
                'identity_reason_class',
            ]);
        });
    }
};
