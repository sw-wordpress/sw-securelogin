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
installs only locked production dependencies, runs Mozart, removes the original
vendor tree and checks that the actual plugin bootstrap still works. Only then does
it replace build/sw-securelogin. The source checkout and its development dependencies
are not rewritten. The generated loader also receives our own SW\Securelogin\ PSR-4
mapping because Mozart generates dependency mappings only.

The collision fixture demonstrated that Mozart 1.2.3 does not rewrite imports in the
root project's src directory. bin/mozart-compose.php therefore uses Mozart's own AST
NamespaceReplacer on staged plugin sources after composing dependencies. It does not
patch Mozart's code or implement a text/regex substitute. Initially this extension
supports PSR-4 and PSR-0 dependencies; files/classmap autoloaders, unbounded namespaces
and selective package scoping stop the build for an explicit compatibility review.
The prefix configuration is prepared for other symbol forms, but those build paths
are not yet approved or tested. Source entry points must not directly import runtime
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
