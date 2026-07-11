<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_usage_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('usage_date');
            $table->string('mode', 32);
            $table->unsignedBigInteger('request_count')->default(0);
            $table->timestamps();
            $table->unique(['usage_date', 'mode']);
            $table->index('usage_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_usage_daily');
    }
};
