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
