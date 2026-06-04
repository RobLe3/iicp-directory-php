<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('endpoint', 255);
            $table->string('region', 64);
            $table->float('load')->default(0.0);
            $table->unsignedInteger('active_jobs')->default(0);
            $table->boolean('available')->default(true);
            $table->timestamp('last_seen')->nullable();
            $table->string('node_token_hash', 512);
            $table->unsignedInteger('max_concurrent');
            $table->unsignedInteger('tokens_per_min');
            $table->string('public_key')->nullable();
            $table->timestamps();

            $table->index('available');
            $table->index('last_seen');
            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
