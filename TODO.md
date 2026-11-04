# SW Securelogin — Aufgaben und Meilensteine

Stand: 04.11.2026. Architektur und Abnahmekriterien stehen in [PLAN.md](PLAN.md),
verbindliche Implementierungsregeln in [CODE-STYLE.md](CODE-STYLE.md).
Diese Datei dokumentiert den Arbeitsstand und die nächsten konkreten Aufgaben.
Bei Änderungen an Meilensteinen beide Dateien konsistent halten.

## Aktueller Stand

M0 bis M2 sind implementiert (Entwicklungsvorschau 0.3.0).
Passkeys lassen sich im eigenen Profil registrieren, benennen und widerrufen.
Benutzernamenbasierte Passkey-Anmeldung und Passkey-Reauthentication sind verfügbar.
OIDC ist noch nicht implementiert; der Passwortlogin bleibt erhalten.
Das Modul ist standardmäßig deaktiviert und wird auf der Adminübersicht ausdrücklich aktiviert.
Aktuell vorgesehen: PHP ab 8.2, WordPress ab 6.7, Einzelinstallationen,
GPL-2.0-or-later. Vor Veröffentlichung die unterstützte Versionsmatrix erneut prüfen.

## M0 — Grundlagen und Architektur

- [x] Plugin-Einstiegspunkt, Composer-Autoloading und versionierte Abhängigkeiten anlegen.
- [x] Modulvertrag und Registry mit deaktivierten Standardwerten und Abhängigkeitsprüfung implementieren.
- [x] Geschützte Adminübersicht und Hinweis für nicht unterstütztes Multisite anlegen.
- [x] Versionierte Migrationen mit Datenbanksperre, Wiederaufnahme und verifizierten Versionsschritten implementieren.
- [x] Einstellungen mit leerer Modulliste initialisieren; Daten bei Deaktivierung und Deinstallation erhalten.
- [x] Bedrohungsmodell und Architekturentscheidungen dokumentieren.
- [x] Deutsche und englische Übersetzungen samt Übersetzungsvorlage anlegen.
- [x] PHPUnit, WPCS, PHPStan, Lint und CI konfigurieren.
- [x] Mozart-Konfiguration aus sw-backupit übernehmen: lokaler Namespace, isolierter Produktionsbuild und Kollisions-/Autoload-Smoke-Tests.
- [x] Sieben Unit-Tests und elf Integrationstests gegen echtes WordPress erfolgreich ausführen.
- [x] Composer-Validierung, PHP-Lint, WPCS, PHPStan und Abhängigkeitsaudit erfolgreich ausführen.

Lokal geprüft mit WordPress 6.7, PHP 8.3 und MariaDB. Tests umfassen unter anderem
Migrationsfehler, Wiederaufnahme, Sperrkonkurrenz, ungültige Versionen, Berechtigungen
sowie Passwortauthentifizierung und Sitzungserhalt bei Deaktivierung.

CI-Verifikation (Actions-Lauf 41090636610, zweiter Versuch):

- [x] GitHub-Actions-Läufe prüfen; alle vier Test-Jobs erfolgreich. Login-Timeout beim ersten Versuch, Ursache offen; Fehlerdiagnose im Browser-Test ergänzen.
- [x] Matrix WordPress 6.7 / PHP 8.2 / MySQL 8.0 bestätigen.
- [x] Matrix WordPress 7.1.4 / PHP 8.3 / MariaDB 11.4 bestätigen.

## M1 — Passkey-Registrierung

Implementiert und lokal geprüft:

- [x] Gepflegte WebAuthn-Bibliotheken hinsichtlich PHP-Kompatibilität, Lizenz, Pflege und Validierungsumfang evaluieren; Auswahl als ADR dokumentieren.
- [x] Bibliothek integrieren und Abhängigkeiten sperren; Mozart-Build anhand realer WebAuthn-Positiv-/Negativfälle prüfen, insbesondere Serialisierung, dynamische Klassen und Ressourcen.
- [x] HTTPS-, Origin- und RP-ID-Konzept festlegen: exakte gepinnte Admin-Origin; Einschränkungen bei abweichenden REST-Origins und Reverse Proxies dokumentieren.
- [x] Modulstart an erfolgreiche Migrationen und erfüllte Anforderungen binden; Einstellungen über einen validierenden Repository-Zugriff lesen.
- [x] Produktionsschema und Migrationen für Credentials und kurzlebige Transaktionen definieren.
- [x] Credential-Repository mit eindeutiger Credential-ID und serverseitiger Benutzerzuordnung implementieren.
- [x] Challenges mit Ablauf, Benutzer-/Sitzungsbindung und atomarem Einmalverbrauch implementieren.
- [x] Frische Einmal-Reauthentication per WordPress-Passwort implementieren; eine vorhandene Sitzung allein genügt nicht. Passkey-basierte Reauthentication folgt mit M2.
- [x] Registrierungsrouten mit Session, Capability, REST-Nonce und frischer Reauthentication absichern.
- [x] Attestation und Credential-Daten vollständig durch die Bibliothek prüfen, einschließlich Challenge, Origin, RP-ID und UP/UV.
- [x] Profiloberfläche für Hinzufügen, Auflisten, Benennen und einzelnen Widerruf implementieren.
- [x] Credential-Limit und mehrere Passkeys pro Benutzer unterstützen.
- [x] Benutzerlöschung, Datenschutzexport/-löschung und Aufbewahrungsregeln integrieren.
- [x] Positive und negative Unit-, Integrations- und Browsertests ergänzen, insbesondere Replay, parallelen Verbrauch, CSRF und fremde Benutzerzuordnung.

Abnahme: Zwei unabhängige Passkeys für dasselbe Konto registrieren und einzeln
widerrufen können; Replay und CSRF werden abgewiesen.

Verifikation: 25 Unit-Tests und 25 Integrationstests unter PHP 8.2 und 8.3,
WordPress 6.7 / MariaDB 11.1.3. Chromium-E2E mit zwei virtuellen Authenticatoren,
Umbenennung und einzelnem Widerruf; Mozart-Build mit echten Signatur-/Serialisierungstests.
Release-Prüfungen für reale Geräte, weitere Browser, MySQL und aktuelle WordPress-Versionen
bleiben offen. Details und Grenzen stehen in [ADR 004](docs/ADR-004-passkey-enrollment.md).

## Profiloberfläche — Überarbeitung

- [x] Registrierung, Passwortbestätigung und bestehende Passkeys visuell gliedern.
- [x] Profil-CSS kapseln, mobile Darstellung und Statusmeldungen verbessern.
- [x] Benutzer-Testübersicht nach Implementierungen als Projektregel in AGENTS.md festhalten.
- [x] Darstellung und Aktionen im Browser bei 1440 px und 390 px bestätigen; Screenshots visuell geprüft.

## SonarQube und automatische Tests

- [x] SonarQube-Cloud-Konfiguration aus sw-backupit auf dieses Repository übertragen.
- [x] Unit-/WordPress-Coverage für Originalquellen konfigurieren; Mozart-Artefakt separat testen.
- [x] Clover-Berichte mit Pfadprüfung und nachvollziehbarer Zusammenführung bereitstellen; lokal mit Xdebug verifiziert (28 Unit-/25 Integrationstests).
- [x] Workflow für Push, PR und manuellen Start mit Artefakten und Quality-Gate-Prüfung erweitern; YAML und Ausdrücke mit actionlint geprüft.
- [x] Mozart-Standardaufruf mit dem eigenen Adapter vergleichen; notwendige Root-Import-Verarbeitung durch Kollisions-Test bestätigen (ADR 003).
- [x] SONAR_TOKEN und SONAR_ORGANIZATION: Remote-Scanner akzeptiert Zugriff und Projektzuordnung.
- [x] CI-basierte SonarQube-Analyse einschließlich Coverage-Import und Quality Gate bestätigen; Lauf 41922137903 ist erfolgreich. Der frühere Konflikt mit Automatic Analysis tritt dort nicht mehr auf.

## M2 — Passkey-Login

- [x] Benutzernamenbasierte Passkey-Anmeldung auf der WordPress-Loginseite ergänzen.
- [x] Frische Reauthentication alternativ per vorhandenem Passkey ergänzen.
- [x] Transaktionsgebundene Assertion-Optionen und vollständige Bibliotheksvalidierung implementieren.
- [x] Neutrale Antworten und Schutz vor Benutzernamen-Erkennung implementieren.
- [x] Gemeinsame Dienste für Authentifizierungspolicies und WordPress-Sitzungsausstellung implementieren.
- [x] WordPress-Login-Hooks und sichere Adminweiterleitung integrieren; unbekannte Authentifizierungs-Erweiterungen bis zur Adapterprüfung sperren.
- [x] Rate Limits, Replay-Schutz, datensparsames Audit und Aufbewahrungsregeln ergänzen.
- [x] Signaturzähler und Backup-/Sync-Eigenschaften ohne pauschale Klonsperre behandeln.
- [x] Passwortfallback erhalten; Recovery dokumentieren.
- [x] Chromium mit zwei virtuellen Authenticatoren sowie Negativtests für Challenge, Ablauf, Origin, RP-ID, Signatur, Credential-ID, Benutzerbindung und UP/UV ausführen.
- [x] Nachweisen, dass fehlgeschlagene Authentifizierung keine Sitzung erzeugt.
- [x] Separate Coverage-Gates >80% für PHP und JavaScript samt LCOV-Import in SonarQube ergänzen.
- [x] Geprüften Adapter für WP Armour Honeypot (`wpae_wplogin_extra_validation`) ergänzen; dessen Login-Filter blockierte die Passkey-Anmeldung.
- [x] Loginseite: standardmäßig nur Benutzername/E-Mail und Passkey-Button; „Log in with password“ blendet das Passwortformular ein. Anmeldung auch per E-Mail-Adresse.

Abnahme: Anmeldung mit mehreren Credentials funktioniert; manipulierte, abgelaufene
oder wiederverwendete Assertions werden ohne Sitzungsausstellung abgewiesen.

Verifiziert: 53 Unit-/60 Integrationstests; PHP 8.2/8.3, WordPress 6.7/7.1.4,
Mozart und Chromium. Finale Coverage: PHP 89,44 %, JavaScript 95,9 %; beide >80 %.

Entscheidungen und Grenzen: [ADR 005](docs/ADR-005-passkey-login.md).
Reale Geräte, Firefox/Safari und konkrete MFA-/Security-Plugin-Adapter bleiben M3-Abnahmen.

## M3 — Stabilisierung und Release 1.0

- [ ] Security Review, Plugin Check und vollständige Qualitätsprüfungen durchführen.
- [ ] Unterstützte WordPress-/PHP-/Datenbankmatrix und Browser-E2E prüfen.
- [x] Sicherheitsplugins, geänderte Login-URLs und MFA-Integration testen (WP Armour/WPS Hide Login; Two Factor ohne Adapter sicher gesperrt).
- [x] Multisite-Einschränkungen, Proxy-Header und Domain-/RP-ID-Wechsel dokumentieren und automatisiert testen; produktionsgleiche Proxy-Abnahme bleibt separat offen.
- [ ] Übersetzungen aller neuen Oberflächen sowie Accessibility vervollständigen.
- [x] Readme, Datenschutzhinweise, Upgrade-/Backupverfahren und Admin-Recovery vervollständigen.
- [x] Bewusste Datenlöschoption und Uninstall-Policy festlegen und implementieren: Standardaufbewahrung, ausdrückliche Zustimmung mit zusätzlicher Browserabfrage; nur Plugin-Daten bei Deinstallation löschen.
- [x] Reproduzierbares Release-ZIP mit Produktionsabhängigkeiten ohne Dev-Abhängigkeiten bauen.
- [x] Finales ZIP in frischer WordPress-Instanz einschließlich Registrierung und Login mit virtuellen Authenticatoren prüfen.
- [ ] Release erst ohne offene Critical-/High-Findings veröffentlichen.

M3-Arbeitspakete vom 04.11.2026 (weiterhin Vorschau, kein 1.0-Release):

- [x] Zentrale Plugin-Version für alle Login-/Profil-Assets und Regressionstests ergänzen.
- [x] WordPress.org-Readme und Betriebsdokumentation für Backup, Upgrade, Rollback, Proxy, Domainmigration und Admin-Recovery ergänzen.
- [x] Deterministisches Produktions-ZIP mit SHA-256, Paket-Negativtests und Smoke-Tests des entpackten ZIPs bereitstellen.
- [x] Finales ZIP in frischer WordPress-6.7-/MySQL-8.0-Instanz installieren; Registrierung/Login mit zwei virtuellen Passkeys prüfen.
- [x] ZIP-Integration auf WordPress 6.7 / PHP 8.2 / MySQL 8.0 und WordPress 7.1.4 / PHP 8.3 / MariaDB 11.4 ausführen.
- [x] WP Armour 2.4.02 und WPS Hide Login 1.9.19 mit geändertem Loginpfad im vollständigen Browserfluss prüfen.
- [x] Reales Two Factor 0.14.2 ohne Adapter abweisen; keine Sitzung erzeugen. Keine allgemeine MFA-Kompatibilität behaupten.
- [x] Tatsächliches Multisite-Netzwerk sowie gefälschte Proxy-Header und Domain-/Port-/Schemawechsel prüfen.
- [x] Deutsch-/Englisch-Kataloge gegen aktuellen Quelltext und kompilierte MO-Dateien prüfen; Tastatur- und Fallback-Browsertests ergänzen.
- [x] CI um ZIP-Installation, Plugin Check, Kompatibilitäts- und Multisite-Tests sowie ZIP-/Berichtsartefakte erweitern; actionlint lokal erfolgreich.
- [x] Bewusste Uninstall-Löschoption nach bestätigter Entscheidung ergänzen; Deaktivierung erhält immer die Daten. Details: [ADR 006](docs/ADR-006-uninstall.md).
- [ ] Reale Geräte, Firefox/Safari, Screenreader und produktionsgleicher Restore/Proxy: noch ungeprüft.
- [x] Remote-CI/SonarQube für den gemergten M3-Stand `fc08548` prüfen: alle sechs Jobs in [Lauf 41922137903](https://github.com/sw-wordpress/sw-securelogin/actions/runs/41922137903) erfolgreich; SonarQube-Analyse vom 04.11.2026, 22:09 UTC, Gate `OK`. Zwei bestehende Critical-Findings zu Lock-Hashes bleiben offen; siehe Security Review.

M3-Fortsetzung: Remote-Protokolle und aktuelle SonarQube-Abfragen bestätigen 56 Unit-
und 65 Integrationstests, beide ZIP-/Browser-Matrixläufe, Plugin Check ohne Fehler
(zwei geprüfte Warnungen), Kompatibilität und Multisite-Abweisung. CI-Coverage:
PHP 89,54 % (890/994), JavaScript 95,9 %. Lokal erneut erfolgreich: Composer-Validierung,
PHP-/JS-Lint, WPCS, PHPStan 8, 56 Unit-Tests und Produktionsabhängigkeitsaudits.
Reale Geräte/Browser, Screenreader, produktionsgleicher Proxy/
Restore und die zwei Critical-Findings bleiben Release-Blocker. Die folgende lokale
Messung stammt aus dem ursprünglichen M3-Arbeitspaket.

Lokale Prüfergebnisse: 56 Unit-/65 Integrationstests; PHP-Coverage 89,44 %, JavaScript
95,9 %. Composer-Audit für Produktionsabhängigkeiten und npm-Audit ohne Dev-Abhängigkeiten
melden keine bekannten Schwachstellen. Plugin Check 2.1.0: Readme-Header ergänzt;
null Fehler und zwei begründete Warnungen. Zwei vollständige Builds sind bytegleich;
Export/Import der isolierten Testdatenbank reproduziert den vollständigen Dump exakt.
Vollständiger Nachweis und Grenzen:
[Release-Prüfungen](docs/RELEASE.md), [Security Review](docs/SECURITY-REVIEW-M3.md),
[Betrieb und Recovery](docs/OPERATIONS.md).

Uninstall-Erweiterung nach bestätigter Policy: 56 Unit- und 74 Integrationstests
erfolgreich; PHP-Coverage 89,93 % (938/1043), JavaScript 96,1 %.
Browser bestätigt Abbrechen, Zustimmen und Zurücknehmen der Löschoption.
WPCS und PHPStan 8 erfolgreich. Die neuen Änderungen benötigen erneut Remote-CI;
der oben belegte grüne Lauf gilt für den vorherigen Stand `fc08548`.
Das neu gebaute, installierte ZIP besteht ebenfalls 74 Integrationstests und Chromium.
Plugin Check 2.1.0: null Fehler, dieselben zwei geprüften Warnungen.
Die echte WP-CLI-Deinstallation entfernt alle vier Tabellen und vier Optionen;
Passwortlogin bleibt möglich. Ein echtes Multisite-Netzwerk bewahrt Tabellen und
Einstellungen selbst bei zuvor gespeicherter Löschzustimmung.

## M4 — Externer OIDC-Login, Version 1.1+

- [ ] Providerverwaltung, geschützte Konfiguration und validierte Discovery-/JWKS-Abfragen implementieren.
- [ ] SSRF-Schutz einschließlich DNS, IP-Bereichen, Redirects und Antwortlimits umsetzen.
- [ ] Authorization Code Flow mit PKCE S256 sowie einmaligem State und Nonce implementieren.
- [ ] ID-Token-Signatur und alle erforderlichen Claims strikt prüfen.
- [ ] Identitäten ausschließlich über Issuer und Subject verknüpfen; Verknüpfung mit frischer Reauthentication absichern.
- [ ] Mehrere Provider, geschützte Adminoberfläche und sichere Trennung/Wiederherstellung integrieren.
- [ ] Mindestens zwei standardkonforme Provider und die im PLAN genannten Angriffe testen.

## M5 — Authorization-Server-Design

- [ ] Separate Protokollspezifikation und Bedrohungsmodell erstellen und freigeben.
- [ ] Ressourcen, Scopes und Capability-Zuordnung festlegen.
- [ ] Gepflegte Serverbibliothek auswählen.
- [ ] Clientverwaltung, Consent, Tokenformat und Tokenlebenszyklen spezifizieren.
- [ ] Schlüsselquelle, Speicherung, Rotation, Backup und Verantwortlichkeiten festlegen.
- [ ] Interoperabilitätstests und unabhängige Sicherheitsprüfung planen.

Vor freigegebenem Design keinen produktiven Authorization Server implementieren.

## M6 — OAuth-Authorization-Server, Version 2.0

- [ ] Clientverwaltung, exakte Redirect-URI-Prüfung und Clientauthentifizierung implementieren.
- [ ] Authorization Code mit PKCE, Tokenendpunkt, Consent und Revocation implementieren.
- [ ] Atomaren Codeverbrauch sowie Scope-/Audience-/Ressourcenbindung sicherstellen.
- [ ] Auditing, Angriffs-, Interoperabilitäts- und Lasttests durchführen.
- [ ] Unabhängige Sicherheitsprüfung vor produktiver Aktivierung bestehen.

## M7 — OIDC-Provider, Version 2.x

- [ ] Signierte ID-Tokens, JWKS, Discovery und UserInfo implementieren.
- [ ] Stabile Subjects, sparsame Claims und Consent-/Datenschutzregeln umsetzen.
- [ ] Schlüsselrotation einschließlich Überlappungszeitraum und Widerruf testen.
- [ ] OIDC-Conformance-Tests und unabhängiges Audit bestehen.

## Laufende Arbeitsregeln

- Neue Autoload-Formen wie files/classmap und weitere Kryptografiepakete vor Mozart-Freigabe explizit prüfen; aktuell sind PSR-4/PSR-0 freigegeben.
- Nur den jeweils bearbeiteten Meilenstein umsetzen; optionale Module bis zur vollständigen Sicherheitsprüfung deaktiviert halten.
- Sicherheitsentscheidungen als ADR dokumentieren; Abnahmekriterien und Negativtests vor der Implementierung festlegen.
- Passende Checks nach jeder Implementierungsänderung ausführen und verbleibende Prüflücken ausdrücklich festhalten.
- Keine privaten Schlüssel, Tokens, Assertions oder Challenges protokollieren.
- Status in dieser Datei nach abgeschlossenen Arbeitspaketen aktualisieren.

Testbefehle und die isolierte WordPress-Testumgebung stehen in
[docs/TESTING.md](docs/TESTING.md). Bisherige Sicherheitsentscheidungen:
[ADR 001](docs/ADR-001-foundation.md), [ADR 002](docs/ADR-002-migrations.md),
[ADR 003](docs/ADR-003-mozart.md), [ADR 004](docs/ADR-004-passkey-enrollment.md) und
[ADR 006](docs/ADR-006-uninstall.md) sowie [Bedrohungsmodell](docs/THREAT-MODEL.md).
