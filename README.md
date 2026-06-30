# iicp-directory-php

Dedicated PHP/Laravel reference implementation of the IICP directory control plane.

This repository is the standalone mirror of the production PHP directory that currently runs `https://iicp.network/api/v1/*`. It accepts node registrations, validates reachability declarations, issues node credentials, receives heartbeats, scores discovery results, tracks credits/reputation, exposes registry views, signs federation events and prepares relay/federation workflows.

## Current snapshot

Refreshed from the main `directory/` implementation on **2026-06-30**.

| Item | Current value |
|---|---:|
| Directory version | `v1.10.55` |
| `/api/v1` routes | 35 |
| Migration files | 61 |
| Test files | 79 |
| Full test run | `php artisan test --compact --no-coverage` → 826 tests, 822 passed, 4 skipped |

## Important guarantees

- The directory is the **control plane**. Task payloads should flow node-to-node, not through the directory.
- Discovery exposes routing, privacy, quality and upgrade signals, not endpoint secrets beyond the serving URL returned to eligible clients.
- `cx_public_key` is the current canonical CX encryption key field; `public_key` remains as a compatibility alias during the unification window.
- Privacy claims must stay conservative: remote providers can read work they execute; metadata privacy and full fail-closed migration are still evolving.

## API surface

Use Laravel's route list as the source of truth:

```bash
php artisan route:list --path=api/v1 --except-vendor --no-ansi
```

Current protocol areas:

- Node lifecycle: register, deregister, heartbeat, peers, self-view.
- Discovery and detail: discover, bootstrap, node detail, public registry and stats.
- Privacy and relay preparation: directory key, consumer token, relay bind ticket.
- Credits and reputation: balance, summary, quote, transactions, award, audit reports.
- Conformance and evidence: badges, verification, probe upload, compliance attestation.
- Federation preparation: events, snapshot, replica registration.
- Operator/community support: operator rename, leaderboards.

## Install locally

```bash
composer install
cp .env.example .env
php artisan key:generate
```

For tests, the configured PHPUnit environment uses SQLite/in-memory behavior where possible.

## Test

```bash
php artisan test --filter=StatsTest --no-coverage
php artisan test --compact --no-coverage
php artisan route:list --path=api/v1 --except-vendor --no-ansi
```

Run the full suite before a release branch or production deployment.

## Relationship to the Rust directory

`iicp-directory-rs` is the typed replacement candidate. The Rust implementation now has `/api/v1` route aliases and live discover-field compatibility, but PHP remains the production seed until REACH/live conformance, deployment behavior and rollback evidence are strong enough for an explicit maintainer cutover.

## Generated files

`vendor/`, `.env`, runtime storage and generated cache files are local artifacts. `bootstrap/cache/.gitignore` is kept so Composer/Laravel can create a writable cache directory without committing generated cache contents.
