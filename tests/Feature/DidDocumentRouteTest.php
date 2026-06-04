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
        config(['app.env' => 'testing']);
        parent::tearDown();
    }

    public function test_404_when_env_unset(): void
    {
        config(['app.env' => 'local']);
        putenv('IICP_DEV_DID_DOCUMENT_JSON');
        $this->get('/.well-known/did.json')->assertStatus(404);
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
