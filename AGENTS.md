# Projektregeln für Coding Agents

- Befolge PLAN.md und CODE-STYLE.md.
- Verfasse Dokumentation möglichst knapp und ohne Wiederholungen, um Tokens zu sparen. Beschränke sie auf nötige Entscheidungen, Sicherheitsdetails und Testhinweise; verlinke vorhandene Dokumentation statt Inhalte zu duplizieren.
- Gib nach jeder Implementierung eine konkrete Übersicht, was der Benutzer selbst testen kann oder sollte. Nenne den Einstiegspunkt, die Schritte und das erwartete Ergebnis. Unterscheide automatisierte Prüfungen von noch offenen manuellen Prüfungen.
- Halte TODO.md nach abgeschlossenen Arbeitspaketen aktuell.
- PHPStan Level 8 deckt den gesamten produktiven PHP-Code ab (`composer analyse`), ohne Baseline und ohne Ignores.

## Tests und Coverage

- Jede funktionale Codeänderung (neue Funktionen, Fehlerbehebungen und Refactorings) muss durch passende automatisierte Tests abgedeckt sein; Tests im selben Arbeitsschritt ergänzen oder anpassen.
- Pflicht ist eine Zeilenabdeckung von **> 80 %** des produktiven Codes je Plugin; genau 80 % reichen nicht. PHP und JavaScript/TypeScript, soweit vorhanden, jeweils prüfen. Bestehende strengere Coverage-Grenzen bleiben verbindlich.
- Vor Abschluss relevante Tests und Coverage-Prüfungen ausführen und Ergebnisse nennen. Fehlende Coverage-Infrastruktur ergänzen; fehlende Messwerte gelten nicht als erfüllte Vorgabe. Keine produktiven Dateien ausschließen oder Grenzen senken, um die Vorgabe zu umgehen.

Vorhanden: PHPUnit-/WordPress-Coverage und SonarQube; siehe `docs/SONARQUBE.md` und `sonar-project.properties`.
