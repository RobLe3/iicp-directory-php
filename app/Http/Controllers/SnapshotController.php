<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\Capability;
use App\Models\Node;
use App\Models\NodeEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * GET /v1/snapshot — Genesis Seed bootstrap endpoint (S.13 §5.5).
 *
 * Returns canonical state of all registered nodes + their capabilities in
 * a single point-in-time view. Replicas call this once at startup, then
 * GET /v1/events?since_seq=<snapshot_seq> for the catch-up tail (§5.3).
 *
 * Auth: Bearer replica_token (ReplicaTokenAuth middleware).
 *
 * Normative requirements (S.13 §5.5):
 * - snapshot_seq = highest emitted event.seq at generation time [DIR-FED-15]
 * - genesis_hash matches GET /v1/events response [DIR-FED-17]
 * - Snapshot is a consistent point-in-time view (one DB transaction)
 * - 5 req/replica/hour rate limit (bootstrap is infrequent)
 *
 * Charter: P6-1.3b-iii.
 */
class SnapshotController extends Controller
{
    public const SCHEMA_VERSION = 'v0.3.0';

    public function show(): JsonResponse
    {
        [$nodes, $capabilities, $snapshotSeq] = DB::transaction(function () {
            $snapshotSeq = (int) (NodeEvent::max('seq') ?? 0);
            $nodes = Node::all();
            $capabilities = Capability::all();

            return [$nodes, $capabilities, $snapshotSeq];
        });

        return response()->json([
            'schema_version' => self::SCHEMA_VERSION,
            'snapshot_seq' => $snapshotSeq,
            'snapshot_ts_ms' => (int) (microtime(true) * 1000),
            'genesis_hash' => $this->genesisHash(),
            'nodes' => $nodes->map(fn (Node $n) => [
                'node_id' => $n->id,
                'endpoint' => $n->endpoint,
                'region' => $n->region,
                'load' => $n->load,
                'active_jobs' => $n->active_jobs,
                'available' => (bool) $n->available,
                'last_seen' => optional($n->last_seen)->toIso8601String(),
                'reputation_score' => $n->reputation_score !== null ? (float) $n->reputation_score : null,
                'credit_balance' => $n->credit_balance !== null ? (float) $n->credit_balance : null,
                'cip_policy' => [
                    'allow_remote_inference' => (bool) $n->allow_remote_inference,
                    'allow_tool_execution' => (bool) $n->allow_tool_execution,
                    'allow_file_access' => (bool) $n->allow_file_access,
                ],
                'pricing' => [
                    'credit_cost_multiplier' => $n->credit_cost_multiplier !== null ? (float) $n->credit_cost_multiplier : null,
                    'pricing_model' => $n->pricing_model,
                    'attested' => (bool) $n->attested,
                ],
            ])->all(),
            'capabilities' => $capabilities->map(fn (Capability $c) => [
                'node_id' => $c->node_id,
                'intent' => $c->intent,
                'models' => $c->models,
                'max_tokens' => $c->max_tokens,
            ])->all(),
        ]);
    }

    /** Cached to match EventsController::genesisHash() — DIR-FED-17 parity. */
    private function genesisHash(): ?string
    {
        return Cache::remember('events:genesis_hash', 3600, function (): ?string {
            $genesis = NodeEvent::orderBy('seq')->first();
            if ($genesis === null) {
                return null;
            }
            $canonical = json_encode($genesis->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            return hash('sha256', $canonical);
        });
    }
}
