<?php

use App\Services\DeploymentProvenanceService;
use App\Services\OperatorReadiness;
use App\Services\RuntimeSecretProvider;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| GET /iicp/health — federation-participant liveness probe.
| The seed's ReplicasController::endpointReachable curls a candidate replica's
| {endpoint}/iicp/health during the join handshake (IICP-E042). A directory acting
| as a replica must therefore expose a lightweight 200 liveness endpoint — same
| convention provider nodes use. Cheap, unauthenticated, no DB.
*/
Route::get('/iicp/health', fn () => response()->json(['ok' => true, 'role' => 'directory']));

/*
| GET /iicp/ready — operator readiness probe.
| Fixed, content-free output only: it confirms database connectivity and that
| every tracked migration has run. It never returns exception, host, schema,
| credential, query, or pending-migration details.
*/
Route::get('/iicp/ready', function (OperatorReadiness $readiness) {
    $ready = $readiness->ready();

    return response()
        ->json(['ok' => $ready, 'role' => 'directory', 'ready' => $ready], $ready ? 200 : 503)
        ->header('Cache-Control', 'no-store');
});

Route::get('/.well-known/iicp-deployment.json', function (DeploymentProvenanceService $provenance) {
    $record = $provenance->record();
    if ($record === null) {
        return response()
            ->json(['error' => ['code' => 'deployment_record_unavailable']], 503)
            ->header('Cache-Control', 'no-store');
    }

    return response()
        ->json($record)
        ->header('Cache-Control', 'public, max-age=300');
});

/*
|--------------------------------------------------------------------------
| /.well-known/did.json — testbed-only DID document
|--------------------------------------------------------------------------
| Spec context: did:web resolution per https://w3c-ccg.github.io/did-method-web/
| reads DID documents from <https://example.com/.well-known/did.json>. The
| testbed (P6-3.1c) needs seed and replica directories to advertise their
| DID documents inside the docker-compose network so the seed's
| ReplicasController.validateDidDocument can fetch them.
|
| Production directories serve their DID document via static-file
| infrastructure (nginx / S3); this route is dev-only and never serves in production.
| Content resolution order (non-production only):
|   1. IICP_DEV_DID_DOCUMENT_JSON — explicit override (verbatim JSON), or
|   2. derived from the genesis signing key — so the advertised Ed25519 pubkey always
|      matches the signatures the seed emits (FED-READY-2 verify path); no fiddly env.
*/
Route::get('/.well-known/did.json', function () {
    if (config('app.env') === 'production') {
        abort(404);
    }

    $secrets = app(RuntimeSecretProvider::class);
    $doc = $secrets->get(RuntimeSecretProvider::DEV_DID_DOCUMENT_JSON) ?? '';
    if ($doc !== '') {
        return response($doc, 200, ['Content-Type' => 'application/json']);
    }

    // Derive the DID document from an Ed25519 signing key (testbed convenience). A seed
    // advertises its genesis key (the one it signs the event log with, so a replica's
    // DIR-FED-01 verification resolves a matching key); a replica has no genesis key, so it
    // advertises its own replica signing key (IICP_REPLICA_ED25519_SECRET_KEY) — the key the
    // seed's join handshake (E040) resolves from did:web:replica-... /.well-known/did.json.
    $hexKey = config('app.genesis_ed25519_secret_key');
    if (! is_string($hexKey) || strlen($hexKey) !== 128 || ! ctype_xdigit($hexKey)) {
        $hexKey = $secrets->get(RuntimeSecretProvider::REPLICA_ED25519_SECRET_KEY);
    }
    if (! is_string($hexKey) || strlen($hexKey) !== 128 || ! ctype_xdigit($hexKey)) {
        abort(404);
    }
    $publicKey = sodium_crypto_sign_publickey_from_secretkey(sodium_hex2bin($hexKey));
    $x = rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '=');
    $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
    $did = "did:web:{$host}";
    $document = [
        '@context' => ['https://www.w3.org/ns/did/v1', 'https://w3id.org/security/suites/jws-2020/v1'],
        'id' => $did,
        'verificationMethod' => [[
            'id' => "{$did}#genesis",
            'type' => 'JsonWebKey2020',
            'controller' => $did,
            'publicKeyJwk' => ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $x],
        ]],
        'assertionMethod' => ["{$did}#genesis"],
    ];

    return response()->json($document);
});
