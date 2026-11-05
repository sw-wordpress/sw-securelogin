=== SW Securelogin ===
Contributors: swarnat
Tags: passkeys, webauthn, login, security
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sign in to WordPress with a passkey. Password login remains available.

== Description ==

Development preview: release 1.0 acceptance checks are still in progress.
Enable passkeys under SW Securelogin > Overview. Users can register up to ten
passkeys, rename them and revoke them in Users > Profile > My passkeys.
Sign in with a username or email address and a passkey on the WordPress login page.

Requires HTTPS, PHP with OpenSSL, and a single-site WordPress installation.
The browser and authenticator must support discoverable passkeys and user verification.
No telemetry, external verification service or third-party resources are used.
Private keys and biometric data are never collected by this plugin.
The site stores public credential data and short-lived authentication transactions;
details and retention rules are in docs/PRIVACY.md in the plugin package.

Unknown authentication extensions block passkey authentication until a compatible
adapter is reviewed. This preview does not claim general MFA-plugin compatibility.
Passwords and WordPress password recovery remain available.

== Installation ==

1. Upload the production ZIP through Plugins > Add New > Upload Plugin and activate it.
2. Enable passkeys under SW Securelogin > Overview.
3. Open Users > Profile > My passkeys and add a passkey after confirming your password.
4. Log out, enter your username or email and choose Sign in with a passkey.

== Frequently Asked Questions ==

= What if I lose my passkey? =

Choose Log in with password and use WordPress password recovery if necessary.
Remove the lost passkey from your profile after signing in. Administrators with
server access can follow docs/OPERATIONS.md for emergency recovery.

= Can I change my domain? =

Credentials are bound to the configured HTTPS origin and RP host. A changed domain
or origin blocks passkeys. Follow docs/OPERATIONS.md and register new keys on the
new domain. Never rewrite credential identifiers or blindly reset the origin pin.

= Is multisite supported? =

No. Authentication modules remain disabled on multisite installations.

= Does deactivation remove data? =

No. Deactivation preserves data and existing WordPress sessions. See
docs/OPERATIONS.md for removal policy, backups and upgrade instructions.

= Does deleting the plugin remove passkeys? =

Data is kept by default. Under SW Securelogin > Overview > Data on plugin deletion,
an administrator can explicitly enable permanent removal and confirm the warning.
WordPress deletion then removes this plugin's passkeys, handles, transactions, rate
limits and settings. WordPress accounts, passwords and sessions remain. Back up first.
The setting can be withdrawn before deactivation; multisite cleanup is unsupported.

== Changelog ==

= 0.3.0 =
* Development preview: passkey enrollment, management, login and reauthentication.
* M3 release preparation; version 1.0 remains gated on acceptance checks.
