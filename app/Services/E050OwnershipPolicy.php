<?php

namespace App\Services;

final class E050OwnershipPolicy
{
    public static function allows(
        bool $strict,
        bool $secured,
        bool $endpointChanged,
        bool $transportEndpointChanged,
        bool $relayEndpointChanged,
        bool $hasOwnership,
        bool $oldEndpointAlive,
    ): bool {
        $routingChanged = $endpointChanged || $transportEndpointChanged || $relayEndpointChanged;
        if ($hasOwnership) {
            return true;
        }
        // Strict mode also protects credential rotation. Allowing a tokenless
        // same-route re-registration would mint a fresh token that could then
        // authorize a routing change in a second request.
        if ($strict && $secured) {
            return false;
        }
        if (! $routingChanged) {
            return true;
        }

        return ! $endpointChanged || ! $oldEndpointAlive;
    }
}
