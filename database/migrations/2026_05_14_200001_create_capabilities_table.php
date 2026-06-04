<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capabilities', function (Blueprint $table) {
            $table->id();
            $table->uuid('node_id');
            $table->string('intent', 255);
            $table->json('models');
            $table->unsignedInteger('max_tokens');
            $table->timestamps();

            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
            $table->index(['node_id', 'intent']);
            $table->index('intent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capabilities');
    }
};
