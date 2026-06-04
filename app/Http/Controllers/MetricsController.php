<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\Node;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-014 §3 — Directory /metrics endpoint (MUST by Phase 5 gate).
 *
 * Emits Prometheus exposition format (text/plain; version=0.0.4).
 * No external library required — format is plain text with # HELP / # TYPE headers.
 * Constraint: shared hosting (128 MB) — no Redis. Cached 60 s in file-based cache.
 */
class MetricsController extends Controller
{
    private const ACTIVE_WINDOW_SECONDS = 90;

    public function __invoke(): Response
    {
        $lines = Cache::remember('metrics.prometheus', 60, function () {
            return $this->buildLines();
        });

        return response($lines, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    private function buildLines(): string
    {
        $cutoff = now()->subSeconds(self::ACTIVE_WINDOW_SECONDS);

        // ── iicp_nodes_registered ────────────────────────────────────────────
        $registeredByRegion = Node::select('region', DB::raw('count(*) as cnt'))
            ->groupBy('region')
            ->pluck('cnt', 'region');

        // ── iicp_nodes_available ─────────────────────────────────────────────
        $availableByRegion = Node::where('available', true)
            ->where('last_seen', '>=', $cutoff)
            ->select('region', DB::raw('count(*) as cnt'))
            ->groupBy('region')
            ->pluck('cnt', 'region');

        // ── iicp_heartbeat_age_seconds (histogram approximation via buckets) ─
        // Sample last-seen ages for active nodes; build p50/p95 buckets.
        $agesSec = Node::where('available', true)
            ->whereNotNull('last_seen')
            ->pluck('last_seen')
            ->map(fn ($ts) => max(0, now()->diffInSeconds($ts)))
            ->sort()
            ->values();

        // ── iicp_discovery_score_p50 ─────────────────────────────────────────
        // Best available proxy: reputation score median over active nodes.
        $reputationScores = DB::table('reputations')
            ->join('nodes', 'reputations.node_id', '=', 'nodes.id')
            ->where('nodes.available', true)
            ->where('nodes.last_seen', '>=', $cutoff)
            ->pluck('reputations.score')
            ->sort()
            ->values()
            ->all();

        $lines = [];

        // ── iicp_nodes_registered gauge ──────────────────────────────────────
        $lines[] = '# HELP iicp_nodes_registered Total registered IICP nodes by region.';
        $lines[] = '# TYPE iicp_nodes_registered gauge';
        foreach ($registeredByRegion as $region => $cnt) {
            $lines[] = 'iicp_nodes_registered{region="'.$this->escapeLabelValue((string) $region).'"} '.$cnt;
        }

        // ── iicp_nodes_available gauge ───────────────────────────────────────
        $lines[] = '# HELP iicp_nodes_available Active IICP nodes (last_seen within 90 s) by region.';
        $lines[] = '# TYPE iicp_nodes_available gauge';
        $allRegions = $registeredByRegion->keys()->merge($availableByRegion->keys())->unique();
        foreach ($allRegions as $region) {
            $cnt = $availableByRegion->get($region, 0);
            $lines[] = 'iicp_nodes_available{region="'.$this->escapeLabelValue((string) $region).'"} '.$cnt;
        }

        // ── iicp_heartbeat_age_seconds summary ───────────────────────────────
        $lines[] = '# HELP iicp_heartbeat_age_seconds Age in seconds since last heartbeat for active nodes.';
        $lines[] = '# TYPE iicp_heartbeat_age_seconds summary';
        if ($agesSec->count() > 0) {
            $ages = $agesSec->all();
            $count = count($ages);
            $lines[] = 'iicp_heartbeat_age_seconds{quantile="0.5"} '.$this->percentile($ages, 50);
            $lines[] = 'iicp_heartbeat_age_seconds{quantile="0.95"} '.$this->percentile($ages, 95);
            $lines[] = 'iicp_heartbeat_age_seconds_count '.$count;
            $lines[] = 'iicp_heartbeat_age_seconds_sum '.array_sum($ages);
        }

        // ── iicp_discovery_score_p50 gauge ───────────────────────────────────
        $lines[] = '# HELP iicp_discovery_score_p50 Median reputation score across active nodes (0-1).';
        $lines[] = '# TYPE iicp_discovery_score_p50 gauge';
        if (count($reputationScores) > 0) {
            $lines[] = 'iicp_discovery_score_p50 '.round($this->percentile($reputationScores, 50), 4);
        } else {
            $lines[] = 'iicp_discovery_score_p50 0';
        }

        return implode("\n", $lines)."\n";
    }

    private function percentile(array $sorted, float $p): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }
        $idx = (int) floor($p / 100 * ($count - 1));

        return round($sorted[$idx], 4);
    }

    private function escapeLabelValue(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n", "\r"],
            ['\\\\', '\\"', '\\n', '\\r'],
            $value
        );
    }
}
