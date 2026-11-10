# M4.0 library and transport feasibility probe

Run `composer test:oidc-spike` from the plugin root. Requires Linux, PHP 8.2+ with
cURL/OpenSSL, Composer 2, Docker, OpenSSL CLI, Python 3 and network access for Composer.
Install the root development dependencies first (`composer install`) for PHPStan/WPCS.
Dependencies are locked separately; scripts/plugins are disabled during installation.
The runner installs into a fresh temporary directory, uses synthetic credentials and
ephemeral keys, binds its HTTPS fixture only to loopback and removes its temporary tree.
It does not load WordPress, read wp-config or change the plugin's runtime dependencies.

The library probe uses injected synthetic HTTP responses; the independent cURL probe
uses an actual local HTTPS server. These two probes do **not** establish that a future
PSR-18 cURL adapter enforces the complete production network policy.

`PASS` means the named narrow capability was observed. `GAP` means the candidate/build
does not meet an integration requirement. Exit zero means the evaluation reproduced
its recorded outcomes, including known gaps; it is **not a passing OIDC security gate**.
If upstream behavior changes, review the affected expectation before updating the lock.
Do not move these gap assertions into production acceptance tests as successful logins.

`profile.php` first reproduces the default-API gaps, then exercises the configured
`StrictVerifierBuilder`/`StrictCodeFlow` adapter: valid results and 56 rejection cases.
The runner checks both adapter classes with WPCS and PHPStan level 8. The adapter
uses library claim verification/checkers, requires a code-only callback and translates
errors without exposing library exceptions. It is evaluation code, not a usable
callback: browser binding, expiry, atomic consumption, raw duplicate parsing and
local session/link policies are still absent.

The runner reproduces unmodified upstream `Compose` failing on a virtual package,
then builds with the corrected project adapter. The scoped consumer repeats its
signed-token/profile tests without a vendor fallback, with foreign symbols before/after
and with real original libraries loaded first. Eager functions, conditional native
stubs, optional AES-KW and licensing metadata/notices are also checked. Unknown autoload
forms remain rejected by separate build/unit tests; see
[ADR 003](../../../docs/ADR-003-mozart.md#virtual-packages-and-oidc-scoping-2026-11-10).

For a local second interpreter, use
`SWSL_SPIKE_PHP=/usr/bin/php8.2 composer test:oidc-spike`. CI uses its configured PHP
8.2/8.3 matrix. Default-behavior `GAP` lines remain visible for comparison; the
configured adapter and scoped artifact must pass. Remaining production integration
gates are in [ADR 008](../../../docs/ADR-008-oidc-library-transport-spike.md).
