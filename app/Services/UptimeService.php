<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\NodeEvent;
use App\Models\TelemetryProbe;

/**
 * Compute verified cumulative uptime for a node from the signed event log.
 *
 * Uptime is derived by pairing session-start events (REGISTER, REACTIVATE) with
 * session-end events (EVICT, DEREGISTER) in seq order. The signed hash-chain makes
 * the log tamper-evident, so uptime computed here is audit-quality — suitable for
 * merit-based badge assignment rather than approximation.
 *
 * Only events emitted after EVICT/REACTIVATE were added (#508) contribute to closed
 * sessions. Existing REGISTER events (before the deploy) still anchor the CURRENT
 * open session, so operators who were already online accumulate uptime going forward.
 *
 * ALIGNMENT NOTE (spec/iicp-recognition.md):
 *   • Ranks (§4) are keyed on operator_id, NOT node_id. A node's uptime feeds into
 *     the operator's aggregate rank, but rank assignment lives on the operators table,
 *     not here. This service does NOT return a rank label.
 *   • Founder ordinals (§5.4) use calendar time since operators.first_seen_ms, not
 *     cumulative uptime. FounderLockinDetector owns that computation.
 *   • duration_progress_pct tracks ONLY the uptime-duration component of the next
 *     uptime-gated rank. Rank triggers also include heartbeat success rate (Tier 1 ≥95%,
 *     Tier 6 ≥99%), DIR-TRUST-01 pass (Tier 2/6), and operator-level aggregation.
 *     Consumers MUST NOT treat duration_progress_pct = 100 as rank qualification.
 */
class UptimeService
{
    private const SESSION_START_TYPES = ['REGISTER', 'REACTIVATE'];

    private const SESSION_END_TYPES = ['EVICT', 'DEREGISTER'];

    /**
     * Uptime-duration thresholds for uptime-gated ranks (spec/iicp-recognition.md §4.1).
     * Tiers 1, 2, 6 are the only ranks with an explicit uptime-duration gate.
     * Full rank qualification additionally requires heartbeat success rate + DIR-TRUST-01.
     */
    private const DURATION_GATES = [
        ['rank' => 1, 'key' => 'mesh_serf',     'title' => 'Mesh Serf',     'seconds' => 604_800],   // 7d
        ['rank' => 2, 'key' => 'local_daemon',  'title' => 'Local Daemon',  'seconds' => 2_592_000],  // 30d
        ['rank' => 6, 'key' => 'mesh_guardian', 'title' => 'Mesh Guardian', 'seconds' => 7_776_000],  // 90d
    ];

    /**
     * Compute uptime breakdown for the given node.
     *
     * Returns per-node session data plus progress toward the next rank's uptime-duration gate.
     * Rank assignment itself is operator-scoped and lives on the operators table.
     *
     * Fields:
     *   cumulative_seconds         — total verified closed-session online time
     *   current_session_seconds    — time in the current open session (0 if dormant)
     *   total_seconds              — cumulative + current_session
     *   sessions_count             — number of fully-closed sessions
     *   first_seen_ms              — ts_ms of the first REGISTER event (null if none)
     *   duration_progress_pct      — % toward the next uptime-duration gate (0.0–100.0)
     *                                duration component ONLY — not full rank qualification
     *   next_duration_gate_rank    — rank number whose uptime threshold is next (null = all met)
     *   next_duration_gate_key     — snake_case rank key (null = all met)
     *   next_duration_gate_title   — human-readable rank title (null = all met)
     *   next_duration_gate_seconds — seconds required to reach that gate (null = all met)
     *   met_duration_gate_rank     — rank number of the highest gate already met (null = none)
     *   met_duration_gate_key      — snake_case key for that gate (null = none)
     */
    public function uptimeForNode(string $nodeId): array
    {
        $events = NodeEvent::where('node_id', $nodeId)
            ->whereIn('event_type', array_merge(self::SESSION_START_TYPES, self::SESSION_END_TYPES))
            ->orderBy('seq')
            ->get(['event_type', 'ts_ms']);

        return $this->uptimeFromEvents($events, (int) (microtime(true) * 1000));
    }

    /**
     * Load health-only lifecycle components for a discovery result set in a
     * bounded number of queries.  Discovery renders these values as evidence;
     * they must not turn into five database lookups per returned node.
     *
     * @param  array<int,string>  $nodeIds
     * @return array<string,array{uptime: ?float, stability: ?float}>
     */
    public function healthScoresForNodes(array $nodeIds, int $windowSeconds = 86400): array
    {
        $ids = array_values(array_unique(array_filter($nodeIds, static fn ($id) => is_string($id) && $id !== '')));
        if ($ids === []) {
            return [];
        }

        $eventsByNode = NodeEvent::whereIn('node_id', $ids)
            ->whereIn('event_type', array_merge(self::SESSION_START_TYPES, self::SESSION_END_TYPES))
            ->orderBy('node_id')
            ->orderBy('seq')
            ->get(['node_id', 'event_type', 'ts_ms'])
            ->groupBy('node_id');

        $probeStatsByNode = TelemetryProbe::whereIn('node_id', $ids)
            ->where('probe_type', 'reachability')
            ->where('probed_at', '>=', now()->subSeconds($windowSeconds))
            ->selectRaw('node_id, COUNT(*) as total, SUM(CASE WHEN passed THEN 1 ELSE 0 END) as passed')
            ->groupBy('node_id')
            ->get()
            ->keyBy('node_id');

        $nowMs = (int) (microtime(true) * 1000);
        $windowStartMs = $nowMs - ($windowSeconds * 1000);
        $scores = [];
        foreach ($ids as $nodeId) {
            $events = $eventsByNode->get($nodeId, collect());
            if ($events->isEmpty()) {
                $scores[$nodeId] = ['uptime' => null, 'stability' => null];

                continue;
            }

            $uptime = $this->uptimeFromEvents($events, $nowMs);
            $uptimeScore = null;
            if ($uptime['first_seen_ms'] !== null) {
                $observedSeconds = max(1, (int) floor(($nowMs - (int) $uptime['first_seen_ms']) / 1000));
                $uptimeScore = round(max(0.0, min(1.0, $uptime['total_seconds'] / $observedSeconds)), 3);
            }
            $scores[$nodeId] = [
                // Keep the single-node contract: an event stream without a
                // signed session start has no auditable uptime denominator.
                'uptime' => $uptimeScore,
                'stability' => $this->stabilityFromEvents(
                    $events,
                    $probeStatsByNode->get($nodeId),
                    $windowStartMs,
                    $nowMs,
                ),
            ];
        }

        return $scores;
    }

    /** @return array<string,mixed> */
    private function uptimeFromEvents($events, int $nowMs): array
    {

        $cumulativeMs = 0;
        $sessionsCount = 0;
        $sessionStartMs = null;
        $firstSeenMs = null;

        foreach ($events as $event) {
            if (in_array($event->event_type, self::SESSION_START_TYPES)) {
                $sessionStartMs = $event->ts_ms;
                if ($firstSeenMs === null) {
                    $firstSeenMs = $event->ts_ms;
                }
            } elseif (in_array($event->event_type, self::SESSION_END_TYPES)) {
                if ($sessionStartMs !== null) {
                    $cumulativeMs += max(0, $event->ts_ms - $sessionStartMs);
                    $sessionsCount++;
                    $sessionStartMs = null;
                }
            }
        }

        $currentSessionMs = ($sessionStartMs !== null) ? max(0, $nowMs - $sessionStartMs) : 0;
        $totalSeconds = (int) (($cumulativeMs + $currentSessionMs) / 1000);

        [$metGate, $nextGate, $progressPct] = $this->durationGateProgress($totalSeconds);

        return [
            'cumulative_seconds' => (int) ($cumulativeMs / 1000),
            'current_session_seconds' => (int) ($currentSessionMs / 1000),
            'total_seconds' => $totalSeconds,
            'sessions_count' => $sessionsCount,
            'first_seen_ms' => $firstSeenMs,
            'duration_progress_pct' => $progressPct,
            'next_duration_gate_rank' => $nextGate ? $nextGate['rank'] : null,
            'next_duration_gate_key' => $nextGate ? $nextGate['key'] : null,
            'next_duration_gate_title' => $nextGate ? $nextGate['title'] : null,
            'next_duration_gate_seconds' => $nextGate ? $nextGate['seconds'] : null,
            'met_duration_gate_rank' => $metGate ? $metGate['rank'] : null,
            'met_duration_gate_key' => $metGate ? $metGate['key'] : null,
        ];
    }

    /**
     * Verified uptime score for health components.
     *
     * Returns total signed online time divided by the signed observation lifetime
     * since first REGISTER/REACTIVATE. Null means no signed lifecycle evidence yet.
     */
    public function uptimeScoreForNode(string $nodeId): ?float
    {
        $uptime = $this->uptimeForNode($nodeId);
        if ($uptime['first_seen_ms'] === null) {
            return null;
        }

        $nowMs = (int) (microtime(true) * 1000);
        $observedSeconds = max(1, (int) floor(($nowMs - (int) $uptime['first_seen_ms']) / 1000));

        return round(max(0.0, min(1.0, $uptime['total_seconds'] / $observedSeconds)), 3);
    }

    /**
     * Conservative stability score over a rolling window.
     *
     * Combines signed lifecycle coverage with independent reachability probes when
     * present, then subtracts a small flap penalty for repeated EVICTs. Null means
     * the node has no signed lifecycle evidence, so a stability claim would be
     * unauditable.
     */
    public function stabilityScoreForNode(string $nodeId, int $windowSeconds = 86400): ?float
    {
        $events = $this->lifecycleEvents($nodeId);
        if ($events->isEmpty()) {
            return null;
        }

        $nowMs = (int) (microtime(true) * 1000);
        $windowStartMs = $nowMs - ($windowSeconds * 1000);
        $probeStats = TelemetryProbe::where('node_id', $nodeId)
            ->where('probe_type', 'reachability')
            ->where('probed_at', '>=', now()->subSeconds($windowSeconds))
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN passed THEN 1 ELSE 0 END) as passed')
            ->first();

        return $this->stabilityFromEvents($events, $probeStats, $windowStartMs, $nowMs);
    }

    private function stabilityFromEvents($events, mixed $probeStats, int $windowStartMs, int $nowMs): float
    {
        $firstSeenMs = (int) $events->first()->ts_ms;
        $effectiveStartMs = max($windowStartMs, $firstSeenMs);
        $effectiveWindowSeconds = max(1, (int) floor(($nowMs - $effectiveStartMs) / 1000));

        $onlineSeconds = $this->onlineSecondsInWindow($events, $effectiveStartMs, $nowMs);
        $coverage = max(0.0, min(1.0, $onlineSeconds / $effectiveWindowSeconds));

        $probeTotal = (int) ($probeStats?->total ?? 0);
        $probeRate = $probeTotal > 0
            ? max(0.0, min(1.0, ((int) $probeStats->passed) / $probeTotal))
            : $coverage;

        $evictions = $events
            ->filter(fn ($event) => $event->event_type === 'EVICT' && (int) $event->ts_ms >= $effectiveStartMs)
            ->count();
        $flapPenalty = min(0.5, $evictions * 0.10);

        $score = ((0.70 * $coverage) + (0.30 * $probeRate)) * (1.0 - $flapPenalty);

        return round(max(0.0, min(1.0, $score)), 3);
    }

    private function lifecycleEvents(string $nodeId)
    {
        return NodeEvent::where('node_id', $nodeId)
            ->whereIn('event_type', array_merge(self::SESSION_START_TYPES, self::SESSION_END_TYPES))
            ->orderBy('seq')
            ->get(['event_type', 'ts_ms']);
    }

    private function onlineSecondsInWindow($events, int $windowStartMs, int $windowEndMs): int
    {
        $onlineStartMs = null;
        $onlineMs = 0;

        foreach ($events as $event) {
            $ts = (int) $event->ts_ms;
            if (in_array($event->event_type, self::SESSION_START_TYPES, true)) {
                $onlineStartMs = $ts;

                continue;
            }

            if (in_array($event->event_type, self::SESSION_END_TYPES, true) && $onlineStartMs !== null) {
                $start = max($onlineStartMs, $windowStartMs);
                $end = min($ts, $windowEndMs);
                if ($end > $start) {
                    $onlineMs += $end - $start;
                }
                $onlineStartMs = null;
            }
        }

        if ($onlineStartMs !== null) {
            $start = max($onlineStartMs, $windowStartMs);
            if ($windowEndMs > $start) {
                $onlineMs += $windowEndMs - $start;
            }
        }

        return (int) floor($onlineMs / 1000);
    }

    /**
     * Compute which duration gate has been met, which is next, and % progress between them.
     * Returns [metGate|null, nextGate|null, progressPct].
     *
     * @return array{0: array|null, 1: array|null, 2: float}
     */
    private function durationGateProgress(int $totalSeconds): array
    {
        $met = null;
        $next = null;

        foreach (self::DURATION_GATES as $gate) {
            if ($totalSeconds >= $gate['seconds']) {
                $met = $gate;
            } else {
                $next = $gate;
                break;
            }
        }

        if ($next === null) {
            return [$met, null, 100.0];
        }

        $fromSeconds = $met ? $met['seconds'] : 0;
        $span = $next['seconds'] - $fromSeconds;
        $progress = round(($totalSeconds - $fromSeconds) / $span * 100, 1);

        return [$met, $next, max(0.0, min(100.0, $progress))];
    }
}
