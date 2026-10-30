# Development checks

Install development dependencies with `composer install`, then run:

```sh
composer validate --strict
composer lint
composer cs
composer analyse
composer test
composer audit
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
PHP 8.2/8.3 and MariaDB 11.1.3; CI jobs are configured but have not been executed from this session.

M1 includes virtual-authenticator browser enrollment tests. Real devices, additional
browsers, security-plugin/MFA compatibility, multisite and release ZIP smoke tests remain
later milestone gates. Passkey login is not implemented yet.

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


# Browser enrollment tests, M1

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
module disable without session loss. An existing cached browser binary may be selected
with SWSL_CHROMIUM. Browser certificate-error tolerance applies only to the disposable
test context. Production plugin code continues to require recognized HTTPS.

For the scoped artifact, point the disposable plugin symlink at build/sw-securelogin
before running integration and browser tests. This is the variant configured in CI.
Do not run these stateful suites concurrently against the same disposable database.

Local M1 verification: 25 unit tests and 25 integration tests passed on both PHP 8.2
and PHP 8.3 with WordPress 6.7 / MariaDB 11.1.3. Chromium E2E and Mozart protocol smoke
tests also pass. GitHub CI, MySQL, latest WordPress, real devices and other browsers
remain separately unverified; no production-conformance claim is made.
