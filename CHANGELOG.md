# Changelog

## Unreleased

## v1.10.90 — 2026-08-10

- Bounded the public 24-hour stats aggregate lookup to the newest deterministic
  row per metric in SQL instead of hydrating the complete history in PHP.
- Added regression coverage for long histories, tied newest timestamps and
  later-inserted older backfills.
- No database migration, OpenAPI, route, task-data-plane or cache-key change is
  included.

## v1.10.89 — 2026-08-08

- Made each reputation score, task-counter and hourly positive-gain update one
  node-locked database transaction. Concurrent heartbeats now share the same
  persisted `+0.20` hourly budget.
- Corrected expired-window persistence so the first positive update in a new
  hour stores the reset budget before applying its delta.
- Added real-MariaDB concurrency evidence plus restart, 3599/3600-second
  boundary and negative-delta regression cases.
- No database migration, OpenAPI, route or task-data-plane change is included.

## v1.10.88 — 2026-08-08

- Updated Laravel to 13.24.0 and `league/commonmark` to 2.9.0, resolving the
  reviewed CommonMark denial-of-service and unsafe-link advisories.
- No database migration, OpenAPI, wire, ranking, routing or conformance
  behavior changed.

## v1.10.87 — 2026-08-05

- Updated the runtime Guzzle dependency to 7.15.2 to reject noncanonical URI
  hosts and cookie domains covered by GHSA-v5mv-p594-2x33 and
  GHSA-f7vp-7xgx-4w4r.
- Preserved the existing endpoint-verification, DNS pinning, API, schema,
  OpenAPI and wire contracts. No database migration is included.

## v1.10.86 — 2026-08-02

- Added separate provider implementation name/version and SDK compatibility
  version axes, with a legacy `sdk_version` alias and fail-closed conflict
  validation.
- Advanced the additive OpenAPI projection to 1.7.0 and kept SDK readiness,
  adoption and E050 decisions on the effective compatibility version.

## v1.10.85 — 2026-08-01

- Advanced SDK release-currency evidence to the coordinated `0.7.101` release.
- Preserved compatibility floors, ranking, eligibility, routes and database schema.
- Added an immutable v1.10.85 parity manifest while retaining the v1.10.84 fixture archive.

## v1.10.84 — 2026-08-01

- Added content-free explanations for health dimensions, Gold threshold state,
  latency basis, SDK compatibility versus release currency, and aggregate
  verified-operator diversity.
- Kept ranking, eligibility, route exposure and failure-domain claims unchanged.

## v1.10.83 — 2026-07-31

- Added authenticated, atomic replica decommissioning with immediate bearer
  invalidation and a signed `REPLICA_DEREGISTERED` lifecycle event.
- Made persistent replica lifecycle and expiry state part of every replica-auth
  decision.
- Made same-DID re-registration reactivate at low trust with a rotated bearer.
- Added versioned PHP/Rust HTTP and lifecycle contract fixtures.
- No schema migration or task-data-plane behavior change is included.

## v1.10.82 — 2026-07-29

- Added a fail-closed, signed `/.well-known/iicp-deployment.json` record that
  binds the running PHP directory to its release tag, source revision, artifact
  digest and protocol compatibility range.
- Added a shared PHP/Rust signature fixture with tamper, purpose, key-rotation
  and freshness-policy tests.
- Documented PHP as the current Genesis implementation and Rust as the public
  pre-1.0 operator preview.
- Reconciled replica-token scope with the protected snapshot endpoint. Newly
  issued tokens use `GET /v1/snapshot`; the previous events scope remains
  accepted for one compatibility window while the signed event tail stays
  public.
- No schema migration or task-data-plane behavior change is included.

## v1.10.81.2 — 2026-07-29

Corrective release after the `v1.10.81.1` tag was accidentally pushed at the
previous main commit before PR #57 merged. The failed tag remains immutable and
must not be deployed; it contains runtime v1.10.80.1 and has no release assets.

- Restored TLS certificate verification for registration dial-back and lifecycle
  probes. Insecure TLS is confined to an explicit non-production testbed flag.
- Made federation verification fail closed in production: replicas wait for the
  seed key, reject unsigned events and do not advance cursors past rejected
  records. Strict replicas reconstruct state from the verified event chain
  rather than applying an unsigned snapshot.
- Added regression tests proving production cannot enable either development
  bypass and that rejected federation input cannot mutate directory state.
- No schema migration or task-data-plane behavior change is included.

## v1.10.80.1 — 2026-07-26

- Made registry and replica non-secret settings safe under Laravel
  configuration caching while preserving established environment names and
  defaults.
- Added an allowlisted runtime-only secret provider for replica signing,
  conformance badges and the development DID document so those values remain
  outside the serialized configuration cache.
- Continued characterization-first decomposition with capability-evidence,
  availability-window, SDK/CX readiness and node-pricing policies.
- Added isolated mutation gates for the extracted policies; readiness measured
  80.43% MSI and pricing measured 81.25% MSI against 60% floors.
- No schema, public protocol behavior, production database, deployment or
  unpublished website change is authorized by this release.

## v1.10.79.1 — 2026-07-26

- Removed avoidable Eloquent model hydration from the selected-node lifecycle
  scoring read after an isolated fresh-restore benchmark passed every fixed
  semantic and latency gate.
- Added pinned Larastan/PHPStan, Pint and repository-local Semgrep security
  gates with accountable baseline and suppression policies.
- Added a measured 90% application coverage floor, an 80% changed-code floor
  and scheduled/manual mutation ratchets for four high-risk domains.
- Began characterization-first `NodeScorer` decomposition by extracting the
  deterministic endpoint address-family classifier without projection drift.
- No schema, public protocol behavior, production database, deployment or
  unpublished website change is authorized by this release.

## v1.10.78.1 — 2026-07-26

- Added separate hardened, non-root PHP-FPM and nginx operator images.
- Added explicit one-shot migrations, fixed content-free readiness, disposable
  failure/recovery and backup/restore rehearsal.
- Added HIGH/CRITICAL container security gates and clean-build content
  reproducibility checks for both hardened images.
- Added a deterministic, content-free 100-node reference capacity profile.
- No production deployment, production database, public website or protocol
  behavior change is authorized by this release.

## v1.10.77.1 — 2026-07-26

Corrective first publication after the v1.10.77 tag workflow stopped before producing any release assets. The v1.10.77 tag is retained as failed provenance and must not be moved.

## v1.10.77 — 2026-07-26 (unpublished candidate)

- Established the standalone public repository as release source authority.
- Serialized signed lifecycle-event appends with a durable chain head.
- Centralized strict node and replica JWT profiles and corrected encoded
  application-key handling.
- Reconciled the OpenAPI projection with runtime behavior and added route,
  middleware and schema-reference drift checks.
- Serialized credit-ledger mutations and added real MariaDB concurrency
  evidence for debit, award, free allocation and operator-wallet contention.
- Updated locked Laravel, Sanctum and GitHub Actions dependencies.

No production deployment or website publication is part of this release.
