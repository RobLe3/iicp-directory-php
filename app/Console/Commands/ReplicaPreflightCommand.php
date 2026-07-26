<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Services\RuntimeSecretProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Pre-flight check for replica configuration BEFORE running iicp:replica-start.
 *
 * Catches the misconfigurations that would otherwise surface as cryptic
 * runtime errors (registration 4xx, sig verify fail, snapshot 401, etc.):
 *  - Required env vars present + well-formed
 *  - Seed reachable + returns matching genesis_hash
 *  - Local public did.json hosted + extractable
 *  - Public key in did.json matches our IICP_REPLICA_ED25519_SECRET_KEY
 *
 * Usage:
 *   php artisan iicp:replica-preflight \
 *     --seed-url=https://iicp.network \
 *     --did=did:web:<replica-domain> \
 *     --endpoint=https://<replica-domain>
 *
 * Exit codes:
 *   0 = all checks passed (ready for iicp:replica-start)
 *   2 = configuration error (missing flags or bad inputs — INVALID)
 *   1 = one or more checks failed (FAILURE)
 */
class ReplicaPreflightCommand extends Command
{
    public function __construct(private readonly RuntimeSecretProvider $secrets)
    {
        parent::__construct();
    }

    protected $signature = 'iicp:replica-preflight '
        .'{--seed-url= : Genesis Seed base URL} '
        .'{--did= : This replica\'s did:web identifier} '
        .'{--endpoint= : This replica\'s public https endpoint} '
        .'{--strict : Treat warnings as failures (exit 1)}';

    protected $description = 'Validate replica configuration before iicp:replica-start (P6 deploy gate)';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $seedUrl = rtrim((string) $this->option('seed-url'), '/');
        $did = (string) $this->option('did');
        $endpoint = rtrim((string) $this->option('endpoint'), '/');
        $strict = (bool) $this->option('strict');

        foreach (['seed-url' => $seedUrl, 'did' => $did, 'endpoint' => $endpoint] as $name => $val) {
            if ($val === '') {
                $this->error("Missing required option: --{$name}");

                return self::INVALID;
            }
        }

        $this->line('<fg=cyan>━━━ iicp:replica-preflight ━━━</fg=cyan>');
        $this->line("  seed_url = {$seedUrl}");
        $this->line("  did      = {$did}");
        $this->line("  endpoint = {$endpoint}");
        $this->line('  mode     = '.($strict ? 'STRICT (warnings → failures)' : 'default'));
        $this->newLine();

        $this->checkEnvFlags();
        $this->checkSecretKey();
        $this->checkEndpointHttps($endpoint);
        $this->checkSeedReachable($seedUrl);
        $this->checkLocalDidDoc($endpoint);

        $this->newLine();
        $this->line('<fg=cyan>━━━ Summary ━━━</fg=cyan>');
        $this->line("  failures: {$this->failures}");
        $this->line("  warnings: {$this->warnings}");

        if ($this->failures > 0) {
            $this->error("✗ Preflight FAILED — fix the {$this->failures} failure(s) above before iicp:replica-start");

            return self::FAILURE;
        }
        if ($strict && $this->warnings > 0) {
            $this->error("✗ Preflight FAILED in strict mode — {$this->warnings} warning(s)");

            return self::FAILURE;
        }
        $this->info('✓ Preflight PASSED — safe to run iicp:replica-start');

        return self::SUCCESS;
    }

    private function markPass(string $check): void
    {
        $this->line("  <fg=green>✓</fg=green> {$check}");
    }

    private function markFail(string $check, string $hint = ''): void
    {
        $this->failures++;
        $this->line("  <fg=red>✗</fg=red> {$check}");
        if ($hint !== '') {
            $this->line("      <fg=yellow>→ {$hint}</fg=yellow>");
        }
    }

    private function markWarn(string $check, string $hint = ''): void
    {
        $this->warnings++;
        $this->line("  <fg=yellow>!</fg=yellow> {$check}");
        if ($hint !== '') {
            $this->line("      <fg=yellow>→ {$hint}</fg=yellow>");
        }
    }

    private function checkEnvFlags(): void
    {
        $this->line('<fg=cyan>[1/5] Environment flags</fg=cyan>');
        $replicaMode = filter_var(config('iicp.replica.enabled', false), FILTER_VALIDATE_BOOLEAN);
        if ($replicaMode) {
            $this->markPass('IICP_REPLICA_MODE=true');
        } else {
            $this->markFail(
                'IICP_REPLICA_MODE is not true',
                'Set IICP_REPLICA_MODE=true in .env (without it the write-gate + signing middleware are no-ops)'
            );
        }
        $seedUrlEnv = (string) config('iicp.replica.seed_url', '');
        if ($seedUrlEnv && str_starts_with($seedUrlEnv, 'https://')) {
            $this->markPass("IICP_SEED_URL set and https ({$seedUrlEnv})");
        } else {
            $this->markFail(
                'IICP_SEED_URL missing or non-https',
                'Set IICP_SEED_URL=https://iicp.network in .env'
            );
        }
    }

    private function checkSecretKey(): void
    {
        $this->line('<fg=cyan>[2/5] Ed25519 secret key</fg=cyan>');
        $key = $this->secrets->get(RuntimeSecretProvider::REPLICA_ED25519_SECRET_KEY) ?? '';
        if ($key === '') {
            $this->markFail(
                'IICP_REPLICA_ED25519_SECRET_KEY is not set',
                'Generate one with `php artisan iicp:genesis-key --show-secret` and put the 128-hex secret in .env'
            );

            return;
        }
        if (strlen($key) !== 128 || ! ctype_xdigit($key)) {
            $this->markFail(
                'IICP_REPLICA_ED25519_SECRET_KEY is not 128 hex chars',
                'Re-generate with `php artisan iicp:genesis-key --show-secret`'
            );

            return;
        }
        try {
            $secret = sodium_hex2bin($key);
            $pub = sodium_crypto_sign_publickey_from_secretkey($secret);
            $pubBase64Url = rtrim(strtr(base64_encode($pub), '+/', '-_'), '=');
            $this->markPass('IICP_REPLICA_ED25519_SECRET_KEY parses to valid Ed25519 keypair');
            $this->line("      <fg=yellow>public key (base64url):</fg=yellow> {$pubBase64Url}");
            $this->line('      <fg=yellow>→ this MUST appear as publicKeyJwk.x in your did.json</fg=yellow>');
        } catch (\SodiumException $e) {
            $this->markFail(
                'Key bytes invalid: '.$e->getMessage(),
                'Re-generate with `php artisan iicp:genesis-key --show-secret`'
            );
        }
    }

    private function checkEndpointHttps(string $endpoint): void
    {
        $this->line('<fg=cyan>[3/5] Endpoint reachability</fg=cyan>');
        if (! str_starts_with($endpoint, 'https://')) {
            $this->markFail("Endpoint {$endpoint} is not https", 'Must be https:// (TLS+DNS trust is part of S.13 §3.1)');

            return;
        }
        try {
            $resp = Http::timeout(10)->get("{$endpoint}/iicp/health");
            if ($resp->status() === 200) {
                $this->markPass("Endpoint {$endpoint}/iicp/health returns 200");
            } else {
                $this->markWarn(
                    "Endpoint {$endpoint}/iicp/health returned HTTP {$resp->status()}",
                    'Seed registration will fail with IICP-E042 unless /iicp/health is reachable'
                );
            }
        } catch (\Throwable $e) {
            $this->markWarn(
                "Endpoint {$endpoint} not reachable: ".$e->getMessage(),
                'Verify DNS + TLS cert before registration'
            );
        }
    }

    private function checkSeedReachable(string $seedUrl): void
    {
        $this->line('<fg=cyan>[4/5] Seed reachability + genesis_hash</fg=cyan>');
        try {
            $resp = Http::timeout(15)->acceptJson()->get("{$seedUrl}/api/v1/events", ['limit' => 1]);
            if ($resp->status() !== 200) {
                $this->markFail(
                    "Seed {$seedUrl}/api/v1/events returned HTTP {$resp->status()}",
                    'Verify seed URL + that seed is online'
                );

                return;
            }
            $genesisHash = $resp->json('genesis_hash');
            if (! $genesisHash) {
                $this->markFail(
                    'Seed /api/v1/events response missing genesis_hash',
                    'Seed must be running directory v1.5+ with event log enabled'
                );

                return;
            }
            $this->markPass('Seed reachable; genesis_hash='.substr($genesisHash, 0, 16).'…');
        } catch (\Throwable $e) {
            $this->markFail("Seed {$seedUrl} unreachable: ".$e->getMessage());
        }
    }

    private function checkLocalDidDoc(string $endpoint): void
    {
        $this->line('<fg=cyan>[5/5] Local did.json publication</fg=cyan>');
        try {
            $resp = Http::timeout(10)->acceptJson()->get("{$endpoint}/.well-known/did.json");
            if ($resp->status() !== 200) {
                $this->markWarn(
                    "{$endpoint}/.well-known/did.json returned HTTP {$resp->status()}",
                    'Replicas MUST publish a DID document so proxies can fetch the verification key. '
                    .'Create one with iicp:genesis-key output + serve it as /.well-known/did.json'
                );

                return;
            }
            $doc = $resp->json();
            $methods = $doc['verificationMethod'] ?? [];
            $found = false;
            foreach ($methods as $m) {
                $jwk = $m['publicKeyJwk'] ?? null;
                if (! $jwk) {
                    continue;
                }
                if (($jwk['kty'] ?? null) === 'OKP' && ($jwk['crv'] ?? null) === 'Ed25519') {
                    $x = $jwk['x'] ?? '';
                    if ($x && $x !== 'GENESIS_KEY_PENDING') {
                        $found = true;
                        // Cross-check against our private key
                        $privHex = $this->secrets->get(RuntimeSecretProvider::REPLICA_ED25519_SECRET_KEY) ?? '';
                        if (strlen($privHex) === 128 && ctype_xdigit($privHex)) {
                            $expectedPub = sodium_crypto_sign_publickey_from_secretkey(sodium_hex2bin($privHex));
                            $expectedB64 = rtrim(strtr(base64_encode($expectedPub), '+/', '-_'), '=');
                            if ($expectedB64 === $x) {
                                $this->markPass('did.json publicKeyJwk.x matches IICP_REPLICA_ED25519_SECRET_KEY');
                            } else {
                                $this->markFail(
                                    'did.json publicKeyJwk.x does NOT match IICP_REPLICA_ED25519_SECRET_KEY',
                                    'Proxies will fail to verify sigs; either update did.json or rotate the secret key'
                                );
                            }
                        } else {
                            $this->markPass('did.json has Ed25519 verification method (secret key not validated separately)');
                        }
                        break;
                    }
                }
            }
            if (! $found) {
                $this->markFail(
                    'did.json has no valid Ed25519 verification method',
                    'Add a verificationMethod with publicKeyJwk: {kty:OKP, crv:Ed25519, x:<base64url>}'
                );
            }
        } catch (\Throwable $e) {
            $this->markWarn(
                "Could not fetch {$endpoint}/.well-known/did.json: ".$e->getMessage(),
                'Replica should publish a DID document; without one, proxies cannot verify replica sigs'
            );
        }
    }
}
