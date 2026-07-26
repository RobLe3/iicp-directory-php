# Hardened operator runbook

This profile is an independently operated reference, not a Genesis Seed
deployment instruction. A release, successful rehearsal, or healthy container
does not authorize production use.

## Supported baseline

- PHP 8.3 operator image built from `Dockerfile.operator`;
- unprivileged nginx 1.28 edge image;
- MariaDB 11.4;
- one PHP-FPM application container and one scheduler container;
- database-backed cache/session state and synchronous queues;
- loopback-only port binding until an operator supplies reviewed TLS ingress.

Default resource guidance is 1 CPU/512 MiB for PHP-FPM, 1 CPU/1 GiB for
MariaDB, and 0.5 CPU/128 MiB for nginx. These are starting values, not an SLA.
Measure the reference workload before raising concurrency.

## Secret preparation and rotation

Keep secret files outside the checkout with mode `0600`:

```bash
umask 077
openssl rand -base64 32 | sed 's/^/base64:/' > app_key
openssl rand -hex 32 > db_password
openssl rand -hex 32 > db_root_password
```

`APP_KEY` rotation invalidates encrypted application state and must be treated
as a planned application migration. Database-password rotation is:

1. create or update the MariaDB account using root/operator authority;
2. write the replacement secret to a new file;
3. update the secret-file path and recreate app, scheduler, and migration
   containers;
4. verify readiness and remove the old database credential;
5. retain no old secret in shell history or logs.

Signing-key rotation follows the protocol key-lifecycle procedure; replacing a
secret file alone is not a valid identity rotation.

## Database privileges

The runtime user receives privileges only on its dedicated database. It must
not have global, grant, file, process, shutdown, replication, or user-management
privileges. Root credentials are mounted only into MariaDB and disposable
backup/restore commands, never the PHP or nginx services.

## Clean installation

1. Verify the public source archive, checksums, manifest, and attestation.
2. Prepare external secret files and non-secret environment values.
3. Run `scripts/rehearse_operator_stack.sh` on the target container runtime.
4. Start MariaDB and wait for its health check.
5. Run the `migrate` profile once and review its output.
6. Start app, scheduler, and web services.
7. Require `/iicp/health` and `/iicp/ready` to pass through the intended
   ingress before allowing registrations.

The application and scheduler never apply migrations implicitly.

## Upgrade

1. Pin and verify the new immutable source release and image digests.
2. Restore the latest backup into disposable infrastructure and rehearse the
   complete upgrade there.
3. Record the current application/nginx image digests and schema head.
4. Quiesce the scheduler and create a verified pre-upgrade backup.
5. Review `php artisan migrate --pretend`, then run the one-shot migration.
6. Replace app, scheduler, and web with the new immutable images.
7. Verify readiness, API conformance, signed-event append, and credit-ledger
   concurrency evidence before reopening writes.

## Rollback

Do not run `migrate:rollback` automatically. For expand-first schema changes,
restore the recorded application images while retaining the expanded schema,
then verify readiness and behavioral checks. If the release contains a
destructive or incompatible migration, stop and restore the verified backup
into a new database; never overwrite the failed database during rehearsal.

The current rehearsal rejects a deliberately invalid candidate configuration
and proves that the existing application remains ready. A true previous-to-next
hardened-image rehearsal becomes mandatory once a second operator-profile
release exists; the first profile has no previous hardened image.

## Backup, restore, and disaster recovery

- Use `mariadb-dump --single-transaction --routines --triggers`.
- Encrypt backups at rest, store them outside the repository and web root, and
  retain a content-free checksum inventory.
- At least quarterly, restore into a new database, run `migrate:status`, verify
  chain-head/event integrity and credit balances, and record only aggregate
  evidence.
- A disaster-recovery exercise must recreate secrets from the operator's
  secret manager, restore the database, start immutable images, and prove both
  readiness and protocol smoke tests without relying on the failed host.
