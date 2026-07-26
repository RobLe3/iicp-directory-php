# Security policy

Do not report vulnerabilities in public issues. Use
[GitHub private vulnerability reporting](https://github.com/RobLe3/iicp-directory-php/security/advisories/new)
or contact community@iicp.network.

Never submit credentials, production database contents, operator records,
private endpoints, or task payloads. The directory is a control plane and must
not receive or log task payloads.

Supported security fixes target the latest immutable release. Critical fixes
are backported to the immediately previous release for 90 days when feasible.
See `RELEASE_POLICY.md`; never rewrite a release tag to deliver a fix.
