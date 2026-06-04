<?php

// SPDX-License-Identifier: Apache-2.0

/**
 * POST /api/v1/heartbeat — periodic liveness + load + reputation update.
 *
 * Every operator-run node beats here on the cadence echoed back in the response
 * (`next_heartbeat_ms`, currently 30s). A missing heartbeat past EXPIRY_SECONDS
 * causes Bootstrap/Peers/Discover to omit the node — this is the directory's
 * sole authority signal (ADR-003: directory is the single source of truth on
 * node liveness during Phase 1).
 *
 * The same beat doubles as the transport for adapter-reported task outcome
 * metrics (Phase 3). When the adapter ships tasks_success / tasks_failed /
 * avg_latency_ms, those are folded into the node's reputation score so future
 * discover() calls reflect real-world behavior, not just self-declared capacity.
 *
 * Implements:
 *   - ADR-003 — directory authority and heartbeat-derived availability
 *   - DIR-ADDR-07 — observed-IP recording on every heartbeat (drift detection)
 *   - ADR-008 §reputation — task-outcome feedback into the scoring formula
 *
 * Authentication is enforced by the NodeTokenAuth middleware (the resolved
 * Node is attached to the request as `_authenticated_node`); this controller
 * relies on that and never trusts the body's `node_id` for identity.
 */

namespace App\Http\Controllers;

use App\Models\Node;
use App\Services\NodeAddressObserver;
use App\Services\NodeEventLogger;
use App\Services\NodeRegistry;
use App\Services\OtelTracer;
use App\Services\ReputationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
    public function __construct(
        private ReputationService $reputation,
        private NodeAddressObserver $addressObserver,
        private NodeEventLogger $eventLogger,
        private NodeRegistry $registry,
    ) {}

    /**
     * Apply a heartbeat update and (optionally) absorb reported task metrics.
     *
     * The update is intentionally partial: each field is only written when the
     * client supplied it, so adapters can omit fields they don't track (e.g.
     * the Rust node only reports load + active_jobs; metrics come from Python
     * adapters). Reputation upsert is skipped when both counters are zero to
     * avoid moving the mean toward noise during idle periods.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Accept both UUIDs and custom alphanumeric names (max 36 chars,
            // matching the RegisterController and the nodes.id CHAR(36) column).
            'node_id' => ['required', 'string', 'max:36', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/'],
            'load' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'active_jobs' => ['sometimes', 'integer', 'min:0'],
            'available' => ['sometimes', 'boolean'],
            // Phase 3 — task outcome metrics reported by adapter
            'metrics' => ['sometimes', 'array'],
            // RT-01 (#375): cap at 1000 per heartbeat (physically implausible above this;
            // ReputationService additionally caps positive delta to +0.10 per call).
            'metrics.tasks_success' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'metrics.tasks_failed' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'metrics.avg_latency_ms' => ['sometimes', 'numeric', 'min:0'],
            // ADR-019 pricing update
            'pricing' => ['sometimes', 'array'],
            'pricing.credit_cost_multiplier' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'pricing.pricing_model' => ['sometimes', 'string', 'in:per_token,per_request,flat'],
            'pricing.declaration_signature' => ['sometimes', 'nullable', 'string', 'max:128'],
            // ADR-047 Part A (#411) — HMAC of the nonce issued in the previous response.
            'challenge_response' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $span = OtelTracer::startSpan($request, 'iicp.directory.heartbeat');

        /** @var Node $node */
        $node = $request->get('_authenticated_node');

        $observedIp = $this->addressObserver->getObservedIp($request);

        $updates = [
            'last_seen' => now(),
            'load' => $validated['load'] ?? $node->load,
            'active_jobs' => $validated['active_jobs'] ?? $node->active_jobs,
            // A heartbeat proves the node is alive + serving, so it must FULLY clear a
            // prior dormancy — restore available=true (alongside status=active below)
            // unless the node explicitly reports available=false this beat. Previously
            // `?? $node->available` preserved the false LivenessMonitor set when the
            // node went briefly stale (sleep/blip), so a heartbeating node stayed
            // available=false → excluded from discover forever until a manual
            // re-register. (Fixes "nodes vanish after a stale window and never come
            // back without a restart".)
            'available' => $validated['available'] ?? true,
            'observed_source_ip' => $observedIp,
            'status' => 'active',   // defensive: clear dormant if heartbeat arrives
            'dormant_since' => null,
        ];

        // ADR-047 Part A (#411) — cryptographic liveness via challenge-response.
        // The node HMACs the nonce we issued last beat with its node_hmac_key; a
        // match upgrades "holds a node_token" to "controls the HMAC key"
        // (anti-replay), recorded as liveness_verified_at — no dial-back needed
        // (works for CGNAT/IPv6). Then we issue a fresh nonce for the next beat.
        // Additive: absent challenge_response → liveness stays unverified (back-compat).
        $hmacKey = (string) ($node->node_hmac_key ?? '');
        if (! empty($validated['challenge_response']) && ! empty($node->liveness_challenge) && $hmacKey !== '') {
            $expected = hash_hmac('sha256', $node->liveness_challenge, $hmacKey);
            if (hash_equals($expected, $validated['challenge_response'])) {
                $updates['liveness_verified_at'] = now();
            }
        }
        $nextChallenge = bin2hex(random_bytes(16));
        $updates['liveness_challenge'] = $nextChallenge;

        // ADR-019: re-verify pricing declaration on every heartbeat; update attested flag
        if (isset($validated['pricing'])) {
            $pricing = $this->registry->resolvePricingBlock(
                $validated['pricing'],
                $node->node_hmac_key ?? ''
            );
            $updates['credit_cost_multiplier'] = $pricing['credit_cost_multiplier'];
            $updates['pricing_model'] = $pricing['pricing_model'];
            $updates['declaration_signature'] = $pricing['declaration_signature'];
            $updates['attested'] = $pricing['attested'];
        }

        $node->update($updates);

        $this->addressObserver->observe($node, $observedIp, 'heartbeat');

        // Update reputation if adapter reported task metrics
        $metrics = $validated['metrics'] ?? [];
        $tasksSuccess = $metrics['tasks_success'] ?? 0;
        $tasksFailed = $metrics['tasks_failed'] ?? 0;
        $avgLatencyMs = (float) ($metrics['avg_latency_ms'] ?? 0.0);

        if ($tasksSuccess > 0 || $tasksFailed > 0) {
            $this->reputation->upsert($node->id, $tasksSuccess, $tasksFailed, $avgLatencyMs);
            // Accumulate lifetime jobs for bootstrap floor threshold
            $node->increment('lifetime_jobs', $tasksSuccess + $tasksFailed);
            // Reload reputation so the event carries the fresh post-upsert score.
            $node->load('reputation');
            $updatedScore = (float) ($node->reputation?->score ?? 0.5);
            // Phase 6 prereq: REPUTATION_UPDATE event lets replicas track score changes
            // from adapter-reported metrics (separate signal from proxy SCORE_UPDATE events).
            $this->eventLogger->log('REPUTATION_UPDATE', $node->id, [
                'source' => 'heartbeat_metrics',
                'tasks_success' => $tasksSuccess,
                'tasks_failed' => $tasksFailed,
                'avg_latency_ms' => $avgLatencyMs,
                'reputation_score' => $updatedScore,
            ]);
        }

        // W-042 / db-D4prime: HEARTBEAT event emission removed.
        // Per S.13 v0.3.0 (db-D3prime): replicas derive liveness from
        // nodes.last_seen + nodes.load + nodes.active_jobs (canonical row
        // columns updated above). HEARTBEAT events were 99% of node_events
        // table growth (126k of 126k rows at 8 nodes pre-cleanup); their
        // information content is already in the canonical row.
        // Reads still go via the relation until Phase 2 drops `reputations`;
        // dual-write in ReputationService keeps nodes.reputation_score in
        // sync for replicas reading the snapshot.
        $reputationScore = (float) ($node->reputation?->score ?? 0.5);

        $span->setAttribute('iicp.node_id', $validated['node_id'])
            ->setAttribute('iicp.heartbeat.available', (bool) ($validated['available'] ?? $node->available))
            ->setAttribute('iicp.heartbeat.load', (float) ($validated['load'] ?? $node->load))
            ->setAttribute('iicp.heartbeat.reputation_score', $reputationScore);
        $span->end();

        return response()->json([
            'ok' => true,
            'next_heartbeat_ms' => 30000,
            'reputation_score' => $reputationScore,
            // ADR-047 Part A (#411) — HMAC this with node_hmac_key, return as
            // `challenge_response` next beat to prove liveness without dial-back.
            'challenge' => $nextChallenge,
        ]);
    }
}
