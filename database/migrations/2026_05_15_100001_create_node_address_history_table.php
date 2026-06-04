<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_address_history', function (Blueprint $table) {
            $table->id();
            $table->uuid('node_id');
            $table->string('ip_address', 45);
            $table->enum('request_type', ['register', 'heartbeat', 'other']);
            $table->timestamp('observed_at');

            $table->index('node_id');
            $table->index('observed_at');
            $table->foreign('node_id')->references('id')->on('nodes')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_address_history');
    }
};
