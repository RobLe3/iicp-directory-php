<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generate a new Ed25519 genesis key pair for event log signing.
 *
 * Usage:
 *   php artisan iicp:genesis-key
 *
 * Output:
 *   - Secret key (128 hex chars): set as IICP_GENESIS_ED25519_SECRET_KEY in .env
 *   - Public key (base64url):     update "x" field in website/public/.well-known/did.json
 *
 * Spec: spec/iicp-federated-directory.md §3.4 + §4 (Genesis Seed DID Document)
 */
class GenesisKeyCommand extends Command
{
    protected $signature = 'iicp:genesis-key {--show-secret : Print secret key to stdout (handle with care)}';

    protected $description = 'Generate Ed25519 genesis key pair for event log signing (Phase 6)';

    public function handle(): int
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $secretHex = bin2hex($secretKey);
        $publicBase64 = rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '=');

        $this->newLine();
        $this->line('<fg=cyan>═══════════════════════════════════════════════</fg=cyan>');
        $this->line('<fg=cyan>  IICP Genesis Key Pair</fg=cyan>');
        $this->line('<fg=cyan>═══════════════════════════════════════════════</fg=cyan>');
        $this->newLine();

        $this->line('<fg=green>✓ Public key (base64url-encoded, 32 bytes):</fg=green>');
        $this->line("  {$publicBase64}");
        $this->newLine();
        $this->line('<fg=yellow>→ Add to website/public/.well-known/did.json:</fg=yellow>');
        $this->line('  "publicKeyJwk": { "kty": "OKP", "crv": "Ed25519", "x": "'.$publicBase64.'" }');
        $this->newLine();

        if ($this->option('show-secret')) {
            $this->line('<fg=red>⚠ Secret key (128 hex chars — KEEP PRIVATE):</fg=red>');
            $this->line("  {$secretHex}");
            $this->newLine();
        } else {
            $this->line('<fg=yellow>→ Secret key: run with --show-secret to display</fg=yellow>');
            $this->line('  (then set IICP_GENESIS_ED25519_SECRET_KEY=<secret> in .env)');
            $this->newLine();
        }

        $this->line('<fg=green>After provisioning:</fg=green>');
        $this->line('  1. Deploy updated did.json to website (ship.sh)');
        $this->line('  2. Set IICP_GENESIS_ED25519_SECRET_KEY in production .env');
        $this->line('  3. Clear Laravel config cache: php artisan config:cache');
        $this->line('  4. New events will be signed; old sig=null events remain valid');
        $this->newLine();

        return self::SUCCESS;
    }
}
