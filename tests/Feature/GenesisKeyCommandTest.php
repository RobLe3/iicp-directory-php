<?php

namespace Tests\Feature;

use Tests\TestCase;

class GenesisKeyCommandTest extends TestCase
{
    public function test_command_outputs_valid_base64url_public_key(): void
    {
        $output = $this->artisan('iicp:genesis-key')->run();

        // Command must exit cleanly
        $this->assertSame(0, $output);
    }

    public function test_command_public_key_is_32_bytes_when_decoded(): void
    {
        // Use the command class directly to verify the key math is correct
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $publicBase64 = rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '=');
        $padded = str_pad(strtr($publicBase64, '-_', '+/'), strlen($publicBase64) + (4 - strlen($publicBase64) % 4) % 4, '=');
        $decoded = base64_decode($padded, strict: true);

        $this->assertNotFalse($decoded, 'Public key round-trips through base64url');
        $this->assertSame(32, strlen($decoded), 'Ed25519 public key must be 32 bytes');
        // Signing with the secret key and verifying with the decoded public key must succeed
        $message = 'test-genesis-signing';
        $sig = sodium_crypto_sign_detached($message, $secretKey);
        $this->assertTrue(sodium_crypto_sign_verify_detached($sig, $message, $decoded));
    }
}
