# M3 implementation review, 2026-11-04

Scope: this checkout's passkey enrollment/login, WordPress policy/session boundary,
credential/transaction persistence, UI and release packaging. This is an implementation
review with automated evidence, not an independent audit or a claim about every deployment.
Physical authenticators, screen readers and deployment-specific MFA/proxy stacks remain
release gates in [RELEASE.md](RELEASE.md).

| Boundary reviewed | Evidence / outcome |
| --- | --- |
| Browser to REST | Bounded JSON, exact HTTPS Origin, nonce/cookie/capability requirements for management, neutral public failures and no-store responses; integration coverage includes rejection paths |
| WebAuthn proof | Library validation plus exact origin/embedding, credential owner/user handle, ES256 and signed backup state; signed fixtures exercise challenge, signature, RP, origin and UP/UV failures |
| Transactions | Random tokens stored as hashes; purpose, session/browser and expiry binding; affected-row DELETE arbitrates parallel consumption; replay/concurrency tests pass |
| Sessions | wp_signon identity guard, reviewed-hook boundary and cleanup after cookie veto/throwing hooks; failed proof never issues a valid session in tests |
| MFA compatibility | Real Two Factor 0.14.2 and 0.17.0 hooks make AuthPolicy and session issuance fail closed; session list and current user remain unchanged. This is refusal coverage, not a supported MFA adapter |
| Persistence / privacy | Prepared identifiers/values, credential uniqueness/limits, user locks around enrollment/login/revocation, privacy export/erasure, expiry cleanup; integration tests cover ownership and storage failures |
| Opt-in uninstall | Capability/nonce/POST-protected consent, strict flag, exact owned-table/option allowlists and authoritative deletion checks; failed option deletions clear misleading cache entries for retry. Installed-ZIP integration, browser confirmation, actual WP-CLI uninstall and actual network retention pass; see ADR 006 |
| Deployment | Origin pin survives forged Host/forwarded headers and rejects changed domain/port/scheme; actual multisite conversion registers no feature/migration hooks |
| UI / assets | Local escaped/translatable controls, labels/status/focus and password fallback; every enqueued asset uses SWSL_VERSION; browser checks include no-script/no-WebAuthn fallback and keyboard registration |
| Distribution | Explicit staging allowlist, no-dev install, scoped library protocol/collision tests, archive rejection tests and extracted-ZIP smoke tests |

No new Critical/High finding was identified within this review scope. Known limitations
are not waived: a custom plugin may enforce policy outside the hooks monitored by
AuthPolicy, and a site-specific integration still needs review. Never globally return
true on `swsl_passkey_auth_compatible` to hide a warning. SQL/request-body logging outside
this plugin, trusted proxy configuration and backup access remain operator concerns.

## Plugin Check findings

Plugin Check 2.1.0 ran against the installed production ZIP, including runtime checks.
The missing readme `Tested up to` header was corrected after testing WordPress 7.1.4.
The final installed ZIP has zero errors and two warnings, retained visibly without ignore flags or suppressions:

- `load_plugin_textdomainFound`: the ZIP bundles local English/German translations and
  is also installed outside WordPress.org. Explicit local translation registration at
  `init` is required for that distribution path; removing it merely to silence an
  advisory aimed at WordPress.org language packs would change supported behavior.
- `NonPrefixedHooknameFound` for `wp_authenticate_user`: applying the existing WordPress
  account policy is intentional. Renaming this core hook would bypass that policy.

These are reviewed scanner warnings, not reported security failures. Plugin Check
excludes vendor-prefixed files by default; the bundled library is covered separately
by Composer audit and the scoped WebAuthn protocol tests. Plugin Check is an automated
baseline, not a security audit. CI retains its full JSON report and fails on errors.
See the [official Plugin Check CLI](https://github.com/WordPress/plugin-check/blob/trunk/docs/CLI.md).

## Remaining decisions and external evidence

- The administrator approved opt-in removal on uninstall. Default retention and
  unconditional retention on deactivation remain in place. [ADR 006](ADR-006-uninstall.md)
  records exact deletion scope, consent checks, failure handling and multisite refusal.
  This subsequent implementation still needs its own remote CI/SonarQube analysis.
- The merged M3 commit `fc08548` passes remote CI and the SonarQube gate;
  the analysis includes the M3 changes. Two existing Critical findings remain open
  despite that passing gate (see below).
- Real devices, Firefox/Safari ceremonies, human assistive-technology checks, the operator's
  production backup restoration and a production-equivalent trusted proxy remain unverified.

Two complete production builds from the final source tree produced identical ZIP bytes
(`cmp` passed). The generated ZIP and its checksum are in `build/`; the final Plugin Check
JSON is retained as `build/plugin-check.json`. CI preserves these artifacts for 14 days.

The isolated MariaDB fixture was exported, its module setting changed, and the full
database imported again. A second deterministic SQL export exactly matched the first
(`cmp` passed), including retained credentials. This checks the documented restore
mechanics on fixtures; it does not certify the operator's backup system or data.


## Authenticated SonarQube MCP follow-up

Project `sw-wordpress_sw-securelogin`, organization `sw-wordpress`, main branch
`master`: latest available analysis `2026-11-04T22:09:00+0000`, gate `OK`.
All six conditions pass: new reliability/security/maintainability ratings A,
new-code coverage 96.0% (minimum 80%), new duplication 0.0% (maximum 3%),
new security hotspots reviewed 100.0% (required 100%). New-code coverage is not
whole-project coverage. The scanner log identifies revision
`fc085483ff2e2742de16e98fcdfa25e37a80d827`, the merged M3 implementation.
[CI run 41922137903](https://github.com/sw-wordpress/sw-securelogin/actions/runs/41922137903)
passes all six jobs, including both WordPress/database matrices, installed-ZIP checks,
Chromium, compatibility, multisite and coverage import. Whole-project PHP line coverage
is 89.54% (890/994); JavaScript is 95.9%. Plugin Check reports zero errors and the same
two reviewed warnings on both installed ZIPs.

The unresolved High/Blocker-impact issue search returned two existing issues,
both reported with legacy severity `CRITICAL` and rule `php:S4790`:

| Issue | Local context |
| --- | --- |
| `AaGNWpduFGk5ZHrok5N9` | `CredentialRepository::userLockName()`: SHA-224 of database name, table prefix and user ID creates a bounded MySQL lock name |
| `AaGNWpdpFGk5ZHrok5N7` | `MigrationRunner::run()`: SHA-224 of database name and table prefix creates a bounded migration lock name |

Both calls are present in the local tree. These digests identify connection locks;
they do not hash passwords, authentication tokens or credential data. The findings
therefore require contextual review rather than an automatic algorithm substitution.
Changing lock identifiers also requires considering concurrency with earlier plugin
versions. No algorithm, suppression or remote issue status was changed in this follow-up.
The issues remain open on SonarQube and block claiming zero unresolved High/Critical
findings for release 1.0, regardless of the passing new-code gate.
