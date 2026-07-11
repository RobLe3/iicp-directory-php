# directory/ — IICP Control Plane (Genesis Seed)

**Plane**: Control (one of three; see [`project/CODE_TOUR.md`](../project/CODE_TOUR.md))
**Stack**: PHP 8.3 + Laravel 13 (ADR-004)
**ADRs**: [ADR-001](../project/decisions/ADR-001-three-plane-architecture.md), [ADR-003](../project/decisions/ADR-003-directory-central-authority.md), [ADR-004](../project/decisions/ADR-004-directory-php-laravel.md), [ADR-008](../project/decisions/ADR-008-node-scoring-formula.md), [ADR-009](../project/decisions/ADR-009-iicp-dir-as-subprotocol.md), [ADR-012](../project/decisions/ADR-012.md), [ADR-013](../project/decisions/ADR-013.md), [ADR-014](../project/decisions/ADR-014.md), [ADR-019](../project/decisions/ADR-019.md)

Reference implementation of the IICP directory service deployed at `https://iicp.network`.
Accepts registrations, validates, issues `node_token`, takes heartbeats, computes ranked
discovery, emits a federated-signed event log (Phase 6 prep). **Never sees task payloads**
(hard rule per ADR-003).

## Directory layout

```
app/
├── Http/
│   ├── Controllers/   REST API endpoints (21 controllers — see §API surface)
│   └── Middleware/    NodeTokenAuth, ProxyTokenAuth, ProbeTokenAuth, LoadRedirect
├── Models/            Eloquent ORM (14 models — see §Models)
├── Services/          Domain logic (14 services — see §Services)
└── Console/Commands/  Artisan commands (11 commands — see §Artisan commands)
config/                Laravel config (scoring weights, throttle limits, OTel)
database/migrations/   Schema evolution
tests/Feature/         PHPUnit feature tests (597)
routes/api_protocol.php  Protocol endpoints — /api/v1/*
routes/api_public.php    Public read-only endpoints — /api/v1/registry/*, /api/metrics
```

## Key files (read these first)

1. **`routes/api_protocol.php`** — every protocol endpoint, every middleware, every throttle.
2. **`app/Http/Controllers/RegisterController.php`** — canonical "how a node joins" flow (issues node_token + proxy_token + node_hmac_key).
3. **`app/Services/NodeScorer.php`** — ADR-008 / ADR-012 scoring formula. Phase 5 weights kick in per-request when `?model=` is present.
4. **`app/Services/NodeEventLogger.php`** — Ed25519-signed append-only event log; the Phase 6 federated-control-plane primitive (ADR-013 §3.4).
5. **`app/Http/Middleware/LoadRedirect.php`** — Phase 6 prep: 307-redirect when `IICP_REPLICA_URLS` is set; safe no-op otherwise.

## API surface

See [`project/ARCHITECTURE.md`](../project/ARCHITECTURE.md) §API table for normative reference.

### Protocol endpoints (`routes/api_protocol.php`)

| Method | Path | Controller | Auth |
|--------|------|-----------|------|
| POST | `/v1/register` | RegisterController | none — issues node_token + proxy_token + node_hmac_key; accepts `input_modalities`, `reachability_tier`, and `operator_delegation` (ADR-045) |
| DELETE | `/v1/register` | DeregisterController | NodeTokenAuth |
| POST | `/v1/heartbeat` | HeartbeatController | NodeTokenAuth — optional `challenge_response` HMAC liveness (ADR-047, #411); response carries the next challenge nonce |
| GET | `/v1/discover` | DiscoverController | none + LoadRedirect; supports `?modality=text\|image\|audio\|video` filter (ADR-046, spec iicp-dir v0.10.0) |
| GET | `/v1/node/{id}` | NodeController | none + LoadRedirect |
| GET | `/v1/bootstrap` | BootstrapController | none + LoadRedirect (Phase 2 seed peer list) |
| POST | `/v1/peers` | PeersController | NodeTokenAuth (HMAC-SHA256, Phase 2 peer exchange) |
| GET | `/v1/me` | MeController | NodeTokenAuth |
| GET | `/v1/credits/balance` | CreditsController | NodeTokenAuth |
| GET | `/v1/credits/transactions` | CreditsController | NodeTokenAuth |
| GET | `/v1/credits/quote` | CreditsController | NodeTokenAuth (pre-flight §8 CIP) |
| POST | `/v1/credits/award` | CreditsController | NodeTokenAuth + HMAC-SHA256 receipt |
| POST | `/v1/telemetry/probe` | TelemetryController | ProbeTokenAuth (REACH daemon) |
| POST | `/v1/telemetry` | TelemetryController | ProxyTokenAuth (Sybil-gated EMA, #114) |
| POST | `/v1/audit-report` | AuditReportController | NodeTokenAuth (#118 Part D) |
| GET | `/v1/probe` | ProbeController | none (SSRF-guarded) |
| GET | `/v1/events` | EventsController | none (Phase 6 event log read, #13) |
| GET | `/v1/compliance-attestation` | ComplianceAttestationController | none (IICP-DIR-EXT-ATTEST signed snapshot, #508) |
| GET | `/v1/stats` | StatsController | none |

### Public endpoints (`routes/api_public.php`)

| Method | Path | Controller | Notes |
|--------|------|-----------|-------|
| GET | `/v1/registry/nodes` | RegistryController | Filter by intent/region/cip_enabled, paginated |
| GET | `/v1/registry/nodes/{prefix}` | RegistryController | Public profile by 8-char prefix |
| GET | `/v1/registry/intents` | RegistryController | Intent URNs with live node count |
| GET | `/v1/registry/stats` | RegistryController | Registry summary stats |
| GET | `/v1/conformance/submit` | ConformanceController | Submit conformance test results |
| GET | `/v1/conformance/verify` | ConformanceController | Verify badge validity |
| GET | `/v1/conformance/badges` | ConformanceController | List issued badges |
| GET | `/v1/badge/{tier}` | BadgeController | Shields.io-compatible conformance badge SVG |
| POST | `/v1/replicas/register` | ReplicasController | Replica registration handshake (Phase 6 federation, S.13) |
| GET | `/v1/snapshot` | SnapshotController | Snapshot + event-tail bootstrap for replicas (Phase 6, S.13) |
| GET | `/api/metrics` | MetricsController | Prometheus text format (60s cache, 30 req/min) |

## Services

| Service | Purpose |
|---------|---------|
| `NodeScorer` | ADR-008 / ADR-012 scoring formula — computes ranked discovery results; Phase 5 weights on `?model=` |
| `NodeRegistry` | Registration lifecycle — validates, persists, re-activates nodes; issues all three credentials |
| `NodeEventLogger` | Ed25519-signed append-only event log emitter (Phase 6 prereq, ADR-013 §3.4) |
| `ReputationService` | Delta-based reputation scoring (spec §11.2); EMA update on heartbeat |
| `CreditService` | Credit ledger for CIP billing (ADR-019); balance, award (with ceiling + nonce), rate limit |
| `JwtService` | Minimal HS256 JWT issuer/verifier for `node_token` (ADR-006); hand-rolled to avoid library overhead |
| `OtelTracer` | Lightweight W3C traceparent + OTLP/JSON emitter (ADR-014); no-op when `OTEL_EXPORTER_OTLP_ENDPOINT` absent |
| `ConformanceBadgeValidator` | Badge submission gate — BADGE-01..BADGE-05 conformance checks |
| `LivenessMonitor` | Marks nodes dormant after 90s heartbeat silence (3× the 30s heartbeat cadence) |
| `NodeAddressObserver` | Observed-IP recording and address change detection — DIR-ADDR-01..DIR-ADDR-06 (ADR-011) |

## Models

| Model | Purpose |
|-------|---------|
| `Node` | Core node record — id, endpoint, region, status, reputation, limits, last_seen |
| `Capability` | Per-node intent + model + engine + quantization combination |
| `Reputation` | EMA reputation score (0–1), decay-eligible, tier classification |
| `NodeEvent` | Immutable event log row — event_type, payload, Ed25519 signature (Phase 6) |
| `CreditTransaction` | Ledger row — amount, reason, task_id, nonce; append-only |
| `Credit` | Credit balance aggregate for a node |
| `ConformanceBadge` | Issued badge record — tier, signed hash, issued_at |
| `ProxyTelemetry` | One-per-(proxy, node, 60s-bucket) proxy-observed latency row |
| `TelemetryProbe` | REACH daemon probe result — endpoint, latency, status, timestamp |
| `AvailabilityWindow` | Scheduled availability window (day-of-week, from-to UTC) |
| `ProbeToken` | REACH probe token record (distinct from node_token) |
| `User` | Laravel default — unused in production (directory has no user accounts) |

## Middleware

| Middleware | Auth scheme | Used by |
|-----------|------------|---------|
| `NodeTokenAuth` | `Authorization: Bearer <node_token>` | Heartbeat, deregister, credits, peers, me, audit |
| `ProxyTokenAuth` | `Authorization: Bearer <proxy_token>` | `POST /v1/telemetry` (Sybil-gated EMA, #114) |
| `ProbeTokenAuth` | `Authorization: Bearer <probe_token>` | `POST /v1/telemetry/probe` (REACH daemon) |
| `LoadRedirect` | — | Discover, node/{id}, bootstrap — 307 redirect if `IICP_REPLICA_URLS` set |

## Artisan commands

```bash
# Normal test run
php artisan test                                    # 307 tests (PHPUnit)
php artisan test --filter RegisterTest              # one feature
php artisan test --compact                          # summary line only

# Operational commands
php artisan iicp:genesis-key                        # provision Ed25519 genesis signing key
php artisan iicp:issue-probe-token                  # issue a REACH daemon probe_token
php artisan iicp:node-lifecycle                     # run liveness expiry sweep (also scheduled)
php artisan iicp:reputation-decay                   # apply reputation decay (also scheduled)
php artisan iicp:probe-nodes                        # TCP-probe each registered node (also scheduled every 5 min, #373 Phase B)
php artisan iicp:db-maintenance-status --json       # metadata-only DB growth/retention report
php artisan iicp:prune-telemetry --dry-run          # preview bounded telemetry pruning
```

The scheduled commands run via `routes/console.php` and `php artisan schedule:run` (every minute from cron).
`iicp:probe-nodes` fires every 5 minutes — records DIR-PROBE-NODE-01 results in `iicp_telemetry_probes` so
`NodeHealthService::reachabilityScore()` can use independently observed signals instead of self-attested `public_reachable`.
DB hygiene is intentionally retention-based, not table-drop based: raw probe rows default to a 14-day hot window,
probe aggregates default to 30 days, proxy telemetry defaults to 30 days, and heartbeat events default to 1 day.
Credits, reputation, node/operator records and signed events are not pruned by `iicp:prune-telemetry`.
Production directory deploys should create SSH DB backups before and after any migration/pruning run via
`deploy/scripts/backup_directory_db_via_ssh.sh`; the helper defaults to `$HOME/iicp_directory_db_backups`
on the SSH host, outside the public web root.

## Environment variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `APP_KEY` | — (required) | Laravel encryption key |
| `APP_ENV` | `production` | Laravel environment. Setting to `local` or `testing` activates the `RoutableEndpoint` validator dev bypass (commit `4ff89a9` / iter-1365, ADR-041), allowing `localhost`/Docker-internal endpoints to register. See [`docs/local-directory-setup.md`](../docs/local-directory-setup.md) for local-dev recipe. **Never set to `local` in production.** |
| `DB_CONNECTION` | `mysql` | Database driver |
| `IICP_GENESIS_PUBLIC_KEY` | — | Ed25519 pubkey for event log verification |
| `IICP_GENESIS_PRIVATE_KEY` | — | Ed25519 privkey for event log signing (keep secret) |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — | OTLP endpoint; if absent, OTel is a no-op |
| `OTEL_SERVICE_NAME` | `iicp-directory` | OTLP service name |
| `IICP_REPLICA_URLS` | — | Comma-separated replica URLs; if set, LoadRedirect 307s under load |
| `IICP_LOAD_THRESHOLD` | `0.85` | CPU fraction above which LoadRedirect activates |

## Testing

```bash
cd directory
php artisan test                    # all 307 feature tests (~2 min, SQLite in-memory)
php artisan test --compact          # one-line summary
```

Tests in `tests/Feature/` are integration-style: SQLite in-memory, real HTTP stack, `Http::fake()` for outbound calls. No mocks of internal services.

## Production deployment

See `../deploy/scripts/build_directory_release.sh` + `../deploy/scripts/deploy_directory.sh`.
Current live version: check `https://iicp.network/api/v1/stats`.

**Critical**: deploy scripts are the operator's concern; **do not modify in feature PRs**
(per `CLAUDE.md` deploy/ off-limits rule).

## Cross-references

- **Spec**: `../spec/iicp-dir.md` (IICP-DIR sub-protocol, ADR-009), `../spec/iicp-core.md` (envelope, errors)
- **Phase 5 CIP**: `../spec/iicp-cooperative-inference.md` (ADR-012 scoring extension, credit system ADR-019)
- **Phase 6 federation**: `../spec/iicp-federated-directory.md` (ADR-013 Genesis Seed event log)
- **Threat model**: `../project/security/THREAT_MODEL.md`
- **Loops architecture**: `../LOOPS.md` (project quality discipline)
