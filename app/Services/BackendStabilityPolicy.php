<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\Node;

final class BackendStabilityPolicy
{
    private const STATES = ['ok', 'degraded', 'draining'];

    private const REASONS = ['ok', 'backend_cold', 'backend_loading', 'backend_unstable', 'observer_error'];

    private const ROUTING_GUARDS = [
        'ok' => 'none',
        'degraded' => 'observe_only',
        'draining' => 'avoid_for_admission',
    ];

    private const STATE_SUMMARIES = [
        'ok' => 'Backend reports ready for new work.',
        'draining' => 'Backend is draining; discovery should avoid assigning new work for now.',
    ];

    private const REASON_SUMMARIES = [
        'backend_cold' => 'Backend is reachable but cold; first request may warm it up.',
        'backend_loading' => 'Backend is loading or unloading a model.',
        'backend_unstable' => 'Backend reports instability separate from network reachability.',
        'observer_error' => 'Backend reports degraded readiness.',
        'ok' => 'Backend reports degraded readiness.',
    ];

    /** @return array<string,mixed> */
    public static function summarize(Node $node): array
    {
        $raw = is_array($node->backend_stability) ? $node->backend_stability : null;
        if ($raw === null || $raw === []) {
            return self::unknown();
        }

        $state = self::coerceToken($raw['backend_state'] ?? null, self::STATES, 'degraded');
        $reason = self::coerceToken($raw['reason_class'] ?? null, self::REASONS, 'observer_error');

        return [
            'backend_state' => $state,
            'reason_class' => $reason,
            'routing_guard' => self::ROUTING_GUARDS[$state] ?? 'none',
            'evidence' => 'self_reported',
            'retry_after_s' => self::optionalInt($raw, 'retry_after_s'),
            'drain_until' => self::optionalInt($raw, 'drain_until'),
            'summary' => self::summaryText($state, $reason),
        ];
    }

    public static function allowsAdmission(Node $node): bool
    {
        return self::summarize($node)['routing_guard'] !== 'avoid_for_admission';
    }

    /** @return array<string,mixed> */
    private static function unknown(): array
    {
        return [
            'backend_state' => 'unknown',
            'reason_class' => 'not_reported',
            'routing_guard' => 'none',
            'evidence' => 'not_reported',
            'retry_after_s' => null,
            'drain_until' => null,
            'summary' => 'Backend stability has not been reported yet.',
        ];
    }

    /** @param list<string> $allowed */
    private static function coerceToken(mixed $value, array $allowed, string $fallback): string
    {
        $token = is_string($value) ? $value : $fallback;

        return in_array($token, $allowed, true) ? $token : $fallback;
    }

    /** @param array<string,mixed> $raw */
    private static function optionalInt(array $raw, string $field): ?int
    {
        return isset($raw[$field]) && is_numeric($raw[$field])
            ? max(0, (int) $raw[$field])
            : null;
    }

    private static function summaryText(string $state, string $reason): string
    {
        if ($state === 'degraded') {
            return self::REASON_SUMMARIES[$reason] ?? 'Backend reports degraded readiness.';
        }

        return self::STATE_SUMMARIES[$state] ?? 'Backend stability has not been reported yet.';
    }
}
