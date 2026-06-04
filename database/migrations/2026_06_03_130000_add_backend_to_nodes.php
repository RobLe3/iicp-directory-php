<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `backend` — the detected backend server flavor a node runs
 * (ollama / lmstudio / vllm / llamacpp / anthropic / custom). Set at REGISTER
 * by the SDK's backend-flavor detection; surfaced in node detail + discover so
 * operators/consumers can see what serves each node. Additive + nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('backend', 32)->nullable()->after('relay_capable');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('backend');
        });
    }
};
