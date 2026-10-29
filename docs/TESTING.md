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
PHP 8.3 and MariaDB; CI jobs are configured but have not been executed from this session.

There are no browser login flows in M0. WebAuthn device tests, security-plugin/MFA
compatibility, multisite and release ZIP smoke tests are later milestone gates.

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
of future WebAuthn/cryptography libraries. These tests do not validate WebAuthn yet.

To run WordPress integration tests against the build, use the same disposable site
setup described above and point its plugin symlink at `$PWD/build/sw-securelogin`
instead of the source checkout. The CI WordPress jobs use this staged variant.
