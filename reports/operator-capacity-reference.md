# Hardened operator reference capacity

This is a disposable, synthetic engineering profile, **not an SLA or a
production-performance claim**. It uses 100 generated nodes whose identifiers
and `.invalid` endpoints never leave the disposable database. The retained JSON
contains only aggregate timing and error counts.

The reference command is:

```bash
./scripts/run_operator_capacity_reference.sh
```

It runs 40 discovery requests at concurrency 1, 8 and 32 against the loopback
operator stack. The Compose limits are one CPU/512 MiB for PHP-FPM, one CPU/1
GiB for MariaDB, and half a CPU/128 MiB for nginx. Results are environment
specific and must be regenerated on the target operator hardware before
capacity planning. CI runs only a small functional smoke profile and applies
no latency threshold.

The checked-in July 26, 2026 run was made with Docker Desktop on an Apple
Silicon development host. It showed the public discovery limiter as the first
explicit envelope: after the 40-request concurrency-1 phase, the sequential
concurrency-8 and concurrency-32 phases reported 20 and 40 rejected requests,
respectively, within the same limiter window. Those errors are retained rather
than hidden or treated as an application throughput claim.

`operator-capacity-reference.json` is replaced by an observed local run before
release; it must never contain SQL, node identifiers, endpoints, response
payloads, credentials, or production data.
