<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\TrustDomainMembership;
use Illuminate\Support\Str;

final class RestrictedDomainMembershipAssertionService
{
    public const DOMAIN = "IICP-RTD-MEMBERSHIP-V0\n";

    /** @return array{assertion: array<string, mixed>, signature: array<string, string>} */
    public function issue(TrustDomainMembership $membership, string $subjectKeyId, string $subjectPublicKey): array
    {
        $secretHex = $this->signingSecret();
        $this->decoded($subjectPublicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $this->assertSubjectKeyId($subjectKeyId);
        $peerScopes = $this->peerScopes($membership);
        $assertion = $this->unsignedAssertion($membership, $subjectKeyId, $subjectPublicKey, $peerScopes);

        return [
            'assertion' => $assertion,
            'signature' => $this->signature($assertion, $secretHex),
        ];
    }

    public function verify(array $envelope, string $authorityPublicKey): bool
    {
        try {
            $assertion = $this->assertionFrom($envelope);
            $signature = $this->signatureFrom($envelope);
            $public = $this->decoded($authorityPublicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
            $detached = $this->decoded($signature, SODIUM_CRYPTO_SIGN_BYTES);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            $detached,
            self::DOMAIN.NodeEventLogger::canonicalJson($assertion),
            $public,
        );
    }

    private function authorityKeyId(string $issuerId): string
    {
        $configured = config('iicp.restricted_domain.authority_key_id');

        return is_string($configured) && trim($configured) !== '' ? $configured : $issuerId.'#key-1';
    }

    /** @param list<string> $peerScopes
     * @return array<string, mixed>
     */
    private function unsignedAssertion(
        TrustDomainMembership $membership,
        string $subjectKeyId,
        string $subjectPublicKey,
        array $peerScopes,
    ): array {
        $issuerId = (string) $membership->issuer_id;

        return [
            'schema' => 'iicp.restricted-trust-domain.membership-assertion.v0',
            'profile' => 'urn:iicp:profile:restricted-trust-domain:v1',
            'assertion_id' => (string) Str::uuid(),
            'domain_id' => (string) $membership->domain_id,
            'subject' => $this->subject($membership, $subjectKeyId, $subjectPublicKey),
            'issuer' => ['id' => $issuerId, 'key_id' => $this->authorityKeyId($issuerId)],
            'issued_at' => $membership->created_at->getTimestamp(),
            'expires_at' => $membership->expires_at->getTimestamp(),
            'generation' => (int) $membership->generation,
            'scopes' => $peerScopes,
            'audience' => [(string) $membership->domain_id],
        ];
    }

    /** @return array<string, string> */
    private function subject(TrustDomainMembership $membership, string $keyId, string $publicKey): array
    {
        return [
            'kind' => (string) $membership->subject_kind,
            'id' => (string) $membership->subject_id,
            'key_id' => $keyId,
            'public_key_ed25519' => $publicKey,
        ];
    }

    /** @return array<string, string> */
    private function signature(array $assertion, string $secretHex): array
    {
        $signature = sodium_crypto_sign_detached(
            self::DOMAIN.NodeEventLogger::canonicalJson($assertion),
            sodium_hex2bin($secretHex),
        );

        return ['algorithm' => 'Ed25519', 'value' => $this->base64UrlEncode($signature)];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function signingSecret(): string
    {
        $secret = config('app.genesis_ed25519_secret_key');
        if (! is_string($secret) || ! preg_match('/^[0-9a-fA-F]{128}$/', $secret)) {
            throw new \LogicException('directory Ed25519 signing key is required for a peer-verifiable membership assertion');
        }

        return $secret;
    }

    private function assertSubjectKeyId(string $keyId): void
    {
        if (trim($keyId) === '') {
            throw new \InvalidArgumentException('subject key identifier is required');
        }
    }

    /** @return list<string> */
    private function peerScopes(TrustDomainMembership $membership): array
    {
        $scopes = array_values(array_intersect(
            $membership->scopes ?? [],
            ['bootstrap', 'peers', 'relay', 'execution', 'cip', 'federation'],
        ));
        if ($scopes === []) {
            throw new \InvalidArgumentException('a peer-verifiable assertion requires at least one peer operation scope');
        }

        return $scopes;
    }

    /** @return array<string, mixed> */
    private function assertionFrom(array $envelope): array
    {
        $assertion = $envelope['assertion'] ?? null;
        if (! is_array($assertion)) {
            throw new \InvalidArgumentException('membership assertion is missing');
        }

        return $assertion;
    }

    private function signatureFrom(array $envelope): string
    {
        $signature = $envelope['signature']['value'] ?? null;
        if (! is_string($signature)) {
            throw new \InvalidArgumentException('membership signature is missing');
        }

        return $signature;
    }

    private function decoded(string $value, int $expectedLength): string
    {
        $this->assertBase64Url($value);
        $decoded = $this->decodeValue($value);
        $this->assertDecodedLength($decoded, $expectedLength);

        return $decoded;
    }

    private function assertBase64Url(string $value): void
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new \InvalidArgumentException('invalid base64url value');
        }
    }

    private function decodeValue(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if (! is_string($decoded)) {
            throw new \InvalidArgumentException('invalid base64url value');
        }

        return $decoded;
    }

    private function assertDecodedLength(string $decoded, int $expectedLength): void
    {
        if (strlen($decoded) !== $expectedLength) {
            throw new \InvalidArgumentException('invalid Ed25519 value length');
        }
    }
}
