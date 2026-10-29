# ADR 001: Foundation scope

Status: accepted for development; release requirements must be revalidated.

Start with M0. Use GPL-2.0-or-later, PHP 8.2+, WordPress 6.7+, Composer PSR-4,
and single-site activation. No production runtime library is needed yet. Evaluate
WebAuthn libraries and compatibility before M1; do not scope cryptographic dependencies blindly.
Mozart build infrastructure is now configured; see ADR 003 for its tested scope.
No OAuth server, role policy, secret storage, or multisite implementation is included.

The alternative of immediately exposing partial authentication routes would create an
unreviewed trust boundary. No login hooks, cookies, routes, credential data, or settings
forms are introduced. Only migration-owned defaults are persisted. Modules default to disabled and cannot register until available
and dependency checks succeed. Disabling on a subsequent request stops hook registration;
modules must not delete sessions or data as a side effect.

WPCS applies; PSR-4 filenames and camelCase class methods are the two intentional OOP
exceptions. Formatting is otherwise WordPress style. Runtime classes reject direct access.

The M0 migration initializes swsl_settings with an empty enabled_modules list. The
versioned migration runner is verified against a real WordPress database, including a
dbDelta fixture upgrade. Production passkey tables remain part of M1. See ADR 002 for
locking and failure handling. Uninstall retains data; explicit deletion must be designed first.

Verification: registry negative cases, duplicate IDs, repeated startup, unauthorized admin
render, PHP lint, WPCS, PHPStan and PHPUnit. CI runs on PHP 8.2 and 8.3.
