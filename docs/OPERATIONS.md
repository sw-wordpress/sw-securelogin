# Operating SW Securelogin

This preview supports single-site installations. Before enabling it, retain a tested
administrator password, a working WordPress password-reset mailbox and server access.
Register two independent passkeys and test each in a separate logged-out browser.
Passkeys never replace your recovery procedure. See [privacy](PRIVACY.md) for stored
data and retention and [release gates](RELEASE.md) for verification limits.

## Upgrade, backup and rollback

1. Back up the complete WordPress database, plugin directory and server configuration.
   Protect backups as personal data; apply your normal retention and access rules.
2. Restore the backup in an isolated staging environment. Disable outbound mail and
   background jobs there. Use passwords on a different staging domain: production
   passkeys must not be rebound to it.
3. Install the candidate ZIP, activate it and visit the overview as an administrator
   to run versioned migrations. Confirm HTTPS, the origin pin and any compatibility
   warning. Test password login, both keys, revocation, cancellation and recovery.
4. Upgrade production only after the release checks and staging tests pass. Keep the
   previous package and matching database backup. Deactivation preserves data and
   sessions; users can continue with passwords.

Migrations are forward-only. Do not lower `swsl_schema_version` or edit tables to
force a downgrade. Roll back the plugin files and the matching database snapshot
together in a maintenance window. A full database restore also restores old user,
session and credential state: revoke keys/sessions that became invalid after the
snapshot and check accounts created or changed since it. Test restored password
and passkey access before reopening the site.

## Administrator recovery

Use WordPress password recovery first. After password login, revoke a lost key in
your own profile. For a site administrator with trusted server access, run these
commands against the intended WordPress path (first take a database backup):

```sh
wp plugin deactivate sw-securelogin --skip-plugins --skip-themes --path=/path/to/wordpress
wp user list --role=administrator --fields=ID,user_login --skip-plugins --skip-themes --path=/path/to/wordpress
wp user update ADMIN_ID --prompt=user_pass --skip-plugins --skip-themes --path=/path/to/wordpress
```

Deactivation stops this plugin's cleanup job and passkey routes without destroying
credentials or existing sessions. The password prompt avoids putting a password in
shell history. Server access is the authorization boundary: never expose this as a
web endpoint. An independent MFA/security plugin still enforces its own recovery
policy; disabling SW Securelogin does not remove another plugin's MFA challenge.
Investigate a compatibility warning before re-enabling passkeys.

## Reverse proxies, caching and separate URLs

Terminate TLS at a trusted proxy, prevent direct public access to the origin server
and ensure WordPress recognizes HTTPS. Only the server administrator may map a
proxy header to `HTTPS=on`, after validating the actual peer against the deployment's
trusted proxy list. The plugin never trusts `X-Forwarded-Host`, `X-Forwarded-Proto`
or client-supplied `Host` to choose a passkey RP. It derives the exact origin from
the configured WordPress `siteurl`, including a non-default port.

Preserve `Origin`, secure cookies, request bodies and POST/PATCH/DELETE methods.
Exclude the login page, profile and `sw-securelogin/v1/passkeys` REST routes from
CDN/page/proxy caching and request-body/SQL logging. Responses include `no-store`.
Do not enable `SAVEQUERIES`. Rate limits use `REMOTE_ADDR`; users behind the same
proxy share a peer budget. Do not change this to trust arbitrary forwarded IPs.

`home` may have another path/domain, but login, admin, passkey REST endpoints and
assets must remain on the pinned HTTPS origin. A cross-origin REST URL is unsupported.
Check rewritten login URLs on the actual proxy stack; changing only a path must
not change the origin pin. See [compatibility results](RELEASE.md).

## Domain, port and RP migration

Changing the configured origin or RP host deliberately disables passkeys. A new
subdomain, scheme or port is a migration, even if a credential might technically
work with a broader RP policy. No automatic rewriting or multi-origin trust exists.

Before moving, test passwords and recovery for every affected account and back up
the database. While the old origin still works, have users revoke their old keys
through their profiles. An administrator must verify that no old plugin credentials
remain before proceeding. Then disable passkeys, move the site and verify password
login over HTTPS. An administrator can now delete `swsl_passkeys_rp` with WP-CLI and
enable the module to pin the new origin; users enroll new keys there.

Old credentials cannot be transferred to a different RP. If any remain or users
cannot remove them through the old site's profile, keep password-only operation
and arrange a reviewed, backed-up database cleanup before resetting the pin.
Management UI is unavailable when the module is disabled or its pin mismatches;
plan revocation before moving. Do not copy a credential row or modify its ID/public
key to migrate it. Restoring the old domain and matching database is the rollback path.

Multisite/network activation is unsupported. The plugin shows a notice and does
not register authentication modules or migrate network sites. Disable it before
converting a single site into a network; plan network data cleanup separately.

## Removal

Deactivation always retains data. The default uninstall behavior preserves settings,
public credentials, handles and transient transaction/rate tables, and removes only
the scheduled cleanup hook. Retained authentication transactions expire and cannot
be used after their deadlines. WordPress privacy erasure and user deletion remove
the affected account's keys while the plugin is installed. Backups are separate
and are governed by the site's retention policy.

For complete removal on a supported single site:

1. Make a database backup and verify password login/recovery. In SW Securelogin >
   Overview > Data on plugin deletion, explicitly check the permanent-deletion option.
2. Save and accept the additional browser confirmation. Cancelling keeps the saved
   policy unchanged. Without JavaScript the explicit checkbox and Save still work.
   To withdraw consent, uncheck and save before deactivating the plugin.
3. Deactivate, then choose Delete in the WordPress plugin list and confirm WordPress's
   deletion prompt. Deactivation alone never removes data. A disabled plugin cannot
   reliably add its own deletion dialog, so consent is saved while it is active.

Uninstall removes exactly the current site's `swsl_passkeys`, `swsl_user_handles`,
`swsl_transactions` and `swsl_limits` tables and the `swsl_settings`,
`swsl_passkeys_rp`, `swsl_schema_version` and `swsl_delete_data_on_uninstall` options.
It leaves WordPress users, passwords, sessions, unrelated tables/options and backups
alone. The saved policy also applies to WP-CLI uninstall; manually removing files
does not run WordPress uninstall. Network cleanup is refused even if an earlier
single-site installation saved consent. Do not convert to multisite to remove data.

Removal failures stop uninstall with a neutral error and preserve consent for retry.
DDL is not transactional: some tables may already be gone. Keep the plugin files,
fix the database failure and retry deletion, or restore the full matching backup
before using the plugin again. Do not reactivate after a partial removal.
