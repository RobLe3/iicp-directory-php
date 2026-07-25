# Publication readiness

The repository may be published while functionality remains pre-1.0 when all
of the following hold:

- current and full-history secret scans are resolved;
- retained GitHub issues, pull requests and Actions logs are reviewed;
- clean installation and tests pass without a private repository;
- locked dependency audits have no unresolved advisories;
- the pinned seed parity manifest passes;
- example configuration contains no production values;
- production topology, credentials, backups and operator data are absent;
- limitations and federation maturity are stated accurately;
- the maintainer explicitly authorizes the visibility change.

Publication is not a production deployment or federation cutover.

The latest reviewed scanner disposition is recorded in
`SECURITY_SCAN_NOTES.md`; it does not replace a fresh pre-publication scan.
