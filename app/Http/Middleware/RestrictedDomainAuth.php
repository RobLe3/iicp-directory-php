<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Middleware;

use App\Models\Node;
use App\Models\TrustDomainMembership;
use App\Services\RestrictedDomainDecisionProjection;
use App\Services\TrustDomainMembershipService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictedDomainAuth
{
    public function __construct(
        private TrustDomainMembershipService $memberships,
        private RestrictedDomainDecisionProjection $projection,
    ) {}

    public function handle(Request $request, Closure $next, string $operation): Response
    {
        if (! config('iicp.restricted_domain.enabled')) {
            return $next($request);
        }

        $membership = $this->membershipFor($request, $operation);
        if (! $membership) {
            return $this->denied();
        }

        $request->attributes->set('iicp_membership', $membership);

        $response = $next($request);
        $decision = $this->projection->forOperation($operation, $membership);
        if ($decision !== null && $response->isSuccessful() && $response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data)) {
                $data['restricted_domain_decision'] = $decision;
                $response->setData($data);
                $response->headers->set('Cache-Control', 'private, no-store');
                $response->headers->set('Vary', 'X-IICP-Membership, X-IICP-Subject-Id');
            }
        }

        return $response;
    }

    private function membershipFor(Request $request, string $operation): ?TrustDomainMembership
    {
        $authenticatedNode = $request->input('_authenticated_node');
        $subjectId = $this->resolveSubjectId($request, $operation, $authenticatedNode);
        if ($subjectId === null) {
            return null;
        }
        $membership = $this->memberships->verify(
            (string) $request->header('X-IICP-Membership', ''),
            $subjectId,
            $operation,
        );
        if ($membership && $this->requiresNodeMembership($operation, $authenticatedNode)) {
            return $membership->subject_kind === 'node' ? $membership : null;
        }

        return $membership;
    }

    private function resolveSubjectId(Request $request, string $operation, mixed $authenticatedNode): ?string
    {
        $claimed = (string) $request->header('X-IICP-Subject-Id', '');
        if ($authenticatedNode instanceof Node) {
            $authenticated = (string) $authenticatedNode->id;

            return $claimed === '' || hash_equals($authenticated, $claimed) ? $authenticated : null;
        }

        return $claimed ?: ($operation === 'registration' ? (string) $request->input('node_id', '') : '');
    }

    private function requiresNodeMembership(string $operation, mixed $authenticatedNode): bool
    {
        return $operation === 'registration' || $authenticatedNode instanceof Node;
    }

    private function denied(): Response
    {
        return response()->json([
            'error' => [
                'code' => 'restricted_domain_denied',
                'message' => 'Restricted trust-domain membership is required',
            ],
        ], 401);
    }
}
