<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // Separate proxy_token issued at registration — only this token is accepted
            // by POST /v1/telemetry (Sybil quorum gate, #114)
            $table->string('proxy_token_hash', 60)->nullable()->after('node_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('proxy_token_hash');
        });
    }
};
