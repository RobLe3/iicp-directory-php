<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_telemetry', function (Blueprint $table) {
            $table->id();
            $table->string('node_id', 36)->index();
            $table->string('proxy_node_id', 36)->index();
            $table->unsignedBigInteger('time_bucket'); // Unix epoch floored to 60s
            $table->float('latency_ms_observed');
            $table->unsignedInteger('tokens_observed')->default(0);
            $table->string('status', 32)->default('success');
            $table->string('qos_advertised', 32)->nullable();
            $table->boolean('qos_met')->default(true);
            $table->timestamps();

            // One report per (node_id, proxy_node_id, time_bucket) — dedup key
            $table->unique(['node_id', 'proxy_node_id', 'time_bucket'], 'proxy_telemetry_dedup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_telemetry');
    }
};
