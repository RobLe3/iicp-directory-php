<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use App\Models\TelemetryProbe;
use Illuminate\Support\Collection;

/**
 * NodeHealthService — ADR-044, Phase A (issue #372).
 *
 * Health is a per-node property first; mesh health is a derived aggregate.
 *
 * Prior to ADR-044 the only health surface was `mesh_health`, a single global
 * score dominated by directory-infrastructure signals (discover latency +
 * conformance) plus a global self-reported task sum — it never reflected the
 * average health of the nodes in the mesh. This service computes a per-node
 * health vector from signals already collected (no new node-side wire
 * contract, no schema change) and aggregates them into a mesh-health figure
 * that is the *median* of active provider nodes, with the full distribution
 * exposed so a few good nodes cannot mask a tail of bad ones.
 *
 * Phase A uses self-attested reachability; Phase B (#373) swaps in directory
 * active-probe verification once origin IPv6 egress exists.
 */
class NodeHealthService
{
    public function __construct(private ?UptimeService $uptime = null)
    {
        $this->uptime ??= new UptimeService;
    }

    /** A heartbeat older than this means the node is offline (gate → score 0, excluded from mesh). */
    private const HEARTBEAT_TTL_SECONDS = 90;

    /** Below this many active nodes, a single mesh number is not meaningful. */
    private const MIN_MESH_SAMPLE = 3;

    // Component weights — sum to 1.0. Reachability dominates because an unreachable
    // node cannot serve regardless of latency. Reputation is intentionally absent:
    // health reflects operational liveness, not earned task history (#492 / ADR-044).
    private const W_REACHABILITY = 0.70;

    private const W_LATENCY = 0.30;

    /**
     * Per-node health vector. Returns score (0–100), label, the component
     * sub-scores, and `observed` — true when at least one component is backed
     * by a directory-observed operational signal rather than self-attested route
     * metadata. Task/inference latency is intentionally not part of operational
     * health; it is exposed separately as a performance/QoS signal (#560).
     */
    public function forNode(Node $node): array
    {
        return $this->buildHealth(
            $node,
            $this->recentReachabilityProbe($node->id),
            $this->recentLatencyProbe($node->id),
            [
                'uptime' => $this->uptime->uptimeScoreForNode($node->id),
                'stability' => $this->uptime->stabilityScoreForNode($node->id),
            ],
        );
    }

    /**
     * Batch health vectors for discovery and mesh summaries.  This preserves
     * every per-node calculation while preventing N+1 probe/event queries as
     * the public provider set grows.
     *
     * @param  Collection<int,Node>  $nodes
     * @return array<string,array<string,mixed>> keyed by node ID
     */
    public function forNodes(Collection $nodes): array
    {
        $nodes = $nodes->values();
        $ids = $nodes->pluck('id')->filter()->values()->all();
        if ($ids === []) {
            return [];
        }

        $reachByNode = $this->recentProbesByNode($ids, false);
        $latencyByNode = $this->recentProbesByNode($ids, true);
        $lifecycleByNode = $this->uptime->healthScoresForNodes($ids);

        $health = [];
        foreach ($nodes as $node) {
            $health[$node->id] = $this->buildHealth(
                $node,
                $reachByNode[$node->id] ?? null,
                $latencyByNode[$node->id] ?? null,
                $lifecycleByNode[$node->id] ?? ['uptime' => null, 'stability' => null],
            );
        }

        return $health;
    }

    /** @param array{uptime: ?float, stability: ?float} $lifecycle */
    private function buildHealth(Node $node, ?TelemetryProbe $reachProbe, ?TelemetryProbe $latencyProbe, array $lifecycle): array
    {
        if ($this->liveness($node) === 0.0) {
            return [
                'score' => 0,
                'label' => 'offline',
                'observed' => false,
                'confidence' => 'none',
                'evidence_level' => 'missing',
                'latency_ms_basis' => 'none',
                'components' => [
                    'liveness' => 0.0,
                    'reachability' => null,
                    'latency' => null,
                    'uptime' => null,
                    'stability' => null,
                    'freshness' => 0.0,
                ],
                'evaluated_at' => now()->toIso8601String(),
            ];
        }

        [$reach, $reachBasis] = $this->reachabilityScore($node, $reachProbe);
        [$lat, $latObserved, $latBasis] = $this->latencyScore($latencyProbe);
        $observed = $latObserved || $reachBasis === 'directory_observed';

        $score01 = self::W_REACHABILITY * $reach
            + self::W_LATENCY * $lat;
        $score = (int) round($score01 * 100);
        if ($latBasis === 'none' && $score >= 85) {
            // No observed/self-reported latency is insufficient evidence for a
            // full "healthy" label. Keep the node reachable, but cap it below
            // the healthy threshold until latency evidence arrives.
            $score = 84;
        }
        $evidence = $this->evidenceLevel($reachBasis, $latBasis);

        return [
            'score' => $score,
            'label' => $this->label($score),
            'observed' => $observed,
            'confidence' => $this->confidence($evidence, $latBasis),
            'evidence_level' => $evidence,
            'latency_ms_basis' => $latBasis,
            'components' => [
                'liveness' => 1.0,
                'reachability' => round($reach, 3),
                'latency' => round($lat, 3),
                // Verified lifecycle evidence: null means no signed lifecycle
                // evidence exists yet, which is more honest than a fabricated score.
                'uptime' => $lifecycle['uptime'],
                'stability' => $lifecycle['stability'],
                'freshness' => 1.0,
            ],
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Mesh health — median of per-node health over active provider nodes, with
     * the distribution and 10th-percentile (worst-decile) exposed. Clients are
     * never modeled in the directory, so this is providers only by construction.
     */
    public function meshHealth(Collection $activeNodes): array
    {
        $healths = collect($this->forNodes($activeNodes))->values();
        $scores = $healths->pluck('score')
            ->reject(fn ($s) => $s === null)
            ->sort()
            ->values();
        $sample = $scores->count();

        if ($sample === 0) {
            return [
                // 0.0 satisfies the float[0,1] contract (DIR-STATS-01 probe);
                // label:"unavailable" carries the "no active nodes" semantic.
                'score' => 0.0,
                'label' => 'unavailable',
                'mean' => null,
                'p10' => null,
                'distribution' => $this->emptyDistribution(),
                'sample' => 0,
                'basis' => 'active_provider_nodes',
                'window' => 'live',
            ];
        }

        $median = $this->percentile($scores, 50);

        // Normalize internal 0–100 integer scores to [0, 1] floats for the wire format.
        // Internal label() + percentile() remain on the 0–100 scale.
        return [
            'score' => round($median / 100.0, 3),
            'label' => $sample < self::MIN_MESH_SAMPLE ? 'insufficient_sample' : $this->label($median),
            'mean' => round($scores->avg() / 100.0, 3),
            'p10' => round($this->percentile($scores, 10) / 100.0, 3),
            'distribution' => $this->distribution($healths),
            'sample' => $sample,
            'basis' => 'active_provider_nodes',
            'window' => 'live',
        ];
    }

    /** Provider nodes that are available with a fresh heartbeat — the mesh's serving set. */
    public function activeProviderNodes(): Collection
    {
        return Node::with('reputation')
            ->where('available', true)
            ->where('status', 'active')
            ->whereNull('endpoint_verified_dead_at')
            ->where('last_seen', '>=', now()->subSeconds(self::HEARTBEAT_TTL_SECONDS))
            ->where(function ($q) {
                $q->where('public_reachable', true)
                    ->orWhereIn('exposure_mode', NodeScorer::RELAY_REACHABLE_EXPOSURE_MODES);
            })
            ->get();
    }

    private function liveness(Node $node): float
    {
        if ($node->last_seen === null) {
            return 0.0;
        }

        return $node->last_seen->gte(now()->subSeconds(self::HEARTBEAT_TTL_SECONDS)) ? 1.0 : 0.0;
    }

    /**
     * Phase B (#373): uses directory active-probe result when a recent probe exists.
     * Falls back to Phase A self-attested signal when no probe is on record.
     *
     * A probe is "recent" when it is within the last 10 minutes — matches the
     * every-5-minute probe cadence with a 2× safety margin.
     */
    private function reachabilityScore(Node $node, ?TelemetryProbe $recentProbe): array
    {
        if ($recentProbe !== null) {
            return [$recentProbe->passed ? 1.0 : 0.0, 'directory_observed'];
        }

        // A confirmed-dead listed endpoint is stronger evidence than the
        // node's self-attested exposure_mode/relay_capable flag.  Without this
        // guard a dead Quick Tunnel or otherwise hidden endpoint can remain
        // labelled "healthy" solely because it is still heartbeating to the
        // directory.  The lifecycle/probe commands clear the flag after a
        // successful probe, at which point normal self-attested fallback resumes.
        if ($node->endpoint_verified_dead_at !== null) {
            return [0.0, 'directory_observed'];
        }

        // Phase A fallback: self-attested signal.
        //
        // A node is "reachable" in Phase A when it self-attests a routable serving
        // surface: dial-back-verified (public_reachable), relay-capable server
        // (relay_capable), or any named ADR-043 exposure_mode (IPv6 direct, CGNAT,
        // relay_required, tunnel, etc. — a null exposure_mode means internal/legacy).
        //
        // With W_REACHABILITY=0.70, the old 0.5 partial score made relay-tier and
        // IPv6-behind-firewall nodes mathematically unable to reach "healthy"
        // (0.70×0.5+0.30×1=0.65 < 0.85 threshold).  All three paths score 1.0 here
        // because the node self-attests it can serve consumers (#492 follow-up).
        if ($node->public_reachable || $node->relay_capable || $node->exposure_mode !== null) {
            return [1.0, 'self_attested'];
        }

        return [0.0, 'missing'];
    }

    /**
     * Operational latency is the directory-observed control/health surface
     * latency, not model/task generation latency (#560).
     *
     * The task-latency columns (`reputations.observed_latency_ms`,
     * `nodes.avg_latency_ms_recent`, `nodes.avg_latency_ms`) remain valuable
     * performance/QoS signals, but using them here made busy nodes look less
     * reachable than nodes with no traffic history. Health therefore uses:
     *
     * 1. recent directory reachability probe latency, when available
     * 2. neutral 0.5 with low confidence, when no operational latency exists
     *
     * ≤ 50ms → 1.0, ≥ 500ms → 0.0; no operational data → neutral 0.5.
     */
    private function latencyScore(?TelemetryProbe $probe): array
    {
        $probeLatency = $probe?->latency_ms;
        if ($probeLatency !== null && (float) $probeLatency > 0) {
            return [$this->latencyCurve((float) $probeLatency), true, 'directory_probe'];
        }

        return [0.5, false, 'none'];
    }

    private function recentReachabilityProbe(string $nodeId): ?TelemetryProbe
    {
        return TelemetryProbe::where('node_id', $nodeId)
            ->where('probe_type', 'reachability')
            ->where('probed_at', '>=', now()->subMinutes(10))
            ->orderByDesc('probed_at')
            ->first(['node_id', 'passed', 'latency_ms', 'probed_at']);
    }

    private function recentLatencyProbe(string $nodeId): ?TelemetryProbe
    {
        return TelemetryProbe::where('node_id', $nodeId)
            ->where('probe_type', 'reachability')
            ->whereNotNull('latency_ms')
            ->where('probed_at', '>=', now()->subMinutes(10))
            ->orderByDesc('probed_at')
            ->first(['node_id', 'passed', 'latency_ms', 'probed_at']);
    }

    /** @param array<int,string> $nodeIds @return array<string,TelemetryProbe> */
    private function recentProbesByNode(array $nodeIds, bool $requireLatency): array
    {
        $query = TelemetryProbe::whereIn('node_id', $nodeIds)
            ->where('probe_type', 'reachability')
            ->where('probed_at', '>=', now()->subMinutes(10));
        if ($requireLatency) {
            $query->whereNotNull('latency_ms');
        }

        return $query->orderByDesc('probed_at')
            ->get(['node_id', 'passed', 'latency_ms', 'probed_at'])
            ->unique('node_id')
            ->keyBy('node_id')
            ->all();
    }

    private function latencyCurve(float $ms): float
    {
        return max(0.0, min(1.0, 1.0 - ($ms - 50.0) / 450.0));
    }

    /**
     * Public ADR-044 label mapping (0–100 int score → health label). Exposed so the
     * federation resolver (ADR-048) buckets per-evaluator votes by the same vocabulary.
     */
    public function labelForScore(int $score): string
    {
        return $this->label($score);
    }

    private function label(int $score): string
    {
        return match (true) {
            $score >= 85 => 'healthy',
            $score >= 65 => 'degraded',
            $score >= 40 => 'impaired',
            default => 'critical',
        };
    }

    private function evidenceLevel(string $reachBasis, string $latBasis): string
    {
        $observed = in_array('directory_observed', [$reachBasis, $latBasis], true)
            || in_array('directory_probe', [$reachBasis, $latBasis], true);
        $self = in_array('self_attested', [$reachBasis, $latBasis], true)
            || in_array('self_reported', [$reachBasis, $latBasis], true);

        return match (true) {
            $observed && $self => 'mixed',
            $latBasis === 'proxy_observed' => 'proxy_observed',
            $latBasis === 'directory_probe' => 'directory_observed',
            $reachBasis === 'directory_observed' => 'directory_observed',
            $self => 'self_attested',
            default => 'missing',
        };
    }

    private function confidence(string $evidenceLevel, string $latBasis): string
    {
        return match (true) {
            $evidenceLevel === 'missing' => 'none',
            $latBasis === 'none' => 'low',
            $evidenceLevel === 'self_attested' => 'medium',
            default => 'high',
        };
    }

    /** Nearest-rank percentile over a pre-sorted, 0-indexed collection of ints. */
    private function percentile(Collection $sorted, int $p): int
    {
        $n = $sorted->count();
        if ($n === 0) {
            return 0;
        }
        $rank = (int) ceil(($p / 100.0) * $n);
        $idx = max(0, min($n - 1, $rank - 1));

        return (int) $sorted->get($idx);
    }

    private function distribution(Collection $healths): array
    {
        $dist = $this->emptyDistribution();
        foreach ($healths as $h) {
            $label = $h['label'];
            if (isset($dist[$label])) {
                $dist[$label]++;
            }
        }

        return $dist;
    }

    private function emptyDistribution(): array
    {
        return ['healthy' => 0, 'degraded' => 0, 'impaired' => 0, 'critical' => 0, 'offline' => 0];
    }
}
