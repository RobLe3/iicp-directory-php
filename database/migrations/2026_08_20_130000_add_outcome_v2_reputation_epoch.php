<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->float('legacy_reputation_score')->nullable()->after('reputation_score');
            $table->string('reputation_model', 32)->default('outcome-v2')->after('legacy_reputation_score');
            $table->uuid('reputation_epoch')->nullable()->after('reputation_model');
            $table->string('last_metrics_batch_id', 64)->nullable()->after('reputation_epoch');
        });

        $epoch = (string) Str::uuid();
        DB::table('nodes')->update([
            'legacy_reputation_score' => DB::raw('reputation_score'),
            'reputation_score' => 0.5,
            'reputation_model' => 'outcome-v2',
            'reputation_epoch' => $epoch,
            'last_metrics_batch_id' => null,
        ]);
        DB::table('reputations')->update(['score' => 0.5]);
    }

    public function down(): void
    {
        DB::table('nodes')->whereNotNull('legacy_reputation_score')->update([
            'reputation_score' => DB::raw('legacy_reputation_score'),
        ]);

        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn([
                'legacy_reputation_score',
                'reputation_model',
                'reputation_epoch',
                'last_metrics_batch_id',
            ]);
        });
    }
};
