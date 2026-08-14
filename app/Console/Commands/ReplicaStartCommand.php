<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use App\Models\Capability;
use App\Models\Node;
use App\Services\ReplicaEventApplier;
use App\Services\SeedDidResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Bring up this directory in replica mode.
 *
 * Phase: P6-1.3a skeleton — registers against a Genesis Seed and polls
 *        GET /api/v1/events for new entries, logging them. State-mirror
 *        application (write to local nodes / credits / replicas tables)
 *        is P6-1.3b and intentionally NOT performed here.
 *
 * Usage:
 *   php artisan iicp:replica-start \
 *     --seed-url=https://iicp.network \
 *     --did=did:web:replica.example.com \
 *     --endpoint=https://replica.example.com \
 *     [--state-file=replica-state.json] \
 *     [--poll-interval=30] \
 *     [--once] [--dry-run]
 *
 * Spec: iicp-federated-directory.md §5.3 (sync lifecycle), §7.1 (registration handshake).
 * Charter: P6-1.3 (Phase 6 Replica Mode); evidence anchor for P6-1.4 tests + P6-3.1 e2e.
 */
class ReplicaStartCommand extends Command
{
    protected $signature = 'iicp:replica-start '
        .'{--seed-url= : Genesis Seed base URL (required, e.g. https://iicp.network)} '
        .'{--did= : This replica\'s did:web identifier (required)} '
        .'{--endpoint= : This replica\'s public https endpoint (required)} '
        .'{--state-file=replica-state.json : Path inside storage/app for persisted state} '
        .'{--poll-interval=30 : Seconds between GET /api/v1/events polls} '
        .'{--once : Register + one poll, then exit (used by P6-1.4 tests)} '
        .'{--apply : Apply events to local state (P6-1.3b); default is log-only (P6-1.3a behaviour)} '
        .'{--no-register : Read-only event tail — skip the join handshake and snapshot, just '
        .'poll the public GET /api/v1/events log from seq 0 (matches the Rust replica\'s mirror '
        .'mechanism; FED-READY-1 existence parity without FED-READY-3 trusted registration). '
        .'--did/--endpoint not required in this mode.} '
        .'{--dry-run : Print intended actions; no network, no persistence}';

    protected $description = 'Bring up this directory in replica mode (Phase 6 — skeleton; state-mirror is P6-1.3b)';

    public function handle(): int
    {
        $seedUrl = rtrim((string) $this->option('seed-url'), '/');
        $did = (string) $this->option('did');
        $endpoint = (string) $this->option('endpoint');
        $stateFile = (string) $this->option('state-file');
        $pollInterval = max(5, (int) $this->option('poll-interval'));
        $once = (bool) $this->option('once');
        $apply = (bool) $this->option('apply');
        $noRegister = (bool) $this->option('no-register');
        $dryRun = (bool) $this->option('dry-run');

        // Read-only tail only needs the seed URL; the join handshake (did/endpoint) is skipped.
        $required = $noRegister ? ['seed-url' => $seedUrl] : ['seed-url' => $seedUrl, 'did' => $did, 'endpoint' => $endpoint];
        foreach ($required as $name => $val) {
            if ($val === '') {
                $this->error("Missing required option: --{$name}");

                return self::INVALID;
            }
        }
        // Validate the seed scheme always; the replica's own endpoint only when registering.
        if ($invalid = $this->validateUrlSchemes($seedUrl, $noRegister ? $seedUrl : $endpoint)) {
            return $invalid;
        }

        $this->line('<fg=cyan>━━━ iicp:replica-start ━━━</fg=cyan>');
        $this->line("  seed_url       = {$seedUrl}");
        $this->line("  did            = {$did}");
        $this->line("  endpoint       = {$endpoint}");
        $this->line("  state_file     = storage/app/{$stateFile}");
        $this->line("  poll_interval  = {$pollInterval}s");
        $this->line('  mode           = '.($dryRun ? 'DRY-RUN (no I/O)' : ($once ? 'ONCE' : 'CONTINUOUS'))
            .($apply ? ' [APPLY events to local state]' : ' [LOG-ONLY — pass --apply to mirror state]'));
        $this->newLine();

        if ($dryRun) {
            $this->line('<fg=yellow>DRY-RUN: would POST {did, endpoint} to '
                ."{$seedUrl}/api/v1/replicas/register, persist response, then poll "
                ."{$seedUrl}/api/v1/events?since_seq=N every {$pollInterval}s.</fg=yellow>");

            return self::SUCCESS;
        }

        if ($apply && $this->verificationRequired() && $this->resolveSeedVerifyKey() === null) {
            $this->error('Replica apply refused: no valid Ed25519 verification key is available from the seed DID document.');

            return self::FAILURE;
        }

        $state = $this->loadState($stateFile);
        if ($state === null && $noRegister) {
            // Read-only tail: no registration, no token — GET /api/v1/events is public.
            $this->line('<fg=green>→ Read-only tail (--no-register): mirroring the public event log from seq 0</fg=green>');
            $state = ['replica_id' => 'read-only-tail', 'replica_token' => '', 'since_seq' => 0, 'last_seq' => 0, 'genesis_hash' => null];
            $this->saveState($stateFile, $state);
        } elseif ($state === null) {
            $this->line('<fg=green>→ No prior state — registering against seed</fg=green>');
            $state = $this->register($seedUrl, $did, $endpoint);
            if ($state === null) {
                return self::FAILURE;
            }
            $this->saveState($stateFile, $state);
            $this->line('<fg=green>✓ Registered: replica_id='.$state['replica_id']
                .' since_seq='.$state['since_seq']
                .' genesis_hash='.substr((string) ($state['genesis_hash'] ?? ''), 0, 16).'…</fg=green>');

            // The current snapshot envelope is not signed. A production replica in
            // apply mode therefore catches up from the signed event stream instead of
            // mutating from an unauthenticated snapshot.
            $strictApply = $apply && $this->verificationRequired();
            if ($strictApply) {
                $state['since_seq'] = 0;
                $state['last_seq'] = 0;
                $this->saveState($stateFile, $state);
                $this->warn('Unsigned snapshot bootstrap skipped; applying the verified event chain from seq 0.');
                $bootstrap = null;
            } else {
                $this->line('<fg=green>→ Fetching snapshot for one-RTT bootstrap</fg=green>');
                $bootstrap = $this->bootstrapFromSnapshot($seedUrl, $state['replica_token'], $apply);
            }
            if ($bootstrap === null && ! $strictApply) {
                $this->warn('Snapshot fetch failed; will catch up via /api/v1/events from since_seq=0');
            } elseif ($bootstrap !== null) {
                $state['last_seq'] = (int) $bootstrap['snapshot_seq'];
                $this->saveState($stateFile, $state);
                $this->line('<fg=green>✓ Snapshot applied: snapshot_seq='.$bootstrap['snapshot_seq']
                    .' nodes='.$bootstrap['node_count']
                    .' capabilities='.$bootstrap['capability_count']
                    .($apply ? '' : ' [LOG-ONLY — pass --apply to mirror state]').'</fg=green>');
            }
            $this->newLine();
        } else {
            $this->line('<fg=green>→ Resuming from saved state: replica_id='.$state['replica_id']
                .' last_seq='.($state['last_seq'] ?? $state['since_seq']).'</fg=green>');
        }
        $this->newLine();

        do {
            $sinceSeq = (int) ($state['last_seq'] ?? $state['since_seq'] ?? 0);
            $events = $this->fetchEvents($seedUrl, $state['replica_token'], $sinceSeq);
            if ($events === null) {
                $this->warn("Poll failed; retry in {$pollInterval}s");
            } else {
                $lastSeq = $this->logEvents($events, $state['genesis_hash'] ?? null, $apply, $sinceSeq);
                if ($lastSeq > $sinceSeq) {
                    $state['last_seq'] = max($sinceSeq, $lastSeq);
                    $this->saveState($stateFile, $state);
                }
            }
            if ($once) {
                break;
            }
            sleep($pollInterval);
        } while (true);

        return self::SUCCESS;
    }

    /**
     * Validate seed-url and endpoint schemes. Production always requires
     * https://. Non-production environments with IICP_DEV_ALLOW_HTTP_DID=true
     * may use http:// — escape hatch for the federation testbed (P6-3.1b-ii).
     *
     * Returns null when valid, or self::INVALID when rejection has been logged.
     * Note: app.env is read via config() not app()->environment() so tests
     * can override the env at runtime.
     */
    private function validateUrlSchemes(string $seedUrl, string $endpoint): ?int
    {
        $allowHttp = config('iicp.replica.dev_allow_http_did', false)
            && config('app.env') !== 'production';

        $okSeed = str_starts_with($seedUrl, 'https://')
            || ($allowHttp && str_starts_with($seedUrl, 'http://'));
        $okEnd = str_starts_with($endpoint, 'https://')
            || ($allowHttp && str_starts_with($endpoint, 'http://'));

        if (! $okSeed) {
            $hint = $allowHttp ? ' (IICP_DEV_ALLOW_HTTP_DID allows http:// in non-production)' : '';
            $this->error("--seed-url must be https{$hint}");

            return self::INVALID;
        }
        if (! $okEnd) {
            $hint = $allowHttp ? ' (IICP_DEV_ALLOW_HTTP_DID allows http:// in non-production)' : '';
            $this->error("--endpoint must be https{$hint}");

            return self::INVALID;
        }
        if ($allowHttp && (str_starts_with($seedUrl, 'http://') || str_starts_with($endpoint, 'http://'))) {
            $this->warn('IICP_DEV_ALLOW_HTTP_DID=true — using http:// URLs (testbed only; production rejects this).');
        }

        return null;
    }

    private function register(string $seedUrl, string $did, string $endpoint): ?array
    {
        $resp = Http::timeout(30)->acceptJson()
            ->post("{$seedUrl}/api/v1/replicas/register", ['did' => $did, 'endpoint' => $endpoint]);
        if (! $resp->successful()) {
            $this->error("Registration failed: HTTP {$resp->status()} — {$resp->body()}");

            return null;
        }
        $body = $resp->json();
        foreach (['replica_id', 'replica_token', 'since_seq', 'genesis_hash'] as $required) {
            if (! array_key_exists($required, $body)) {
                $this->error("Seed response missing required field: {$required}");

                return null;
            }
        }

        return [
            'replica_id' => $body['replica_id'],
            'replica_token' => $body['replica_token'],
            'since_seq' => (int) $body['since_seq'],
            'genesis_hash' => $body['genesis_hash'],
            'seed_url' => $seedUrl,
            'did' => $did,
            'endpoint' => $endpoint,
            'last_seq' => (int) $body['since_seq'],
            'registered_at' => date('c'),
        ];
    }

    private function bootstrapFromSnapshot(string $seedUrl, string $token, bool $apply): ?array
    {
        $resp = Http::timeout(30)->withToken($token)->acceptJson()
            ->get("{$seedUrl}/api/v1/snapshot");
        if (! $resp->successful()) {
            $this->error("Snapshot fetch failed: HTTP {$resp->status()}");

            return null;
        }
        $body = $resp->json();
        $nodes = $body['nodes'] ?? [];
        $caps = $body['capabilities'] ?? [];

        if ($apply) {
            $applier = app(ReplicaEventApplier::class);
            foreach ($nodes as $n) {
                $applier->apply([
                    'event_id' => 'snapshot:'.($n['node_id'] ?? ''),
                    'event_type' => 'REGISTER',
                    'node_id' => $n['node_id'] ?? null,
                    'payload' => [
                        'endpoint' => $n['endpoint'] ?? null,
                        'region' => $n['region'] ?? null,
                        'cip_policy' => $n['cip_policy'] ?? null,
                        'pricing' => $n['pricing'] ?? null,
                    ],
                ]);
                if (isset($n['reputation_score'])) {
                    Node::where('id', $n['node_id'])->update(['reputation_score' => $n['reputation_score']]);
                }
                if (isset($n['credit_balance'])) {
                    Node::where('id', $n['node_id'])->update(['credit_balance' => $n['credit_balance']]);
                }
            }
            foreach ($caps as $c) {
                Capability::updateOrCreate(
                    ['node_id' => $c['node_id'] ?? null, 'intent' => $c['intent'] ?? null],
                    [
                        'models' => $c['models'] ?? [],
                        'max_tokens' => $c['max_tokens'] ?? 0,
                        'variant_id' => $c['variant_id'] ?? null,
                        'input_modalities' => $c['input_modalities'] ?? ['text'],
                        'output_modalities' => $c['output_modalities'] ?? null,
                        'features' => $c['features'] ?? null,
                        'execution_capabilities' => $c['execution_capabilities'] ?? null,
                        'capability_limits' => $c['limits'] ?? null,
                        'supported_profiles' => $c['supported_profiles'] ?? [],
                        'claim_provenance' => $c['claim_provenance'] ?? null,
                        'extensions' => $c['extensions'] ?? null,
                    ]
                );
            }
        }

        return [
            'snapshot_seq' => (int) ($body['snapshot_seq'] ?? 0),
            'node_count' => count($nodes),
            'capability_count' => count($caps),
        ];
    }

    private function fetchEvents(string $seedUrl, string $token, int $sinceSeq): ?array
    {
        // GET /api/v1/events is public — only attach the bearer when we actually have one
        // (read-only --no-register tail runs token-less).
        $req = Http::timeout(15)->acceptJson();
        if ($token !== '') {
            $req = $req->withToken($token);
        }
        $resp = $req->get("{$seedUrl}/api/v1/events", ['since_seq' => $sinceSeq, 'limit' => 100]);
        if (! $resp->successful()) {
            $body = $resp->json();
            if (($body['error']['code'] ?? null) === 'IICP-E045') {
                $this->warn('Seed returned IICP-E045 (snapshot_required) — replica fell behind rolling window. '
                    .'P6-1.3b will implement GET /api/v1/snapshot bootstrap; for now, restart with fresh state.');
            }

            return null;
        }

        return $resp->json();
    }

    private function logEvents(array $resp, ?string $expectedGenesisHash, bool $apply, int $lastAcceptedSeq): int
    {
        $events = $resp['events'] ?? [];
        $genesisHash = $resp['genesis_hash'] ?? null;
        if ($expectedGenesisHash && $genesisHash && $genesisHash !== $expectedGenesisHash) {
            $this->error("⚠ genesis_hash drift: expected {$expectedGenesisHash}, got {$genesisHash} — seed forked or replaced");
        }
        if (empty($events)) {
            $this->line('  · no new events');

            return $lastAcceptedSeq;
        }
        $applier = $apply ? app(ReplicaEventApplier::class) : null;
        $verifyKey = $apply ? $this->resolveSeedVerifyKey() : null;
        // #458: track the last applied signature so each next event's prev_hash can be
        // checked for chain continuity. Unknown at drain start (only seq is persisted), so
        // the first event skips the continuity check; its own signature is still verified.
        $prevSig = null;
        foreach ($events as $ev) {
            $seq = $ev['seq'] ?? '?';
            $type = $ev['event_type'] ?? '?';
            $tsMs = $ev['ts_ms'] ?? 0;
            $signer = $ev['signer_did'] ?? '?';
            $suffix = '[NOT APPLIED — pass --apply]';
            if ($applier) {
                $expectedPrev = $prevSig === null ? null : hash('sha256', $prevSig);
                $r = $applier->apply($ev, $verifyKey, $expectedPrev);
                $suffix = "[{$r['status']}: {$r['detail']}]";
                if (in_array(($r['status'] ?? ''), [
                    ReplicaEventApplier::RESULT_APPLIED,
                    ReplicaEventApplier::RESULT_SKIPPED,
                ], true)) {
                    $prevSig = $ev['sig'] ?? null;
                    $lastAcceptedSeq = max($lastAcceptedSeq, (int) ($ev['seq'] ?? 0));
                }
            } else {
                $lastAcceptedSeq = max($lastAcceptedSeq, (int) ($ev['seq'] ?? 0));
            }
            $this->line("  · seq={$seq} type={$type} ts_ms={$tsMs} signer={$signer} {$suffix}");
        }

        return $lastAcceptedSeq;
    }

    private ?string $cachedVerifyKey = null;

    private bool $verifyKeyAttempted = false;

    private function resolveSeedVerifyKey(): ?string
    {
        if ($this->verifyKeyAttempted) {
            return $this->cachedVerifyKey;
        }
        $this->verifyKeyAttempted = true;
        $seedUrl = (string) $this->option('seed-url');
        if ($seedUrl === '') {
            return null;
        }
        $this->cachedVerifyKey = app(SeedDidResolver::class)->publicKey(rtrim($seedUrl, '/'));
        if ($this->cachedVerifyKey === null) {
            $message = 'No Ed25519 verification key available from seed DID document.';
            if ($this->verificationRequired()) {
                $this->error($message.' Production apply mode will not mutate replica state.');
            } else {
                $this->warn($message.' Explicit non-production unsigned-event mode is active.');
            }
        }

        return $this->cachedVerifyKey;
    }

    private function verificationRequired(): bool
    {
        return config('app.env') === 'production'
            || ! config('iicp.replica.dev_allow_unsigned_events', false);
    }

    private function loadState(string $file): ?array
    {
        if (! Storage::exists($file)) {
            return null;
        }
        $raw = Storage::get($file);
        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : null;
    }

    private function saveState(string $file, array $state): void
    {
        Storage::put($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
