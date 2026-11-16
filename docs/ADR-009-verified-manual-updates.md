# ADR 009: Verifizierte manuelle GitHub-Updates

Entscheidung vom 15.11.2026: Bis zum Store-Wechsel einen lokalen, pluginspezifischen Provider aus ChurchTools Suite 49dc66f und der nativen sw-backupit-Pluginzeile adaptieren. Keine gemeinsame Laufzeitbibliothek oder dynamischer Code aus anderen Plugins. Login-/Passkey-Architektur bleibt unabhängig.

Vertrauensgrenze ist das lokal vorab verteilte Ed25519-Public-Key-Material. Sodium prüft exakte Manifestbytes; SHA-256 bindet das tatsächlich heruntergeladene ZIP. Unsigned-/Hash-only-Verfahren und ein erstmals remote geladener Schlüssel sind ausgeschlossen. WordPress führt die manuell autorisierte Installation aus; der Provider prüft vor Core-Extraktion. Eigene Kopierinstaller und automatische Installation sind ausgeschlossen.

Persistente Tagesgrenze und Höchstversion liegen außerhalb von Transients, auf Multisite im Netzwerkzustand. Ein kurzer MySQL/MariaDB-Mutex serialisiert Lease-Akquisition, Compare-and-Delete schützt den Nachfolger, Eigentümerprüfung und Ergebnisübernahme sind unter demselben Mutex wie die Lease-Akquisition atomar; ein alter Besitzer kann kein Nachfolgeergebnis überschreiben. Ohne atomaren Mutex oder dauerhaft speicherbaren Versuchszeitpunkt kein HTTP. Die Netzwerkmutex-ID bleibt auch nach `switch_to_blog()` gleich. GET_LOCK-inkompatible Datenbankproxies unterstützen diesen Updateweg deshalb nicht.

Alternativen: ausschließlich manuelle ZIP-Verteilung vermeidet den Provider, bietet aber keine verifizierten Angebote in der Pluginübersicht. WordPress.org ist das spätere Ziel und benötigt einen Provider-/URI-freien Store-Build. Ein gemeinsamer globaler SW-Keyring würde Vertrauensbereiche vermischen und wird nicht verwendet.

Grenzen: kompromittierter PHP-/Signierprozess liegt außerhalb dieser Schutzgrenze; ein erstmaliger Client erkennt ohne zeitgebundenen signierten Channel kein absichtlich vorenthaltenes neueres Release. GitHub-CDN-Änderungen können eine bewusste Clientanpassung verlangen. Produktive Aktivierung erfordert Betreiber-Schlüsselverteilung, geschützte GitHub-Umgebung und signierten Release. Teststrategie und Betrieb: [TESTING.md](TESTING.md), [GITHUB-UPDATES-BETRIEB.md](GITHUB-UPDATES-BETRIEB.md).

## Plugin-Check-Gate für diese Distribution

Die aktuelle Distribution erfolgt ausdrücklich über signierte GitHub-ZIPs, nicht
über WordPress.org. Das entspricht der Übergangsentscheidung oben; das langfristige
WordPress.org-Ziel aus PLAN.md setzt einen separaten Provider-/URI-freien Build voraus.

Plugin Check 2.1.0 läuft unverändert mit allen Prüfungen auf dem installierten ZIP.
`bin/check-plugin-check.py --distribution github` prüft vor jeder Ausnahme exakt den
Header `https://github.com/sw-wordpress/sw-securelogin`, die Textdomain und sämtliche
literalen Werte/Tokens aus `Config/source.php` und `Config/descriptors.php`
(Repository einschließlich ID
1439006733, einzig erlaubter Slug, Hauptslug und Speicherschlüssel, inklusive
Direktzugriffsschutz). Es wird kein PHP aus dem Artefakt ausgeführt. Änderungen an
dieser Konfiguration erfordern eine erneute Prüfung der Distributionspolicy.

Nur die beiden exakten `plugin_updater_detected`-ERROR-Meldungen aus dem versionierten
2.1.0-Report werden jeweils höchstens einmal akzeptiert: das WordPress.org-Verbot des
Update-URI-Headers und das WordPress.org-Verbot von `site_transient_update_plugins`.
Meldung, Code, Schweregrad, Dokumentations-URL und Position müssen exakt passen.
Dies sind Store-Zulassungsregeln, keine Sicherheitsfreigabe des Updaters. Alle anderen
ERRORs, insbesondere fehlender Direktzugriffsschutz, bleiben fatal; ungültige Reports,
unbekannte oder geänderte Updater-Findings und falsche Konfiguration ebenfalls.
Die drei bekannten `update_modification_detected`-Warnungen bleiben im Rohreport;
geänderte Updater-Warnungen benötigen Review. Keine Prüfgruppe wird ausgeschlossen,
kein Finding im Rohreport verändert. Dieser wird auch bei Fehlern als CI-Artefakt
aufbewahrt. Fixture- und Negativtests: [TESTING.md](TESTING.md). Für einen künftigen
WordPress.org-Build gilt diese GitHub-Ausnahme ausdrücklich nicht.
