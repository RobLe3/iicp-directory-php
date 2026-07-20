# Self-host operations

These instructions are implementation-neutral examples. Adapt paths, retention
and access controls to the operator's environment.

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
