<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\PolicyKeyLifecycleRecord;
use Carbon\CarbonImmutable;

/**
 * Verifies public node policy manifests.
 *
 * Phase A deliberately treats signatures as tamper evidence for public policy
 * claims, not as a complete legal identity/KYC proof. Phase A.1 adds a safe
 * identity-binding signal when the policy-signing key is the same Ed25519 key
 * as a directory-verified operator delegation. That still remains a technical
 * accountability signal, not DPA acceptance or legal compliance proof.
 */
class NodePolicyManifestVerifier
{
    public const STATUS_SELF_ATTESTED = 'self_attested';

    public const STATUS_SIGNED_VALID = 'signed_valid';

    public const STATUS_SIGNED_INVALID = 'signed_invalid';

    public const STATUS_SIGNED_EXPIRED = 'signed_expired';

    public const STATUS_SIGNED_REVOKED = 'signed_revoked';

    public const STATUS_SIGNED_SUPERSEDED = 'signed_superseded';

    public const IDENTITY_SELF_ATTESTED = 'self_attested';

    public const IDENTITY_SIGNED_VALID = 'signed_valid';

    public const IDENTITY_OPERATOR_BOUND = 'operator_bound';

    public const IDENTITY_KNOWN_OPERATOR = 'known_operator';

    public const IDENTITY_ROTATED = 'rotated';

    public const IDENTITY_REVOKED = 'revoked';

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array{operator_pubkey?:?string,operator_verified?:bool,operator_trust_tier?:?string,operator_known?:bool,rotated?:bool}|null  $operatorContext
     * @return array{status:string,evidence:string,algorithm:?string,key_id:?string,signed_at:?string,expires_at:?string,canonical_sha256:string,public_key_sha256:?string,manifest_identity_level:string,operator_fingerprint:?string,policy_key_fingerprint:?string,revoked_at:?string,rotation_epoch:?int,revocation_reason_class:?string,error:?string}
     */
    public static function verify(array $manifest, ?array $operatorContext = null): array
    {
        $canonical = self::canonicalPayload($manifest);
        $base = self::baseVerification($canonical);

        $signature = $manifest['signature'] ?? null;
        if ($signature === null) {
            return $base;
        }
        if (! is_array($signature)) {
            return self::invalid($base, 'signature block must be an object');
        }

        $base = self::withSignatureMetadata($base, $signature);

        if (($base['algorithm'] ?? null) !== 'Ed25519') {
            return self::invalid($base, 'unsupported signature algorithm');
        }

        $material = self::signatureMaterial($signature);
        if ($material['error'] !== null) {
            return self::invalid($base, $material['error']);
        }
        $publicKey = $material['public_key'];
        $detachedSignature = $material['detached_signature'];
        $base['public_key_sha256'] = hash('sha256', $publicKey);
        $base['policy_key_fingerprint'] = substr($base['public_key_sha256'], 0, 12);

        if (! sodium_crypto_sign_verify_detached($detachedSignature, $canonical, $publicKey)) {
            return self::invalid($base, 'signature verification failed');
        }

        if ($directoryLifecycle = self::directoryLifecycle($base)) {
            return $directoryLifecycle;
        }

        if ($revocation = self::verifiedRevocation($base, $signature)) {
            return $revocation;
        }

        if ($expiry = self::verifiedExpiry($base, $signature)) {
            return $expiry;
        }

        return self::signedValid($base, $publicKey, $operatorContext);
    }

    /**
     * Apply authoritative directory-owned lifecycle state for a policy key (#608).
     * This is intentionally checked after signature verification so random keys
     * cannot be used to probe lifecycle state without proving key possession.
     *
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>|null
     */
    private static function directoryLifecycle(array $base): ?array
    {
        $keySha = $base['public_key_sha256'] ?? null;
        if (! is_string($keySha) || $keySha === '') {
            return null;
        }

        try {
            $record = PolicyKeyLifecycleRecord::query()
                ->where('policy_key_sha256', $keySha)
                ->first();
        } catch (\Throwable) {
            // Pure unit-test / non-Laravel contexts can use the verifier as a
            // deterministic crypto helper without a database. Runtime directory
            // paths run inside Laravel and apply the lifecycle registry.
            return null;
        }
        if ($record === null) {
            return null;
        }

        $base['rotation_epoch'] = $record->rotation_epoch ?? $base['rotation_epoch'];
        $base['revocation_reason_class'] = self::safeReasonClass($record->revocation_reason_class)
            ?? $base['revocation_reason_class']
            ?? 'directory_record';
        $base['revoked_at'] = $record->revoked_at?->toIso8601String();

        if (! in_array($record->status, PolicyKeyLifecycleRecord::ALLOWED_STATUSES, true)) {
            return self::invalid($base, 'invalid policy key lifecycle status');
        }

        if ($record->status === PolicyKeyLifecycleRecord::STATUS_ACTIVE) {
            return null;
        }

        if ($record->status === PolicyKeyLifecycleRecord::STATUS_REVOKED) {
            $base['status'] = self::STATUS_SIGNED_REVOKED;
            $base['evidence'] = 'directory_revoked';
            $base['manifest_identity_level'] = self::IDENTITY_REVOKED;
            $base['error'] = 'policy key revoked by directory lifecycle registry';

            return $base;
        }

        $base['status'] = self::STATUS_SIGNED_SUPERSEDED;
        $base['evidence'] = 'directory_superseded';
        $base['manifest_identity_level'] = self::IDENTITY_ROTATED;
        $base['error'] = 'policy key superseded by directory lifecycle registry';

        return $base;
    }

    public static function canonicalPayload(array $manifest): string
    {
        $copy = $manifest;
        if (isset($copy['signature']) && is_array($copy['signature'])) {
            unset($copy['signature']['signature']);
        } else {
            unset($copy['signature']);
        }
        $normalized = self::sortRecursive($copy);

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '{}';
    }

    /** @param array<string,mixed> $base @return array<string,mixed> */
    private static function invalid(array $base, string $error): array
    {
        $base['status'] = self::STATUS_SIGNED_INVALID;
        $base['evidence'] = 'signed_invalid';
        $base['error'] = $error;

        return $base;
    }

    /** @return array<string,mixed> */
    private static function baseVerification(string $canonical): array
    {
        return [
            'status' => self::STATUS_SELF_ATTESTED,
            'evidence' => 'self_attested',
            'algorithm' => null,
            'key_id' => null,
            'signed_at' => null,
            'expires_at' => null,
            'canonical_sha256' => hash('sha256', $canonical),
            'public_key_sha256' => null,
            'manifest_identity_level' => self::IDENTITY_SELF_ATTESTED,
            'operator_fingerprint' => null,
            'policy_key_fingerprint' => null,
            'revoked_at' => null,
            'rotation_epoch' => null,
            'revocation_reason_class' => null,
            'error' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $signature
     * @return array<string,mixed>
     */
    private static function withSignatureMetadata(array $base, array $signature): array
    {
        $algorithm = (string) ($signature['algorithm'] ?? '');
        $base['algorithm'] = $algorithm ?: null;
        $base['key_id'] = isset($signature['key_id']) ? (string) $signature['key_id'] : null;
        $base['signed_at'] = isset($signature['signed_at']) ? (string) $signature['signed_at'] : null;
        $base['expires_at'] = isset($signature['expires_at']) ? (string) $signature['expires_at'] : null;
        $base['rotation_epoch'] = self::safeRotationEpoch($signature['rotation_epoch'] ?? null);
        $base['revocation_reason_class'] = self::safeReasonClass($signature['revocation_reason_class'] ?? null);

        return $base;
    }

    /**
     * @param  array<string,mixed>  $signature
     * @return array{public_key:string,detached_signature:string,error:?string}
     */
    private static function signatureMaterial(array $signature): array
    {
        $publicKey = self::decodeBase64Flexible((string) ($signature['public_key'] ?? ''));
        $detachedSignature = self::decodeBase64Flexible((string) ($signature['signature'] ?? ''));
        if ($publicKey === null || strlen($publicKey) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return ['public_key' => '', 'detached_signature' => '', 'error' => 'invalid Ed25519 public key'];
        }
        if ($detachedSignature === null || strlen($detachedSignature) !== \SODIUM_CRYPTO_SIGN_BYTES) {
            return ['public_key' => '', 'detached_signature' => '', 'error' => 'invalid Ed25519 signature'];
        }

        return ['public_key' => $publicKey, 'detached_signature' => $detachedSignature, 'error' => null];
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $signature
     * @return array<string,mixed>|null
     */
    private static function verifiedRevocation(array $base, array $signature): ?array
    {
        if (! isset($signature['revoked_at'])) {
            return null;
        }

        try {
            $revokedAt = CarbonImmutable::parse((string) $signature['revoked_at']);
        } catch (\Throwable) {
            return self::invalid($base, 'invalid signature revocation time');
        }

        $base['revoked_at'] = $revokedAt->toIso8601String();
        if (! $revokedAt->isPast()) {
            return null;
        }

        $base['status'] = self::STATUS_SIGNED_REVOKED;
        $base['evidence'] = 'signed_revoked';
        $base['manifest_identity_level'] = self::IDENTITY_REVOKED;
        $base['error'] = 'policy key revoked';

        return $base;
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $signature
     * @return array<string,mixed>|null
     */
    private static function verifiedExpiry(array $base, array $signature): ?array
    {
        if (! isset($signature['expires_at'])) {
            return null;
        }

        try {
            $expired = CarbonImmutable::parse((string) $signature['expires_at'])->isPast();
        } catch (\Throwable) {
            return self::invalid($base, 'invalid signature expiry');
        }
        if (! $expired) {
            return null;
        }

        $base['status'] = self::STATUS_SIGNED_EXPIRED;
        $base['evidence'] = 'signed_expired';
        $base['error'] = 'signature expired';

        return $base;
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>|null  $operatorContext
     * @return array<string,mixed>
     */
    private static function signedValid(array $base, string $publicKey, ?array $operatorContext): array
    {
        $base['status'] = self::STATUS_SIGNED_VALID;
        $base['evidence'] = 'signed_verified';
        $base['manifest_identity_level'] = self::identityLevel($publicKey, $operatorContext);
        if (in_array($base['manifest_identity_level'], [self::IDENTITY_OPERATOR_BOUND, self::IDENTITY_KNOWN_OPERATOR], true)) {
            $base['operator_fingerprint'] = self::operatorFingerprint((string) ($operatorContext['operator_pubkey'] ?? ''));
        } elseif (($operatorContext['rotated'] ?? false) === true) {
            $base['manifest_identity_level'] = self::IDENTITY_ROTATED;
        }

        return $base;
    }

    private static function decodeBase64Flexible(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $standard = strtr($value, '-_', '+/');
        $padding = strlen($standard) % 4;
        if ($padding !== 0) {
            $standard .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($standard, true);

        return $decoded === false ? null : $decoded;
    }

    /** @param array<string,mixed>|null $operatorContext */
    private static function identityLevel(string $policyPublicKey, ?array $operatorContext): string
    {
        if ($operatorContext === null || ($operatorContext['operator_verified'] ?? false) !== true) {
            return self::IDENTITY_SIGNED_VALID;
        }

        $operatorPubkey = (string) ($operatorContext['operator_pubkey'] ?? '');
        $operatorPublicKey = self::decodeBase64Flexible($operatorPubkey);
        if ($operatorPublicKey === null || ! hash_equals($operatorPublicKey, $policyPublicKey)) {
            return self::IDENTITY_SIGNED_VALID;
        }

        if (($operatorContext['operator_known'] ?? false) === true || self::isKnownOperatorTier($operatorContext['operator_trust_tier'] ?? null)) {
            return self::IDENTITY_KNOWN_OPERATOR;
        }

        return self::IDENTITY_OPERATOR_BOUND;
    }

    private static function isKnownOperatorTier(mixed $tier): bool
    {
        return is_string($tier) && in_array($tier, [
            'known_operator',
            'terms_accepted',
            'dpa_accepted',
            'governed',
            'verified_legal',
        ], true);
    }

    private static function operatorFingerprint(string $operatorPubkey): ?string
    {
        $operatorPubkey = trim($operatorPubkey);
        if ($operatorPubkey === '') {
            return null;
        }

        return substr(hash('sha256', $operatorPubkey), 0, 12);
    }

    private static function safeRotationEpoch(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        return max(0, (int) $value);
    }

    private static function safeReasonClass(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        if (! preg_match('/^[a-z0-9_-]{1,64}$/', $value)) {
            return null;
        }

        return $value;
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'sortRecursive'], $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursive($item);
        }

        return $value;
    }
}
