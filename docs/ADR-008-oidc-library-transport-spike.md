# ADR 008: OIDC library and transport feasibility

Status: evaluation evidence, 2026-11-10; **production library/transport selection pending**.
This completes an initial M4.0 spike, not M4.0 acceptance. No OIDC runtime dependency,
network adapter, route or database migration is introduced.

## Candidate and alternatives

Evaluate a complete OIDC client before considering a JWT-only composition, as required
by [the M4 plan](M4-OIDC-PLAN.md#technische-freigabegates-aus-der-review).
Facile exposes PSR-18 injection separately for discovery, JWKS and token exchange;
this permits testing a single explicit transport without introducing PHP sessions.
The tested stable client and verifier releases are 1.0.0 (June 2026), require PHP 8.2,
and declare MIT licenses. Continuing maintenance/security-response review is still
required before selection; a release date alone does not establish that guarantee.

The isolated [Composer lock](../tests/Spikes/Oidc/composer.lock) pins all 24 dependencies,
including `web-token/jwt-library` 4.2.7 and Nyholm PSR-7 1.8.2. No dependency was added
to the plugin's production lock. Plugins/scripts are disabled. Composer audit found
no known advisories in this test tree on the evaluation date.

Screened alternative: [Jumbojett's client](https://github.com/jumbojett/OpenID-Connect-PHP)
provides cURL integration, PKCE and its own session-oriented flow. Its documented
default session storage and network API would need separate integration proofs; it
was not installed or tested here. Replacing the complete client with JOSE building
blocks would require the separate architecture approval already specified in M4.

Pinned source references:

- [OIDC client 1.0.0](https://github.com/facile-it/php-openid-client/tree/4257463518a8a9f1de6fe6014c130330030331fb)
- [JOSE verifier 1.0.0](https://github.com/facile-it/php-jose-verifier/tree/2fed59e21b28729c1fa57456ac129ffc2bc2c08e)
- [JWT library 4.2.7](https://github.com/web-token/jwt-library/tree/62643a2f31274c546a7d158ceab6dd5c052db911)

## Initial observations before the build-adapter correction

Run `composer test:oidc-spike`; details and exit semantics are in the
[probe README](../tests/Spikes/Oidc/README.md). The library test generates an ephemeral
RSA key, signs synthetic ID tokens with OpenSSL and passes them through the actual
OIDC authorization service. Its injected HTTP client accepts only the three expected
URLs and checks client authentication, callback URI and PKCE verifier on exchange.

| Observation | Result |
| --- | --- |
| Discovery, token exchange and JWKS use the same injected client | Confirmed by exact method/URL sequence |
| Valid RS256 ID token | Accepted with expected subject |
| Wrong issuer/audience/nonce; expired/future-issued/future-not-before token | Rejected |
| Modified signature, `none`, HS256 against RS256 policy, unknown key ID | Rejected |
| Wrong `azp` with a single matching audience, using default verifier builder | Accepted: integration gap |
| Missing nonce despite an expected nonce in the auth session | Accepted: integration gap |
| Missing ID token in code-exchange response | Callback returns an OAuth token set: integration must require an ID token |
| Wrong plain callback `state` | Callback completes: application must validate/consume the browser-bound transaction first |
| Production Mozart adapter with this dependency tree | Rejects `files` autoloading before scoping, as intended |

These are API/configuration observations, not a claim that all are upstream
vulnerabilities. In particular the application owns state/transaction validation and
must require the OIDC result. Defaults must not be mistaken for the project's strict
profile. Missing nonce and `azp` handling must be resolved with explicit library
configuration/checkers or an upstream correction, with executable rejection tests,
before this candidate can be selected. No replacement protocol validator is approved.
Exceptions also originate from JOSE header checkers, not solely the OIDC exception
hierarchy; a future boundary must translate them without exposing claim/secret values.

### Configured application adapter follow-up

The isolated `StrictVerifierBuilder` now configures the library's public
`withExpectedAzp(client_id)` API. `StrictCodeFlow` requires code/state/issuer strings,
the matching expected state and issuer, a registered callback URI and non-empty
session state/nonce/verifier before exchange. Its deliberately narrow test profile
requires callback `iss` and an explicit RS256 policy. Unexpected callback fields,
including front-channel tokens or a supplied verifier, are rejected before HTTP.
This does not establish a general provider profile or parse raw query parameters.

After the complete OIDC service has verified the token, JOSE's `ClaimCheckerManager`
enforces mandatory claims including nonce, and azp for multiple audiences. Matching
nonce values and azp values are checked by the configured library. No custom JWT
parser, signature implementation or replacement OIDC client is introduced. Library
failures become a neutral exception without an attached cause that could expose claims.

`profile.php` retains the original default-behavior probe, then demonstrates valid
single/multiple-audience results and **56 rejected cases** through this adapter.
They include each missing required claim, mismatched nonce/azp, missing ID token,
signature/algorithm failures, malformed callbacks, missing transaction values and
redirect substitution. Callback/transaction-input failures assert zero additional
HTTP calls. This resolves the four observed default-API gaps **in the isolated test
adapter only**, not in the shipped plugin.

The two adapter classes pass WPCS and a separate PHPStan level 8 configuration without
baseline/ignores. A test-only stub describes the exercised external API because its
Psalm type imports are not resolved by PHPStan; the callback shape matches the
upstream token-set attributes, and claims describe the actual verified JWT payload.
It is neither loaded at runtime nor used to change production analysis.

Remaining before integration: raw duplicate parameter/claim rejection, complete claim
type/age rules, bounded JWKS rotation, actual browser binding/expiry/atomic transaction
consumption and local session/link policies. This adapter cannot issue WordPress
sessions or persist identities. It must not be exposed as a callback endpoint.

### Mozart follow-up: tested artifact now passes

The project adapter now handles the virtual provider graph, reviewed eager function
files/conditional Php83 classmap, optional cross-package references and local-name
collisions. The scoped consumer passes signed-token/profile cases on PHP 8.2/8.3
without an original vendor fallback, with incompatible foreign symbols before/after
and with real original libraries loaded first. Eager functions, polyfill stubs,
dynamic algorithm loading and optional AES-KW are exercised. Exact autoload approvals,
implementation boundaries and regression evidence are in
[ADR 003](ADR-003-mozart.md#virtual-packages-and-oidc-scoping-2026-11-10).
The baseline `mozart.php` still reproduces unmodified `Compose` failing on
`psr/http-client-implementation`; the corrected project adapter succeeds. Unknown
autoload forms remain rejected. The remaining OIDC protocol/runtime work is not approved.

## Direct cURL capability evidence

The independent transport probe starts an actual loopback TLS fixture with an ephemeral
self-signed certificate explicitly trusted only by that test request. The URL hostname
is `oidc-spike.invalid`; `CURLOPT_RESOLVE` directs it to 127.0.0.1. This is a test-only
loopback exception, not a proposed production allowlist. TLS peer/hostname verification
stays enabled. Each request uses a new handle and disables proxies explicitly.

Observed: connection reaches the pinned IP while preserving TLS hostname checks;
wrong hostname and an untrusted certificate fail; redirects are not followed;
responses over 1,024 bytes abort; a slow response aborts at a 500 ms total timeout.
An unusable proxy environment does not alter the explicitly direct connection.
Those size/time values exercise the mechanisms and are not production limits.
See [libcurl's resolve contract](https://curl.se/libcurl/c/CURLOPT_RESOLVE.html).

This supports further evaluation of a cURL-only PSR-18 adapter. It does **not** prove
DNS/IP classification, rebinding rejection, IPv6, VPN/private-network allowlists,
connect/header budgets, proxy-required installations or compatibility with WordPress
HTTP integrations. The library and transport probes are separate; their eventual
composition needs its own adversarial end-to-end test. No Streams fallback is approved.

## Next acceptance work

1. Extend the configured-adapter proofs to duplicate claims, full claim type rules,
   maximum token age and JWKS rotation, then combine them with actual browser-bound
   atomic transactions before session/link integration; record maintenance assessment.
2. Keep the successful Composer/Mozart artifact regressions on dependency updates;
   review new autoload forms and complete the candidate's maintenance/distribution
   assessment before adding it to production dependencies.
3. Specify and prove an injected transport against hostile DNS, IPv4/IPv6 and VPN
   targets, redirects, header/body/time budgets, cache/refresh storms and failure paths.
4. Complete the remaining AEAD, callback/cookie, schema/retention, local-policy and
   lockout ADRs. The existing M3 release gates still apply.

Local environment: PHP 8.3.16, cURL 7.81.0/OpenSSL 3.0.2, Python 3.10.12, Composer 2.6.6;
Mozart 1.2.3 image ID `sha256:f100625d694ce05fdc28fe24c00f27099b524ecafca8658d7121b09781d3f5fa`.
Dependency resolution targets PHP 8.2 via Composer platform configuration; execution
on PHP 8.2 and remote CI are still unverified. No real provider credentials or devices
are needed. Manual reproduction: run the command above and inspect both `PASS` and
`GAP` lines; the final message must explicitly retain the failed acceptance status.

Regression checks: 70 unit tests/325 assertions, PHPStan level 8, WPCS, PHP lint,
Composer validation, Bash syntax and Python parsing pass. This addition changes
evaluation tooling and documentation only; whole-project coverage, WordPress/browser
integration, production ZIP acceptance and remote SonarQube were not rerun.
