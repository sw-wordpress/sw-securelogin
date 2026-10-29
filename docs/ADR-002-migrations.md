# ADR 002: Resumable migrations and failure handling

Status: accepted for the M0 foundation.

Migrations are consecutive, immutable steps, starting at version one. Each step must
be idempotent and verify its own postconditions. Only then may the runner advance
`swsl_schema_version`. A failure preserves completed steps and retries the failed
step on the next privileged administration request. MySQL DDL can commit implicitly;
this mechanism does not promise transactional rollback. Downgrades and malformed
version markers fail closed. Back up before a production upgrade.

The first migration creates the non-autoloaded `swsl_settings` option with no enabled
modules. Existing settings are preserved. No production tables exist in M0; M1 will
supply dbDelta migrations for actual passkey data. Integration fixtures already test
creation, additive upgrades, row preservation, and repeat execution using dbDelta.

The runner takes a zero-wait, database-and-prefix-scoped GET_LOCK and releases it in
finally. An independent connection cannot migrate while it is held. Expiring option
locks were rejected because stale-lock takeover can permit concurrent DDL. The host
must support MySQL/MariaDB named locks on a stable primary connection. Database
proxies, reconnection, and multi-primary deployments require separate compatibility
validation. No unsafe lock fallback is provided. This infrastructure is not a
one-time challenge repository and must not be used as WebAuthn replay protection.

Version reads bypass the WordPress options cache. Updates use the WordPress API and
are verified against the database. Driver exception messages and SQL are never
returned to the browser. Administrator notices report a neutral failure. Migration
success must gate any future module startup: M0 contains no authentication modules
or authentication hooks. No sessions are deleted by migration or deactivation.

Tests use a disposable WordPress installation with a dedicated database and table
prefix. They cover contention on a second connection, failure/resume, version write
failure, malformed/newer state, invalid sequence, settings preservation, capabilities,
password authentication and session preservation across deactivation.

References:

- https://developer.wordpress.org/plugins/creating-tables-with-plugins/
- https://developer.wordpress.org/reference/functions/dbdelta/
- https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/get_lock
