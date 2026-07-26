<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LoadRedirect — Phase 6 prerequisite (ADR-013 / iicp-federated-directory.md §6).
 *
 * When the Genesis Seed is under load, redirect read-heavy discovery requests
 * to a healthy replica from the configured replica list. The middleware is a
 * no-op when redirection is disabled OR no replicas are configured.
 *
 * Configuration:
 * - IICP_REDIRECT_ENABLED      (bool, default false) — master switch
 * - IICP_REPLICA_URLS          (CSV string, default empty) — replica base URLs
 * - IICP_REDIRECT_TRUST_TIER   (string, default "low") — applied to X-IICP-Replica-Trust
 * - IICP_REDIRECT_RETRY_AFTER  (int seconds, default 5) — Retry-After header
 *
 * Response (when triggered):
 *   HTTP/1.1 307 Temporary Redirect
 *   Location: <replica-url>/<original-path-and-query>
 *   X-IICP-Seed-Redirect: true
 *   X-IICP-Replica-Trust: low | medium | high
 *   X-IICP-Redirect-Reason: load
 *   Retry-After: 5
 *
 * Conformance: DIR-FED-05 (clients MUST follow 307 transparently).
 */
class LoadRedirect
{
    private const VALID_TRUST_TIERS = ['low', 'medium', 'high'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->enabled()) {
            return $next($request);
        }

        $replicas = $this->replicaUrls();
        if (empty($replicas)) {
            return $next($request);
        }

        $replica = $this->pickReplica($replicas);
        $target = rtrim($replica, '/').'/'.ltrim($request->getRequestUri(), '/');
        $trust = $this->trustTier();
        $retry = max(1, (int) config('iicp.replica.redirect.retry_after', 5));

        return response('', 307, [
            'Location' => $target,
            'X-IICP-Seed-Redirect' => 'true',
            'X-IICP-Replica-Trust' => $trust,
            'X-IICP-Redirect-Reason' => 'load',
            'Retry-After' => (string) $retry,
        ]);
    }

    private function enabled(): bool
    {
        return filter_var(config('iicp.replica.redirect.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Replica URLs must start with https:// to prevent open-redirect-via-misconfig
     * and to guarantee TLS for the redirected client.
     *
     * @return list<string>
     */
    private function replicaUrls(): array
    {
        $raw = (string) config('iicp.replica.redirect.urls', '');
        if ($raw === '') {
            return [];
        }
        $urls = array_map('trim', explode(',', $raw));
        $urls = array_filter($urls, fn ($u) => $u !== '' && str_starts_with($u, 'https://'));

        return array_values($urls);
    }

    /**
     * Trust tier MUST be one of the allowlist values; defaults to "low" on any
     * unknown or malformed value. Prevents header injection via env misconfig.
     */
    private function trustTier(): string
    {
        $tier = strtolower(trim((string) config('iicp.replica.redirect.trust_tier', 'low')));

        return in_array($tier, self::VALID_TRUST_TIERS, true) ? $tier : 'low';
    }

    /** Random pick — simple load distribution across replicas. */
    private function pickReplica(array $replicas): string
    {
        return $replicas[array_rand($replicas)];
    }
}
