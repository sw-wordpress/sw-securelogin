# Initial login and enrollment threat model

Assets: existing WordPress accounts, sessions, passkey public keys and identity bindings.
Boundaries: untrusted browser to REST; REST to protocol library; verified result to WordPress
session issuance; authenticated profile to credential persistence. A session alone is not
fresh reauthentication. Compromised WordPress administrators or server code are outside the
remote protocol threat model.

Before M1/M2 exposes endpoints, verify all of the following:

- Enrollment: capability and same-user binding, REST nonce and independent fresh password
  or passkey proof. Reject cross-account enrollment even from another administrator.
- Assertion: library verifies challenge, exact Origin/RP ID, signature, UP/UV and credential
  ownership. Never trust browser userHandle as an account selector.
- Transactions: CSPRNG, short TTL, browser and expected-user binding, atomic one-time consume.
  Concurrent attempts must yield at most one success. Transients alone are insufficient.
- Login: no session cookie before all protocol and local security/MFA policies succeed.
  Test third-party MFA integration before claiming compatibility.
- Abuse: rate limits, generic account responses, bounded request size, no-store responses,
  validated internal redirects, no sensitive request data in logs.
- Recovery: retain password fallback; no policy may silently lock out administrators.
- Persistence: prepared queries, unique credential identity, fail closed on migration error,
  user-deletion cleanup and explicit privacy retention policy.

Negative tests must cover cross-user credential IDs, failed/expired/replayed challenges,
concurrent replay, wrong signature/Origin/RP ID, missing UV/UP, CSRF, stale reauthentication,
redirect injection, user enumeration and failure without session creation. No security claim
about these future flows follows from the current foundation tests.

## M4.0 OIDC evaluation boundaries

Additional assets: operator-supplied client secrets, recoverable PKCE verifiers,
browser-bound transactions and issuer/subject identity links. Additional boundaries:
mutable provider settings to operator approval; approved URLs to DNS/TLS connections;
provider responses to the OIDC library; validated identity to local policy/session
issuance. Provider configuration approval does not itself authorize network access.

The initial [library/transport spike](ADR-008-oidc-library-transport-spike.md) confirms
that transport injection is possible, but identifies missing default nonce/azp checks
and callback requirements. The configured test adapter rejects these cases using
library configuration/JOSE presence checks and callback guards; production integration
still requires the remaining transaction and profile proofs. Unchecked state or an
OAuth token set without an ID token must never authorize a
login/link. Foreign dependency collisions must not change verification behavior.
The corrected project adapter passes scoped OIDC signature/profile tests with foreign
symbols and original libraries loaded separately. It handles upstream's virtual-package,
eager-file and optional-reference limitations; unknown autoload forms remain rejected.
Exact tested boundaries are in [ADR 003](ADR-003-mozart.md).

The cURL capability test proves a loopback test connection can pin an address while
checking the TLS hostname. A full SSRF policy still needs DNS/IPv4/IPv6/private-network
classification and connection-binding tests, with explicit budgets and no transport
fallback. Remaining OIDC threats and mandatory attack cases are tracked in the
[M4 plan](M4-OIDC-PLAN.md#verbindliche-angriffstests). Full M4.0 threat-model acceptance
also requires the pending AEAD, routing, transaction, retention and local-policy ADRs.
