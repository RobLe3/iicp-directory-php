# Release and compatibility policy

## Source authority and versions

This public repository is the authoritative PHP directory source beginning
with release `v1.10.77.1`. Files under `parity/seed-manifest-*` remain immutable,
time-bounded evidence of the earlier extraction; they do not define current
source or require a private-tree mirror.

The project keeps separate namespaces:

- Git tag and `VERSION`: PHP implementation release (the tag is `v` plus the
  exact `VERSION` value);
- `openapi.yaml` `info.version`: machine-readable HTTP contract revision;
- IICP specification versions: protocol documents owned by `RobLe3/IICP`;
- database migrations: ordered schema history, not a separate release number;
- deployment version/build identity: operator-selected release plus the
  content-free source digest exposed by the runtime.

Implementation versions continue the established directory lineage. They do
not claim that every IICP profile or multi-root federation mode is stable.

## Immutability and verification

Release tags and attached artifacts are immutable. Never move a published tag
or replace an asset. A correction receives a newer version.

The tag workflow publishes a deterministic source archive, release manifest,
SHA-256 checksums and GitHub artifact attestation. Verify with:

```bash
sha256sum --check SHA256SUMS
version="$(cat VERSION)"
gh attestation verify "iicp-directory-php-v${version}.tar.gz" \
  --repo RobLe3/iicp-directory-php
```

## Compatibility

- PHP: 8.3.x is the supported runtime line for the current release. The release
  artifact and `VERSION` identify that release without duplicating it here.
- Database: MariaDB 11.4 is the concurrency-tested reference; compatible
  MySQL deployments require operator rehearsal.
- HTTP: additive fields and endpoints may appear in a minor release. Removing
  or changing documented behavior requires specification review, an OpenAPI
  revision and at least one release of deprecation notice.
- Schema: upgrades run every pending migration in order. Expand-first changes
  may remain after application rollback. Destructive rollback is never assumed.
- Pre-1.0 protocol profiles and explicitly experimental federation behavior may
  change, but security, accounting and signed-event invariants remain protected.

## Support and deprecation

Security fixes target the latest release. When feasible, the immediately
previous release receives critical fixes for 90 days after its successor.
Deprecations are announced in release notes and retained for at least one
implementation release unless continued behavior creates an active security
or integrity defect.

Production deployment, migration and rollback remain operator decisions. A
GitHub release does not authorize deployment to the Genesis Seed.
