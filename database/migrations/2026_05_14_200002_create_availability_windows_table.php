<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_windows', function (Blueprint $table) {
            $table->id();
            $table->uuid('node_id');
            $table->time('start_time');
            $table->time('end_time');
            $table->float('share')->default(1.0);
            $table->timestamps();

            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
            $table->index('node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_windows');
    }
};
