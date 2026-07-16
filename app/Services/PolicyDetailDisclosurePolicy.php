<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

/**
 * Pre-normative authorization/redaction policy for provider-served details.
 *
 * The caller MUST pass a consumer_auth value produced by cryptographic
 * verification. This service is not an HTTP endpoint and is not wired into
 * public discovery or production routing.
 */
final class PolicyDetailDisclosurePolicy
{
    private const CONSUMER_TOKEN_DOMAIN = "iicp:consumer-token:v1\n";

    public const ALLOWED_DETAIL_FIELDS = [
        'retention_intervals',
        'subprocessor_references',
        'approval_evidence_references',
        'operational_evidence_references',
    ];

    public static function evaluate(array $context): array
    {
        $auth = $context['consumer_auth'] ?? null;
        if ($auth === 'missing') {
            return self::decision(401, 'consumer_auth_required');
        }
        if (! in_array($auth, ['valid', 'expired'], true)) {
            return self::decision(401, 'consumer_auth_invalid');
        }
        if ($auth === 'expired') {
            return self::decision(401, 'consumer_auth_expired');
        }
        if (($context['disclosure_allowed'] ?? false) !== true) {
            return self::decision(403, 'disclosure_forbidden');
        }

        $provider = $context['provider_node_id'] ?? null;
        $intent = $context['consumer_intent'] ?? null;
        $digest = $context['manifest_sha256'] ?? null;
        $bound = is_string($provider) && $provider !== ''
            && $provider === ($context['consumer_target_node_id'] ?? null)
            && $provider === ($context['ticket_target_node_id'] ?? null)
            && is_string($intent) && $intent !== ''
            && $intent === ($context['ticket_intent'] ?? null)
            && is_string($digest) && $digest !== ''
            && $digest === ($context['ticket_manifest_sha256'] ?? null);
        if (! $bound) {
            return self::decision(404, 'resource_concealed');
        }

        $details = is_array($context['details'] ?? null) ? $context['details'] : [];
        $safe = array_intersect_key($details, array_flip(self::ALLOWED_DETAIL_FIELDS));

        return [
            'status' => 200,
            'reason' => 'compatible',
            'body' => [
                'profile' => 'urn:iicp:profile:policy-detail-disclosure:v0',
                'manifest_sha256' => $digest,
                'details' => $safe,
                'claim_status' => 'provider_declared',
            ],
        ];
    }

    public static function verifyConsumerToken(
        string $token,
        string $publicKeyHex,
        string $targetNodeId,
        string $intent,
        int $now,
    ): array {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || strlen($parts[1]) !== 128) {
            return ['status' => 'invalid'];
        }
        [$payloadB64, $signatureHex] = $parts;
        try {
            $valid = sodium_crypto_sign_verify_detached(
                sodium_hex2bin($signatureHex),
                self::CONSUMER_TOKEN_DOMAIN.$payloadB64,
                sodium_hex2bin($publicKeyHex),
            );
            $payload = strtr($payloadB64, '-_', '+/');
            $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
            $claims = json_decode(base64_decode($payload, true), true, flags: JSON_THROW_ON_ERROR);
        } catch (\SodiumException|\JsonException|\TypeError) {
            return ['status' => 'invalid'];
        }
        if (! $valid || ! is_array($claims)
            || ($claims['v'] ?? null) !== 1
            || ($claims['aud'] ?? null) !== $targetNodeId
            || ($claims['intent'] ?? null) !== $intent
            || ! is_string($claims['sub'] ?? null)) {
            return ['status' => 'invalid'];
        }
        if (! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now) {
            return ['status' => 'expired', 'claims' => $claims];
        }

        return ['status' => 'valid', 'claims' => $claims];
    }

    private static function decision(int $status, string $reason): array
    {
        return ['status' => $status, 'reason' => $reason];
    }
}
