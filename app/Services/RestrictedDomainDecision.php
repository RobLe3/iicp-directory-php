<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

class RestrictedDomainDecision
{
    /** @return array{decision: string, reason: string, network_activity_permitted: bool} */
    public static function evaluate(array $input): array
    {
        $mode = $input['mode'] ?? 'invalid';
        $reason = self::reason($input, $mode);

        return [
            'decision' => $reason === 'allowed' ? 'allow' : 'deny',
            'reason' => $reason,
            'network_activity_permitted' => $reason === 'allowed' && $mode !== 'local_only',
        ];
    }

    private static function reason(array $input, string $mode): string
    {
        if (! in_array($mode, ['public', 'private', 'federated_private', 'local_only', 'custom'], true)) {
            return 'invalid_input';
        }
        $profile = $input['profile_support'] ?? 'supported';
        if ($profile === 'unknown_required' || ($profile === 'unknown_optional' && $mode !== 'public')) {
            return 'unsupported_required_profile';
        }
        if ($mode === 'local_only' && ($input['external_network'] ?? false)) {
            return 'local_only_external_forbidden';
        }
        if (in_array($mode, ['private', 'federated_private'], true) && ($input['public_fallback'] ?? false)) {
            return 'public_fallback_forbidden';
        }
        if ($mode !== 'public' && ! ($input['authenticated'] ?? false)) {
            return 'authentication_required';
        }
        if ($input['replayed'] ?? false) {
            return 'replay_detected';
        }
        $membershipReason = self::membershipReason($input['membership'] ?? ($mode === 'public' ? 'valid' : 'missing'));
        if ($membershipReason !== null) {
            return $membershipReason;
        }
        if (($input['operation'] ?? '') === 'federation') {
            if (! ($input['federation_trusted'] ?? false)) {
                return 'federation_untrusted';
            }
            if (! ($input['federation_scope_allowed'] ?? false)) {
                return 'federation_scope_denied';
            }
        }
        if (array_key_exists('policy_allowed', $input) && ! $input['policy_allowed']) {
            return 'policy_denied';
        }
        if (in_array($input['operation'] ?? '', ['relay', 'execution', 'cip', 'federation'], true)
            && ! ($input['route_authorized'] ?? false)) {
            return 'route_authorization_required';
        }

        return 'allowed';
    }

    private static function membershipReason(string $membership): ?string
    {
        return match ($membership) {
            'valid' => null,
            'missing' => 'membership_missing',
            'expired' => 'membership_expired',
            'revoked' => 'membership_revoked',
            'wrong_domain' => 'wrong_trust_domain',
            default => 'invalid_input',
        };
    }
}
