=== SW Securelogin ===
Contributors: swarnat
Requires at least: 6.7
Requires PHP: 8.2
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modular authentication for WordPress. Passkey enrollment development preview.

== Description ==
Register, name and independently revoke up to ten passkeys in your own WordPress profile.
Passkey login is not implemented yet. Existing password login remains available.
Enrollment is disabled by default and requires an explicit administrator choice.
Single-site installations only. No external services, external assets or telemetry.
Requires PHP OpenSSL, HTTPS and a stable MySQL/MariaDB primary connection supporting named locks.

== Installation ==
For a source checkout, run composer install from the plugin directory before activation.
For an isolated production build, run composer build:prefixed on a build machine with
Docker, then use build/sw-securelogin as the plugin directory.
Open SW Securelogin as an administrator to apply database migrations.
Enable passkey registration in the overview, then open Users > Profile > My passkeys.
Confirm the current password separately for every enrollment, rename or revocation.

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
The release security review, real-device/browser matrix and passkey login remain future work.
