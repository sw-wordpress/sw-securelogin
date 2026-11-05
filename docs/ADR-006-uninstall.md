# ADR 006: Explicit data removal on uninstall

Status: accepted for M3 following the administrator's policy decision.

Deactivation and uninstall retain data by default. An administrator with
`manage_options` can explicitly opt into removal on a supported single site.
The separate POST form requires a WordPress nonce, accepts only the literal
checkbox value and verifies persistence directly against the database, since WordPress
may cache an option as missing even when its DELETE fails. Its non-autoloaded option is separate
from module settings, so enabling/disabling passkeys cannot overwrite consent.
Saving never deletes credentials. A browser confirmation explains the consequences;
the labelled checkbox and Save remain usable without JavaScript.

An extra prompt during deactivation was rejected: deactivation must preserve data
and an inactive plugin cannot reliably add a dialog to later WordPress deletion.
WordPress retains its own deletion confirmation. Uninstall, including WP-CLI, honors
only the stored literal `1`; absent or malformed values preserve data. Direct file
removal does not invoke uninstall. Multisite deletion is deliberately refused.

`uninstall.php` checks `WP_UNINSTALL_PLUGIN`, uses no plugin autoloader, removes the
cleanup hook, then drops an exact allowlist of four owned tables using prepared
identifiers and deletes four exact options. It never enumerates a prefix for deletion
or removes WordPress users, passwords or sessions. WPCS's schema-change advisory is
locally documented because the authorized uninstall necessarily drops owned tables
and WordPress provides no table-removal API.

Table or option deletion failure stops uninstall with a neutral message. Consent
remains until the last successful deletion, permitting retry. DDL cannot be rolled
back atomically: operators must retry deletion or restore the matching backup before
reactivating. Existing active requests are not globally serialized with uninstall;
administrators must deactivate first and use a maintenance window for busy sites.

Tests cover authorization, nonce, HTTP method, malformed values, persistence failure,
default retention, exact deletion scope, retained WordPress sessions/password login,
table/option deletion failures and retry, plus actual multisite refusal. Browser tests
cover cancellation, confirmation, saved consent and withdrawal. See
[testing](TESTING.md) and [operations](OPERATIONS.md#removal).
