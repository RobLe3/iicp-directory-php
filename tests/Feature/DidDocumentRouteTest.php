<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * P6-3.1c — testbed-only /.well-known/did.json route.
 *
 * Serves the DID document supplied via IICP_DEV_DID_DOCUMENT_JSON env when
 * APP_ENV is non-production. Always 404 in production.
 */
class DidDocumentRouteTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('IICP_DEV_DID_DOCUMENT_JSON');
        putenv('IICP_REPLICA_ED25519_SECRET_KEY');
        config(['app.env' => 'testing', 'app.genesis_ed25519_secret_key' => null]);
        parent::tearDown();
    }

    public function test_iicp_health_returns_200(): void
    {
        // E042: the seed's join handshake curls a replica's /iicp/health for reachability.
        $this->get('/iicp/health')
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
    }

    public function test_derives_did_from_replica_key_when_no_genesis(): void
    {
        // A replica has no genesis key — it advertises its own replica signing key so the
        // seed's join handshake (E040) can resolve an Ed25519 verificationMethod.
        $keypair = sodium_crypto_sign_keypair();
        $secretHex = bin2hex(sodium_crypto_sign_secretkey($keypair));
        $expectedX = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($keypair)), '+/', '-_'), '=');

        config(['app.env' => 'local', 'app.genesis_ed25519_secret_key' => null]);
        putenv('IICP_DEV_DID_DOCUMENT_JSON');
        putenv("IICP_REPLICA_ED25519_SECRET_KEY={$secretHex}");

        $this->get('/.well-known/did.json')
            ->assertStatus(200)
            ->assertJsonPath('verificationMethod.0.publicKeyJwk.x', $expectedX);
    }

    public function test_404_when_env_unset_and_no_genesis_key(): void
    {
        config(['app.env' => 'local', 'app.genesis_ed25519_secret_key' => null]);
        putenv('IICP_DEV_DID_DOCUMENT_JSON');
        $this->get('/.well-known/did.json')->assertStatus(404);
    }

    public function test_derives_did_document_from_genesis_key_when_env_unset(): void
    {
        // FED-READY-2: when no explicit doc is set, the route derives the DID document from
        // the genesis signing key so the advertised pubkey matches the seed's signatures.
        $keypair = sodium_crypto_sign_keypair();
        $secretHex = bin2hex(sodium_crypto_sign_secretkey($keypair));
        $expectedX = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($keypair)), '+/', '-_'), '=');

        config(['app.env' => 'local', 'app.genesis_ed25519_secret_key' => $secretHex]);
        putenv('IICP_DEV_DID_DOCUMENT_JSON');

        $response = $this->get('/.well-known/did.json');
        $response->assertStatus(200);
        $response->assertJsonPath('verificationMethod.0.publicKeyJwk.crv', 'Ed25519');
        $response->assertJsonPath('verificationMethod.0.publicKeyJwk.x', $expectedX);
    }

    public function test_serves_did_document_when_env_set(): void
    {
        config(['app.env' => 'local']);
        $doc = '{"@context":["https://www.w3.org/ns/did/v1"],"id":"did:web:replica-directory"}';
        putenv("IICP_DEV_DID_DOCUMENT_JSON={$doc}");
        $response = $this->get('/.well-known/did.json');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertSame($doc, $response->getContent());
    }

    public function test_404_in_production_even_when_env_set(): void
    {
        config(['app.env' => 'production']);
        putenv('IICP_DEV_DID_DOCUMENT_JSON={"id":"did:web:should-not-serve"}');
        $this->get('/.well-known/did.json')->assertStatus(404);
    }
}
