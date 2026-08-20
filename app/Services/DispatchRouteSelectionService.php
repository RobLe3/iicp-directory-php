<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

/**
 * Selector/projector for ticketed dispatch discovery (#612).
 */
class DispatchRouteSelectionService
{
    /**
     * @param  array<string,mixed>  $validated
     */
    public function discoveryLimit(array $validated, int $defaultLimit, int $maxLimit): int
    {
        return (($validated['node_id'] ?? null) !== null || ($validated['node_id_prefix'] ?? null) !== null)
            ? $maxLimit
            : $defaultLimit;
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,mixed>|int
     */
    public function select(array $nodes, ?string $nodeId, ?string $nodeIdPrefix, array $excludedPrefixes = []): array|int
    {
        $nodes = array_values(array_filter($nodes, static function (array $node) use ($excludedPrefixes): bool {
            $id = (string) ($node['node_id'] ?? '');
            foreach ($excludedPrefixes as $prefix) {
                if (str_starts_with($id, $prefix)) {
                    return false;
                }
            }

            return true;
        }));

        if ($nodeId !== null) {
            foreach ($nodes as $node) {
                if (($node['node_id'] ?? null) === $nodeId) {
                    return $node;
                }
            }

            return 404;
        }

        if ($nodeIdPrefix !== null) {
            $matches = array_values(array_filter(
                $nodes,
                fn (array $node) => str_starts_with((string) ($node['node_id'] ?? ''), $nodeIdPrefix)
            ));

            return count($matches) > 1 ? 409 : ($matches[0] ?? 404);
        }

        return $nodes[0] ?? 404;
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    public function routeMaterial(array $node): array
    {
        $fields = [
            'node_id', 'endpoint', 'transport_endpoint', 'transport_method', 'transport_metadata',
            'cx_public_key', 'region', 'score', 'health_label', 'health_confidence',
            'routing_hint', 'browser_usable', 'reachability_tier', 'route_evidence', 'models',
            'capability_summary', 'pricing', 'node_policy_manifest', 'available',
            'reputation_score', 'reputation_model', 'reputation_epoch', 'reputation_tier',
            'exposure_mode', 'transport',
            'directory_observed_reachable',
        ];

        return array_filter(
            array_intersect_key($node, array_flip($fields)),
            fn ($value) => $value !== null
        );
    }
}
