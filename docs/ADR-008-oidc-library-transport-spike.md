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

## Configured candidate adversarial characterization, 2026-11-17

Historical baseline before the schema/header follow-up below:
the isolated [adversarial matrix](../tests/Spikes/Oidc/adversarial.php) added 64 cases
through the existing configured `StrictCodeFlow`, using genuinely signed malformed
payloads and injected JWKS. It changes no runtime class or production dependency.
Results are reproduced with the locked candidate on PHP 8.2.27 and 8.3.16, both
unscoped and in all three Mozart collision modes:

| Input | Observed configured-candidate behavior |
| --- | --- |
| Invalid types for issuer, nonce, authorized party and time claims; invalid simple audiences; numeric-string times | 42 rejected cases across the claim/audience matrix |
| Empty JWKS, encryption-only use, wrong declared algorithm, signing-only key operations, wrong key type | Five rejected cases; each proves a fresh JWKS HTTP fetch |
| Null/boolean/integer/empty/array/object `sub` | Six accepted gaps |
| 256-character ASCII `sub` | Accepted gap |
| Audience array containing a non-string; JSON object containing the expected audience | Two accepted gaps |
| Duplicate `sub`, escaped equivalent `sub`, duplicate nonce, duplicate `alg`, duplicate `kid` | Five accepted gaps, using exact raw JSON bytes before signing |
| `jku` / `x5u` naming an untrusted URL | Two accepted gaps against the project's header rejection policy; neither causes an HTTP fetch to that URL |
| Two identical JWKS entries with the same `kid` | Accepted gap against the project's unambiguous-selection policy |

The matrix asserts the exact 17 accepted gap names and 47 rejections. An unexpected
acceptance, newly rejected known gap or exception escaping the neutral boundary fails
the probe. A valid flow is repeated after adversarial cases to check fixture isolation.
These assertions characterize evaluation behavior; accepted attack fixtures must never
become production acceptance tests for successful authentication.

Claim shapes and subject constraints follow [OIDC Core section 2](https://openid.net/specs/openid-connect-core-1_0.html#IDToken).
Duplicate-name rejection is the project's stricter profile: [RFC 7519 section 4](https://www.rfc-editor.org/rfc/rfc7519.html#section-4)
allows either rejection or retaining the last duplicate member. These observations
therefore identify integration requirements, not a blanket upstream vulnerability claim.
Integer NumericDates are not counted as malformed; maximum token age and clock skew
remain undecided. No custom protocol validator or JSON parser was added to mask gaps.

Before production selection, demonstrate library-supported enforcement for these
remaining requirements or review an alternative candidate/adapter design. Actual
browser-bound atomic transactions, raw callback parsing, JWKS rotation/refresh budgets,
transport composition and maintenance assessment also remain open. M4.0 is not accepted.

Checks: full spike under both PHP versions, including configured-adapter WPCS/PHPStan 8,
scoped artifact/collision tests, Composer audit and local cURL/TLS probes; root unit suite
104 tests / 896 assertions, PHPStan 8, WPCS, PHP/JS lint and Composer validation pass.
Whole-project coverage, WordPress integration, browser, final release ZIP and remote
SonarQube were not rerun for this test/documentation-only change.

Manual reproduction: `composer test:oidc-spike`, then
`SWSL_SPIKE_PHP=/usr/bin/php8.2 composer test:oidc-spike`. Expect 56 existing profile
rejections and `Adversarial characterization: 47 rejected, 17 accepted gaps` in each
unscoped/scoped run, plus explicit `GAP` output. Exit zero confirms reproduction of
recorded gaps, not production readiness.

## Schema and header checker follow-up, 2026-11-17

The configured test adapter now uses JOSE's public `CallableChecker` and
`ClaimCheckerManager` for a non-empty ASCII subject of at most 255 characters and
a non-empty string/list-of-non-empty-strings audience shape. These schema constraints
supplement the complete client's verified result; issuer/audience values, nonce,
time checks and signatures remain the client's responsibility. JOSE's
`CompactSerializer` and `HeaderCheckerManager` reject `jku`/`x5u` presence regardless
of value. No alternate JWT parser, cryptography or upstream patch was introduced.

Eleven of the original 17 accepted attack fixtures are now rejected. Thirteen new
adversarial cases cover malformed header values, non-ASCII subjects, additional
audience members and numeric-object audiences. The matrix asserts **70 rejections
and seven remaining accepted gaps across 77 cases**. Four positive controls prove
1-/255-character subject boundaries, exact case preservation and single-list audience
support. These checks run before any verified claims can leave the adapter.

The seven remaining gaps are five duplicate JSON name fixtures, duplicate JWKS `kid`
and a newly demonstrated audience object `{"0":"fixture-client"}`. The candidate's
associative JSON decoding collapses that object into a PHP list; checking the decoded
shape alone cannot distinguish it. A custom JSON parser is not approved. The previous
17-gap characterization remains historical evidence, not the current expectation.

The malformed nonce/azp fixtures also expose upstream `Array to string conversion`
warnings in the candidate's exception formatting (`NonceChecker`/`AzpChecker`). The
adapter's neutral exception does not contain the original cause, but it cannot prevent
PHP diagnostics emitted earlier by the candidate. Warnings remain visible; no error
suppression or global error-handler workaround is applied. Production selection must
resolve this diagnostic path as well as the seven acceptance gaps. Token age, skew,
atomic transactions, transport, rotation and maintenance gates are unchanged.

Validation: full spike under PHP 8.2.27/8.3.16, all three scoped/collision modes and
local TLS probes reproduce these results. WPCS and PHPStan 8 pass for the adapters
and root source, with no baseline/ignore changes. Root PHPUnit passes 104 tests /
896 assertions and regenerates unit Clover; PHP-/JS-lint, Composer validation and
the spike's locked dependency audits pass. A local Xdebug run of the complete profile
measured 55/55 executable lines in `StrictCodeFlow` and 11/12 in `StrictVerifierBuilder`.
Those are test-adapter metrics, not whole-plugin coverage. Whole-project PHP/JS gates,
WordPress/browser integration, final release ZIP and remote SonarQube are not rerun.

Manual reproduction: `composer test:oidc-spike` and
`SWSL_SPIKE_PHP=/usr/bin/php8.2 composer test:oidc-spike`. Expect 56 existing profile
rejections, `Adversarial characterization: 70 rejected, 7 accepted gaps`, four positive
schema controls, visible upstream warnings and explicit remaining `GAP` lines in each
unscoped/scoped run. Exit zero confirms the evaluation reproduces those outcomes;
M4.0 remains unaccepted and no runtime OIDC route or dependency is enabled.

## Alternative candidate review and decision, 2026-11-24

Desk review only; no alternative was installed or tested. Findings:

- [Jumbojett's client](https://github.com/jumbojett/OpenID-Connect-PHP) is session-oriented
  with its own cURL transport and conflicts with browser-bound one-time transactions and a
  single explicit transport.
- [oidcphp/core](https://github.com/oidcphp/core) is a PSR-7/17/18 relying party. PKCE
  support and maintenance are unverified; it is **not** evaluated.
- `league/oauth2-client` covers OAuth 2.0/PKCE but not ID-token validation; adopting it
  means a custom validator, which this ADR does not approve.
- No PHP relying-party library appears on the OpenID Foundation certification list
  (phpOIDC is certified for provider profiles only).

The duplicate JSON-name and `{"0":...}` audience gaps stem from PHP's `json_decode`
and are expected to affect any PHP candidate, so switching would not close them.
They additionally require a token signed by the trusted provider, which an attacker
cannot forge.

Decision: **retain the Facile candidate for further evaluation; do not switch.** The seven
gaps stay open and recorded. Any mitigation must be a narrowly scoped, separately
reviewed raw-token pre-check (for example a PSR-18 decorator inspecting the token and
JWKS responses); no custom JWT parser is approved. The `nonce`/`azp` upstream warnings
originate before the adapter sees claims and need either that pre-check or an upstream
fix. M4.0 remains unaccepted.

## Raw-response guard prototype, 2026-11-24

`StrictResponseGuard` is a test-only PSR-18 decorator around the injected transport.
It is **not a JWT parser**: it verifies no signature, time, issuer, audience value or nonce
value. For every JSON response it rejects duplicate object names at any depth (compared
after unescaping, so `su\u0062` equals `sub`) using a small structural scanner that runs
only on text `json_decode` already accepted. For an `id_token` it requires a compact
three-segment token, strict base64url segments, no duplicate names in header/payload and
string-typed `sub`/`nonce`/`azp` and a string-or-array `aud`, which closes the numeric-object
audience gap. For a JWKS it rejects duplicate `kid` values. Everything else, including all
cryptographic and claim validation, stays with the candidate. Responses above 256 KiB are
rejected. Failures raise a content-free PSR-18 exception that the adapter maps to the
neutral error.

Result on the 79-case matrix (77 earlier cases plus nested duplicate names and duplicate
names inside a raw JWKS): **79 rejected, 0 accepted gaps**, and the harness now records PHP
diagnostics and asserts none occur, so the `nonce`/`azp` array-to-string warnings no longer
reach the candidate. The four positive boundary controls and the valid flow still pass.
Reproduced under PHP 8.2 and 8.3, unscoped and in all three scoped/collision modes, with
WPCS and PHPStan 8 clean. The earlier default-API `GAP` lines in `library.php` are kept
for comparison and still show the unguarded behavior.

Differential test (`tests/Spikes/Oidc/fuzz.php`, run by the spike runner): the guard is compared
with an unrelated oracle (`fuzz_oracle.py`: Python's JSON parser and base64, run with
`python3 -I`) on seeded generated documents in three categories: generic JSON (duplicate names
at any depth, literal/`\u`/surrogate-pair/`\/` escapes, whitespace, structural mutations such as
deleted/inserted/duplicated slices, truncation, invalid UTF-8), compact tokens (corrupted
segments: padding, standard alphabet, whitespace, empty, wrong part count; claim/header
duplicates and type confusion) and JWKS documents (duplicate/non-string `kid`, object-shaped
`keys`, duplicate members). Additionally 99 targeted edge cases (key text inside values,
equal values in arrays, same names in siblings/parents, 500-level nesting, a 600-level
document that `json_decode` refuses, BOM, NaN, lone surrogates, 256 KiB size boundary,
linear-time checks, unchanged transport errors and response bodies). PHP diagnostics fail the
run. The runner default is 30,000 documents per category; a one-off run of 300,000 per category
with three seeds (1, 777, 424242, plus the default seed during development) under PHP 8.2 and 8.3
showed **zero disagreements** and no diagnostics. During development the oracle itself was
corrected once: it dropped lone surrogates hidden in overwritten duplicates; PHP/Python
validity disagreements are now failures. A mutation check (eleven deliberately broken guard
variants: no unescaping, root-only duplicates, unchecked `azp`/`sub`, object `aud` allowed,
no `kid` check, padding allowed, no size limit, no stack pop, name detection always true,
header not scanned, lenient key decoding) was run: ten were caught by the suite; the
eleventh (lenient decoding of a key literal) is an equivalent mutant because the scanner only sees text `json_decode`
already accepted.

Remaining limits: the evidence is generated and fixture-based, not a security review or
a proof; coverage of exotic encodings beyond the generator is not guaranteed. Documents that PHP's
decoder rejects pass through unchanged, which is safe only while the candidate decodes with
the same PHP function (asserted for the depth case, not for future upstream changes).
Guard and adapter must be reviewed together before any production use. Browser-bound atomic transactions, raw callback parsing, JWKS rotation and
refresh budgets, real transport composition (TLS/IP pinning with this decorator) and the
maintenance assessment remain open. M4.0 is not accepted; no runtime route or dependency
is enabled.

## Historical initial-spike environment and checks

Initial local environment: PHP 8.3.16, cURL 7.81.0/OpenSSL 3.0.2, Python 3.10.12, Composer 2.6.6;
Mozart 1.2.3 image ID `sha256:f100625d694ce05fdc28fe24c00f27099b524ecafca8658d7121b09781d3f5fa`.
Dependency resolution targets PHP 8.2 via Composer platform configuration; execution
on PHP 8.2 was initially unverified (now covered by the follow-ups); remote CI for
these changes remains unverified. No real provider credentials or devices
are needed. Manual reproduction: run the command above and inspect both `PASS` and
`GAP` lines; the final message must explicitly retain the failed acceptance status.

Regression checks: 70 unit tests/325 assertions, PHPStan level 8, WPCS, PHP lint,
Composer validation, Bash syntax and Python parsing pass. This addition changes
evaluation tooling and documentation only; whole-project coverage, WordPress/browser
integration, production ZIP acceptance and remote SonarQube were not rerun.
