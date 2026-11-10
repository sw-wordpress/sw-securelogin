# ADR 007: Operator approval of OIDC provider configuration

Status: first isolated implementation of the approved M4 operator-approval decision.
This does not approve or implement an OIDC protocol client or network transport.

`ProviderTrust` is a PHP-only configuration boundary, constructed with the operator's
`SWSL_OIDC_PROVIDER_TRUST` value by the future composition root. It does not read
WordPress options, constants, cookies or requests itself, and performs no HTTP calls.
This keeps its tests independent of WordPress and makes the configuration source explicit.

The approval map is keyed by immutable provider instance ID. Each approval requires
non-empty string fields `issuer`, `client_id`, `authorization_endpoint`, `token_endpoint`
and `jwks_uri`. Optional `discovery_uri` must be present in both records or absent from
both; explicit null is invalid. Unknown approval fields are rejected, including an
accidentally misplaced client secret. Non-security candidate metadata, such as the
display name, does not alter approval. The component never resolves a secret reference.

Every protected field must match exactly: no trimming, URL normalization or host-only
comparison. This permits explicitly approved different hosts while rejecting changes
to a path, query, client or issuer. URLs require HTTPS and valid syntax, without userinfo,
fragments, backslashes or raw control/space characters; issuer query strings are rejected.
Input bounds are 2,048 bytes per URL and 1,024 bytes per client ID. These are configuration
input bounds, not token, response or network limits. Error messages contain no configured
values. An absent or malformed referenced approval fails closed.

This check is necessary but insufficient to release credentials or trust signing keys.
The future caller must supply operator-controlled approval separately from mutable
provider settings, bind secrets to the same provider ID and recheck configuration
revision/status at completion. DNS/IP/port/TLS restrictions, redirect refusal, provider
metadata validation and OIDC-library proof remain separate mandatory gates. An approved
private HTTPS URL is not automatically authorized for a network request by this class.

The class is not wired into the plugin bootstrap. There are no new routes, UI, schema
changes, secret reads or enabled OIDC modules. It is the first tested building block;
M4.0 library/transport/cryptography decisions remain outstanding. Tests cover every
protected-field substitution, malformed/missing approval, provider isolation, discovery
mode changes, URL ambiguity and input bounds. See [M4 plan](M4-OIDC-PLAN.md).

## Provider-bound secret access, 2026-11-10

`ProviderSecrets` accepts `ProviderTrust` and the operator's
`SWSL_OIDC_CLIENT_SECRETS` value through constructor injection. Its
`clientSecret(provider_id, configuration)` method checks the full current approval
on every call before reading the entry keyed by that same immutable provider ID.
Only a non-empty string is accepted; bytes are returned unchanged, without trimming
or encoding. Missing/malformed entries fail with a value-free exception. No fallback
to another provider, mutable secret reference, constant name or file path exists.
Unrelated malformed entries do not disable an otherwise valid provider.

This implements the already agreed provider binding without choosing the still-open
transaction encryption, transport or protocol contracts. Neither class reads constants
itself or registers WordPress hooks. The future composition root supplies wp-config
values; the token exchange caller must additionally enforce network approval,
provider status and transaction revision. This accessor alone does not authorize an
HTTP request. No diagnostic/status endpoint may expose its returned secret.

Tests use synthetic secrets and cover exact-byte preservation, separate provider
entries, missing/wrong-type values, unavailable approval, changed protected fields,
discovery-mode changes, repeated checks and attempted mutable secret selection.
Local checks: 70 unit tests (325 assertions), PHPStan 8, WPCS, Composer validation,
PHP/JS lint pass. Unit coverage covers all 5 statements in `ProviderSecrets` and all
22 in `ProviderTrust`. Whole-project PHP/JS coverage, WordPress integration, browser,
release-ZIP and remote SonarQube checks were not rerun for this isolated addition.

Manual regression check: with the existing passkey module enabled, password/passkey
login and profile management should remain available; there must be no new OIDC login
button or provider-setting screen. This isolated component has no browser workflow yet.
