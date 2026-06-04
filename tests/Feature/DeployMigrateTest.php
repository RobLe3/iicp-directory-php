<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use Tests\TestCase;

class DeployMigrateTest extends TestCase
{
    private string $secret = 'test-deploy-secret-32-bytes-hex-only-for-test-purposes';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.deploy_secret' => $this->secret]);
    }

    private function sign(int $ts, string $version): string
    {
        return hash_hmac('sha256', $ts."\n".$version, $this->secret);
    }

    public function test_returns_503_when_secret_is_unset(): void
    {
        config(['app.deploy_secret' => '']);
        $ts = time();
        $resp = $this->withHeaders([
            'X-IICP-Deploy-Timestamp' => (string) $ts,
            'X-IICP-Deploy-Signature' => $this->sign($ts, '1.9.24'),
        ])->postJson('/api/_deploy/migrate', ['version' => '1.9.24']);
        $resp->assertStatus(503)->assertJsonPath('error.code', 'deploy_disabled');
    }

    public function test_rejects_missing_headers(): void
    {
        $resp = $this->postJson('/api/_deploy/migrate', ['version' => '1.9.24']);
        $resp->assertStatus(400)->assertJsonPath('error.code', 'bad_request');
    }

    public function test_rejects_out_of_window_timestamp(): void
    {
        $ts = time() - 600; // 10 minutes old
        $resp = $this->withHeaders([
            'X-IICP-Deploy-Timestamp' => (string) $ts,
            'X-IICP-Deploy-Signature' => $this->sign($ts, '1.9.24'),
        ])->postJson('/api/_deploy/migrate', ['version' => '1.9.24']);
        $resp->assertStatus(401)->assertJsonPath('error.code', 'timestamp_out_of_window');
    }

    public function test_rejects_invalid_signature(): void
    {
        $ts = time();
        $resp = $this->withHeaders([
            'X-IICP-Deploy-Timestamp' => (string) $ts,
            'X-IICP-Deploy-Signature' => str_repeat('0', 64),
        ])->postJson('/api/_deploy/migrate', ['version' => '1.9.24']);
        $resp->assertStatus(401)->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_rejects_signature_for_different_version(): void
    {
        $ts = time();
        // Sign one version, claim another
        $resp = $this->withHeaders([
            'X-IICP-Deploy-Timestamp' => (string) $ts,
            'X-IICP-Deploy-Signature' => $this->sign($ts, '9.9.9'),
        ])->postJson('/api/_deploy/migrate', ['version' => '1.9.24']);
        $resp->assertStatus(401)->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_runs_migrate_with_valid_signature(): void
    {
        $ts = time();
        $resp = $this->withHeaders([
            'X-IICP-Deploy-Timestamp' => (string) $ts,
            'X-IICP-Deploy-Signature' => $this->sign($ts, '1.9.24'),
        ])->postJson('/api/_deploy/migrate', ['version' => '1.9.24']);
        $resp->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('version', '1.9.24')
            ->assertJsonPath('exit_code', 0)
            ->assertJsonStructure(['output']);
    }
}
