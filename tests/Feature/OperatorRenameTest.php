<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Http\Controllers\OperatorController;
use App\Models\Operator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #460/#463 — operator-signed display_name rename (mutable nickname over the immutable
 * operator_id). Only the operator key-holder may rename; replay-protected; updates the one
 * operator-keyed record (reflected on every node + the leaderboard).
 */
class OperatorRenameTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:string,1:string} [base64 pubkey, raw secret] */
    private function keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();

        return [base64_encode(sodium_crypto_sign_publickey($kp)), sodium_crypto_sign_secretkey($kp)];
    }

    private function signRename(string $pub, string $secret, string $name, int $ts): string
    {
        return base64_encode(sodium_crypto_sign_detached(
            OperatorController::canonicalBytes($name, $pub, $ts),
            $secret
        ));
    }

    public function test_operator_signed_rename_updates_display_name(): void
    {
        [$pub, $secret] = $this->keypair();
        Operator::create(['operator_pubkey' => $pub, 'display_name' => 'Old Name', 'first_seen_ms' => 1]);

        $ts = time();
        $resp = $this->postJson('/api/v1/operator/rename', [
            'operator_pub' => $pub,
            'display_name' => 'New Name',
            'ts' => $ts,
            'sig' => $this->signRename($pub, $secret, 'New Name', $ts),
        ]);
        $resp->assertStatus(200)->assertJsonPath('display_name', 'New Name');
        $this->assertSame('New Name', Operator::where('operator_pubkey', $pub)->value('display_name'));
    }

    public function test_bad_signature_is_rejected(): void
    {
        [$pub] = $this->keypair();
        Operator::create(['operator_pubkey' => $pub, 'display_name' => 'Old', 'first_seen_ms' => 1]);
        $ts = time();
        $this->postJson('/api/v1/operator/rename', [
            'operator_pub' => $pub, 'display_name' => 'Hijacked', 'ts' => $ts,
            'sig' => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES)),
        ])->assertStatus(401);
        $this->assertSame('Old', Operator::where('operator_pubkey', $pub)->value('display_name'));
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        [$pub, $secret] = $this->keypair();
        Operator::create(['operator_pubkey' => $pub, 'display_name' => 'Old', 'first_seen_ms' => 1]);
        $ts = time() - 3600; // way outside the replay window
        $this->postJson('/api/v1/operator/rename', [
            'operator_pub' => $pub, 'display_name' => 'New', 'ts' => $ts,
            'sig' => $this->signRename($pub, $secret, 'New', $ts),
        ])->assertStatus(401);
    }

    public function test_unknown_operator_is_404(): void
    {
        [$pub, $secret] = $this->keypair(); // never inserted into operators
        $ts = time();
        $this->postJson('/api/v1/operator/rename', [
            'operator_pub' => $pub, 'display_name' => 'Ghost', 'ts' => $ts,
            'sig' => $this->signRename($pub, $secret, 'Ghost', $ts),
        ])->assertStatus(404);
    }
}
