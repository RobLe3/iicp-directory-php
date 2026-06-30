<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #494 — per-node health model list reported by the SDK on each heartbeat.
 *
 * When null: backward-compat — SDK has not reported health_models; discover
 * uses only static capabilities registration.
 * When []: runtime reports no models loaded; discover excludes this node from
 * model-filtered queries (models are registered but unservable right now).
 * When ['qwen2.5:0.5b',...]: only these models are currently live; discover
 * filters out any registered model not in this list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->json('health_models')->nullable()->after('backend');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('health_models');
        });
    }
};
