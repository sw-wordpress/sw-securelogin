# AGENTS.md – WordPress Plugin Development

## Grundlagen
- Befolge vorhandene `PLAN.md`, `CODE-STYLE.md` und relevante Projektdokumentation. Bestehende Architektur und Konventionen respektieren.
- Bevorzuge einfache, wartbare Lösungen. Keine unnötigen Abhängigkeiten, Abstraktionen oder Workarounds.
- Nutze WordPress-APIs und etablierte Standards (WPCS, PHP, JavaScript/TypeScript). Framework-unabhängige Komponenten bleiben frei von WordPress-Abhängigkeiten.

## Unsicherheit und Entscheidungen
- Bei unklaren Anforderungen, Architektur- oder Sicherheitsentscheidungen **nachfragen statt Annahmen treffen**.
- Zuvor Code, Tests und Dokumentation prüfen. Bei mehreren sinnvollen Lösungen Alternativen mit Empfehlung nennen.
- Kleine, risikoarme Details eigenständig entscheiden. Keine spekulativen Fixes oder unbeabsichtigten Verhaltensänderungen.

## Sicherheit und Kompatibilität
- Eingaben validieren und sanitizen, Ausgaben kontextgerecht escapen. Berechtigungen und CSRF-Schutz passend zum Zugriffskontext prüfen.
- Datenbankzugriffe absichern, Geheimnisse schützen und sensible Daten nicht protokollieren.
- Bestehende APIs, Hooks, Datenstrukturen und Einstellungen abwärtskompatibel halten. Breaking Changes vorher abstimmen.
- Keine destruktiven Operationen auf produktiven Systemen. Deinstallation darf ausschließlich eigene Plugin-Daten entfernen.

## Codequalität
- PHPStan Level 8 prüft den gesamten produktiven PHP-Code, ohne Baseline oder Ignores. Stubs und Testhilfen getrennt halten.
- Warnungen von PHPStan, SonarQube und Lintern an ihrer Ursache beheben, nicht durch Suppressions oder kosmetische Änderungen umgehen.
- Keine Fake-Implementierungen oder stillschweigend ignorierten Fehler. Unvollständige Funktionen ausdrücklich kennzeichnen.
- Öffentliche APIs dokumentieren und bestehende `@since`-/`@deprecated`-Angaben respektieren.
- Benutzerseitige Texte übersetzbar halten; vorhandene Sprachdateien und Übersetzungen synchronisieren.

## Tests und Coverage
- Jede funktionale Änderung benötigt passende automatisierte Tests im selben Arbeitsschritt, einschließlich Fehlerfällen und relevanter Grenzfälle.
- **Zeilenabdeckung > 80 %** des produktiven Codes je Plugin, getrennt für PHP und JavaScript/TypeScript, soweit vorhanden. Strengere bestehende Grenzen gelten weiterhin.
- Fehlende Coverage-Infrastruktur ergänzen. Keine produktiven Dateien ausschließen, Tests abschwächen oder Grenzwerte senken, um Vorgaben zu erfüllen.
- Vor Abschluss relevante Tests, Coverage, statische Analyse und Linter ausführen. Fehler beheben und Ergebnisse nennen.
- Nicht ausführbare Prüfungen ausdrücklich als ungeprüft kennzeichnen.
- Browser- und End-to-End-Tests bevorzugt mit Playwright in einem Docker-Container ausführen. Fehlende Testinfrastruktur bei Bedarf einrichten. Relevante Benutzerabläufe einschließlich Formularen, Interaktionen und Fehlerfällen prüfen. Screenshots und Fehlermeldungen zur Analyse nutzen.
- Vorhanden: PHPUnit-/WordPress-Coverage und SonarQube; siehe `docs/SONARQUBE.md`, `docs/TESTING.md` und `sonar-project.properties`.

## Workflow und Dokumentation
- Änderungen zunächst im Kontext bestehender Implementierungen analysieren, anschließend implementieren, testen und prüfen.
- Dokumentation knapp und ohne Wiederholungen halten. Bestehende Dokumentation verlinken statt duplizieren.
- Öffentliche Schnittstellen und betroffene Dokumentation im selben Arbeitsschritt aktualisieren.
- Vorhandene `TODO.md` und vergleichbare Fortschrittsdateien bei Bedarf aktualisieren.
- Nach jeder Implementierung kurz zusammenfassen: Änderungen, automatisierte Prüfergebnisse und konkrete manuelle Testschritte mit erwartetem Ergebnis.
- Keine erfolgreichen Prüfungen, vollständige Implementierungen oder Commits behaupten, die nicht tatsächlich erfolgt sind.

## Statische Ressourcen und Cache-Busting
- Eingebundene CSS- und JavaScript-Dateien immer mit der aktuellen Plugin-Version als Versionsparameter versehen, um veraltete Browser-Caches nach Updates zu vermeiden.
- In WordPress bevorzugt wp_enqueue_script() und wp_enqueue_style() mit der zentral definierten Plugin-Version verwenden.
- Keine fest codierten Versionsnummern, Zeitstempel oder zufälligen Cache-Busting-Parameter nutzen.
- Auch dynamisch eingebundene Assets und weitere statische Ressourcen berücksichtigen.
