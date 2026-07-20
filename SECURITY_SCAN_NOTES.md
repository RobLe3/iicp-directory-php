# Security scan disposition

The 2026-07-20 publication review scanned the complete Git history with
Gitleaks and TruffleHog.

- TruffleHog reported no verified secrets.
- Seven Gitleaks findings were reviewed. They are documentation references to
  the Genesis signing-key environment variable, DID verification-method text,
  and deliberately non-secret public/fake key material in test fixtures.
- No production credential, private key, database value or operator secret was
  identified by that review.

These classifications are not a blanket allow-list. A fresh full-history scan
and manual review are required before a visibility change, and any finding in
runtime configuration, deployment material or non-fixture source blocks
publication until resolved.
