# ADR 003: Isolated production dependencies with Mozart

Status: accepted for the build infrastructure, requested by the project owner.

Use the build pattern from sw-backupit: Mozart 1.2.3 in its separate Docker image,
a generated vendor-prefixed/autoload.php, and the original Composer autoloader for
source checkouts. Mozart is not installed into the plugin's runtime or dev dependency
tree. The Flysystem-specific recursive-loading patch from sw-backupit is not needed
for our current dependency tree and is not copied.

Configuration in composer.json:

- Dependency namespace: SW\Securelogin\Dependencies\
- Namespaced and classmap output: vendor-prefixed/
- Global class and constant prefix: SWSL_
- Global function prefix: swsl_
- Generated autoloader and deletion of processed vendor directories enabled.

composer build:prefixed copies an explicit allowlist into a temporary directory,
installs only locked production dependencies, runs the Mozart adapter, removes the original
vendor tree and checks that the actual plugin bootstrap still works. Only then does
it replace build/sw-securelogin. The source checkout and its development dependencies
are not rewritten. The generated loader also receives our own SW\Securelogin\ PSR-4
mapping because Mozart generates dependency mappings only.

The collision fixture demonstrated that Mozart 1.2.3 does not rewrite imports in the
root project's src directory. bin/mozart-compose.php therefore uses Mozart's AST
visitors on staged plugin sources and dependencies. It does not patch Mozart's code
or implement a text/regex substitute. PSR-4 and PSR-0 dependencies are supported;
the exact reviewed function files and PHP 8.3 polyfill classmap described below are
also supported. Other files/classmap forms, unbounded namespaces and selective package
scoping stop the build for an explicit compatibility review. Source entry points must not directly import runtime
libraries; keep those references inside src so the staging pass can process them.

M0 originally had no production protocol library. M1 now integrates lbuchs/webauthn;
see ADR 004 for its tested ES256/none-attestation scope. The synthetic
collision test proves basic dependency loading and rewritten root imports, not
protocol compatibility. For every additional library, test the built artifact's
serialization, dynamic class references, internal resources, valid signatures and
all negative protocol cases. Do not silently fall back to unprefixed libraries when
a scoped dependency fails.

The bootstrap prefers vendor-prefixed/autoload.php when present. It uses the original
vendor/autoload.php only when the prefixed loader is absent in a source checkout.
Build outputs contain no original vendor tree. Tests cover a conflicting dependency
loaded by another plugin and parent source references, plus plugin loading with no
development dependencies.

This is a development build directory, not the complete reproducible ZIP release
pipeline or a passkey release approval. Release packaging and final ZIP smoke tests
remain in M3. WordPress CI is configured to test the staged build; remote CI runs
still need verification.

Upstream reference: https://github.com/coenjacobs/mozart/blob/1.2.3/README.md


## Vergleich mit den übrigen sw-Plugins (30.10.2026)

Die Prüfung der lokalen Projekte zeigt unterschiedliche Buildwege. sw-mailgate,
sw-newspaper2 und sw-forms rufen /mozart/bin/mozart compose direkt auf.
sw-cloudcalendar führt zusätzlich bin/prefix-sources.php für eigene Imports aus;
sw-backupit verwendet bin/mozart-compose.php für einen Abhängigkeitszyklus.
Unsere PHP-Datei ist ein Build-Adapter für Root-Imports und Lizenzhinweise, kein
Ersatz für den Mozart-Autoloader. Sie wird nicht im Plugin-Artefakt ausgeliefert.

Ein isolierter Vergleich mit dem unveränderten Container coenjacobs/mozart:1.2.3
bestätigte: Die Abhängigkeit wird präfixiert, der Import des Root-Consumers bleibt
unverändert. Der vorhandene Kollisions-Smoke-Test bricht dann mit
"Parent dependency references were not rewritten" ab. Die Standardkonfiguration
allein ersetzt diesen Schritt in der derzeit geprüften Version daher nicht.
Andere Plugins wurden nur gelesen und nicht verändert. Sobald Mozart Root-Imports
selbst verarbeitet, kann der Adapter nach bestandenem Kollisions- und WebAuthn-Test
entfernt werden. Keine globalen Klassen-Aliase als Ersatz für die Isolation einführen.

## Virtual packages and OIDC scoping, 2026-11-10

The real OIDC candidate exposed four independent failures, now handled by the project
adapter using Mozart's pipeline and AST APIs, without patching upstream source or
dependency manifests:

- `MozartGraph` scopes Composer's complete resolved inventory. Direct, `provide` and
  `replace` edges include every installed provider, without another version solver.
  Missing requirements, duplicate/unsafe names and cycles fail before mutation.
  A flat dependency-first inventory avoids recursive upstream package loading.
- Composer's generated eager-file list supplies loading order. A source-to-target map
  follows Mover's first-target rule, including function files already covered by PSR
  paths that upstream omitted from its eager list.
- The final AST pass includes optional references absent from `require`, such as
  JOSE's AESKW integration. `MozartVisitor` extends the upstream visitor's local-name
  protection to already scoped namespaces, preserving JOSE's local `AESKW` class.
- `MozartAutoload` permits only the exact reviewed Facile function lists, Symfony
  deprecation/polyfill bootstraps and Php83 `Resources/stubs` classmap. Installed and
  source metadata must match in types/list order, ignoring object key order. Unknown
  forms or changed file lists still require review. Native conditional compatibility
  symbols stay native; the deprecation helper is plugin-prefixed.

The artifact excludes the original vendor tree and build helpers. Existing notices
are retained, with deterministic `licenses/packages.json` metadata for every installed
package. The evaluated Facile archives contain no LICENSE file; metadata retention
does not invent missing copyright notices or complete the separate distribution review.

Evidence: 83 unit tests/363 assertions on PHP 8.2.27 and 8.3.16; PHPStan 8 for production
and the pure build helpers; WPCS and PHP/Bash/Python syntax checks pass. The scoped OIDC
consumer repeats signature/claim/callback tests without a vendor fallback, with foreign
symbols before/after and with original libraries loaded first. It verifies eager
functions, conditional Override/date stubs, optional AES-KW against RFC3394's vector and
retained notices/metadata. Synthetic collision/unknown-autoload tests and WebAuthn
scoping tests pass. The byte-repeatable production ZIP passes extracted-artifact smoke
tests. CI now includes OIDC artifact checks in both PHP jobs; remote execution remains
unverified. Full WordPress/browser/whole-project coverage was not rerun. Actionlint
1.7.7 reports the existing SC2016 advisory on deliberately single-quoted inline PHP in
the Plugin Check step; no suppression was added.

Reproduce with `composer test:mozart`, `composer test:oidc-spike`,
`SWSL_SPIKE_PHP=/usr/bin/php8.2 composer test:oidc-spike` and `composer build:release`.
These results approve the tested scoping forms/tree; remaining OIDC runtime/protocol
gates are tracked in [ADR 008](ADR-008-oidc-library-transport-spike.md).
