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
            // Optional during the legacy-SDK migration window. New SDKs send it
            // whenever metrics are present; absent IDs retain legacy at-most-once behavior.
            'metrics_batch_id' => ['sometimes', 'string', 'min:1', 'max:64', 'regex:/^[!-~]+$/'],
            // ADR-019 pricing update
            'pricing' => ['sometimes', 'array'],
            'pricing.credit_cost_multiplier' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'pricing.pricing_model' => ['sometimes', 'string', 'in:per_token,per_request,flat'],
            'pricing.declaration_signature' => ['sometimes', 'nullable', 'string', 'max:128'],
            // ADR-047 Part A (#411) — HMAC of the nonce issued in the previous response.
            'challenge_response' => ['sometimes', 'nullable', 'string', 'max:64'],
            // #494 — runtime model list from the node's local backend (e.g. Ollama /api/tags).
            // null/absent = SDK has not reported yet (backward compat, no filtering applied).
            // [] = backend reports no models loaded right now.
            // ['model:tag',...] = currently-live subset; discover filters to this intersection.
            'health_models' => ['sometimes', 'nullable', 'array'],
            'health_models.*' => ['string', 'max:128'],
            // #561 — provider-local backend/model readiness, reported by SDK observers.
            // This is deliberately separate from directory reachability health:
            // a node can be network-reachable while its local backend is cold,
            // loading, unstable, or draining. Store only the redacted public shape.
            'backend_stability' => ['sometimes', 'nullable', 'array'],
            'backend_stability.backend_state' => ['required_with:backend_stability', 'string', 'in:ok,degraded,draining'],
            'backend_stability.reason_class' => ['required_with:backend_stability', 'string', 'in:ok,backend_cold,backend_loading,backend_unstable,observer_error'],
            'backend_stability.retry_after_s' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
            'backend_stability.drain_until' => ['sometimes', 'nullable', 'integer', 'min:0'],
            // Optional updater evidence from SDKs. Advisory only; the directory
            // still derives routing/compliance from observed version/key fields.
            'auto_update_enabled' => ['sometimes', 'nullable', 'boolean'],
            'auto_update_interval_s' => ['sometimes', 'nullable', 'integer', 'min:300', 'max:86400'],
            'sdk_latest_seen' => ['sometimes', 'nullable', 'string', 'max:32'],
            'sdk_update_last_checked_at' => ['sometimes', 'nullable', 'date'],
            'sdk_update_error_class' => ['sometimes', 'nullable', 'string', 'max:64'],
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

        // #494 — update health_models if the SDK reported them this beat.
        // Explicit null means "clear" (operator reset); absent key = no change.
        if (array_key_exists('health_models', $validated)) {
            $updates['health_models'] = $validated['health_models'];
        }
        $updates += $this->backendStabilityUpdate($validated);
        foreach ([
            'auto_update_enabled',
            'auto_update_interval_s',
            'sdk_latest_seen',
            'sdk_update_last_checked_at',
            'sdk_update_error_class',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        // Uptime tracking (#508): capture dormant state BEFORE the update clears it.
        $wasDormant = $node->status === 'dormant';
        $dormantSinceMs = ($wasDormant && $node->dormant_since)
            ? $node->dormant_since->timestamp * 1000
            : null;

        $node->update($updates);

        // Emit REACTIVATE so UptimeService can open a new session in the signed log.
        if ($wasDormant) {
            $nowMs = (int) (microtime(true) * 1000);
            $this->eventLogger->log('REACTIVATE', $node->id, [
                'dormant_since_ms' => $dormantSinceMs,
                'dormancy_duration_seconds' => $dormantSinceMs !== null
                    ? (int) (($nowMs - $dormantSinceMs) / 1000)
                    : null,
            ]);
        }

        $this->addressObserver->observe($node, $observedIp, 'heartbeat');

        $node = $this->applyMetrics($node, $validated);

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
            'reputation_model' => $node->reputation_model ?? 'legacy',
            'reputation_epoch' => $node->reputation_epoch,
            'metrics_batch_accepted' => $validated['metrics_batch_id'] ?? null,
            // ADR-047 Part A (#411) — HMAC this with node_hmac_key, return as
            // `challenge_response` next beat to prove liveness without dial-back.
            'challenge' => $nextChallenge,
        ]);
    }

    /** @param array<string,mixed> $validated */
    private function applyMetrics(Node $node, array $validated): Node
    {
        $metrics = $validated['metrics'] ?? [];
        $tasksSuccess = $metrics['tasks_success'] ?? 0;
        $tasksFailed = $metrics['tasks_failed'] ?? 0;
        if ($tasksSuccess === 0 && $tasksFailed === 0) {
            return $node;
        }

        $avgLatencyMs = (float) ($metrics['avg_latency_ms'] ?? 0.0);
        $metricsBatchId = $validated['metrics_batch_id'] ?? null;
        $applied = $this->reputation->upsert(
            $node->id,
            $tasksSuccess,
            $tasksFailed,
            $avgLatencyMs,
            $metricsBatchId,
        );
        if ($applied) {
            $countedSuccess = min($tasksSuccess, ReputationService::MAX_COUNTED_SUCCESS_PER_HEARTBEAT);
            $node->increment('lifetime_jobs', $countedSuccess + $tasksFailed);
        }

        $node->refresh()->load('reputation');
        if ($applied) {
            $this->eventLogger->log('REPUTATION_UPDATE', $node->id, [
                'source' => 'heartbeat_metrics',
                'tasks_success' => $tasksSuccess,
                'tasks_failed' => $tasksFailed,
                'avg_latency_ms' => $avgLatencyMs,
                'reputation_score' => (float) ($node->reputation?->score ?? 0.5),
                'reputation_model' => $node->reputation_model ?? 'legacy',
                'metrics_batch_id' => $metricsBatchId,
            ]);
        }

        return $node;
    }

    /** @param array<string,mixed> $validated @return array<string,mixed> */
    private function backendStabilityUpdate(array $validated): array
    {
        return array_key_exists('backend_stability', $validated)
            ? ['backend_stability' => $this->normalizeBackendStability($validated['backend_stability'])]
            : [];
    }

    /**
     * Keep only the public, SDK-standard backend stability fields.
     *
     * SDK observers may know local details (backend URLs, model names, exception
     * messages). The directory stores none of those; it only keeps the coarse
     * state/reason and optional timing hints needed for routing/admission.
     */
    private function normalizeBackendStability(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $out = [
            'backend_state' => (string) ($raw['backend_state'] ?? 'degraded'),
            'reason_class' => (string) ($raw['reason_class'] ?? 'observer_error'),
        ];

        foreach (['retry_after_s', 'drain_until'] as $field) {
            if (array_key_exists($field, $raw) && $raw[$field] !== null) {
                $out[$field] = (int) $raw[$field];
            }
        }

        return $out;
    }
}
