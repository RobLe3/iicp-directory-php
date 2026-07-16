<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator public keys are serialized as `ed25519:` plus 64 hexadecimal
 * characters (72 characters total). The original 64-character columns worked
 * in SQLite tests but truncate/reject valid identities in MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('operator_pubkey', 80)->nullable()->change();
        });

        Schema::table('operators', function (Blueprint $table) {
            $table->string('operator_pubkey', 80)->change();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('operator_pubkey', 64)->nullable()->change();
        });

        Schema::table('operators', function (Blueprint $table) {
            $table->string('operator_pubkey', 64)->change();
        });
    }
};
