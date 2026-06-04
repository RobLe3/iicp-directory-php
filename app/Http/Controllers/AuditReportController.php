<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\Node;
use App\Models\NodeEvent;
use App\Models\Reputation;
use App\Services\NodeEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/audit-report — adapter trust-auditor declaration-divergence report (#118 Part D).
 *
 * A registered node (reporter) submits a finding about a peer node's health endpoint.
 * Currently supports finding: "declaration_divergence" — peer's /iicp/health models[]
 * does not include all registered_models declared in the directory.
 *
 * Safety bounds:
 * - Reporter must be an active registered node (NodeTokenAuth).
 * - Reporter cannot report on itself.
 * - Rate limit: one report per (reporter, target) per 24 hours to prevent harassment.
 * - Reputation delta: −0.05, floored at 0.0.
 * - Emits AUDIT_REPORT event to the append-only event log.
 */
class AuditReportController extends Controller
{
    private const FINDING_DECLARATION_DIVERGENCE = 'declaration_divergence';

    private const VALID_FINDINGS = [self::FINDING_DECLARATION_DIVERGENCE];

    private const REPUTATION_DELTA = -0.05;

    private const RATE_WINDOW_HOURS = 24;

    // RT-05 (#379): cap total distinct reporters whose reports are accepted per
    // target per 24h window. Beyond this cap new reports are acknowledged but
    // carry no further reputation delta. Prevents colluding-reporter griefing.
    private const MAX_REPORTERS_PER_TARGET_PER_DAY = 2;

    // RT-05b (#383): reporter must meet these eligibility requirements for their
    // report to carry reputation weight. Prevents rotation via fresh node registrations.
    public const AUDIT_MIN_AGE_DAYS = 3;

    public const AUDIT_MIN_REPUTATION = 0.55;

    public function __construct(private NodeEventLogger $eventLogger) {}

    public function __invoke(Request $request): JsonResponse
    {
        $reporter = $request->input('_authenticated_node');

        $validated = $request->validate([
            // Accept both UUIDs and custom alphanumeric node names (≤36 chars) —
            // custom-named nodes are first-class (register + heartbeat), so they
            // must be auditable too. Matches RegisterController/HeartbeatController.
            'target_node_id' => ['required', 'string', 'max:36', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/'],
            'finding' => ['required', 'string', 'in:'.implode(',', self::VALID_FINDINGS)],
        ]);

        $targetNodeId = $validated['target_node_id'];
        $reporterNodeId = $reporter->id;

        // A node cannot audit itself.
        if ($targetNodeId === $reporterNodeId) {
            return response()->json([
                'error' => ['code' => 'invalid_target', 'message' => 'Reporter and target must differ'],
            ], 422);
        }

        // Rate limit: one AUDIT_REPORT from this reporter about this target per 24 hours.
        // PHP-side filter on payload to avoid JSON_VALUE (not portable across test SQLite / prod MariaDB).
        $window = now()->subHours(self::RATE_WINDOW_HOURS);
        $recentCount = NodeEvent::where('event_type', 'AUDIT_REPORT')
            ->where('node_id', $targetNodeId)
            ->where('created_at', '>=', $window)
            ->get()
            ->filter(fn ($e) => ($e->payload['reporter_node_id'] ?? null) === $reporterNodeId)
            ->count();

        if ($recentCount > 0) {
            return response()->json([
                'accepted' => false,
                'reason' => 'rate_limited',
                'retry_after_hours' => self::RATE_WINDOW_HOURS,
            ], 429);
        }

        // RT-05: global cap — count distinct reporters who already had reports accepted
        // for this target in the last 24h. Once the cap is reached, acknowledge the
        // report but skip the reputation delta (prevents colluding-reporter griefing).
        $distinctReportersToday = NodeEvent::where('event_type', 'AUDIT_REPORT')
            ->where('node_id', $targetNodeId)
            ->where('created_at', '>=', $window)
            ->get()
            ->pluck('payload')
            ->map(fn ($p) => $p['reporter_node_id'] ?? null)
            ->filter()
            ->unique()
            ->count();

        // RT-05b bypass 1 (#383): reporter eligibility — fresh nodes carry no weight.
        $reporterEligible = $reporter->created_at
            && $reporter->created_at->diffInDays(now()) >= self::AUDIT_MIN_AGE_DAYS
            && (float) $reporter->reputation_score >= self::AUDIT_MIN_REPUTATION;

        $applyDelta = $reporterEligible
            && ($distinctReportersToday < self::MAX_REPORTERS_PER_TARGET_PER_DAY);

        // Apply reputation delta (−0.05, floor 0.0).
        $rep = Reputation::firstOrCreate(
            ['node_id' => $targetNodeId],
            ['score' => 0.5, 'tasks_total' => 0, 'tasks_failed' => 0,
                'completed_tasks_count' => 0, 'avg_latency_ms' => 0.0],
        );

        $oldScore = (float) $rep->score;
        $newScore = $applyDelta
            ? max(0.0, round($oldScore + self::REPUTATION_DELTA, 4))
            : $oldScore;
        if ($applyDelta) {
            $rep->update(['score' => $newScore]);

            // RT-05b bypass 2 (#383): dual-write to nodes.reputation_score (canonical column).
            // AuditReportController previously only updated reputations.score — causing
            // divergence as Phase 2 migration makes nodes.reputation_score the sole source.
            Node::where('id', $targetNodeId)->update(['reputation_score' => $newScore]);
        }

        $this->eventLogger->log('AUDIT_REPORT', $targetNodeId, [
            'reporter_node_id' => $reporterNodeId,
            'finding' => $validated['finding'],
            'reputation_delta' => $applyDelta ? self::REPUTATION_DELTA : 0.0,
            'delta_suppressed' => ! $applyDelta,
            'reporter_eligible' => $reporterEligible,
            'old_score' => $oldScore,
            'new_score' => $newScore,
        ]);

        return response()->json(['accepted' => true], 202);
    }
}
