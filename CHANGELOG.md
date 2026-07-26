# Changelog

## v1.10.77 — 2026-07-26

- Established the standalone public repository as release source authority.
- Serialized signed lifecycle-event appends with a durable chain head.
- Centralized strict node and replica JWT profiles and corrected encoded
  application-key handling.
- Reconciled the OpenAPI projection with runtime behavior and added route,
  middleware and schema-reference drift checks.
- Serialized credit-ledger mutations and added real MariaDB concurrency
  evidence for debit, award, free allocation and operator-wallet contention.
- Updated locked Laravel, Sanctum and GitHub Actions dependencies.

No production deployment or website publication is part of this release.
