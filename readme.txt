=== SW Securelogin ===
Contributors: swarnat
Requires at least: 6.7
Requires PHP: 8.2
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modular authentication for WordPress. Passkey login development preview.

== Description ==
Register, name and independently revoke up to ten passkeys in your own WordPress profile.
Sign in from the WordPress login page with your username or email address and a discoverable passkey.
Existing password login remains available via "Log in with password".
Enrollment is disabled by default and requires an explicit administrator choice.
Single-site installations only. No external services, external assets or telemetry.
Requires PHP OpenSSL, HTTPS and a stable MySQL/MariaDB primary connection supporting named locks.

== Installation ==
For a source checkout, run composer install from the plugin directory before activation.
For an isolated production build, run composer build:prefixed on a build machine with
Docker, then use build/sw-securelogin as the plugin directory.
Open SW Securelogin as an administrator to apply database migrations.
Enable passkey registration and login in the overview, then open Users > Profile > My passkeys.
Confirm each enrollment, rename or revocation with your password or an existing passkey.
Older non-discoverable keys need re-enrollment for login; password recovery stays available.
Unknown authentication extensions block passkey login until a compatibility adapter is reviewed.

== Configuration ==
The relying-party ID comes from the configured HTTPS site URL. Enabling pins the
exact origin, including a non-default port. Domain changes require a separate migration.
WordPress must recognize HTTPS; configure trusted reverse proxies at the WordPress layer.
Enrollment requires the REST endpoint to be accessible with the current cookie session.
Cross-origin REST installations and database proxy failover are not validated yet.
SAVEQUERIES must be disabled to keep challenge payloads out of SQL debug logs.
Third-party request/SQL logging must not record passwords, challenges or proof tokens.

== Privacy ==
Stores public credential IDs and public keys, random user handles, names, timestamps,
signature counters, AAGUIDs, backup flags and browser transport hints in local tables.
No private passkey keys or biometric data are received or stored by this plugin.
Reauthentication and enrollment use hashed one-time tokens bound to the current session.
Login uses a secure browser-binding cookie and a two-minute one-time transaction.
Identifier-free aggregate authentication counters expire at the daily boundary.
Proof expires within five minutes; enrollment expires within two minutes and cannot
outlive its proof. Expired rows and counters are removed by an hourly WP-Cron task.
Cleanup requires WP-Cron to run; expired rows cannot be used even if cleanup is delayed.
WordPress personal-data export/erasure and user-deletion cleanup are provided, also
while enrollment is disabled. See docs/PRIVACY.md for details.

== Removal ==
Deactivation and uninstall retain tables, options and registered credentials.
Data cleanup pauses while the plugin is inactive. Password login and sessions are preserved.
Automatic destructive uninstall is not provided in this preview.

== Development ==
See docs/TESTING.md, docs/ADR-004-passkey-enrollment.md and TODO.md.
The release security review, real-device/browser matrix and actual MFA-plugin adapters remain future work.
Passkey login decisions and limits: docs/ADR-005-passkey-login.md.
