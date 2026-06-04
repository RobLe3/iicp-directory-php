<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credits', function (Blueprint $table) {
            $table->uuid('node_id')->primary();
            $table->decimal('balance', 15, 4)->default(0);
            $table->timestamps();
            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('node_id');
            $table->decimal('amount', 15, 4);
            $table->enum('type', ['credit', 'debit']);
            $table->string('task_id', 36)->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
            $table->index(['node_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('credits');
    }
};
