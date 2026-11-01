# Development checks

Install development dependencies with `composer install`, then run:

```sh
composer validate --strict
composer lint
composer cs
composer analyse
composer test
composer audit --locked --no-dev
```

`composer test` is isolated from WordPress. Integration tests use an actual disposable
WordPress site and database, not the development site's configuration. They require
PHP mysqli and the installed Composer development dependencies. The bootstrap refuses
any database name other than `swsl_m0_test` or prefix other than `swsltest_`.
Never point it at a site containing valuable data. It creates/drops a fixture table,
resets plugin options, creates/destroys a test session, and toggles plugin activation.
Run against a fresh database, without other plugins or persistent caching drop-ins.

With a disposable MySQL/MariaDB server available, install WordPress with WP-CLI:

```sh
export SWSL_TEST_WORDPRESS=/tmp/swsl-test-wordpress
wp core download --path="$SWSL_TEST_WORDPRESS" --version=6.7
wp config create --path="$SWSL_TEST_WORDPRESS" --dbname=swsl_m0_test --dbprefix=swsltest_ --dbuser=YOUR_TEST_DB_USER --dbhost=YOUR_TEST_DB_HOST --prompt=dbpass
wp core install --path="$SWSL_TEST_WORDPRESS" --url=http://swsl.test --title='Securelogin integration' --admin_user=swsl-test-admin --admin_password=swsl-disposable-test-password --admin_email=test@example.org --skip-email
ln -s "$PWD" "$SWSL_TEST_WORDPRESS/wp-content/plugins/sw-securelogin"
wp plugin activate sw-securelogin --path="$SWSL_TEST_WORDPRESS"
composer test:integration
```

Create the dedicated database before installation. These admin credentials are fixtures
for a local disposable site only. CI provisions MySQL 8.0 / WordPress 6.7 / PHP 8.2 and
MariaDB 11.4 / latest WordPress / PHP 8.3. The local verification used WordPress 6.7,
PHP 8.2/8.3 and MariaDB 11.1.3; CI matrix verification is recorded in TODO.md; M2 changes still need their next pushed CI run.

M1 includes virtual-authenticator browser enrollment tests. Real devices, additional
browsers, security-plugin/MFA compatibility, multisite and release ZIP smoke tests remain
later milestone gates. Passkey login and existing-passkey reauthentication are covered by M2 tests.

# Mozart build and isolation tests

Requirements on the build machine: PHP 8.2+, Composer, Bash and Docker access.
WordPress installations do not need Docker or Mozart.

```sh
composer test:mozart
composer build:prefixed
```

The configuration follows sw-backupit, with Mozart pinned to the Docker image
`coenjacobs/mozart:1.2.3`. Production dependencies use the namespace
`SW\Securelogin\Dependencies\` in `vendor-prefixed/`. The complete staged plugin is
written to `build/sw-securelogin/`; source files and development dependencies remain
unchanged. The bootstrap registers the plugin's own PSR-4 classes on the generated
loader. No original vendor directory or development tooling is shipped in that tree.

The isolated fixture test installs one local synthetic dependency with Packagist
disabled, runs the real Mozart build, removes the original dependency tree, and checks
that an incompatible class from another plugin cannot affect our dependency or its
caller. It also checks loader precedence and rejection of an unreviewed autoload
form. The build itself checks the actual plugin entry point and core hook setup.
See [ADR 003](ADR-003-mozart.md) for current autoload restrictions and required review
of other future cryptography libraries. The production build additionally verifies the
real WebAuthn library with Chromium enrollment and signed-assertion fixtures, including
a modified-signature rejection and isolation of binary serialization settings. Dependency
license notices are retained and checked.

To run WordPress integration tests against the build, use the same disposable site
setup described above and point its plugin symlink at `$PWD/build/sw-securelogin`
instead of the source checkout. The CI WordPress jobs use this staged variant.


# Browser enrollment and login tests, M1/M2

Use only the disposable WordPress/database setup above. Install development browser
tools separately; they are excluded from plugin builds:

```sh
npm ci --ignore-scripts
npx playwright-core install --with-deps chromium
npm run lint
export SWSL_TEST_WORDPRESS=/tmp/swsl-test-wordpress
npm run test:e2e
```

The harness creates a temporary self-signed test certificate and starts a PHP server
on 127.0.0.1:8089 with an HTTPS proxy on 127.0.0.1:8443. Keep these ports free. It removes
its certificate and stops both servers on completion. The test resets the fixture
administrator's passkeys, transactions, module configuration and rate counters, and
sets the disposable site's URLs to https://localhost:8443. It never uses the development
site's WordPress configuration. Database/prefix checks run before fixture mutations.

Chromium uses virtual CTAP2 authenticators with user verification. Tests cover two
independent registrations, rename, separate revocation, password-field clearing and
module disable without session loss, two-key login, passkey reauthentication, cancellation,
wrong usernames, replay, revoked-key rejection and password fallback. Background cron,
core HTTP updates/feeds and external browser requests are disabled in this disposable
harness so unrelated services do not affect authentication tests. An existing cached browser binary may be selected
with SWSL_CHROMIUM. The harness pins its temporary test certificate in Chromium; standalone
execution without that pin tolerates certificate errors only in its disposable context. Production plugin code continues to require recognized HTTPS.

For the scoped artifact, point the disposable plugin symlink at build/sw-securelogin
before running integration and browser tests. This is the variant configured in CI.
Do not run these stateful suites concurrently against the same disposable database.

Local M1 verification: 25 unit tests and 25 integration tests passed on both PHP 8.2
and PHP 8.3 with WordPress 6.7 / MariaDB 11.1.3. Chromium E2E and Mozart protocol smoke
tests also pass. GitHub CI, MySQL, latest WordPress, real devices and other browsers
remain separately unverified; no production-conformance claim is made.


# Manual profile UI checks after restyling

Open Users > Profile and hard-refresh the page. Confirm the password area, registration
card and credential list are separated clearly. Check an empty list and multiple keys;
register, rename and revoke one credential with a fresh password each time. A rejected
password should produce a visible error and focus the password field. Repeat at a narrow
viewport and with keyboard navigation. The E2E suite covers the existing management
workflow plus panel overflow and credential counts at desktop/mobile widths.


# Automatic SonarQube analysis

See [SONARQUBE.md](SONARQUBE.md) for GitHub secrets/variables, source coverage collection,
Clover merging and checks of the CI/quality-gate status. Coverage must be collected on
original PHP files; compiled Mozart builds are tested separately.

M2 policy, privacy and compatibility decisions: [ADR 005](ADR-005-passkey-login.md).

## Manual M2 acceptance

1. SW Securelogin > Overview: enable Passkeys. An adapter warning means passkey proof is deliberately blocked by an unreviewed authentication extension; password login must still work.
2. Users > Profile > My passkeys: register two independent discoverable keys, using your password for confirmation. Existing non-discoverable keys must be replaced after password login.
3. Log out; enter the username and choose Sign in with a passkey. Each key should reach the backend. Cancelling should leave password login usable.
4. In the profile choose Existing passkey for confirmation, then rename or add a key. Biometric/PIN confirmation should complete the change without a new login.
5. Revoke one key, log out and try it: generic failure; the other key and password should still work. Disable the module: its login button disappears and an existing session remains valid.

Repeat with real devices and target browsers; virtual Chromium tests do not establish physical-device or MFA-plugin interoperability.

Coverage gates: `composer coverage:merge && composer coverage:check` after PHP coverage;
`npm run test:e2e` writes `coverage/js/lcov.info` and checks JavaScript. Each language
must exceed 80%; see [SonarQube setup](SONARQUBE.md).

## Dependency security

Every push/PR fails on known advisories in shipped dependencies (`composer audit --locked --no-dev`,
`npm audit --omit=dev --audit-level=high`). `.github/workflows/security-audit.yml` audits all
dependencies, including dev tooling, daily and keeps one open `security-audit` issue (closed once
clean). `.github/dependabot.yml` opens weekly grouped PRs; Dependabot PRs get no secrets, so the
SonarQube job skips them. Enable Dependabot alerts and security updates in the repository settings.
Use Node 22 locally. PHPStan level 8 (`composer analyse`/`composer stan`)
covers `src`, `sw-securelogin.php` and `uninstall.php` without baseline; constants live in
`tests/phpstan/bootstrap.php`.
