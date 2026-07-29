<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use Carbon\CarbonImmutable;

final class DeploymentProvenanceService
{
    public const DOMAIN = "IICP-DEPLOYMENT-RECORD-V1\0";

    public const PURPOSE = 'iicp-deployment-record-v1';

    public function record(): ?array
    {
        $unsigned = $this->unsignedRecord();
        $secret = config('app.genesis_ed25519_secret_key');
        if ($unsigned === null || ! is_string($secret) || ! preg_match('/^[0-9a-fA-F]{128}$/', $secret)) {
            return null;
        }

        $canonical = NodeEventLogger::canonicalJson($unsigned);
        $signature = sodium_crypto_sign_detached(self::DOMAIN.$canonical, sodium_hex2bin($secret));
        $keyId = $unsigned['root_key_id'];

        return $unsigned + [
            'signature' => [
                'algorithm' => 'Ed25519',
                'purpose' => self::PURPOSE,
                'key_id' => $keyId,
                'value' => self::base64UrlEncode($signature),
            ],
        ];
    }

    public static function verify(
        array $record,
        string $publicKey,
        ?CarbonImmutable $observedAt = null,
        ?int $maxAgeSeconds = null
    ): bool {
        $signature = $record['signature'] ?? null;
        if (! is_array($signature)
            || ($signature['algorithm'] ?? null) !== 'Ed25519'
            || ($signature['purpose'] ?? null) !== self::PURPOSE
            || ($signature['key_id'] ?? null) !== ($record['root_key_id'] ?? null)
            || strlen($publicKey) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        $encoded = $signature['value'] ?? null;
        if (! is_string($encoded)) {
            return false;
        }
        $raw = self::base64UrlDecode($encoded);
        if ($raw === null || strlen($raw) !== \SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        unset($record['signature']);
        if (! sodium_crypto_sign_verify_detached(
            $raw,
            self::DOMAIN.NodeEventLogger::canonicalJson($record),
            $publicKey
        )) {
            return false;
        }

        if ($observedAt !== null && $maxAgeSeconds !== null) {
            $deployedAt = $record['deployed_at'] ?? null;
            if (! is_string($deployedAt)) {
                return false;
            }
            try {
                $age = CarbonImmutable::parse($deployedAt)->diffInSeconds($observedAt, false);
            } catch (\Throwable) {
                return false;
            }
            if ($age < 0 || $age > $maxAgeSeconds) {
                return false;
            }
        }

        return true;
    }

    private function unsignedRecord(): ?array
    {
        $deployment = config('app.iicp_deployment');
        $required = [
            'kind', 'release_tag', 'source_commit', 'deployed_at',
            'openapi_version', 'protocol_min', 'protocol_max', 'root_key_id',
        ];
        if (! is_array($deployment)) {
            return null;
        }
        foreach ($required as $field) {
            if (! is_string($deployment[$field] ?? null) || trim($deployment[$field]) === '') {
                return null;
            }
        }
        if (! in_array($deployment['kind'], ['shared_hosting', 'container', 'native', 'other'], true)) {
            return null;
        }
        try {
            CarbonImmutable::parse($deployment['deployed_at']);
        } catch (\Throwable) {
            return null;
        }

        $buildId = config('app.iicp_build_id');
        $version = config('app.iicp_version');
        if (! is_string($buildId) || ! preg_match('/^sha256:[0-9a-f]{64}$/', $buildId)
            || ! is_string($version)
            || ! preg_match('/^[0-9a-f]{40}$/', $deployment['source_commit'])) {
            return null;
        }

        return [
            'schema' => 'iicp.deployment-record.v1',
            'deployment_kind' => $deployment['kind'],
            'directory' => [
                'flavor' => 'php',
                'runtime_version' => $version,
                'release_tag' => $deployment['release_tag'],
                'source_commit' => strtolower($deployment['source_commit']),
            ],
            'artifact' => [
                'build_digest' => $buildId,
                'image_digest' => self::nullableDigest($deployment['image_digest'] ?? null),
                'sbom_digest' => self::nullableDigest($deployment['sbom_digest'] ?? null),
            ],
            'compatibility' => [
                'openapi_version' => $deployment['openapi_version'],
                'protocol_min' => $deployment['protocol_min'],
                'protocol_max' => $deployment['protocol_max'],
            ],
            'deployed_at' => $deployment['deployed_at'],
            'root_key_id' => $deployment['root_key_id'],
        ];
    }

    private static function nullableDigest(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^sha256:[0-9a-f]{64}$/', $value) ? $value : null;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
