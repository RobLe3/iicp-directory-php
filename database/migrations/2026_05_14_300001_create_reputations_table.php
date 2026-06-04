<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reputations', function (Blueprint $table) {
            $table->uuid('node_id')->primary();
            $table->float('score')->default(0.5);
            $table->unsignedInteger('tasks_total')->default(0);
            $table->unsignedInteger('tasks_failed')->default(0);
            $table->float('avg_latency_ms')->default(0.0);
            $table->timestamps();
            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reputations');
    }
};
