<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Http\Controllers\OperatorSelfServiceController;
use App\Models\Operator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $pub;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $kp = sodium_crypto_sign_keypair();
        $this->pub = base64_encode(sodium_crypto_sign_publickey($kp));
        $this->secret = sodium_crypto_sign_secretkey($kp);
        Operator::create([
            'operator_pubkey' => $this->pub,
            'display_name' => 'Self Service Test',
            'first_seen_ms' => 1,
        ]);
    }

    private function challenge(): string
    {
        return $this->postJson('/api/v1/operator/challenge', ['operator_pub' => $this->pub])
            ->assertOk()
            ->json('nonce');
    }

    /** @param array<string,mixed> $extra */
    private function signedBody(string $action, array $extra = []): array
    {
        $body = [
            'operator_pub' => $this->pub,
            'nonce' => $this->challenge(),
            'ts' => time(),
            ...$extra,
        ];
        $body['sig'] = base64_encode(sodium_crypto_sign_detached(
            OperatorSelfServiceController::canonicalBytes($action, $body),
            $this->secret,
        ));

        return $body;
    }

    public function test_signed_acceptance_updates_current_versions_and_returns_redacted_receipt(): void
    {
        $body = $this->signedBody('accept', [
            'terms_version' => config('app.iicp_operator_terms_version'),
            'dpa_version' => config('app.iicp_operator_dpa_version'),
        ]);

        $response = $this->postJson('/api/v1/operator/acceptance', $body)
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('legal_certification', false);
        $this->assertArrayNotHasKey('operator_pub', $response->json());
        $this->assertArrayNotHasKey('nonce', $response->json());

        $operator = Operator::where('operator_pubkey', $this->pub)->firstOrFail();
        $this->assertSame('operator_key_challenge', $operator->acceptance_method);
        $this->assertSame(hash('sha256', $body['nonce']), $operator->acceptance_nonce_sha256);
    }

    public function test_challenge_is_one_time_and_bad_signature_does_not_mutate(): void
    {
        $body = $this->signedBody('accept', [
            'terms_version' => config('app.iicp_operator_terms_version'),
            'dpa_version' => config('app.iicp_operator_dpa_version'),
        ]);
        $body['sig'] = base64_encode(random_bytes(64));
        $this->postJson('/api/v1/operator/acceptance', $body)->assertUnauthorized();
        $this->postJson('/api/v1/operator/acceptance', $body)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'IICP-E062');
        $this->assertNull(Operator::where('operator_pubkey', $this->pub)->value('terms_version'));
    }

    public function test_self_service_export_is_redacted_and_requires_valid_signature(): void
    {
        $body = $this->signedBody('dsr_export', ['tracking_id' => 'dsr-export-test']);
        $response = $this->postJson('/api/v1/operator/dsr/export', $body)
            ->assertOk()
            ->assertJsonPath('schema', 'iicp.dsr.export.v1')
            ->assertJsonPath('tracking_id', 'dsr-export-test');
        $raw = $response->getContent();
        $this->assertStringNotContainsString($this->pub, $raw);
        $this->assertStringNotContainsString($body['nonce'], $raw);
        $this->assertStringNotContainsString($body['sig'], $raw);
    }

    public function test_restrict_requires_explicit_confirmation(): void
    {
        $body = $this->signedBody('dsr_restrict', ['tracking_id' => 'dsr-restrict-test']);
        $this->postJson('/api/v1/operator/dsr/restrict', $body)->assertUnprocessable();

        $body = $this->signedBody('dsr_restrict', ['tracking_id' => 'dsr-restrict-test', 'confirm' => true]);
        $this->postJson('/api/v1/operator/dsr/restrict', $body)
            ->assertOk()
            ->assertJsonPath('action', 'restrict');
        $this->assertSame(['dsr' => 'restricted'], Operator::where('operator_pubkey', $this->pub)->value('provenance'));
    }

    public function test_anonymize_removes_operator_identity(): void
    {
        $body = $this->signedBody('dsr_anonymize', ['tracking_id' => 'dsr-anonymize-test', 'confirm' => true]);
        $this->postJson('/api/v1/operator/dsr/anonymize', $body)
            ->assertOk()
            ->assertJsonPath('action', 'anonymize');
        $this->assertDatabaseMissing('operators', ['operator_pubkey' => $this->pub]);
    }
}
