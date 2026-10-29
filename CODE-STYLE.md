# SW Securelogin — CODE-STYLE.md

> Verbindliche Implementierungsrichtlinien für Menschen und Coding Agents.  
> Priorität: **Sicherheit und Protokollkonformität > Korrektheit > Wartbarkeit > WordPress-Integration > Performance > Stil**.  
> In Konfliktfällen begründe die Abweichung in der Pull-Request-Beschreibung; Sicherheitsanforderungen sind nicht verhandelbar.

## 1. Grundregeln

1. Schreibe verständlichen, testbaren, wartbaren Produktionscode; keine spekulativen Frameworks und keine versteckte Magie.
2. **Nie Kryptografie, WebAuthn- oder OAuth-/OIDC-Protokollvalidierung selbst erfinden.** Bewährte, aktiv gepflegte Bibliotheken verwenden und konform konfigurieren.
3. Jede Änderung muss WordPress-Standardmechanismen für Benutzer, Capabilities, Sessions, Hooks, Fehler und REST respektieren.
4. Kein Login oder Rechteübergang ohne vollständig erfolgreiche, serverseitige Identitätsprüfung.
5. Keine Speicherung oder Ausgabe von privaten Schlüsseln, Secret-Werten, OAuth-Codes, Tokens oder Authentifizierungs-Challenges in Logs.
6. Sicherheit darf nicht durch „Fallback auf unsicheren Modus“ repariert werden; bei Unklarheit **fail closed**.
7. Abhängigkeiten nur für notwendige Funktionen; kompatible Lizenzen und Updatehistorie prüfen.
8. Keine Änderung ohne passende Tests (inklusive Negativtests für Authentifizierungslogik).
9. Keine ungefragten globalen Änderungen an `wp-login.php`, WordPress-Core, anderen Plugins oder Passwort-Authentifizierung.
10. Öffentliche API und interne Implementation sauber voneinander trennen.

## 2. PHP- und WordPress-Konventionen

- Neue Klassen: PHP mit expliziten Rückgabe- und Parametertypen, `declare(strict_types=1);` wo PHP-/Bibliotheks-Kompatibilität sichergestellt ist.
- Namespaces eindeutig: `SW\Securelogin\...`; Composer PSR-4 für eigene Klassen.
- WordPress Coding Standards (WPCS) mit PHP_CodeSniffer verpflichtend; für namespacete OOP-Klassen dokumentierte, konsistente PSR-12-Ausnahmen bei WPCS-Konflikten festlegen, nicht beides inkonsistent mischen.
- WordPress globale Funktionen/Hooks nach WP-Konvention (snake_case), eigene Funktionen stets mit `swsl_` prefixen; globale Konstanten mit `SWSL_`.
- Option-/Meta-/Hook-/Cron-Namen mit eindeutigem Prefix `swsl_`; Tabellen `{$wpdb->prefix}swsl_*`; REST-Namespace `sw-securelogin/v1`.
- Klassen mit einer Verantwortung. Controller steuern Requests, Services implementieren Ablauf/Policies, Repositories die Persistenz.
- Kein statischer Allzweck-Helper und keine Service-Locator-Zugriffe tief in Domainklassen; Konstruktor-Injection für relevante Dependencies.
- `WP_Error` an WordPress-Grenzen sinnvoll verwenden, intern klar typisierte Ergebnisse/Exceptions mit kontrollierter Übersetzung.
- Fehler nicht verschlucken. Öffentlich neutrale Fehler, intern strukturierte Ereignisse ohne Secrets.
- Internationale Texte mit `__()`, `_e()`, `esc_html__()` etc. und Textdomain `sw-securelogin`; Translator Notes bei Platzhaltern.
- Alle direkten PHP-Dateien vor ungewolltem Direktzugriff schützen, sofern Entry-Point nicht explizit öffentlich sein muss (`defined( 'ABSPATH' ) || exit;`).
- Keine unnötigen WP-Queries während jedes normalen Seitenrequests. Modules/Assets nur dort laden, wo benötigt.

## 3. PHP-Codequalität

- Methoden klein und auf einen klaren Zweck begrenzt; frühzeitige Returns statt tief verschachtelter Bedingungen.
- Keine booleschen Parameter für mehrere Modi; stattdessen Enums/konkrete Methoden, sofern von unterstützter PHP-Version gedeckt.
- Keine dynamisch konstruierten Klassennamen oder Reflection, solange nicht zwingend notwendig.
- Externe Strings/Daten früh validieren und normalisieren; geprüfte Value Objects für Issuer, Redirect URI, Credential ID, Client ID wo sinnvoll.
- Keine stillschweigende Type Coercion an Sicherheitsgrenzen. ID-/Token-Bytes nicht über verlustbehaftete Stringkonvertierung behandeln.
- Kommentare erklären Sicherheitsinvarianten, Gründe und externe Constraints — nicht offensichtliche Codezeilen.
- Public-API mit PHPDoc, einschließlich `@throws` und Datenstrukturen, wenn Typen nicht ausreichen.
- Veraltete WordPress-APIs vermeiden; Änderungen mit offizieller Doku prüfen.
- Kein produktiver `var_dump`, `error_log` mit Nutzdaten, `die`/`exit` in Anwendungslogik oder `@`-Fehlerunterdrückungsoperator.

## 4. Datenbank und Migrationen

- Datenbankoperationen über `$wpdb`, vorbereitete Queries mit `$wpdb->prepare()` und geprüfte Parametrisierung. SQL-Identifiers nur aus eigener Allowlist, nicht aus untrusted Input.
- WordPress-Tabellenpräfix respektieren, `dbDelta()`-kompatibles Schema und versionierte, idempotente Migrationen.
- Datenbank nicht mit sensiblen Klartext-Tokenwerten oder privaten Signaturschlüsseln befüllen. Token-Hashes für lookup/revocation; Key-Storage separat spezifizieren.
- Credentials eindeutig indizieren; Nutzer-Zuordnung nie nur auf Clientdaten vertrauen.
- **One-Time-Challenges und Authorization Codes atomar konsumieren**; gleichzeitige Requests dürfen nicht beide erfolgreich sein. Transients sind kein hinreichender atomarer Replay-Schutz.
- Migrationsfehler dürfen nicht zu halbfertiger Authentifizierung führen. Versionssprünge und Rückwärtskompatibilität testen.
- Beim Löschen von Benutzerkonten dazugehörige Passkeys und Identity Links löschen; Datenschutzhinweise, WP Personal Data Exporter/Eraser prüfen.
- Plugin-Deaktivierung darf Daten nicht löschen; Uninstall nur nach klar dokumentierter, bewusster Lösch-Policy.

## 5. Input, Output und HTTP

- Input **validieren** (Typ, Länge, Format, erlaubte Werte, Grenzen); Sanitization ersetzt keine Validierung.
- Ausgabe kontextgerecht escapen: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()` nur falls HTML ausdrücklich erlaubt.
- Kein beliebiger User-Input in HTML, JS, SQL, HTTP-Header oder Redirects.
- REST-Routen über `register_rest_route()` mit `permission_callback`, `args` und Validierungs-/Sanitization-Callbacks, wo sinnvoll.
- WordPress REST-Nonce und `current_user_can()` bei authentifizierten Mutationen; Nonces sind kein Ersatz für Berechtigungsprüfung oder erneute Identitätsbestätigung.
- Bei anonymen Auth-Endpunkten: Anfragen ratenlimitieren, Fehlermeldungen neutral halten, Content-Type/Body-Größe begrenzen.
- Login-Redirects mit `wp_validate_redirect()`/`wp_safe_redirect()` und internen Allowlists; IdP-Redirects nur zu validierten, vertrauenswürdigen Provider-URLs.
- API-Responses mit `Cache-Control: no-store` für sensitive Auth-Endpunkte; keine Caches für Tokens, Challenges oder personenbezogene Sicherheitsdaten.
- HTTP-Clients mit Timeouts, Größenlimits, TLS-Zertifikatsprüfung; keine deaktivierte SSL-Verifikation.
- SSRF-Schutz für Discovery, JWKS, Token-, UserInfo- und ggf. Provider-Metadata-URLs: zulässige Schemes/Hosts, IP-Ranges, Redirectketten, DNS-Rebinding prüfen.

## 6. WebAuthn: verbindliche Sicherheitsinvarianten

- Registrierung ausschließlich aus authentifizierter, capabilitygeprüfter, CSRF-geschützter und **frisch reauthentifizierter** Sitzung.
- Login-Challenge mit CSPRNG erzeugen; befristet, eindeutig, an Logintransaktion und erwarteten Benutzer gebunden; atomar einmal verwendbar.
- `navigator.credentials.get()`/`create()` nur über sichere Kontexte; Browserfehler sauber, ohne Secret-Leak, behandeln.
- Bei jeder Assertion serverseitig Origin, RP-ID-Hash, Challenge, Signatur, Credential-ID, User-Zuordnung sowie UP/UV prüfen; User Verification standardmäßig **required**.
- Nie Browserfelder wie `userHandle`, `rawId`, `authenticatorData` ungeprüft als Wahrheitsquelle übernehmen.
- Vor Speicherung Attestation-/Credential-Validierung der Bibliothek vollständig ausführen; keine manuellen Abkürzungen.
- Signaturzähler nicht als alleinigen Beweis eines Klons behandeln; Backup-/Sync-Eigenschaften berücksichtigen; Anomalien nachvollziehbar protokollieren.
- Passkey-Löschung/Änderung mit passender Berechtigung, CSRF und je nach Sensitivität frischer Authentifizierung.
- Domain-/RP-ID-Änderungen als Migrationsrisiko behandeln, nicht automatisch Credentials umschreiben.

## 7. OIDC-Client: verbindliche Sicherheitsinvarianten

- Externes Login nur über OIDC mit Authorization Code Flow + PKCE S256; `state` und `nonce` zufällig, transaktionsgebunden und einmal verwendbar.
- Discovery-Metadaten gegen exakt konfigurierte Issuer-/Endpoint-Policies prüfen; kein dynamisch aus Benutzerinput akzeptierter Issuer.
- ID-Tokens mit etablierter JOSE-Bibliothek überprüfen: feste Algorithmusallowlist, verifizierte Signatur/JWKS, `iss`, `aud`, `azp` (wenn erforderlich), `exp`, `iat`, `nonce`; keine unsignierten Tokens.
- Account-Zuordnung auf **Issuer + Subject** basieren lassen. Nicht stillschweigend anhand `email` zusammenführen — auch nicht wenn `email_verified` wahr ist.
- Identity-Linking erfordert Session, Capability, CSRF-Schutz und frische Reauthentication.
- Erstmals unbekannte externe Identitäten standardmäßig ablehnen oder gezielt zur verifizierten Verknüpfung führen; keine heimliche Admin-Account-Erzeugung.
- OAuth-/OIDC-Provider-Mix-up, Login-CSRF, Code Injection, Redirect-URI-Manipulation und Replay als Testfälle implementieren.
- Client-Secrets und Tokens nicht im HTML, Browser-Storage, Query-Log oder Auditlog ausgeben.

## 8. OAuth-Authorization-Server: zusätzliche Regeln

- **Vor Implementierung** RFC-basiertes Protokolldesign, Data Model, Threat Model und Bibliotheksauswahl freigeben.
- Authorization Code mit PKCE S256; kein Implicit- oder Password Grant; Redirect-URIs exakt matchen.
- Client-Authentifizierung passend zu Clienttyp; keine vertraulichen Client-Secrets in Public Clients.
- Consent muss konkrete Scopes und Clientidentität verständlich und korrekt darstellen; bei fehlendem Consent kein Token.
- Einmaliger Authorization Code, kurze Ablaufzeiten; Tokens scope-/audiencegebunden. Refresh-Token-Rotation und Replay-Erkennung, falls Refresh unterstützt wird.
- ID-Tokens nur bei eingeschaltetem OIDC und mit korrekten Claims/signiertem Key; `.well-known` und JWKS müssen konsistente, valide Metadaten liefern.
- Signing Keys nicht selbst erzeugen durch schwache Zufallsquellen; Key-Rotation mit `kid`, Überlappungsfenster, Revocation-Strategie, Backup und dokumentierter Eigentümerschaft.
- WordPress-Capabilities nicht pauschal aus OAuth-Scopes ableiten; Mapping explizit, deny by default.
- Servermodul nicht aktivieren, bevor externe Security Review und Protokoll-/Interoperabilitätstests bestanden sind.

## 9. JavaScript, CSS und Admin-UX

- Vanilla JavaScript oder WordPress-Standardpakete, sofern benötigt; keine schwere SPA für wenige Adminseiten.
- Moderne Modulsyntax nur mit passend gebündelter Browserunterstützung; kein inline JS mit dynamisch interpolierten Secrets.
- Fetch-Aufrufe kapseln, Fehlerfälle/Abbruch/Timeout und Ladezustände behandeln; kein doppeltes Submit.
- WebAuthn-Binärfelder mit standardkonformer Base64url-Konvertierung handhaben; keine fehlerhafte UTF-8-Konvertierung.
- Kein Token oder Credential in localStorage/sessionStorage protokollieren; Cookies nur über WordPress-Sessionmechanismus.
- CSS-Klassen mit `swsl-` prefixen und auf Pluginoberflächen begrenzen; keine globalen Admin-Styles überschreiben.
- A11y: Labels, Tastatursteuerung, Fokusmanagement, Statusmeldung, verständliche Fehlermeldungen; WordPress-Designkonventionen beachten.
- Kein Google Fonts CDN und keine sonstige externe Ressource im Admin-Login; wenn Schrift gebraucht wird, vorhandene System-/WP-Fonts nutzen.
- Alle Benutzertexte übersetzbar; Standardsprachdateien Deutsch/Englisch.

## 10. Dependency Management und Builds

- Composer für PHP-Bibliotheken; `composer.lock` versionieren und produktive Versionen pinnen.
- Vor Entscheidung zu Mozart prüfen, welche Klassen gefahrlos umgeschrieben werden können. **Namespacing/Scoping von Kryptografie- und WebAuthn-/OIDC-Libraries nie ungeprüft erzwingen**; dynamische Klassennamen, Serialisierung, Reflection, interne Ressourcen und Signaturvalidierung können betroffen sein.
- Keine Pakete allein zur Verringerung von Namespace-Kollisionen forken; erst reproduzierbaren Konflikt und kompatiblen Scoping-Ansatz belegen.
- Keine Scripts/Tools aus unzuverlässigen Buildabhängigkeiten unkontrolliert ausführen; Supply-Chain-Risiken prüfen.
- Release-ZIP enthält Pluginordner `sw-securelogin/`, autoloadbare Produktionsabhängigkeiten, Assets, Übersetzungen, Lizenz und readme; keine Tests, `.git`, Secrets, Devtools.
- Nach Build Smoke-Test **des finalen ZIPs** in frischer WordPress-Instanz, inklusive Passkey-Registrierung/Login.
- Dependabot/Renovate, Composer-Audit und nachvollziehbarer Updateprozess.

## 11. Testvorgaben und CI-Gates

Pflicht in Pull Requests:

1. PHP-Lint und Composer-Validierung.
2. PHP_CodeSniffer mit WPCS; begründete Whitelists statt globaler Deaktivierung.
3. PHPStan (Level schrittweise hochziehen, definierte Baseline mit Ablaufstrategie).
4. PHPUnit Unit-/Integrationstests und WP-Integrationstestumgebung.
5. JS-Lint sowie Browser-/E2E-Tests für relevante Login-Flows.
6. Abhängigkeits-/Security-Scanning; keine High/Critical Findings ohne bewussten Blocker-Review.
7. Negativtests bei jeder Änderung an Auth- oder Persistenzlogik.

Mindestfälle:

- Erfolgreicher Login mit zwei Passkeys; unabhängiger Widerruf.
- Falsche Challenge, Origin, RP-ID, Signatur, Credential-ID, Benutzerzuordnung; doppelte Verwendung/Parallel-Requests.
- Fehlende UV/UP, Ablauf, Browser-Abbruch, Rate Limit, Username Enumeration.
- Keine Session bei Fehlern, kein unerwarteter MFA-/Login-Hook-Bypass.
- OIDC später: manipuliertes ID-Token, falscher Issuer/Audience/Nonce, `alg=none`, JWKS-Wechsel, Mix-up, Account Linking.
- Authorization Server später: Code Replay, PKCE Mismatch, Redirect-/Client-Mismatch, Consent-, Scope- und Token-Bindungsfehler.

## 12. Dokumentations- und Review-Regeln

- Jede sicherheitsrelevante Entscheidung erhält eine kurze ADR: Kontext, Alternativen, Wahl, Risiko, Teststrategie.
- Änderungen an Datenformaten/Endpoints dokumentieren; semantische Versionierung und migrationsfähiges Schema.
- Jede Pull Request beantwortet: Was ändert sich? Welches Sicherheitsinvariant bleibt erhalten? Wie getestet? Rollback möglich?
- Keine Sicherheitsgarantien behaupten, die nicht durch konkrete Tests oder nachweisbare Bibliotheksprüfungen gestützt sind.
- Code Reviews priorisieren Authentifizierung, Berechtigungen, Challenge/Token-Lebenszyklen, Drittanbieterbibliotheken, Race Conditions und Datenschutz.

## 13. Arbeitsanweisung für Coding Agents

1. Lies `PLAN.md` und diese Datei vollständig, bevor du implementierst.
2. Arbeite nur am zugewiesenen Meilenstein; baue keine halbfertigen OAuth-Server-Funktionen „vorsorglich“ produktiv ein.
3. Identifiziere zuerst Trust Boundaries, Invarianten und konkrete Tests.
4. Verwende bestehende Core-Schnittstellen; keine doppelten Auth-/Session-Services im Modul.
5. Stelle sicher, dass fehlgeschlagene Authentifizierung **niemals** zu einem gültigen Login-Cookie führt.
6. Führe Lint, Static Analysis und passende Tests selbst aus; beschreibe verbleibende Unsicherheiten ausdrücklich.
7. Bei Protokollzweifeln die maßgebliche Spezifikation und gepflegte Bibliotheksdokumentation konsultieren, nicht raten.
8. Lass Features lieber deaktiviert als mit unvollständiger Sicherheitsprüfung verfügbar.
9. Füge keine fremden Cloudservices, Telemetrie, Nutzungsanalysen oder externen Assets hinzu, sofern nicht explizit vereinbart.
10. Halte Änderungen klein, reviewbar und rückgängig machbar; dokumentiere offene Fragen als Issues/TODOs statt stillschweigender Annahmen.

## 14. Normative Referenzen

- https://developer.wordpress.org/coding-standards/wordpress-coding-standards/
- https://developer.wordpress.org/plugins/security/
- https://www.w3.org/TR/webauthn-3/
- https://www.rfc-editor.org/rfc/rfc9700
- https://www.rfc-editor.org/rfc/rfc7636
- https://openid.net/specs/openid-connect-core-1_0.html
- https://openid.net/specs/openid-connect-discovery-1_0.html
