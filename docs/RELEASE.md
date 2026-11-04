# Release 1.0 acceptance record

M3 is in progress. The plugin remains a 0.3.0 development preview until every
release gate below has evidence. Building a candidate does not publish a release.

## Build a candidate

Requirements: PHP 8.2+ with ZipArchive/OpenSSL, Composer 2, Bash, Docker and the
pinned Mozart image. From the same checkout and locked dependencies:

```sh
composer test:release
composer build:release
sha256sum -c build/sw-securelogin-0.3.0.zip.sha256
```

The build uses an explicit production file allowlist and `composer install --no-dev`.
The packager rejects symlinks, unexpected root files, development directories and
private-key/log files; it requires matching plugin/asset/readme versions. Entries
are sorted with fixed UTC timestamps, 0644 permissions and no compression. Identical
production trees produce byte-identical ZIPs regardless of file mtimes/modes.
The wrapper packages twice, compares hashes and tests bootstrap and signed WebAuthn
fixtures from the extracted ZIP. Full reproducibility also requires the same Composer,
Mozart image and dependency archives; record tool versions and the image digest.
The checksum is for integrity, not a signature or proof of release authorization.

Install the **ZIP**, not a source symlink, in a fresh disposable WordPress installation.
Run registration, login with two keys, revocation, password recovery, disable/reactivate,
privacy erasure and removal. Integration tests must point at the extracted artifact;
coverage must still be collected from the original sources. See [testing](https://github.com/sw-wordpress/sw-securelogin/blob/main/docs/TESTING.md).

## Gates and verification limits

| Gate | Required evidence |
| --- | --- |
| Code quality | PHP lint, WPCS, PHPStan 8, unit/integration tests; PHP and JS each >80% coverage |
| Security | Review auth, CSRF/permissions, ownership, origin/RP, replay/expiry, policies/session rollback, SQL, logs and deletion; no unresolved High/Critical findings |
| Dependency audit | No known advisories in shipped packages; record locked versions and audit date |
| Plugin Check | Run all checks on extracted artifact; review every finding, including runtime checks |
| Matrix | WordPress 6.7 / PHP 8.2 / MySQL 8.0 and current WordPress / PHP 8.3 / MariaDB 11.4 |
| Browser | Chromium automation plus physical-device registration/login on target Chrome/Firefox/Safari versions |
| Compatibility | WP Armour, renamed login path, an actual MFA plugin; demonstrate fail-closed behavior for unsupported policies without disabling password/MFA recovery |
| Deployment | Multisite refusal, untrusted forwarded headers, pinned-domain/port changes, trusted TLS termination and cache exclusion |
| UX | English/German catalogs complete; keyboard, labels, focus/status, 390px and desktop layout; human screen-reader check |
| Operations | Tested backups, matching database rollback, administrator recovery and explicit uninstall policy |
| Distribution | Repeatable ZIP, license notices, no dev dependencies, fresh-site ZIP enrollment/login smoke test |
| Remote | CI matrix and SonarQube quality gate for the final commit |

Physical devices, human screen-reader checks and deployment-specific proxy/MFA stacks
cannot be established by virtual authenticators. Record actual versions and outcomes;
never infer them from unit tests or a prior milestone's CI run. This record is not an
independent security audit. The final 1.0 tag and publication require these gates.


## Local candidate evidence, 2026-11-04

- 56 unit tests and 65 integration tests pass. PHP line coverage: 89.44%; JavaScript:
  95.9%. PHPStan 8, WPCS, PHP/JS lint, Composer validation, actionlint, scoped dependency
  collision/protocol tests and production dependency audits pass.
- Installed ZIP: WordPress 6.7 / PHP 8.2.27 / MySQL 8.0 and WordPress 7.1.4 /
  PHP 8.3.16 / MariaDB 11.4.12. Full Chromium virtual-authenticator flows pass in fresh
  ZIP installations. Chromium: 156.0.8078.4; Node: 22.22.3. Browser tests ran on the host;
  databases and Mozart ran in Docker. CI changes are locally linted, not remotely run.
- WP Armour 2.4.02 with WPS Hide Login 1.9.19 and `/secure-entry`: complete browser
  flow passes. Two Factor 0.14.2: unreviewed policy/session issuance is blocked without
  creating a session. No MFA adapter is asserted. Actual multisite refusal passes.
- English/German source catalogs and MO compilation agree. No-script/no-WebAuthn
  fallback, keyboard enrollment and 390px/desktop checks pass.
- Plugin Check 2.1.0: readme header corrected; two reviewed warnings remain visible.
  Details: [implementation review](https://github.com/sw-wordpress/sw-securelogin/blob/main/docs/SECURITY-REVIEW-M3.md).

Release remains gated on physical devices, Firefox/Safari, human screen-reader review,
restore/proxy deployment evidence, the explicit uninstall-policy decision and the final
remote CI/SonarQube result. See [operations](OPERATIONS.md) before acceptance testing.
