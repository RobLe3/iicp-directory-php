<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;

/**
 * Public, privacy-preserving mesh visibility summary.
 *
 * This does not change routing or discovery. It gives the website and clients a
 * stable way to explain short recovery windows where nodes are still
 * heartbeating or were recently seen, but the currently discoverable serving set
 * is smaller because a route/relay/tunnel is rebuilding.
 */
class MeshResilienceSummary
{
    public const ACTIVE_WINDOW_S = 90;

    public const RECENT_WINDOW_S = 300;

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $activeCutoff = now()->subSeconds(self::ACTIVE_WINDOW_S);
        $recentCutoff = now()->subSeconds(self::RECENT_WINDOW_S);

        $active = Node::where('available', true)
            ->where('status', 'active')
            ->where('last_seen', '>=', $activeCutoff);

        $recent = Node::where('available', true)
            ->where('status', 'active')
            ->where('last_seen', '>=', $recentCutoff);

        $heartbeating = (clone $active)->count();
        $recentNodes = (clone $recent)->count();
        $publicNow = $this->discoverableQuery(clone $active)->count();
        $limitedReach = max(0, $heartbeating - $publicNow);
        $recentlySeenNotCurrent = max(0, $recentNodes - $heartbeating);

        $relayNow = $this->discoverableQuery(
            (clone $active)->where('relay_capable', true)
        )->count();

        $lastRelay = Node::where('relay_capable', true)
            ->whereNotNull('last_seen')
            ->orderByDesc('last_seen')
            ->first(['last_seen']);

        $lastPublic = $this->discoverableQuery(
            Node::where('available', true)
                ->where('status', 'active')
                ->whereNotNull('last_seen')
        )->orderByDesc('last_seen')->first(['last_seen']);

        $lastRelaySeenAt = $lastRelay?->last_seen;
        $relayRecentlySeen = $lastRelaySeenAt !== null && $lastRelaySeenAt->gte($recentCutoff);

        return [
            'active_window_s' => self::ACTIVE_WINDOW_S,
            'recent_window_s' => self::RECENT_WINDOW_S,
            'visible_nodes_now' => $publicNow,
            'public_routable_nodes_now' => $publicNow,
            'heartbeating_nodes_now' => $heartbeating,
            'limited_reach_nodes_now' => $limitedReach,
            'recent_nodes' => $recentNodes,
            'recently_seen_not_current' => $recentlySeenNotCurrent,
            'recovering_nodes_now' => $limitedReach + $recentlySeenNotCurrent,
            'relay_available_now' => $relayNow > 0,
            'relay_capable_nodes_now' => $relayNow,
            'last_relay_seen_at' => $lastRelaySeenAt?->toIso8601String(),
            'last_public_node_seen_at' => $lastPublic?->last_seen?->toIso8601String(),
            'recovery_window' => $publicNow < $heartbeating
                || $recentNodes > $heartbeating
                || ($relayRecentlySeen && $relayNow === 0),
        ];
    }

    /**
     * Apply the same public serving-set predicate used by stats/discover:
     * endpoint not confirmed dead and either dial-back public or advertising a
     * routable relay/tunnel/direct exposure mode.
     */
    private function discoverableQuery($query)
    {
        return $query
            ->whereNull('endpoint_verified_dead_at')
            ->where(function ($w) {
                $w->where('public_reachable', true)
                    ->orWhereIn('exposure_mode', NodeScorer::RELAY_REACHABLE_EXPOSURE_MODES);
            });
    }
}
