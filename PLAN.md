# SW Securelogin — PLAN.md

> Status: Architektur- und Implementierungsplan · Stand: 29.10.2026  
> Produkt: SW Securelogin · Plugin-Slug: `sw-securelogin` · Textdomain: `sw-securelogin`  
> Projektziel: Modulare Authentifizierungsplattform für WordPress mit Passkeys, externem OpenID-Connect-Login und später einem eigenen OAuth-2.0-/OpenID-Connect-Provider.

## 1. Vision und Produktgrenzen

SW Securelogin erweitert die WordPress-Anmeldung um standardbasierte Anmeldeverfahren. Die Plattform besteht aus einem kleinen, stabilen Core und unabhängig aktivierbaren Modulen. **Passkeys** sind der erste produktive Loginmechanismus. Später können WordPress-Benutzer über externe **OpenID-Connect-Provider** angemeldet werden (OAuth-/OIDC-Client). Schließlich kann WordPress selbst als **OAuth-2.0-Authorization-Server mit optionalem OpenID-Connect-Provider** für externe Anwendungen auftreten.

Die Begriffe werden präzise verwendet: **OAuth 2.0** ist ein Autorisierungsframework und allein kein sicheres Login-Protokoll. Für „Login per OAuth“ wird standardmäßig **OpenID Connect (OIDC)** auf OAuth 2.0 eingesetzt. Der spätere Server stellt OAuth-2.0-Autorisierung und — sofern OIDC aktiviert — standardkonforme Identitätstokens bereit.

### Grundprinzipien

1. Login zunächst **nur für die WordPress-Administrationsanmeldung** via bestehendem `wp-login.php` und Übergang nach `wp-admin/`; keine Frontend-Login-Widgets, WooCommerce-Integration oder öffentlichen Shortcodes im MVP.
2. Bestehende WordPress-Benutzerkonten, Rollen, Capabilities und Sitzungen bleiben maßgeblich; kein paralleles User- oder Session-System.
3. Mehrere Passkeys je WordPress-Benutzer, benutzernamenbasierte Passkey-Anmeldung.
4. Module lassen sich unabhängig deaktivieren; Deaktivierung darf keine Benutzer aussperren oder aktive Sessions unbeabsichtigt löschen.
5. Bevorzugt etablierte, gepflegte Bibliotheken; keine selbst geschriebene Kryptografie oder Protokollvalidatoren.
6. Sichere Defaults, abwärtskompatible Datenmigrationen, Testbarkeit und WordPress.org-konforme Distribution.
7. Ein OAuth-/OIDC-Provider ist **kein bloßes Token-Endpoint-Feature**: Er benötigt Clientverwaltung, Grants, Consent, Scopes, Signaturschlüssel, Tokenlebenszyklen, Discovery, Revocation und sorgfältige Sicherheitsprüfung.

### Nicht-Ziele von Version 1.0

- Passwort-Login zwangsläufig abschalten; Single Sign-on in fremde Frontend-Logins integrieren.
- Passkey-Login ohne Benutzernamen (discoverable credentials als primärer Loginweg).
- Self-Service-Registrierung neuer WordPress-Benutzer über externe Identitätsprovider.
- Einen OAuth-Provider oder OIDC-Provider produktiv anbieten.
- Sich als universelle Identity-Management-Lösung mit Provisioning, SCIM, SAML oder MFA-Orchestrierung ausgeben.

## 2. Zielarchitektur

```text
sw-securelogin/
├── sw-securelogin.php
├── composer.json
├── composer.lock
├── readme.txt
├── uninstall.php
├── PLAN.md
├── CODE-STYLE.md
├── src/
│   ├── Core/
│   │   ├── Plugin.php
│   │   ├── Container.php
│   │   ├── ModuleInterface.php
│   │   ├── ModuleRegistry.php
│   │   ├── SettingsRepository.php
│   │   ├── MigrationRunner.php
│   │   └── Requirements.php
│   ├── Auth/
│   │   ├── LoginSessionIssuer.php
│   │   ├── AuthPolicy.php
│   │   ├── AccountResolver.php
│   │   └── Reauthentication.php
│   ├── Http/
│   │   ├── RestRegistrar.php
│   │   ├── RequestValidation.php
│   │   └── ResponseFactory.php
│   ├── Security/
│   │   ├── RateLimiter.php
│   │   ├── ReplayProtection.php
│   │   ├── AuditLogger.php
│   │   └── SecretStore.php
│   ├── Admin/
│   │   ├── SettingsPage.php
│   │   └── Notices.php
│   └── Modules/
│       ├── Passkeys/
│       │   ├── PasskeysModule.php
│       │   ├── WebAuthnService.php
│       │   ├── ChallengeRepository.php
│       │   ├── CredentialRepository.php
│       │   ├── LoginController.php
│       │   ├── RegistrationController.php
│       │   └── ProfileController.php
│       ├── OidcClient/
│       │   ├── OidcClientModule.php
│       │   ├── ProviderRepository.php
│       │   ├── OidcFlowService.php
│       │   ├── TokenValidator.php
│       │   ├── IdentityLinkRepository.php
│       │   └── CallbackController.php
│       └── AuthorizationServer/
│           ├── AuthorizationServerModule.php
│           ├── ClientRepository.php
│           ├── AuthorizationController.php
│           ├── TokenController.php
│           ├── ConsentController.php
│           ├── DiscoveryController.php
│           └── SigningKeyManager.php
├── assets/
│   ├── js/
│   └── css/
├── templates/
├── languages/
├── tests/
│   ├── Unit/
│   ├── Integration/
│   ├── E2E/
│   └── Security/
└── .github/workflows/
```

**Hinweis:** Diese Struktur beschreibt Modulgrenzen, nicht zwingend jedes finale Klassenfile. Abstraktionen entstehen erst, wenn ihr tatsächlicher Nutzen feststeht. Core-Dienste dürfen keine direkten Abhängigkeiten zu optionalen Modulen haben. Module hängen von Core-Schnittstellen ab, niemals umgekehrt. OAuth-Client und Authorization Server sind getrennte Module mit getrennten Datenmodellen und Policies.

### Gemeinsame Core-Verträge

- `ModuleInterface`: ID, Status, Registrierung von Hooks/Routes, Konfigurationsschema, Abhängigkeitsprüfung.
- `AccountResolver`: Eingehende externe Identität explizit einem lokalen `WP_User` zuordnen; keine Identitätszuordnung allein anhand unbestätigter E-Mail.
- `LoginSessionIssuer`: Erst nach vollständig verifizierter Authentifizierung über die WordPress-Session-/Cookie-APIs anmelden; relevante Hooks korrekt auslösen.
- `AuthPolicy`: Definiert erlaubte Loginverfahren und Reauthentication-Anforderungen; kein globaler „Login erlaubt“-Bypass.
- `RateLimiter`, `AuditLogger`, `ReplayProtection`: gemeinsame Infrastruktur, mit pro Modul getrennten Limits und Ereignistypen.
- `SecretStore`: sensible Provider-Credentials und Signaturschlüssel nur gemäß explizitem Schlüsselmanagementkonzept speichern; niemals im Frontend ausgeben.

## 3. Modul A — Passkeys (MVP / v1.0)

### Benutzerfluss

1. Benutzer öffnet `wp-login.php`, gibt **Benutzernamen** ein und wählt „Mit Passkey anmelden“.
2. Server ermittelt nur zu dieser Kennung passende Credentials, erstellt eine kryptografisch sichere Login-Challenge und bindet sie an eine kurzlebige, unabhängige Transaktion.
3. Browser ruft `navigator.credentials.get()` auf; `userVerification: required`.
4. Server überprüft Credential-ID und exakte Zuordnung zum ausgewählten WordPress-Benutzer, Signatur, Challenge, Origin, RP-ID-Hash, UP-/UV-Flags und Replay-Schutz.
5. Erst danach erstellt `LoginSessionIssuer` die WordPress-Sitzung und leitet sicher zu `wp-admin/` bzw. einer geprüften internen Redirect-URL weiter.
6. Neutrale Fehlerausgaben; die Login-UI darf nicht zuverlässig offenbaren, ob ein Benutzer existiert oder Passkeys besitzt.

### Registrieren und Verwalten

- Unter **Benutzer → Profil → Meine Passkeys** mehrere Passkeys hinzufügen, benennen, auflisten und einzeln widerrufen.
- Registrierung erfordert angemeldete Sitzung, Berechtigung, CSRF-Schutz und **frische Reauthentication** (bestehender Passkey oder Passwort, unabhängig vom bloßen Session-Cookie).
- `navigator.credentials.create()` mit serverseitiger Überprüfung von Challenge, RP-ID, Origin, Attestation-Konfiguration und erforderlicher User Verification.
- Credential-Namen, Registrierungs- und letzte Nutzungszeit anzeigen; authenticator-Angaben nur, sofern technisch zuverlässig ermittelbar.
- Öffentlichen Schlüssel, Credential-ID, signCount, User Handle, transports, AAGUID, Backup-Flags und Metadaten speichern; niemals private Schlüssel oder Biometrie.
- Passkeyverlust: vorhandener Passwort-Fallback sowie geprüfter Wiederherstellungsweg. Passwort-Abschaltung erst nach eigenem Recovery-Konzept.
- Signaturzähler korrekt behandeln; bei synchronisierten Passkeys können Null-/nicht-monotone Zähler auftreten. Verdachtsfälle protokollieren, risikobasiert bewerten, nicht blind inkrementieren.

### Endpunkte (vorläufig)

| Route | Methode | Authentifizierung | Zweck |
|---|---|---|---|
| `/sw-securelogin/v1/passkeys/login/options` | POST | öffentlich, Rate Limit | Assertion-Optionen |
| `/sw-securelogin/v1/passkeys/login/verify` | POST | öffentlich, transaktionsgebunden | Assertion prüfen, Session erzeugen |
| `/sw-securelogin/v1/passkeys` | GET | WordPress-Session + Capability | Eigene Credentials |
| `/sw-securelogin/v1/passkeys/registration/options` | POST | Session + Nonce + frische Reauth | Registrierung starten |
| `/sw-securelogin/v1/passkeys/registration/verify` | POST | Session + Nonce + frische Reauth | Registrierung prüfen |
| `/sw-securelogin/v1/passkeys/{id}` | PATCH/DELETE | Session + Nonce + Reauth für Widerruf | Benennen/widerrufen |

*Registrierung und Löschung von Credentials Dritter nur über ausdrücklich autorisierte Admin-Prozesse; Admins dürfen nicht heimlich neue Credentials für fremde Konten anlegen.*

### Persistenz

- Eigene Tabelle `{$wpdb->prefix}swsl_passkeys`; `user_id`, `credential_id` (eindeutiger binärer Identifier), `public_key` (bibliothekskonforme Serialisierung), `user_handle`, `sign_count`, `transports`, `aaguid`, `backup_eligible`, `backup_state`, `name`, `created_at`, `last_used_at`.
- Eigene kurzlebige Challenge-/Transaktionspersistenz mit atomarer Verbrauchsoperation; **keine bloßen, race-anfälligen Transients** für sicherheitskritisches One-Time-Consume.
- Datenschutz- und Retentionsregeln für Logs; WP-User-Löschung muss referenzierte Credentials entfernen.

## 4. Modul B — Externer Login: OIDC Relying Party (v1.1+)

„Login per OAuth“ wird als **OIDC Authorization Code Flow mit PKCE S256** realisiert. Generische OAuth-Provider ohne nachweisbare Identitätstokens sind **nicht automatisch** als Loginprovider zulässig. Optional später gesonderte, auditiert implementierte Provideradapter für explizit validierte Identitäts-APIs.

### Anforderungen

- Mehrere externe OIDC-Provider (z. B. Microsoft Entra ID, Google, Keycloak, Authentik, andere standardkonforme OIDC-Provider).
- Administrierbare Einstellungen je Provider: Anzeigename, Issuer/Discovery-URL, Client-ID, Client-Secret falls erforderlich, zugelassene Redirect-URIs, erlaubte Scopes, Aktivstatus.
- Discovery und JWKS nur von validierten, vertrauenswürdigen URLs beziehen; HTTPS und SSRF-Schutz (DNS-Rebinding, private IPs, Redirects, Timeouts, Antwortgrößen).
- Zufällige `state`- und `nonce`-Werte, PKCE S256, transaktionsgebundene Prüfung, strikte Callback-Validierung, Schutz gegen Mix-up und Login-CSRF.
- ID-Token strikt prüfen: JOSE-Algorithmusallowlist, Signatur per vertrauenswürdigem JWKS, `iss`, `aud`, ggf. `azp`, `exp`, `iat`, `nonce`; `sub` ist die stabile externe Identität.
- Externe Identitäten ausschließlich über `(issuer, subject)` verknüpfen; **kein automatisches Account-Linking nach E-Mail-Adresse**, auch bei verifizierter E-Mail, ohne explizite zusätzliche Policy und Absicherung.
- Standard: Bestehende WordPress-Benutzer müssen ihre externe Identität aus einer eingeloggten, frisch reauthentifizierten Sitzung heraus verknüpfen. Automatisches Provisioning zunächst aus.
- Callback und Admin-Login getrennt von sonstigen Frontend-Auth-Flows; sichere Redirect-Whitelist.
- Refresh-Tokens möglichst vermeiden, sofern für reinen Login nicht benötigt; Secrets und Tokens niemals in Logs oder Browser-Storage ablegen.

### Spätere Erweiterungen

- Provider-spezifische Claim-Mappings mit expliziter Allowlist.
- Rollen-/Domainrichtlinien ohne automatische Übernahme privilegierter Rollen.
- Administratorseitiges Trennen eines verknüpften Kontos und sichere Recovery-Regeln.
- Option für „OIDC-only“ pro Rolle erst nach Lockout-/Recovery-Tests.

## 5. Modul C — OAuth 2.0 Authorization Server + OIDC Provider (v2.0+)

Das Plugin kann externe Anwendungen zur Autorisierung und — bei OIDC — zum Login gegen WordPress verwenden. **Dieses Modul wird erst nach separater Protokollspezifikation, Bedrohungsanalyse und Security Review veröffentlicht.** Die Implementierung soll eine gepflegte, auditierte Server-Bibliothek verwenden, wenn WordPress/PHP-Kompatibilität und Lizenz passen. Einen vollständigen OAuth-Server nicht selbst aus Einzelkomponenten zusammensetzen.

### Geplante Funktionen

- Verwaltung registrierter Clients, erlaubter Redirect-URIs, Clienttypen (`public`/`confidential`), Scopes und Aktivierung.
- **Authorization Code Grant mit PKCE S256** als initialer interaktiver Flow; keine Implicit- oder Resource-Owner-Password-Grants.
- Exakte Redirect-URI-Prüfung; einmalige, kurzlebige Authorization Codes; Tokenendpunkt mit korrekter Client-Authentifizierung.
- Consent-Screen mit Clientnamen, verständlichen Scopes, Zustimmung und Widerruf; keine stillschweigende Zustimmung für unbekannte Drittclients.
- Access-Token-Lebensdauer und Scope-/Audience-Bindung, Token-Revocation, Client-Deaktivierung und Auditlog.
- OIDC zusätzlich: signierte ID-Tokens mit `iss`, `sub`, `aud`, `iat`, `exp` und ggf. `nonce`, UserInfo-Endpoint, Discovery (`.well-known/openid-configuration`) und JWKS-Veröffentlichung.
- Asymmetrische Signaturschlüssel, `kid`, dokumentierte Rotation inkl. Überlappungszeitraum. Private Keys gesichert und niemals über JWKS ausgegeben.
- Keine Bearer-Token-Ausstellung allein aufgrund einer ungeprüften WordPress-Session; explizite Client-, Scope-, Consent- und Sessionprüfung.
- Public Clients ausschließlich mit PKCE und klar dokumentiertem Bedrohungsmodell.

### Explizite Architekturentscheidungen vor Implementierung

1. OAuth-only Resource Authorization oder vollwertiger OIDC-Provider? (Ziel: beides, OIDC gesondert abschließbar.)
2. Welche Ressourcen/APIs werden geschützt? WordPress-REST-API nicht pauschal per OAuth freischalten; Scope-to-Capability-Mapping definieren.
3. Tokenformat: opaque oder JWT; Validierungs-/Revocation-Modell, Audience, Key-Rotation, Uhrzeitdrift.
4. Ob Refresh Tokens gebraucht werden, inklusive Rotation, Wiederverwendungserkennung und Widerruf.
5. Sollen Clients extern administriert werden oder nur von WordPress-Admins angelegt werden? MVP: Adminanlage.
6. Single Site vs. Multisite: Issuer, RP-IDs, Mandantentrennung, Netzwerk-User.
7. Verlässliche Bereitstellung der `.well-known`-Routen unabhängig von Permalinkkonfiguration und Reverse Proxy.
8. OAuth-Server-Sicherheitsprüfung vor Public Release zwingend.

### Erwartete HTTP-Endpunkte (Namen noch nicht fest)

- `/.well-known/openid-configuration` und JWKS (OIDC-Modus).
- `/authorize`, `/token`, `/userinfo`, `/revoke` über eindeutig zugeordnete Rewrite-/REST-Routen gemäß Spezifikation.
- Kein öffentliches, frei zugängliches Dynamic Client Registration im MVP.

## 6. WordPress-spezifische Integration

- `login_enqueue_scripts`, `login_form`: Login-UI ergänzen, nur auf WordPress-Loginseite.
- `show_user_profile`/`edit_user_profile`: Passkeys und Identity-Links in Profilen; strikte Capability-Checks.
- REST-Routen unter `rest_api_init` anmelden und für jede Route eine passende `permission_callback` definieren.
- `wp_set_auth_cookie()` und `wp_set_current_user()` nur nach erfolgreicher, vollständiger Authentifizierung; mit den relevanten WordPress-Login-Hooks und Session-Policies integrieren.
- `wp_safe_redirect()` und `wp_validate_redirect()` für interne Redirects; externe OAuth-Redirects ausschließlich an vorab validierte Provider-Endpoints, nie über frei übergebene URL.
- Passkey-Login-Session und OIDC-Login müssen mit vorhandenen Sicherheits-/2FA-Plugins getestet werden. Eine alternative Loginmethode darf notwendige MFA-/Policy-Hooks nicht heimlich umgehen.
- Auf Installationen mit geänderten Login-URLs durch Security-Plugins: Kompatibilitätsprüfung, keine Annahme fester Pfade für Login-UI.
- Anforderungen: HTTPS produktiv, aktuelle unterstützte WordPress-/PHP-Versionen bei Release verbindlich festlegen; MySQL/MariaDB über `$wpdb`.

## 7. Konfiguration und UI

**SW Securelogin → Übersicht / Passkeys / Externe Loginprovider / Authorization Server / Sicherheit / Ereignisprotokoll**. Menüpunkte optionaler Module nur bei Aktivierung anzeigen, inaktive Module auf Übersicht mit Status präsentieren.

Global: Modulauswahl, Sicherheitsstatus (HTTPS, PHP-Erweiterungen, korrektes Origin/Issuer-Setup), Rate Limits, Retention, Recovery-Hinweise. Modul A: Credential-Limit, WebAuthn-RP-ID (sicher ermittelt und validiert), Origins, Passwortfallback. Modul B: Provider, Mapping/Linking, Callback-Status. Modul C: Clients, Scopes, Consent, Signaturschlüssel und Tokens.

**Default:** Passwort-Login bleibt verfügbar. Verbindliche reine-Passkey- oder reine-OIDC-Policies erst nach spezifiziertem Recovery-Verfahren und Schutz vor Admin-Lockout.

## 8. Sicherheits- und Betriebskonzept

- WebAuthn: W3C WebAuthn, FIDO2; Challenge, Origin, RP-ID, UV, UP, Signatur und User-Bindung prüfen.
- OAuth/OIDC: OAuth 2.0 Security BCP (RFC 9700), PKCE (RFC 7636), OIDC Core/Discovery, JOSE/JWK-Regeln; kein Implicit/Password Grant.
- Sitzungen: Authentifizierungsereignis atomar abschließen, keine Zwischenzustände als Login akzeptieren; Replay, Brute Force und Race Conditions abwehren.
- CSRF: WordPress-Nonces zusätzlich zu Capability-Checks für authentifizierte Mutationen; OAuth-State und WebAuthn-Challenges erfüllen jeweils andere Sicherheitszwecke.
- Ausgabe/Logs: Ein- und Ausgaben validieren und kontextgerecht escapen; keine Secrets, privaten Keys, Assertions, Codes oder Tokens in Logs.
- Secrets: Schlüsselmaterial nicht im Klartext in gewöhnlichen Optionsseiten, Logs oder Exports. Verschlüsselung/Key-Source/Rotation vor Auth-Server-Start verbindlich definieren; WP-Salts allein sind kein vollständiges KMS-Konzept.
- Retention: Auditlog-Events pseudonymisiert/gedrosselt, DSGVO-Löschung/Export, konfigurierbare Aufbewahrungsdauer.
- Multisite, Reverse Proxies, Domainmigration, Cache/CDN und getrennte `home_url()`/`site_url()` explizit prüfen.
- Notfallplan: Backup/Restore, Migrations-Rollback, Admin-Recovery via WP-CLI unter lokaler Systemadministrator-Kontrolle.

## 9. Meilensteine und Abnahmekriterien

### M0 — Grundlagen und Architektur

- [x] Produktname, Plugin-Slug, Lizenz, PHP-/WP-Mindestversionen und Modulverträge festlegen.
- [x] Composer/Autoloading, Coding Standards, CI, Testbasis, Datenbank-Migrationen einrichten.
- [x] Core-Registrierung und Admin-Navigation erstellen.
- [x] Bedrohungsmodell für Login-Session und Passkey-Enrollment dokumentieren.

**Abnahme:** Aktivierbares Plugin ohne Loginänderung, Tests/Static Analysis laufen, Module können sicher deaktiviert werden.

### M1 — Passkey-Registrierung

- [ ] Bibliothek evaluieren/integrieren, korrektes Origin/RP-ID-Konzept.
- [ ] DB-Schema, Credential-Repository, atomare Challenges.
- [ ] Profilansicht, Registrierung, Benennung, Widerruf und Reauth.
- [ ] Mehrere Passkeys pro Nutzer, User-Löschung und Datenschutz.

**Abnahme:** Zwei unabhängige Passkeys für denselben Account registrierbar, getrennt widerrufbar, Replay/CSRF verweigert.

### M2 — Passkey-Login (v1.0-Kern)

- [ ] Benutzername + Passkey-UI, neutraler Fehlerpfad.
- [ ] Assertion-Verifikation mit Tests für alle Prüfpunkte.
- [ ] WordPress-Session, Login-Hooks, sichere Admin-Weiterleitung.
- [ ] Rate Limits, Audit, Passwortfallback und Geräte-/Browser-Tests.

**Abnahme:** Login klappt mit mehreren Credentials; falsche Signatur, falscher User, falsche Origin, Replay und abgelaufene Challenge schlagen fehl; WordPress-Session nur nach erfolgreicher Prüfung.

### M3 — Stabilisierung / v1.0-Release

- [ ] Security Review, Plugin Check, WPCS, PHPStan, PHPUnit, Browser-E2E.
- [ ] Multisite-/Reverse-Proxy-Verhalten dokumentieren, unterstützte Matrix testen.
- [ ] i18n (Deutsch/Englisch), Accessibility, readme.txt, Datenschutzhinweise, Upgradepfad.
- [ ] GitHub Release mit reproduzierbarem ZIP einschließlich produktionsfähiger Vendor-Abhängigkeiten.

**Abnahme:** Keine offenen Critical/High Findings; dokumentierte Recovery; Releases ohne Dev-Abhängigkeiten.

### M4 — OIDC Client / externer Login (v1.1+)

- [ ] Provider-Discovery/JWKS, geschützte Konfiguration, SSRF-Schutz.
- [ ] Authorization Code + PKCE, State, Nonce, Callback-Validierung.
- [ ] ID-Token-Validierung, Identity-Linking auf `iss`+`sub`.
- [ ] Mehrere Provider, Admin-UI, Rollback und Integrations-/Negativtests.

**Abnahme:** Login mit mindestens zwei standardkonformen OIDC-Providern; manipulierte Tokens/Claims, Mix-up, CSRF und Account-Takeover-Versuche scheitern.

### M5 — Authorization-Server-Design (Vorstufe v2.0)

- [ ] Separate Sicherheits- und Protokollspezifikation mit Scope- und Ressourcenmodell.
- [ ] Bibliotheksauswahl, Schlüsselspeicherung, Consent- und Tokenmodell.
- [ ] Interoperabilitäts-Testsuite und unabhängige Security Review planen.

**Abnahme:** Freigegebenes Design mit Threat Model; **kein produktiver Serverbetrieb**.

### M6 — OAuth 2.0 Authorization Server (v2.0)

- [ ] Clientregistrierung, Authorization Code/PKCE, Token, Consent, Revocation.
- [ ] Scope-to-resource/Capability-Prüfung und Auditing.
- [ ] Interoperabilitäts-, Attack- und Lasttests.

**Abnahme:** RFC-konformes Verhalten für die dokumentierten Features und bestandenes Security Review.

### M7 — OIDC Provider (v2.x)

- [ ] ID-Token/JWKS/Discovery/UserInfo und Key Rotation.
- [ ] `sub`-Stabilität, Claim-Sparsamkeit, Privacy-/Consent-Tests.
- [ ] OIDC-Conformance-Tests, unabhängiges Audit.

**Abnahme:** Funktionierender, interoperabler OIDC-Login externer Testclients; keine offenen Critical/High Findings.

## 10. Qualitätssicherung

- Unit-Tests für Policies, Ablaufzustände, Zuordnung, Fehler-/Replayfälle.
- Integrationstests mit WordPress-Testumgebung und echter Datenbank.
- Browser-E2E für Chrome/Edge, Firefox, Safari wo technisch verfügbar; Passkey-Geräte simulieren oder dedizierte reale Tests.
- Security-Negativfälle: falscher Benutzer, falsche RP-ID/Origin, abgelaufene/verwendete Challenge, paralleler Replay, CSRF, Session Fixation, Redirect-Injection, Username Enumeration.
- Für OIDC: abweichender Issuer, falsches Audience, JWKS-Manipulation, `alg=none`, Nonce-/State-/PKCE-Fehler, Redirect-URI-Mismatch, Provider-Mix-up, Account-Linking-Angriffe, SSRF.
- Für Auth-Server: Code Reuse, Consent Bypass, Client Impersonation, Token Audience Confusion, Key Rotation, Refresh Token Replay (falls unterstützt).

## 11. Versionsstrategie und offene Entscheidungen

- `1.0`: Core + Passkeys; bestehender Passwortlogin bleibt.
- `1.1+`: OIDC-Client mit mehreren externen Loginprovidern.
- `2.0+`: OAuth-Authorization-Server erst nach freigegebenem Sicherheitsdesign.
- `2.x`: OIDC-Provider bei nachgewiesener Interoperabilität und Audit.

**Vor Entwicklungsbeginn entscheiden:** Minimalversionen PHP/WP; Composer-Bibliotheken und Namespace-Prefixing; Einsatz im Multisite-Netzwerk; ob Login per externem IdP ausschließlich zu wp-admin führt (Standard: ja); exakte Key-Management-Strategie für späteren Provider; ob Login-Policies pro Rolle unterstützt werden sollen.

## 12. Referenzen

- WordPress Developer Handbook: https://developer.wordpress.org/
- WordPress Coding Standards: https://developer.wordpress.org/coding-standards/wordpress-coding-standards/
- WebAuthn: https://www.w3.org/TR/webauthn-3/
- OAuth 2.0 Security Best Current Practice (RFC 9700): https://www.rfc-editor.org/rfc/rfc9700
- Proof Key for Code Exchange (RFC 7636): https://www.rfc-editor.org/rfc/rfc7636
- OpenID Connect Core: https://openid.net/specs/openid-connect-core-1_0.html
- OpenID Connect Discovery: https://openid.net/specs/openid-connect-discovery-1_0.html
