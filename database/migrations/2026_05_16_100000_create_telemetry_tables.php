<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Probe tokens — separate from node tokens; issued per REACH probe installation
        Schema::create('probe_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();   // SHA-256 hex of the raw token (64 chars)
            $table->string('label')->nullable();           // human-readable e.g. "eu-de-cologne-01"
            $table->string('region')->nullable();
            $table->timestamp('expires_at')->nullable();   // null = never expires
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        // Raw probe results from REACH daemon
        Schema::create('iicp_telemetry_probes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('probe_token_id')->constrained('probe_tokens')->cascadeOnDelete();
            $table->string('run_id', 36);                 // UUID from REACH
            $table->string('probe_id', 64);
            $table->string('probe_type', 32);             // reachability | conformance
            $table->string('test_id', 32);                // e.g. DIR-DISC-01
            $table->string('level', 8);                   // MUST | SHOULD | INFO
            $table->boolean('passed');
            $table->float('latency_ms')->nullable();
            $table->text('detail')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('probed_at')->useCurrent();
            $table->index(['probe_token_id', 'probed_at']);
            $table->index(['test_id', 'probed_at']);
        });

        // Rolling aggregates (5-minute job writes these)
        Schema::create('iicp_telemetry_aggregates', function (Blueprint $table) {
            $table->id();
            $table->string('window', 8);                  // 1h | 24h | 7d
            $table->string('metric', 64);                 // e.g. discover_p95_ms
            $table->float('value')->nullable();
            $table->integer('sample_count')->default(0);
            $table->timestamp('computed_at')->useCurrent();
            $table->index(['window', 'metric', 'computed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iicp_telemetry_aggregates');
        Schema::dropIfExists('iicp_telemetry_probes');
        Schema::dropIfExists('probe_tokens');
    }
};
