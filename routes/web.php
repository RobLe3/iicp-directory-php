<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
| infrastructure (nginx / S3); this route is dev-only and only activates when
| IICP_DEV_DID_DOCUMENT_JSON env is set + APP_ENV is non-production.
*/
Route::get('/.well-known/did.json', function () {
    if (config('app.env') === 'production') {
        abort(404);
    }
    $doc = env('IICP_DEV_DID_DOCUMENT_JSON', '');
    if ($doc === '') {
        abort(404);
    }

    return response($doc, 200, ['Content-Type' => 'application/json']);
});
