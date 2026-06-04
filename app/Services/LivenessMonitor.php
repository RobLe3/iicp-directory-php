<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;
use Carbon\Carbon;

/**
 * Liveness expiry sweep — marks nodes dormant after 90s of heartbeat silence.
 *
 * WHY 90s (not 30s or 60s): heartbeat cadence is 30s. A node is allowed to miss
 * one heartbeat (transient load, network blip) before being marked dormant.
 * 90s = 3 × heartbeat interval, giving the adapter two retry chances (at 30s + 60s)
 * before the directory stops routing to it. This matches _PEER_EXPIRY_S in the
 * adapter's PeerManager (same constant, derived from the same reasoning).
 *
 * WHY 'dormant' not 'offline': dormant nodes are not deleted from the DB. When the
 * adapter restarts and re-registers, NodeRegistry re-activates the dormant record
 * (preserving reputation + credit history) rather than creating a duplicate entry.
 *
 * Called by: a Laravel scheduled command running every 60s (not every request, to
 * avoid lock contention on high-traffic instances). The 60s sweep granularity means
 * expiry fires up to 60s late — 90s + 60s = 150s worst case before node appears
 * unavailable in discover results. This is acceptable per ADR-003.
 *
 * Spec: spec/iicp-dir.md §heartbeat (EXPIRY_SECONDS). ADR: ADR-003 (directory authority).
 */
class LivenessMonitor
{
    private const EXPIRY_SECONDS = 90;

    public function expireStaleNodes(): int
    {
        $cutoff = Carbon::now()->subSeconds(self::EXPIRY_SECONDS);

        return Node::query()
            ->where('status', 'active')
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_seen')
                    ->orWhere('last_seen', '<', $cutoff);
            })
            ->update([
                'available' => false,
                'status' => 'dormant',
                'dormant_since' => Carbon::now(),
            ]);
    }
}
