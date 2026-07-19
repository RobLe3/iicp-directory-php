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

`--strict` fails for every known gap. Both local variants are expected to pass
the current contract; operational cutover still requires live evidence rather
than route-count parity alone.

The checker is not a replacement for container tests.  Releases must also run
the variant's full suite, migration upgrade tests, and cross-implementation
request fixtures before any cutover claim.

`policy-detail-disclosure-v0.json` is a portable mirror of the pre-normative
fixture under `spec/proposals/fixtures/`. The PHP unit test verifies that both
copies remain byte-identical in the monorepo while allowing the standalone
directory repository to run without depending on files outside its checkout.

`dsr-related-records-v1.json` defines the eleven redacted export families,
retention/deletion boundaries and negative controls shared by both variants.
`scripts/docker_directory_dsr_parity_gate.sh` exercises that contract through
signed HTTP requests against independent disposable MySQL databases.
