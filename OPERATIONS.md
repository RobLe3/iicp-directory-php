# Self-host operations

These instructions are implementation-neutral examples. Adapt paths, retention
and access controls to the operator's environment.

## Deployment profiles

- `Dockerfile` and `docker-testbed-entrypoint.sh` are development/testbed
  conveniences. They generate local state and may apply migrations.
- `Dockerfile.operator` plus `Dockerfile.operator-nginx` are the hardened
  operator images. They use pinned multi-stage inputs, run as non-root users,
  do not contain default credentials, do not generate `APP_KEY`, and never
  apply migrations from the web entrypoint.

The operator image accepts `APP_KEY_FILE` and `DB_PASSWORD_FILE`; optional
secret-file inputs are limited to the names explicitly loaded by
`operator/entrypoint.sh`. Provide `APP_ENV=production`, `APP_DEBUG=false`,
`APP_URL`, `DB_HOST`, `DB_DATABASE`, and `DB_USERNAME` separately. Do not put
secret values in an image layer, Compose file, command line, or Git.

### Disposable operator stack

`compose.operator.yml` separates MariaDB, PHP-FPM, nginx, the scheduler, and
the explicit one-shot migration service. Start or upgrade only in this order:

```bash
docker compose -f compose.operator.yml up -d db
docker compose -f compose.operator.yml --profile tools run --rm migrate
docker compose -f compose.operator.yml up -d app scheduler web
```

Secret paths and non-secret settings are required inputs; `operator/example.env`
documents only the non-secret names. The web service binds to loopback by
default. Put a reviewed TLS reverse proxy in front of it for remote access.

Run `scripts/rehearse_operator_stack.sh` to create and destroy a fully
disposable stack. It verifies clean migration, fixed liveness/readiness,
fail-closed candidate configuration, database failure/recovery, backup,
restore, and restored migration status. The generated report and SQL backup
remain in a private temporary directory with `--keep` or after a failed rehearsal;
never commit them. Read the printed content-free closure receipt before cleanup.
Set `IICP_OPERATOR_REHEARSAL_OUTPUT` to copy only the content-free JSON result
to a chosen private path before cleanup.

For a real immutable previous-to-next check, set `PREVIOUS_TAG` and `NEXT_TAG`
to reviewed immutable release tags, then run:

```bash
IICP_OPERATOR_UPGRADE_OUTPUT=/private/path/result.json \
  scripts/rehearse_operator_upgrade.sh \
  --previous-tag "$PREVIOUS_TAG" --next-tag "$NEXT_TAG"
```

This builds both tags in detached worktrees, upgrades through the explicit
one-shot migration, restores the pre-upgrade database while rolling the
application images back, verifies the previous migration status, then moves
forward again. It is disposable and does not authorize production adoption.

### Prebuilt upgrade inputs

For a packaged rehearsal, use the additive prebuilt mode instead of the tag/build
mode. Prepare both app and nginx images before the run. The rehearsal performs
no builds or pulls; the two Compose dependency images must also be loaded.
A Docker Compose version supporting the [build-reset override](https://docs.docker.com/reference/compose-file/merge/#reset-value) is required. Both models are parsed before startup. The daemon
must be native Linux amd64 or arm64 matching all images; emulated images are not
admitted by this mode.

Supply a reviewed JSON manifest with exactly these fields (replace the symbolic
values with actual lowercase hashes and versions):

```json
{
  "schema": "iicp.directory.operator-prebuilt-upgrade.v1",
  "platform": "linux/amd64",
  "compose_sha256": "SHA256_OF_COMPOSE_OPERATOR_YML",
  "previous": {
    "source_commit": "PREVIOUS_40_HEX_COMMIT",
    "version": "1.10.93",
    "archive_sha256": "PREVIOUS_RELEASE_ARCHIVE_SHA256",
    "app_image": "sha256:PREVIOUS_APP_IMAGE_ID",
    "web_image": "sha256:PREVIOUS_NGINX_IMAGE_ID"
  },
  "next": {
    "source_commit": "CANDIDATE_40_HEX_COMMIT",
    "version": "1.10.94",
    "archive_sha256": "CANDIDATE_RELEASE_ARCHIVE_SHA256",
    "app_image": "sha256:CANDIDATE_APP_IMAGE_ID",
    "web_image": "sha256:CANDIDATE_NGINX_IMAGE_ID"
  }
}
```

Both images for each version must carry matching build-time labels:
`org.opencontainers.image.revision`, `org.opencontainers.image.version`, and
`network.iicp.release-archive-sha256`. Use the exact source archive as build input
and record the image IDs from that build. These labels are provenance assertions,
not independent proof of a build: approve the manifest from reviewed build
receipts, never infer source provenance from an arbitrary image's labels.
The manifest and its externally pinned SHA-256 are inputs, not generated trust.
This command does not build images or create a candidate artifact fragment.

```sh
scripts/rehearse_operator_upgrade.sh \
  --prebuilt-manifest /private/rehearsal/inputs.json \
  --manifest-sha256 "$REVIEWED_MANIFEST_SHA256" \
  --previous-source "$REVIEWED_PREVIOUS_COMMIT" \
  --next-source "$REVIEWED_CANDIDATE_COMMIT"
```

The source pins are mandatory and independent of the manifest. Tag arguments
cannot be mixed with prebuilt arguments. Validation checks the manifest size,
file type, digest, exact schema, Compose digest, native platform, loaded image
IDs and provenance labels before creating the rehearsal workspace. Symlink
inputs are refused. Both complete Compose models must parse before startup.
Missing images cannot trigger a source build or a network pull.

The existing eight upgrade/rollback assertions and runtime VERSION checks still
run. A content-free `prebuilt-inputs.json` sidecar is retained with the attempt
and closure evidence, including on failure. Images are caller-owned inputs and
are not deleted. The normal cleanup still removes only this run's containers,
volumes, network and successful workspace. This is a project rehearsal with zero
qualification credit, not authorization to deploy or proof of representative
onboarding. Interrupted-upgrade injection and the cross-client matrix remain
separate qualification work.

## Verify a public source release

Before preparing an operator artifact, verify both checksum and provenance:

```bash
sha256sum --check SHA256SUMS
version="$(cat VERSION)"
gh attestation verify "iicp-directory-php-v${version}.tar.gz" \
  --repo RobLe3/iicp-directory-php
```

The verified public archive is the source input. A retained private-hub copy is
not release authority. Verification does not authorize production deployment.

Shared-hosting operators should materialize the runtime artifact through
`scripts/materialize_shared_hosting_runtime.sh`. Its reviewed allowlist keeps
application code, migrations, public assets, production Composer manifests and
the writable Laravel directory skeleton while excluding tests, reports,
repository metadata and development/operator tooling. Validate the layout with
`scripts/test_shared_hosting_runtime_artifact.sh` before adding production
dependencies or environment configuration.

## Backup before a migration

```bash
umask 077
mkdir -p backups
mysqldump --single-transaction --routines --triggers \
  --host="$DB_HOST" --user="$DB_USER" --password \
  "$DB_DATABASE" | gzip > "backups/iicp-directory-pre-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
```

Store backups outside the web root and repository. Encrypt off-host copies and
test restoration against a disposable database.

## Migration sequence

1. Record the application and schema versions.
2. Create and verify a pre-migration backup.
3. Run `php artisan migrate --pretend` and review the SQL.
4. Apply the migration during an approved maintenance window.
5. Run health and conformance checks.
6. Create and verify a post-migration backup.

## Restore rehearsal

Restore into a new disposable database, never over production during a test:

```bash
gzip -dc backup.sql.gz | mysql --host="$DB_HOST" --user="$DB_USER" --password "$RESTORE_DATABASE"
```

Credits, reputation, identity and signed lifecycle evidence require explicit
retention decisions. Do not prune them using generic telemetry cleanup.
