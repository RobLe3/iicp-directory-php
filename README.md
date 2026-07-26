# IICP Directory — PHP reference implementation

Laravel reference implementation of the IICP discovery and control plane.
The directory registers providers, receives heartbeats, publishes capability
and health evidence, selects eligible routes, and issues control-plane tokens
and receipts. Task payloads are sent directly between consumers and selected
providers and must not pass through the directory.

## Maturity

This is active pre-1.0 software and the implementation used by the current
IICP Genesis Seed. Public source availability does not imply that independent
multi-root federation, every optional profile, or production operation without
review is complete.

The protocol is defined in the public [IICP specification](https://github.com/RobLe3/IICP).

## Local setup

Requirements: PHP 8.3 or newer, Composer, and the SQLite extensions used by
the test suite.

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

Use a generated application key and dedicated database credentials. Never copy
credentials from the public Genesis Seed.

## Verification

```bash
composer install --no-interaction --prefer-dist
php artisan test --compact
python3 scripts/check_seed_parity.py \
  --manifest parity/seed-manifest-v1.10.76.2.json \
  --php-dir . \
  --git-revision c489d4e02a636b337ba4237f8543f83675162db0
```

The versioned parity manifests identify and preserve the reviewed extraction
snapshot at the pinned public Git revision. Current development is authoritative
in this public repository; it is not required to remain a live mirror of a
private tree. The manifests do not certify live operational equivalence on
another host.

### Version namespaces

The repository uses separate version namespaces that should not be compared as
one release sequence:

- `openapi: 3.1.0` selects the OpenAPI format;
- `info.version: 1.6.0` versions the documented OpenAPI contract;
- runtime `v1.10.76` identifies the application baseline; and
- parity manifest `v1.10.76.2` identifies the second evidence snapshot for
  that runtime baseline.

## Major protocol surfaces

- provider registration, deregistration and heartbeat;
- intent, model and policy-aware discovery;
- public node, intent and mesh statistics;
- dispatch tickets and consumer tokens;
- credits, reputation and signed receipts;
- signed lifecycle events and snapshot preparation;
- operator identity, DSR and policy-manifest lifecycle;
- telemetry, health evidence and conformance badges.

See `routes/api_protocol.php`, `routes/api_public.php`, and `openapi.yaml` for
the implementation surface. The IICP specification remains authoritative when
documentation and code disagree.

## Configuration and operations

- `.env.example` contains placeholders and safe defaults only.
- `APP_ENV=local` or `testing` permits local endpoints for development;
  production mode rejects private and loopback provider routes.
- Signing keys, database passwords and deployment configuration must remain
  outside the repository.
- Back up a self-hosted database before and after migrations or maintenance.

See `OPERATIONS.md` for generic self-host backup, migration, retention and
restore guidance.

## Repository boundary

This repository owns the PHP directory implementation. It does not own the
protocol specification, client SDKs, website, production topology, production
credentials, or the deployment of `iicp.network`.

See `SECURITY.md`, `CONTRIBUTING.md`, and `PUBLICATION_READINESS.md` before
operating or contributing.
