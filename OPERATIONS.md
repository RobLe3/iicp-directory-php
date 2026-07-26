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
remain in a private temporary directory only with `--keep`; never commit them.
Set `IICP_OPERATOR_REHEARSAL_OUTPUT` to copy only the content-free JSON result
to a chosen private path before cleanup.

## Verify a public source release

Before preparing an operator artifact, verify both checksum and provenance:

```bash
sha256sum --check SHA256SUMS
gh attestation verify iicp-directory-php-v1.10.79.1.tar.gz \
  --repo RobLe3/iicp-directory-php
```

The verified public archive is the source input. A retained private-hub copy is
not release authority. Verification does not authorize production deployment.

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
