# ADR 004: Passkey enrollment, M1

Status: implemented as a disabled-by-default development preview, version 0.2.0.

## Library and algorithm policy

Use lbuchs/webauthn 2.2.0, MIT, pinned by composer.lock (source revision
20adb4a240c3997bd8cac7dc4dde38ab0bea0ed1). It supports our PHP 8.2 minimum with
OpenSSL and no additional production Composer packages. The alternative evaluated,
web-auth/webauthn-lib, supplies a broader framework and more dependencies, including
serializer/autoload mechanisms that need separate Mozart compatibility work.
This is a footprint and integration choice, not a claim of an independent library audit.

Enrollment accepts attestation format none, with required user presence and user
verification. Attestation none supplies no device-vendor trust assertion. M1 advertises
and accepts ES256/P-256 only; the library parses the key and OpenSSL validates the PEM
and curve. Other algorithms and attestation formats require additional fixture and
interoperability tests before being enabled. No custom signature or CBOR parser exists
in production code. AAGUID bytes are converted to a canonical UUID string for storage.

The library checks challenge, ceremony type, RP hash, attestation, flags and public key.
Its origin check permits RP suffixes, so the adapter additionally requires the exact
configured origin and rejects cross-origin embedding/topOrigin. Browser rawId must
match the library-validated credential ID. Backup state without backup eligibility
is rejected. No assertion login endpoint is exposed in M1.

## Trust boundaries and persistence

The composition root injects module migrations into the core. Module startup requires
schema version 2, valid settings, OpenSSL, recognized HTTPS, single-site mode and the
pinned site origin. Core services have no dependency on the optional passkey module.
Shared one-time transactions and rate limiting live under Security; fresh proof is an
Auth service. API routes cannot select another WordPress user.

Version 2 adds swsl_passkeys, swsl_transactions, swsl_user_handles and swsl_limits.
Credentials have a full binary unique index, not a case-insensitive textual index.
Handles are random 32-byte values inserted from an existing WordPress user row and
persist independently of individual credentials. Names and browser transport hints
are presentation metadata; they never establish account identity.

Transaction tokens contain 256 random bits; only hashes are stored. Context is bound
to purpose, current user and a fingerprint of the active WordPress token/password hash.
A SELECT followed by a conditional DELETE is safe here because only a DELETE affecting
one row grants permission to proceed. Expiry is checked again at consumption and before
credential insertion. A forced two-process stale-read test proves a single winner.
Failed verification consumes the transaction and cannot store a credential or session.

A per-account MySQL named lock protects the ten-credential limit and data erasure.
Deletion invalidates pending proof/enrollment state and removes the stable handle.
Account existence and handle ownership are checked again before insertion. Named-lock
connection/proxy restrictions from ADR 002 also apply here.

## Fresh proof and HTTP boundary

Every enrollment, rename and revoke requires an authenticated cookie session, the
current-user capability, REST nonce, same-origin JSON request and fresh password proof.
Password verification uses WordPress wp_check_password; no login cookie is issued.
Proof is single-use, lasts at most 300 seconds, and enrollment lasts at most 120 seconds
without extending that deadline. A future existing-passkey proof can reuse this contract
when M2 introduces assertion handling. Roles cannot disable password fallback in M1.

Five password attempts and sixty management requests per five-minute user window are
the initial fixed limits. Anonymous users cannot access these routes. Body and binary
field sizes are bounded, base64url is canonical, and errors contain no library/SQL details.
Responses, including errors, receive Cache-Control: no-store. Passwords stay in request
memory only and are cleared from the UI immediately after proof submission.

SQL error output is suppressed around transaction writes and last_query is cleared.
SAVEQUERIES prevents enrollment startup/storage instead of recording challenge context.
Third-party request/SQL logging remains an installation compatibility responsibility;
server/DB administrators are outside the remote enrollment threat boundary.

## Verification and limits

A Chromium virtual authenticator produced the committed public registration/assertion
fixture; no private authenticator key is included. Unit tests exercise invalid origin,
port, RP, challenge, type, IDs, UP/UV, embedding, backup flags and malformed binary data.
The Mozart build verifies a valid signed assertion, rejects a modified signature and
checks serialization while an original library instance is loaded by another plugin.
Dependency licenses are retained in vendor-prefixed/licenses.

WordPress integration tests cover permissions, fresh proof, binding/expiry, replay,
failed validation, uniqueness, limits, forced parallel consumption, privacy and deletion.
Browser E2E registers two independent virtual authenticators, renames and separately
revokes a credential, then disables the module without losing the WordPress session.
Real devices, Safari/Firefox, MFA/security-plugin compatibility, cross-origin REST,
proxy failover and final release ZIP review remain release gates. Enrollment preview
completion does not approve production passkey login.

## References

- https://github.com/lbuchs/WebAuthn/tree/v2.2.0
- https://github.com/web-auth/webauthn-lib
- https://www.w3.org/TR/webauthn-3/
