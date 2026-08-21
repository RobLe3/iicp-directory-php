<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Models\TrustDomainMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TrustDomainMembershipService
{
    public function __construct(private RestrictedDomainMembershipAssertionService $assertions) {}

    /** @return array{membership: TrustDomainMembership, token: string} */
    public function issue(
        string $subjectKind,
        string $subjectId,
        array $scopes,
        int $ttlSeconds,
    ): array {
        $this->assertSubjectKind($subjectKind);
        $this->assertConfigured();
        $token = 'iicp_mem_'.Str::random(64);
        $maxTtl = (int) config('iicp.restricted_domain.max_credential_ttl_seconds', 86400);

        $membership = DB::transaction(fn (): TrustDomainMembership => $this->persistRotation(
            (string) config('iicp.restricted_domain.domain_id'),
            $subjectKind,
            $subjectId,
            $token,
            $this->normalizeScopes($scopes),
            max(60, min($ttlSeconds, $maxTtl)),
        ));

        return ['membership' => $membership, 'token' => $token];
    }

    /** @return array{membership: TrustDomainMembership, token: string, assertion: array} */
    public function issueWithAssertion(
        string $subjectKind,
        string $subjectId,
        array $scopes,
        int $ttlSeconds,
        string $subjectKeyId,
        string $subjectPublicKey,
    ): array {
        $this->assertSubjectKind($subjectKind);
        $this->assertConfigured();
        $token = 'iicp_mem_'.Str::random(64);
        $maxTtl = (int) config('iicp.restricted_domain.max_credential_ttl_seconds', 86400);

        return DB::transaction(fn (): array => $this->persistIssuedMembership(
            $subjectKind,
            $subjectId,
            $scopes,
            $ttlSeconds,
            $maxTtl,
            $token,
            $subjectKeyId,
            $subjectPublicKey,
        ));
    }

    public function verify(string $token, string $subjectId, string $operation): ?TrustDomainMembership
    {
        if ($token === '' || $subjectId === '' || ! config('iicp.restricted_domain.enabled')) {
            return null;
        }

        $membership = $this->currentMembership($token);
        if (! $membership || ! hash_equals($membership->subject_id, $subjectId)) {
            return null;
        }

        return $this->scopeAllows($membership, $operation) ? $membership : null;
    }

    private function currentMembership(string $token): ?TrustDomainMembership
    {
        return TrustDomainMembership::query()
            ->where('domain_id', (string) config('iicp.restricted_domain.domain_id'))
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where('generation', '>=', (int) config('iicp.restricted_domain.membership_epoch', 1))
            ->first();
    }

    private function scopeAllows(TrustDomainMembership $membership, string $operation): bool
    {
        $scopes = $membership->scopes ?? [];

        return in_array('*', $scopes, true) || in_array($operation, $scopes, true);
    }

    public function revoke(string $subjectKind, string $subjectId): bool
    {
        $this->assertSubjectKind($subjectKind);

        return TrustDomainMembership::query()
            ->where('domain_id', (string) config('iicp.restricted_domain.domain_id'))
            ->where('subject_kind', $subjectKind)
            ->where('subject_id', $subjectId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]) === 1;
    }

    private function assertConfigured(): void
    {
        if (! config('iicp.restricted_domain.enabled')) {
            throw new \LogicException('restricted trust-domain mode is not enabled');
        }
    }

    private function persistRotation(
        string $domainId,
        string $subjectKind,
        string $subjectId,
        string $token,
        array $scopes,
        int $ttlSeconds,
    ): TrustDomainMembership {
        $existing = TrustDomainMembership::query()
            ->where('domain_id', $domainId)
            ->where('subject_kind', $subjectKind)
            ->where('subject_id', $subjectId)
            ->lockForUpdate()
            ->first();

        $generation = max(
            (int) config('iicp.restricted_domain.membership_epoch', 1),
            ($existing->generation ?? 0) + 1,
        );
        $values = [
            'issuer_id' => (string) config('iicp.restricted_domain.authority_id'),
            'token_hash' => hash('sha256', $token),
            'scopes' => $scopes,
            'generation' => $generation,
            'expires_at' => now()->addSeconds($ttlSeconds),
            'revoked_at' => null,
        ];
        if ($existing) {
            return $this->replaceMembership($existing, $values);
        }

        return TrustDomainMembership::create($values + [
            'domain_id' => $domainId,
            'subject_kind' => $subjectKind,
            'subject_id' => $subjectId,
        ]);
    }

    /** @return array{membership: TrustDomainMembership, token: string, assertion: array} */
    private function persistIssuedMembership(
        string $subjectKind,
        string $subjectId,
        array $scopes,
        int $ttlSeconds,
        int $maxTtl,
        string $token,
        string $subjectKeyId,
        string $subjectPublicKey,
    ): array {
        $membership = $this->persistRotation(
            (string) config('iicp.restricted_domain.domain_id'),
            $subjectKind,
            $subjectId,
            $token,
            $this->normalizeScopes($scopes),
            max(60, min($ttlSeconds, $maxTtl)),
        );

        $assertion = $this->assertions->issue($membership, $subjectKeyId, $subjectPublicKey);
        $membership->forceFill(['membership_envelope' => $assertion])->save();

        return [
            'membership' => $membership,
            'token' => $token,
            'assertion' => $assertion,
        ];
    }

    private function replaceMembership(TrustDomainMembership $membership, array $values): TrustDomainMembership
    {
        $membership->fill($values)->save();

        return $membership->fresh();
    }

    private function assertSubjectKind(string $subjectKind): void
    {
        if (! in_array($subjectKind, ['node', 'client'], true)) {
            throw new \InvalidArgumentException('subject kind must be node or client');
        }
    }

    /** @return list<string> */
    private function normalizeScopes(array $scopes): array
    {
        $allowed = ['*', 'registration', 'discovery', 'bootstrap', 'heartbeat', 'peers', 'consumer_token', 'dispatch', 'relay'];
        $normalized = array_values(array_unique(array_filter(
            array_map(fn ($scope) => is_string($scope) ? trim($scope) : '', $scopes),
            fn ($scope) => in_array($scope, $allowed, true),
        )));
        if ($normalized === []) {
            throw new \InvalidArgumentException('at least one valid membership scope is required');
        }

        return $normalized;
    }
}
