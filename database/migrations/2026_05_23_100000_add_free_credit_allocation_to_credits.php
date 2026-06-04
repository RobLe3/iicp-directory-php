<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credits', function (Blueprint $table) {
            // Timestamp of the last free credit allocation for this node.
            // Null = never allocated (eligible immediately). Used to enforce
            // the 6-hour re-allocation window (issue #306 / spec §free-tier).
            $table->timestamp('free_credit_last_allocation_at')->nullable()->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('credits', function (Blueprint $table) {
            $table->dropColumn('free_credit_last_allocation_at');
        });
    }
};
