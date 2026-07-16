# Directory implementation parity

`directory/` is the current IICP control-plane reference implementation.  The
versioned contract in this directory prevents standalone implementations from
quietly drifting behind its public API, migration, privacy, and operations
surface.

Run the check from the `iicp.network` workspace:

```bash
python3 scripts/check_directory_variant_parity.py \
  --php-dir iicp-directory-php \
  --rust-dir ../iicp-directory-rust
```

`--strict` fails for every known gap.  The PHP variant is expected to pass that
gate for the current contract.  Rust is intentionally reported as a gap until
its ticketed-dispatch, intent-policy, operator self-service/DSR, policy-key
lifecycle, and dispatch-accounting features are implemented and tested.

The checker is not a replacement for container tests.  Releases must also run
the variant's full suite, migration upgrade tests, and cross-implementation
request fixtures before any cutover claim.

`policy-detail-disclosure-v0.json` is a portable mirror of the pre-normative
fixture under `spec/proposals/fixtures/`. The PHP unit test verifies that both
copies remain byte-identical in the monorepo while allowing the standalone
directory repository to run without depending on files outside its checkout.
