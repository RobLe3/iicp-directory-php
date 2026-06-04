<?php

namespace Tests\Feature;

use App\Services\SeedDidResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeedDidResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeDidDoc(string $xBase64Url): array
    {
        return [
            '@context' => ['https://www.w3.org/ns/did/v1'],
            'id' => 'did:web:iicp.network',
            'verificationMethod' => [[
                'id' => 'did:web:iicp.network#key-1',
                'type' => 'JsonWebKey2020',
                'controller' => 'did:web:iicp.network',
                'publicKeyJwk' => ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $xBase64Url],
            ]],
        ];
    }

    public function test_extracts_valid_ed25519_public_key(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $rawPub = sodium_crypto_sign_publickey($kp);
        $b64 = rtrim(strtr(base64_encode($rawPub), '+/', '-_'), '=');

        Http::fake([
            'https://iicp.network/.well-known/did.json' => Http::response($this->makeDidDoc($b64), 200),
        ]);

        $resolver = new SeedDidResolver;
        $key = $resolver->publicKey('https://iicp.network');

        $this->assertNotNull($key);
        $this->assertSame(32, strlen($key));
        $this->assertSame($rawPub, $key);
    }

    public function test_returns_null_on_fetch_failure(): void
    {
        Http::fake(['https://iicp.network/.well-known/did.json' => Http::response('', 404)]);
        $this->assertNull((new SeedDidResolver)->publicKey('https://iicp.network'));
    }

    public function test_returns_null_when_key_is_placeholder(): void
    {
        Http::fake([
            'https://iicp.network/.well-known/did.json' => Http::response($this->makeDidDoc('GENESIS_KEY_PENDING'), 200),
        ]);
        $this->assertNull((new SeedDidResolver)->publicKey('https://iicp.network'));
    }

    public function test_returns_null_when_key_wrong_curve(): void
    {
        $doc = $this->makeDidDoc(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
        $doc['verificationMethod'][0]['publicKeyJwk']['crv'] = 'P-256';
        Http::fake(['https://iicp.network/.well-known/did.json' => Http::response($doc, 200)]);
        $this->assertNull((new SeedDidResolver)->publicKey('https://iicp.network'));
    }

    public function test_caches_result(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $b64 = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($kp)), '+/', '-_'), '=');
        Http::fake([
            'https://iicp.network/.well-known/did.json' => Http::response($this->makeDidDoc($b64), 200),
        ]);

        $resolver = new SeedDidResolver;
        $resolver->publicKey('https://iicp.network');
        $resolver->publicKey('https://iicp.network');

        // Only one HTTP request despite two calls
        Http::assertSentCount(1);
    }

    public function test_forget_clears_cache(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $b64 = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($kp)), '+/', '-_'), '=');
        Http::fake([
            'https://iicp.network/.well-known/did.json' => Http::response($this->makeDidDoc($b64), 200),
        ]);

        $resolver = new SeedDidResolver;
        $resolver->publicKey('https://iicp.network');
        $resolver->forget('https://iicp.network');
        $resolver->publicKey('https://iicp.network');

        Http::assertSentCount(2);
    }
}
