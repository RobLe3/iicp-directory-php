<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reputations', function (Blueprint $table) {
            // EMA of proxy-observed latency (α=0.1, consistent with ReputationService)
            $table->float('observed_latency_ms')->nullable()->after('avg_latency_ms');
        });
    }

    public function down(): void
    {
        Schema::table('reputations', function (Blueprint $table) {
            $table->dropColumn('observed_latency_ms');
        });
    }
};
