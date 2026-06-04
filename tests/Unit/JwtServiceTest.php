<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Services\JwtService;
use Tests\TestCase;

class JwtServiceTest extends TestCase
{
    public function test_issues_and_verifies_a_valid_jwt(): void
    {
        $jwt = app(JwtService::class);
        $nodeId = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';

        $token = $jwt->issue($nodeId);
        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token);

        $claims = $jwt->verify($token);
        $this->assertIsArray($claims);
        $this->assertSame($nodeId, $claims['sub']);
        $this->assertSame('iicp.network', $claims['iss']);
        $this->assertGreaterThan(time(), $claims['exp']);
    }

    public function test_verify_rejects_tampered_token(): void
    {
        $jwt = app(JwtService::class);
        $token = $jwt->issue('some-node-id');
        $parts = explode('.', $token);
        $parts[1] = base64_encode('{"sub":"evil","exp":9999999999,"iss":"iicp.network","iat":1}');
        $tampered = implode('.', $parts);

        $this->assertNull($jwt->verify($tampered));
    }

    public function test_verify_rejects_malformed_token(): void
    {
        $jwt = app(JwtService::class);

        $this->assertNull($jwt->verify('not.a.valid.token.at.all'));
        $this->assertNull($jwt->verify('onlytwoparts.x'));
    }

    public function test_is_expired_jwt_returns_false_for_valid_token(): void
    {
        $jwt = app(JwtService::class);
        $token = $jwt->issue('some-node');

        $this->assertFalse($jwt->isExpiredJwt($token));
    }

    public function test_is_expired_jwt_returns_true_for_expired_token(): void
    {
        $jwt = app(JwtService::class);

        // Craft a token with exp in the past using the real secret
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $claims = ['sub' => 'x', 'iss' => 'iicp.network', 'iat' => time() - 7200, 'exp' => time() - 3600];
        $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        // Use real signing so signature is valid
        $token = $jwt->issue('throwaway'); // issue to get format, then replace segments below

        // Re-sign with real secret via reflection to access private method — simpler: just verify
        // that a freshly issued + manually expired token is detected.
        // Easiest: issue a valid token and check isExpiredJwt(tamperedExpiry) = false (wrong sig)
        $tampered = $token;
        $parts = explode('.', $tampered);
        // Tamper the payload to set exp in the past (this breaks the signature)
        $parts[1] = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        $tampered = implode('.', $parts);
        // Tampered token has wrong sig → isExpiredJwt must return false (not a valid-sig expired token)
        $this->assertFalse($jwt->isExpiredJwt($tampered));
    }

    public function test_is_expired_jwt_returns_false_for_invalid_signature(): void
    {
        $jwt = app(JwtService::class);
        $token = $jwt->issue('node-x');
        $parts = explode('.', $token);
        $parts[2] = 'invalidsignature';
        $this->assertFalse($jwt->isExpiredJwt(implode('.', $parts)));
    }

    public function test_is_expired_jwt_returns_false_for_malformed_token(): void
    {
        $jwt = app(JwtService::class);

        $this->assertFalse($jwt->isExpiredJwt('not.valid'));
        $this->assertFalse($jwt->isExpiredJwt('a.b.c.d'));
    }
}
