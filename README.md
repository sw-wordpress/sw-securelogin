# SW Securelogin

[![Release](https://img.shields.io/github/v/release/sw-wordpress/sw-securelogin?sort=semver)](https://github.com/sw-wordpress/sw-securelogin/releases)
[![CI](https://github.com/sw-wordpress/sw-securelogin/actions/workflows/quality.yml/badge.svg)](https://github.com/sw-wordpress/sw-securelogin/actions/workflows/quality.yml)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=sw-wordpress_sw-securelogin&metric=coverage)](https://sonarcloud.io/summary/overall?id=sw-wordpress_sw-securelogin)
[![SonarQube Issues](https://sonarcloud.io/api/project_badges/measure?project=sw-wordpress_sw-securelogin&metric=vulnerabilities)](https://sonarcloud.io/project/issues?id=sw-wordpress_sw-securelogin&impactSoftwareQualities=SECURITY&resolved=false)
![PHPStan level 8](https://img.shields.io/badge/phpstan%20level-8%20of%209-brightgreen?style=flat-square&logo=php)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?style=flat-square&logo=php&logoColor=white)
![WordPress 6.7+](https://img.shields.io/badge/WordPress-6.7%2B-21759b?style=flat-square&logo=wordpress&logoColor=white)
[![License GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)

Adds [passkey](https://en.wikipedia.org/wiki/Passkey_(credential)) login to the WordPress login page.
Password login stays available. Preview, disabled until an administrator enables it.

## Features

- **Passkey login** — Sign in on the standard WordPress login page with username or email plus passkey.
- **Up to ten passkeys per user** — Each user adds, renames and removes their own passkeys in the profile.
- **Password fallback** — Password login keeps working; disabling the plugin never locks anyone out.
- **No external services** — All data stays in your WordPress database; no tracking.

## Languages

English, German.

## Usage

1. Open **SW Securelogin → Overview**, tick **Enable passkey registration and login** and save.
2. Each user opens **Users → Profile → My passkeys**, confirms with the current password and clicks **Add passkey**.
3. On the login page, enter the username or email address and click **Sign in with a passkey**.
   **Log in with password** remains available.

## Configuration

### Requirements

PHP 8.2+ with OpenSSL, WordPress 6.7+, MySQL or MariaDB, single-site installation, HTTPS.

### Settings

The only setting is the switch under **SW Securelogin → Overview**. Enabling it binds passkeys
to the current site address; changing the domain later is not supported yet.

The plugin has no constants of its own. These existing `wp-config.php` settings matter:

```php
// Must be off (default). Passkeys are disabled while SQL query logging is active.
define( 'SAVEQUERIES', false );

// Behind a reverse proxy or load balancer, tell WordPress the request is HTTPS
// (only if the proxy is trusted and always sets this header):
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
	$_SERVER['HTTPS'] = 'on';
}
```

### Background tasks

Expired login attempts are removed hourly by WP-Cron. On sites with little traffic or with
`DISABLE_WP_CRON`, run WordPress cron from the server, e.g. `*/15 * * * * wp cron event run --due-now --path=/path/to/wordpress`.

### Deactivation and removal

Deactivating or deleting the plugin keeps all registered passkeys and settings; users simply
sign in with their password again. Personal data can be exported or erased with the WordPress
privacy tools, and a user's passkeys are removed when the user is deleted.
Details: [docs/PRIVACY.md](docs/PRIVACY.md).

## Troubleshooting

| Problem | Solution |
|---|---|
| The passkey button does not appear. | Check that the site runs on HTTPS, `SAVEQUERIES` is off and the feature is enabled. |
| Notice about a "reviewed passkey adapter". | Another security or login plugin hooks into the login. Passkey login stays blocked for safety; use the password. |
| Lost device. | Sign in with your password and remove the lost key under **My passkeys**. |

## Built with AI

This plugin was implemented with AI assistance. Why it exists and how AI is used:
[sw-wordpress manifest](https://github.com/sw-wordpress/.github/blob/main/profile/README.md).

AI agents must follow the project rules before changing code:

- Rules and mandatory coverage: [AGENTS.md](AGENTS.md), [CODE-STYLE.md](CODE-STYLE.md)
- Architecture and milestones: [PLAN.md](PLAN.md), current status: [TODO.md](TODO.md)
- Decisions and security: [docs/](docs/) (ADRs, [THREAT-MODEL.md](docs/THREAT-MODEL.md))
- README structure: [docs/README-STANDARD.md](docs/README-STANDARD.md)

## Development

Requirements: PHP 8.2+, Composer, Node.js with npm, and Docker for the production build.
JavaScript and CSS are plain files; there is no frontend compile step.

```sh
composer install
npm ci --ignore-scripts
npx playwright-core install --with-deps chromium   # browser tests only

composer lint && composer cs && composer analyse   # syntax, WordPress coding standards, PHPStan
composer test                                      # unit tests
composer test:integration                          # needs a disposable WordPress, see docs/TESTING.md
npm run lint && npm run test:e2e                   # JavaScript checks and browser tests

composer build:prefixed                            # production build in build/sw-securelogin
```

Every change needs tests; line coverage must stay above 80 % for PHP and JavaScript.
Setup of the test site, coverage and SonarQube: [docs/TESTING.md](docs/TESTING.md),
[docs/SONARQUBE.md](docs/SONARQUBE.md). GitHub Actions run all checks on every push.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) — see [LICENSE](LICENSE). Provided by Stefan Warnat.

Bundled third-party components (license texts in `vendor-prefixed/licenses/` of the release build):

- [lbuchs/webauthn](https://github.com/lbuchs/WebAuthn) — MIT
