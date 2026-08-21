# Restricted trust-domain operator preview

The directory contains a disabled-by-default implementation foundation for the
pre-normative `urn:iicp:profile:restricted-trust-domain:v1` Profile. It turns
registration, discovery, bootstrap and authority-issuing routes into an
explicit membership boundary. It does not change the public-directory default,
activate federation or authorize a Genesis deployment.

## Trust boundary

When `IICP_RESTRICTED_DOMAIN_ENABLED=true`, protected requests require both
`X-IICP-Membership`, the bearer credential issued by this directory, and
`X-IICP-Subject-Id`, the stable node or client identifier bound to it.

The directory stores only a SHA-256 digest of the high-entropy credential.
Credentials are scoped to one configured domain, subject, operation set,
generation and expiry. Rotation replaces the prior digest. Revocation and a
membership-epoch increase take effect on the next request; there is no positive
authorization cache. Protected routes return one bounded refusal and do not
reveal whether a subject was missing, expired, revoked or in another domain.

Membership is a prerequisite, not a dispatch or relay ticket. Existing node
authentication remains required where applicable.

## Standalone configuration

Set these values in the directory's protected environment, not in a portable
client configuration:

```dotenv
IICP_RESTRICTED_DOMAIN_ENABLED=true
IICP_TRUST_DOMAIN_ID=example.internal
IICP_DIRECTORY_AUTHORITY_ID=did:key:replace-with-reviewed-authority
IICP_DIRECTORY_AUTHORITY_KEY_ID=did:key:replace-with-reviewed-authority#key-1
IICP_GENESIS_ED25519_SECRET_KEY=REVIEWED_64_BYTE_SECRET_KEY_AS_HEX
IICP_MEMBERSHIP_EPOCH=1
IICP_MEMBERSHIP_MAX_TTL_SECONDS=86400
```

Startup fails if the domain, authority, authority key identifier, Ed25519
signing secret, epoch or TTL is invalid. Requiring signing material at startup
prevents a private directory from accepting members that it cannot later bind
to peer-verifiable bootstrap evidence. Restricted
mode currently also rejects replica mode because cross-domain federation policy
is not implemented. The directory has no dependency on `iicp.network` in this
mode; configure local DNS, TLS, database, backups and process supervision as for
any independent deployment.

Apply the reversible database migration before issuing membership:

```bash
php artisan migrate --force
```

## Issue, rotate and revoke membership

```bash
php artisan iicp:membership-issue node node-a \
  --scope=registration --scope=heartbeat --scope=peers \
  --scope=consumer_token --scope=dispatch --scope=relay --ttl=3600 \
  --key-id=did:key:node-a#key-1 --public-key=BASE64URL_ED25519_PUBLIC_KEY

php artisan iicp:membership-issue client client-a \
  --scope=discovery --scope=bootstrap --ttl=3600

php artisan iicp:membership-revoke node node-a

# Inspect one member without printing its identifier, credential digest or assertion.
php artisan iicp:membership-status node node-a
```

The status command returns a one-way subject reference, lifecycle state,
generation, scopes, expiry and whether peer evidence exists. It does not list
members or print the subject identifier, token digest or signed assertion.

The bearer credential is printed once. Store it in a protected secret provider.
Do not put it in argv, portable configuration, logs or source control. When a
subject key is supplied, the command also prints a short-lived, directory-signed
membership assertion. That assertion contains the public identity binding and
peer-operation scopes, but not the bearer credential. Peers can verify it with
the directory's public key. Running the issue command again for the same domain,
kind and subject rotates the credential and advances its generation. Revocation
prevents new directory operations immediately; peers must also enforce their
configured revocation-freshness bound.

Existing task execution and transport sessions need their own revalidation
rules in the Rust runtime and are not claimed complete by this directory change.

## Backup, restore and authority loss

Back up the database, application encryption key and separately managed
authority material together. A database restore can reintroduce old membership
state; after a restore, raise `IICP_MEMBERSHIP_EPOCH` and issue new credentials
before reopening protected routes. If the authority is lost or suspected
compromised, stop protected access, replace the authority under an explicit
operator recovery procedure, raise the epoch and re-enrol every member.

Rollback consists of disabling restricted mode and rolling back the migration.
That restores public behavior and therefore is a security-sensitive operator
decision, not an automatic failure fallback.

## Current limits

- Federation and trusted cross-domain policy are not implemented or enabled.
- The bearer credential is an HTTP binding implementation. The signed
  membership assertion is a pre-normative Profile artifact. Neither is a new
  base-wire field.
- Peer gossip admission, CIP worker inheritance and execution-time
  revalidation remain owned by the Rust runtime.
- A future wizard must emit the canonical Rust configuration and secret
  references; it must not copy membership bearer values into exported files.

Do not claim complete restricted-domain conformance until the shared semantic
fixtures, both directory implementations, Rust runtime/CIP enforcement and
black-box restart/revocation probes all pass.
