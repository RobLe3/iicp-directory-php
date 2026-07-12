<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

/**
 * Additive directory-capability negotiation for the pre-normative profile
 * fixtures. It deliberately does not evaluate provider declarations: native
 * clients retain that candidate-level decision until a ratified profile exists.
 */
class ProfileNegotiation
{
    public const PROFILE_ID = 'iicp.profile.compatibility.v0';

    public const PROFILE_VERSION = '0.3.0-draft';

    public const FIXTURE_SHA256 = '4137ecf91b4748a2b368cf4428b4604c6947f8879d77402cc7937d11d24b2aaf';

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function negotiate(array $request): array
    {
        $profileId = $request['profile_id'] ?? null;
        if ($profileId === null) {
            return ['requested' => false];
        }

        $required = (bool) ($request['profile_required'] ?? false);
        $compatible = $profileId === self::PROFILE_ID
            && ($request['profile_version'] ?? null) === self::PROFILE_VERSION
            && ($request['profile_fixture_sha256'] ?? null) === self::FIXTURE_SHA256;

        return [
            'requested' => true,
            'profile_id' => $profileId,
            'profile_version' => $request['profile_version'] ?? null,
            'fixture_sha256' => $request['profile_fixture_sha256'] ?? null,
            'required' => $required,
            'status' => $compatible ? 'compatible' : 'unsupported',
            'reason' => $compatible ? 'compatible' : 'unsupported_pre_normative_profile',
            'dispatch_allowed' => $compatible || ! $required,
            'supported_profile' => self::PROFILE_ID,
            'supported_version' => self::PROFILE_VERSION,
            'supported_fixture_sha256' => self::FIXTURE_SHA256,
        ];
    }
}
