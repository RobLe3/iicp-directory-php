<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Services\DispatchRouteSelectionService;
use App\Services\DispatchRouteTicketService;
use App\Services\DispatchUsageCounter;
use App\Services\IntentPolicyGuard;
use App\Services\NodeScorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/dispatch/ticket — explicit route-bearing dispatch discovery (#612).
 *
 * This is the staged replacement path for default route-bearing GET discovery: a
 * dispatching client asks for one concrete route and receives a short-lived,
 * intent-scoped directory ticket plus the route material needed to contact that
 * node. The directory still never receives task prompts or payloads.
 */
class DispatchRouteTicketController extends Controller
{
    private const MAX_LIMIT = 50;

    private const DEFAULT_LIMIT = 10;

    public function __construct(
        private NodeScorer $scorer,
        private IntentPolicyGuard $intentPolicy,
        private DispatchRouteTicketService $tickets,
        private DispatchRouteSelectionService $routes,
        private DispatchUsageCounter $usage,
    ) {}

    public function issue(Request $request): JsonResponse
    {
        $payloadLikeFields = ['prompt', 'messages', 'payload', 'input', 'chat', 'content', 'response'];
        foreach ($payloadLikeFields as $field) {
            if ($request->has($field)) {
                throw ValidationException::withMessages([
                    $field => ['Dispatch ticket issuance is control-plane only; send task payloads directly to the selected node.'],
                ]);
            }
        }

        foreach (['cip_capable', 'relay_capable', 'include_internal'] as $boolParam) {
            if ($request->has($boolParam)) {
                $request->merge([$boolParam => $request->boolean($boolParam)]);
            }
        }

        $validated = $request->validate([
            'intent' => ['required', 'string', 'max:255'],
            'qos' => ['sometimes', 'string', 'in:realtime,interactive,batch,best-effort'],
            'region' => ['sometimes', 'string', 'max:64'],
            'model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'min_reputation' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'max_multiplier' => ['sometimes', 'numeric', 'min:0'],
            'min_quality_score' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'cip_capable' => ['sometimes', 'boolean'],
            'relay_capable' => ['sometimes', 'boolean'],
            'include_internal' => ['sometimes', 'boolean'],
            'modality' => ['sometimes', 'string', 'in:text,image,audio,video'],
            'score_version' => ['sometimes', 'string', 'in:v2_shadow'],
            'node_id' => ['sometimes', 'string', 'max:64'],
            'node_id_prefix' => ['sometimes', 'string', 'min:4', 'max:36'],
            'exclude_node_id_prefixes' => ['sometimes', 'array', 'max:10'],
            'exclude_node_id_prefixes.*' => ['string', 'min:4', 'max:36', 'distinct'],
        ]);

        if (($validated['node_id'] ?? null) !== null && ($validated['node_id_prefix'] ?? null) !== null) {
            throw ValidationException::withMessages([
                'node_id' => ['Use node_id or node_id_prefix, not both.'],
            ]);
        }

        if ($message = $this->intentPolicy->refusalMessage($validated['intent'])) {
            throw ValidationException::withMessages(['intent' => [$message]]);
        }

        $nodes = $this->scorer->discover(
            intent: $validated['intent'],
            qos: $validated['qos'] ?? null,
            region: $validated['region'] ?? null,
            model: $validated['model'] ?? null,
            minReputation: isset($validated['min_reputation']) ? (float) $validated['min_reputation'] : 0.0,
            limit: $validated['limit'] ?? $this->routes->discoveryLimit($validated, self::DEFAULT_LIMIT, self::MAX_LIMIT),
            maxMultiplier: isset($validated['max_multiplier']) ? (float) $validated['max_multiplier'] : null,
            minQualityScore: isset($validated['min_quality_score']) ? (float) $validated['min_quality_score'] : null,
            cipCapable: isset($validated['cip_capable']) ? (bool) $validated['cip_capable'] : null,
            includeInternal: (bool) ($validated['include_internal'] ?? false),
            modality: $validated['modality'] ?? null,
            relayCapable: isset($validated['relay_capable']) ? (bool) $validated['relay_capable'] : null,
            scoreVersion: $validated['score_version'] ?? null,
        );

        $selected = $this->routes->select(
            $nodes,
            $validated['node_id'] ?? null,
            $validated['node_id_prefix'] ?? null,
            $validated['exclude_node_id_prefixes'] ?? [],
        );
        if (is_int($selected)) {
            return response()->json([
                'error' => [
                    'code' => $selected === 409 ? 'ambiguous_node_prefix' : 'no_route_available',
                    'message' => $selected === 409
                        ? 'The node_id_prefix matches more than one eligible node; provide a longer prefix.'
                        : 'No eligible route matched the requested intent and filters.',
                ],
            ], $selected);
        }

        $route = $this->routes->routeMaterial($selected);
        $policyManifestSha256 = $route['node_policy_manifest']['verification']['canonical_sha256'] ?? null;
        $ticket = $this->tickets->issue(
            $selected['node_id'],
            $validated['intent'],
            policyManifestSha256: is_string($policyManifestSha256) ? $policyManifestSha256 : null,
        );
        if ($ticket === null) {
            return response()->json([
                'error' => [
                    'code' => 'not_configured',
                    'message' => 'Dispatch route ticket signing key not configured on this directory.',
                ],
            ], 503);
        }

        $this->usage->record(DispatchUsageCounter::TICKETED_DISPATCH);

        return response()->json([
            'ticket' => $ticket['token'],
            'ticket_id_prefix' => substr($ticket['ticket_id'], 0, 12),
            'expires_at' => $ticket['expires_at'],
            'intent' => $validated['intent'],
            'node_id' => $selected['node_id'],
            'node_id_prefix' => substr($selected['node_id'], 0, 8),
            'route' => $route,
            'algorithm' => 'ed25519',
            'data_class' => 'ticketed_route_dispatch',
            'route_fields_present' => true,
            'prompt_payload_accepted' => false,
        ], 201)->header('Cache-Control', 'no-store')
            ->header('X-IICP-Discover-Data-Class', 'ticketed_route_dispatch');
    }
}
