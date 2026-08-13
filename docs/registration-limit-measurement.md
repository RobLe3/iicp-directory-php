# Registration-limit measurement lane

Issue #65 remains activation-gated. The current production contract stays at
60 registration attempts per source IP per minute, a separate 60 heartbeats per
node per minute, and the active-node capacity control. This lane measures the
implementation without changing those limits.

Run it only against the disposable Laravel test database:

```bash
./scripts/run_registration_limit_measurement.sh /tmp/registration-limits.json
```

The output contains aggregate status counts and elapsed time. It excludes node
identifiers, tokens, endpoints, source addresses, payloads and raw responses.
It tests fresh registration, authenticated re-registration, heartbeat and
malformed registration independently.

A disposable project run can expose present behavior and measurement gaps. It
cannot represent shared-NAT fairness, external operator reconciliation or an
attack incident. Do not change production limits until issue #65's activation
condition is met and representative evidence, PHP/Rust review and rollback
instructions exist.
