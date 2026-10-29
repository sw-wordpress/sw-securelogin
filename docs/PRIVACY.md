# Passkey enrollment data

The plugin stores passkey public keys and IDs, a random account handle, a chosen label,
registration/last-use times, counters, AAGUIDs, backup flags and optional browser transport
hints in the site's database. Private passkey keys and biometric data stay outside this
plugin. Names and AAGUIDs do not prove a device's identity. No external verification or
metadata service is called, and no telemetry is collected.

Fresh-password proof and enrollment context use short-lived, single-use transactions.
Only token hashes are stored. Session binding is a salted fingerprint, not a raw session
cookie. Proof lasts up to five minutes and enrollment up to two minutes, capped by proof
expiry. Rate-limit buckets contain hashed purpose/user/window identifiers. Their retention
ends on hourly WP-Cron cleanup once expired. If cron is delayed, records remain stored
longer but are unusable for authentication after expiry. Inactive plugins cannot run cleanup.

WordPress's verified personal-data export includes credential management metadata, without
keys, raw session tokens or pending proof data. Its eraser removes credentials, handles
and pending transactions for the account. Deleting a WordPress user performs the same
cleanup. These hooks stay active when enrollment is disabled in plugin settings.

Passwords are checked through WordPress and are never persisted by this plugin. Existing
WordPress password recovery remains available. Passkey login is not yet implemented.
Deactivation and uninstall preserve all plugin data; an explicit destructive-uninstall
policy remains future work. Backups and database administrator logging follow the site's
own retention policy. Disable SAVEQUERIES and avoid third-party logging of auth requests
or transaction SQL. Never include proof tokens, challenges or passwords in support logs.
