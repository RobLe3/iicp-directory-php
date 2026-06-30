<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-048 — federation-aware mesh_health. Per-node, per-evaluator health snapshots
 * replicated via the signed HEALTH event (S.13 §3.4, #374).
 *
 * One row per (node_id, evaluator_did): the latest snapshot that evaluator published
 * for that node. The mesh_health read resolves each node's canonical value by
 * majority-vote across evaluators (fallback most-recent by evaluated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_health_observations', function (Blueprint $table) {
            $table->id();
            $table->string('node_id', 36)->index();
            // The directory (seed or replica) that produced this health vector.
            $table->string('evaluator_did', 255);
            // Per-node health score on the wire scale [0,1] (ADR-044 forNode score/100).
            $table->float('score');
            $table->string('label', 32)->nullable();
            $table->json('components')->nullable();
            // Producer-stamped evaluation time — the monotonic key for staleness resolution.
            $table->unsignedBigInteger('evaluated_at_ms')->index();
            // Provenance: the HEALTH event that last wrote this row (idempotency aid).
            $table->uuid('event_id')->nullable();
            $table->timestamps();

            // Exactly one current snapshot per (node, evaluator).
            $table->unique(['node_id', 'evaluator_did']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_health_observations');
    }
};
