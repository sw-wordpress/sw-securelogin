# README-Standard für sw-* Plugins

Gilt für alle Repositories in `sw-wordpress`. Die `README.md` ist auf Englisch,
richtet sich zuerst an Endanwender ohne Entwicklungswissen und hat genau diese
Abschnitte in dieser Reihenfolge. Referenzumsetzung: [README.md](../README.md).

| # | Abschnitt | Regeln |
|---|-----------|--------|
| 1 | `# <Plugin-Name>` | Anzeigename aus dem Plugin-Header, keine weitere H1. |
| 2 | Badges | Eine Zeile, genau drei Badges in dieser Reihenfolge (siehe unten). |
| 3 | Beschreibung (ohne Überschrift) | 2–3 kurze Sätze, max. 6: was das Plugin tut, nicht wie die Technik dahinter funktioniert. Fachbegriffe beim ersten Vorkommen auf Wikipedia o. Ä. verlinken statt erklären. Status nur bei Preview/Beta. |
| 4 | `## Features` | Liste: `- **Kurzer Titel** — 1 kurzer Satz.` Nur Key Facts (3–6 Punkte), die das Plugin auszeichnen. Keine Sprachen, Anforderungen, Roadmap oder allgemeinen Erklärungen. |
| 5 | `## Languages` | Unterstützte UI-Sprachen als kommagetrennte Liste. |
| 6 | `## Usage` | Schnellstart für Endanwender als nummerierte Liste (3–5 Schritte): Menüpfad im Backend, erster sinnvoller Arbeitsschritt, Einbindung auf Seiten (Shortcode, Block, PHP-Aufruf) mit Beispiel. Exakte UI-Beschriftungen verwenden. |
| 7 | `## Configuration` | Unterabschnitte in dieser Reihenfolge; nicht zutreffende optionale weglassen: |
|   | `### Requirements` | **Pflicht.** Eine Zeile: PHP-Version und benötigte Erweiterungen, WordPress-Version, Datenbank, Single-/Multisite, HTTPS o. Ä. |
|   | `### Settings` | **Pflicht.** Backend-Einstellungen (Menüpfad) und `wp-config.php`-Konstanten als Codeblock mit Default und Wirkung. Gibt es keine eigenen Konstanten, ausdrücklich sagen. |
|   | `### Background tasks` | Optional: was per WP-Cron läuft und dass bei wenig Besuchern oder `DISABLE_WP_CRON` ein System-Cron nötig ist (Befehl angeben). |
|   | `### Integrations` | Optional: unterstützte andere Plugins/Dienste, je ein Satz, was sie ergänzen; alles Weitere in `docs/`. |
|   | `### Deactivation and removal` | **Pflicht.** Was beim Deaktivieren und Deinstallieren mit Einstellungen, Inhalten und Benutzerdaten passiert (inkl. Multisite, falls unterstützt). |
| 8 | `## Troubleshooting` | Optional, nur bei typischen Stolperfallen: Tabelle `Problem | Solution`, max. ca. 8 Zeilen. |
| 9 | `## Built with AI` | Für interessierte Nutzer: Hinweis auf KI-gestützte Implementierung mit Link auf das [Organisations-Manifest](https://github.com/sw-wordpress/.github/blob/main/profile/README.md) als Begründung, warum es das Plugin in dieser Form gibt. Danach für Agenten: Pflicht, die Projektregeln zu befolgen, mit Links auf `AGENTS.md` und vorhandene Projektdokumente (`PLAN.md`, `CODE-STYLE.md`, `TODO.md`, `docs/`). Nur verlinken, nicht duplizieren. |
| 10 | `## Development` | Voraussetzungen, Installation, Build/Kompilierung, Prüfungen und Tests inkl. Coverage-Grenze, Release-Build. Ausführbare Befehle als Codeblock; Details in `docs/` verlinken. |
| 11 | `## License` | Fester Text: `[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) — see [LICENSE](LICENSE). Provided by Stefan Warnat.` Danach **alle** mitgelieferten Drittkomponenten (Laufzeit-Bibliotheken, kopierter Code, Fonts, JS-Bibliotheken; keine Dev-Abhängigkeiten) als Liste `- [Name](URL) — Lizenz`, mit Fundort der Lizenztexte im Paket. Ohne Drittkomponenten: `No third-party components are bundled.` |

## Badges

`<repo>` = Repository-Name, `<key>` = `sonar.projectKey` aus `sonar-project.properties`
(Konvention `sw-wordpress_<repo>`).

```markdown
[![Release](https://img.shields.io/github/v/release/sw-wordpress/<repo>?sort=semver)](https://github.com/sw-wordpress/<repo>/releases)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=<key>&metric=coverage)](https://sonarcloud.io/summary/overall?id=<key>)
[![SonarQube Issues](https://sonarcloud.io/api/project_badges/measure?project=<key>&metric=vulnerabilities)](https://sonarcloud.io/project/issues?id=<key>&impactSoftwareQualities=SECURITY&resolved=false)
```

Das SonarQube-Cloud-Projekt muss öffentlich sein, sonst zeigen die Badges keinen Wert.

## Abgrenzung

- Lizenz ist immer **GPL-2.0-or-later** (wie WordPress). Überall gleich angeben: `LICENSE` (GPL-2.0-Volltext),
  `composer.json`/`package.json` (`"license": "GPL-2.0-or-later"`), Plugin-Header (`License: GPL-2.0-or-later`,
  `License URI: https://www.gnu.org/licenses/gpl-2.0.html`), `readme.txt` (`License: GPLv2 or later`) und `.pot`/`.po`-Kopf.
  Mitgelieferte Drittkomponenten müssen GPL-kompatibel sein.
- `readme.txt` (WordPress.org-Format) bleibt eigenständig, muss aber zur README passen.
- Architektur, Sicherheitsdetails und Testprotokolle gehören in `docs/`, nicht in die README.
