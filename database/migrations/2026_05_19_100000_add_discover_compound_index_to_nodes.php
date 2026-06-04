<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Compound index covers the discover hot-path: WHERE available=1 AND status='active' AND last_seen>=?
// MySQL can use this to narrow to live nodes in one B-tree range scan instead of two separate indexes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->index(['available', 'status', 'last_seen'], 'nodes_discover_idx');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropIndex('nodes_discover_idx');
        });
    }
};
