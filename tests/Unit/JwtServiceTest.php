<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Services\JwtService;
use Tests\Support\TestAppKey;
use Tests\TestCase;

class JwtServiceTest extends TestCase
{
    private JwtService $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => TestAppKey::base64()]);
        $this->jwt = app(JwtService::class);
    }

    public function test_issues_and_verifies_node_profile(): void
    {
        $nodeId = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
        $result = $this->jwt->verifyNode($this->jwt->issueNode($nodeId));

        $this->assertTrue($result->isValid());
        $this->assertSame($nodeId, $result->claims['sub']);
        $this->assertSame('iicp.network', $result->claims['iss']);
        $this->assertArrayNotHasKey('role', $result->claims);
        $this->assertGreaterThan(time(), $result->claims['exp']);
    }

    public function test_replica_profile_round_trips_with_standard_base64_app_key(): void
    {
        $replicaId = 'rep-'.str_repeat('a', 32);
        $result = $this->jwt->verifyReplica($this->jwt->issueReplica($replicaId));

        $this->assertTrue($result->isValid());
        $this->assertSame($replicaId, $result->claims['sub']);
        $this->assertSame('replica', $result->claims['role']);
        $this->assertSame('GET /v1/snapshot', $result->claims['scope']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result->claims['jti']);
    }

    public function test_profiles_reject_each_others_tokens(): void
    {
        $nodeAsReplica = $this->jwt->verifyReplica($this->jwt->issueNode('node-1'));
        $replicaAsNode = $this->jwt->verifyNode($this->jwt->issueReplica('replica-1'));

        $this->assertFalse($nodeAsReplica->isValid());
        $this->assertSame('invalid_profile', $nodeAsReplica->failure);
        $this->assertFalse($replicaAsNode->isValid());
        $this->assertSame('invalid_profile', $replicaAsNode->failure);
    }

    public function test_existing_replica_profile_without_jti_and_legacy_scope_remains_valid(): void
    {
        $result = $this->jwt->verifyReplica(
            $this->signedToken($this->header(), $this->replicaClaims()),
        );

        $this->assertTrue($result->isValid());
        $this->assertArrayNotHasKey('jti', $result->claims);
    }

    public function test_rejects_unexpected_algorithm_type_and_critical_header(): void
    {
        $claims = $this->nodeClaims();

        foreach ([
            ['alg' => 'none', 'typ' => 'JWT'],
            ['alg' => 'HS256', 'typ' => 'JWS'],
            ['alg' => 'HS256', 'typ' => 'JWT', 'crit' => ['exp']],
        ] as $header) {
            $result = $this->jwt->verifyNode($this->signedToken($header, $claims));
            $this->assertFalse($result->isValid());
            $this->assertSame('invalid_header', $result->failure);
        }
    }

    public function test_rejects_wrong_issuer_claim_types_and_replica_scope(): void
    {
        $wrongIssuer = $this->nodeClaims();
        $wrongIssuer['iss'] = 'attacker.example';
        $stringExpiry = $this->nodeClaims();
        $stringExpiry['exp'] = (string) $stringExpiry['exp'];
        $wrongScope = $this->replicaClaims();
        $wrongScope['scope'] = 'POST /v1/credits';

        $this->assertSame(
            'invalid_claims',
            $this->jwt->verifyNode($this->signedToken($this->header(), $wrongIssuer))->failure,
        );
        $this->assertSame(
            'invalid_claims',
            $this->jwt->verifyNode($this->signedToken($this->header(), $stringExpiry))->failure,
        );
        $this->assertSame(
            'invalid_profile',
            $this->jwt->verifyReplica($this->signedToken($this->header(), $wrongScope))->failure,
        );
    }

    public function test_expired_valid_profile_is_distinguished_from_invalid_tokens(): void
    {
        $claims = $this->nodeClaims();
        $claims['iat'] = time() - 7200;
        $claims['exp'] = time() - 3600;

        $expired = $this->jwt->verifyNode($this->signedToken($this->header(), $claims));
        $tampered = $this->jwt->issueNode('node-1').'x';

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($expired->isValid());
        $this->assertFalse($this->jwt->verifyNode($tampered)->isExpired());
    }

    public function test_rejects_malformed_and_tampered_tokens(): void
    {
        foreach (['not.valid', 'a.b.c.d', 'not.a.jwt'] as $token) {
            $this->assertFalse($this->jwt->verifyNode($token)->isValid());
        }

        $parts = explode('.', $this->jwt->issueNode('node-1'));
        $parts[1] = $this->b64url('{"sub":"evil"}');
        $result = $this->jwt->verifyNode(implode('.', $parts));
        $this->assertFalse($result->isValid());
        $this->assertSame('invalid_signature', $result->failure);
    }

    public function test_empty_subject_fails_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->jwt->issueNode('');
    }

    public function test_missing_application_key_fails_closed_without_exposing_key_material(): void
    {
        config(['app.key' => '']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('An application key is required for JWT operations.');
        $this->jwt->issueNode('node-1');
    }

    private function header(): array
    {
        return ['alg' => 'HS256', 'typ' => 'JWT'];
    }

    private function nodeClaims(): array
    {
        return [
            'sub' => 'node-1',
            'iss' => 'iicp.network',
            'iat' => time(),
            'exp' => time() + 3600,
        ];
    }

    private function replicaClaims(): array
    {
        return [
            ...$this->nodeClaims(),
            'sub' => 'replica-1',
            'role' => 'replica',
            'scope' => 'GET /v1/events',
        ];
    }

    private function signedToken(array $header, array $claims): string
    {
        $encodedHeader = $this->b64url(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedClaims = $this->b64url(json_encode($claims, JSON_THROW_ON_ERROR));
        $input = "{$encodedHeader}.{$encodedClaims}";
        $secret = base64_decode(substr((string) config('app.key'), 7), true);
        $signature = $this->b64url(hash_hmac('sha256', $input, $secret, true));

        return "{$input}.{$signature}";
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
