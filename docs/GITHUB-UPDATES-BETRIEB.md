# Betrieb der verifizierten GitHub-Updates

Noch keine bestätigte produktiv aktive Updateversorgung: Der Betreiber hat den öffentlichen Schlüssel `sw-securelogin-2026-11` im Checkout eingerichtet; Erstverteilung, GitHub-Schutzregeln und ein signierter Release sind noch nicht verifiziert. Je Plugin/Release-Quelle ist ein eigener Ed25519-Schlüssel erforderlich. WordPress erhält ausschließlich öffentliche Schlüssel. Die folgenden Befehle führt der Betreiber auf seinem vertrauenswürdigen Release-Rechner aus; private Dateien liegen außerhalb des Webroots unter `$HOME/.local/share/sw-securelogin-release-keys`. Nicht auf dem WordPress-Server ausführen. Verzeichnis 0700, Dateien 0600; verschlüsselt sichern und niemals committen oder protokollieren.

## Copy & Paste: Schlüssel für SW Securelogin einrichten

Diese Befehle sind für Bash/Zsh auf dem lokalen Entwicklungs-/Release-Rechner mit PHP und Sodium gedacht, nicht für den produktiven WordPress-Webserver. Der Checkout liegt hier unter `/var/www/html/wordpress_dev/wp-content/plugins/sw-securelogin`. Private Dateien landen ausschließlich unter `$HOME/.local/share/sw-securelogin-release-keys`, außerhalb dieses Checkouts und Webroots. Diesen Ordner separat verschlüsselt sichern; er gehört nicht in Git, Release-ZIPs oder öffentlich synchronisierte Ordner. Die Befehle wurden mit Wegwerfschlüsseln geprüft; produktive Schlüssel werden nicht durch die Dokumentation angelegt.

**1. Einmalig Schlüssel erzeugen.** Der Block verweigert einen bereits vorhandenen Schlüsselordner, damit ein erneutes Einfügen keinen bestehenden Schlüssel ersetzt. Die Key-ID ist eine öffentliche Kennung; beim späteren Schlüsselwechsel eine neue Kennung und einen neuen Ordner verwenden.

```bash
(
  set -eu
  umask 077
  export SWSL_RELEASE_KEYS_DIR="$HOME/.local/share/sw-securelogin-release-keys"
  php <<'PHP'
<?php
declare(strict_types=1);
if (!function_exists('sodium_crypto_sign_keypair')) {
    throw new RuntimeException('PHP-Sodium fehlt.');
}
$dir = getenv('SWSL_RELEASE_KEYS_DIR');
if (!$dir || file_exists($dir) || is_link($dir)) {
    throw new RuntimeException('Schlüsselordner existiert bereits oder Pfad fehlt. Keine Dateien verändert.');
}
if (!mkdir($dir, 0700, true)) {
    throw new RuntimeException('Schlüsselordner konnte nicht angelegt werden.');
}
$id = 'sw-securelogin-2026-11';
$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = sodium_crypto_sign_publickey($pair);
$files = [
    'signing-key.base64' => base64_encode($secret) . "\n",
    'public-keys.php' => "<?php\nreturn " . var_export([$id => base64_encode($public)], true) . ";\n",
    'key-id.txt' => $id . "\n",
];
foreach ($files as $name => $contents) {
    $handle = fopen($dir . '/' . $name, 'x');
    if (!$handle || fwrite($handle, $contents) !== strlen($contents)) {
        throw new RuntimeException('Schlüsseldatei konnte nicht vollständig geschrieben werden.');
    }
    fclose($handle);
}
sodium_memzero($secret);
sodium_memzero($pair);
echo "Schlüsseldateien erzeugt; privater Schlüssel wurde nicht ausgegeben.\n";
PHP
)
```

**2. Nur den öffentlichen Schlüssel ins Plugin übernehmen.** Dieser Block erhält bereits vorhandene öffentliche Schlüssel und lehnt eine abweichende Belegung derselben Key-ID ab. Dafür werden keine privaten Schlüssel in eine Umgebungsvariable oder nach WordPress kopiert.

```bash
(
  set -eu
  export SWSL_PLUGIN_DIR='/var/www/html/wordpress_dev/wp-content/plugins/sw-securelogin'
  export SWSL_RELEASE_KEYS_DIR="$HOME/.local/share/sw-securelogin-release-keys"
  php <<'PHP'
<?php
declare(strict_types=1);
$target = getenv('SWSL_PLUGIN_DIR') . '/src/Update/Config/trusted-keys.php';
$existing = require $target;
$incoming = require getenv('SWSL_RELEASE_KEYS_DIR') . '/public-keys.php';
if (!is_array($existing) || !is_array($incoming) || count($incoming) !== 1) {
    throw new RuntimeException('Ungültiger öffentlicher Schlüsselring.');
}
foreach ($incoming as $id => $key) {
    $decoded = is_string($key) ? base64_decode($key, true) : false;
    if (!is_string($decoded) || strlen($decoded) !== 32 || (isset($existing[$id]) && $existing[$id] !== $key)) {
        throw new RuntimeException('Ungültiger öffentlicher Schlüssel oder widersprüchliche Key-ID.');
    }
    $existing[$id] = $key;
}
$rows = [];
$width = max(array_map(static fn($id) => strlen(var_export($id, true)), array_keys($existing)));
foreach ($existing as $id => $key) {
    $rows[] = "\t" . str_pad(var_export($id, true), $width) . ' => ' . var_export($key, true) . ',';
}
$contents = "<?php\n/**\n * Public Ed25519 verification keys.\n *\n * @package SW\\Securelogin\n */\n\nreturn array(\n" . implode("\n", $rows) . "\n);\n";
if (file_put_contents($target, $contents, LOCK_EX) !== strlen($contents)) {
    throw new RuntimeException('Öffentlicher Schlüsselring konnte nicht geschrieben werden.');
}
echo "Öffentlicher Schlüsselring aktualisiert.\n";
PHP
  php -l "$SWSL_PLUGIN_DIR/src/Update/Config/trusted-keys.php"
  git -C "$SWSL_PLUGIN_DIR" diff -- src/Update/Config/trusted-keys.php
)
```

Die angezeigte Änderung an `src/Update/Config/trusted-keys.php` enthält ausschließlich den öffentlichen Schlüssel. Diese Datei nach Review regulär committen. Bestehende Installationen benötigen sie einmal über einen vertrauenswürdigen manuellen Upload oder eine lokale Konfiguration; erst dann können sie signierte Folgeupdates akzeptieren.

**3. GitHub vorbereiten und Secret hinterlegen.** Zuerst in den [Repository-Environments](https://github.com/sw-wordpress/sw-securelogin/settings/environments) die Umgebung `plugin-releases` anlegen und Freigaben sowie erlaubte Release-Tags passend zum Workflow absichern. Erst danach folgenden Block mit einem für dieses Repository berechtigten GitHub-CLI-Konto ausführen. `gh auth status` prüft die Anmeldung; falls nötig zuvor `gh auth login` ausführen.

```bash
(
  set -eu
  SWSL_RELEASE_KEYS_DIR="$HOME/.local/share/sw-securelogin-release-keys"
  gh auth status
  gh secret set SW_PLUGIN_RELEASE_SIGNING_KEY \
    --repo sw-wordpress/sw-securelogin --env plugin-releases \
    < "$SWSL_RELEASE_KEYS_DIR/signing-key.base64"
  gh variable set SW_PLUGIN_RELEASE_KEY_ID \
    --repo sw-wordpress/sw-securelogin --env plugin-releases \
    --body "$(cat "$SWSL_RELEASE_KEYS_DIR/key-id.txt")"
  gh secret list --repo sw-wordpress/sw-securelogin --env plugin-releases
  gh variable list --repo sw-wordpress/sw-securelogin --env plugin-releases
)
```

Der private Schlüssel wird über Standardeingabe an GitHub übergeben und erscheint weder im Befehlsargument noch in der Terminalausgabe. Die Listen zeigen Secret-Namen beziehungsweise öffentliche Variablen. Referenzen: [GitHub CLI: Secret setzen](https://cli.github.com/manual/gh_secret_set), [Variable setzen](https://cli.github.com/manual/gh_variable_set), [PHP: Ed25519-Schlüsselpaar erzeugen](https://www.php.net/manual/en/function.sodium-crypto-sign-keypair.php).

Ein erneutes Setzen des Secrets ersetzt den GitHub-Wert. Deshalb vor Ausführung sicherstellen, dass dieser lokale Schlüssel zum gewünschten öffentlichen Schlüsselring passt; bei Rotation den Ablauf weiter unten verwenden. Die Befehle erzeugen keinen Release-Tag und veröffentlichen kein ZIP. Der eingerichtete Tag-Workflow übernimmt später Build, Signierung und Veröffentlichung.


## Release auslösen

`make release VERSION=1.2.3` verlangt einen sauberen Checkout mit ausgechecktem Branch, verweigert bestehende lokale/remote Tags und Downgrades, aktualisiert Plugin-Header, `SWSL_VERSION` und `readme.txt`, committet und pusht Branch und annotierten Tag atomar. Der Befehl veröffentlicht damit den Tag und startet den Release-Workflow; lokal erzeugt er keinen Produktionsschlüssel. Vorher PR-Prüfungen und die bestehenden Release-Abnahmen aus [RELEASE.md](RELEASE.md) erfüllen. `make dist` baut nur einen lokalen Kandidaten der aktuellen Version.

`.github/workflows/release.yml` verwendet `plugin-releases`, ergänzt den Build-Public-Key unter Erhalt bestehender Schlüssel, baut ZIPs, lädt sie in einen Draft, bindet die tatsächlichen Asset-IDs, signiert exakte Manifestbytes und lädt Manifest/Signatur hoch. Vor Veröffentlichung werden alle Assets wieder heruntergeladen, bytegenau verglichen und mit dem Laufzeitprüfer geprüft. Bereits veröffentlichte Releases werden nie verändert. Ohne Secret/Key-ID bricht der Build vor Veröffentlichung ab. Ein zurückgelassener Draft kann nach Fehlerbehebung mit demselben Tag erneut gebaut werden; keine veröffentlichten Assets überschreiben.

## Vertrauensanker, Rotation und Store

Den öffentlichen Schlüssel zuerst einmal vertrauenswürdig an bestehende Installationen verteilen, etwa durch kontrollierten manuellen ZIP-Upload. Alternativ lokal `SWSL_RELEASE_PUBLIC_KEYS` als Array Key-ID → Base64-Public-Key setzen. Der erste Release darf seinen eigenen erstmals remote geladenen Schlüssel nicht legitimieren. Keine gemeinsame `SW_PLUGIN_RELEASE_PUBLIC_KEYS`-Liste.

Rotation: neue Key-ID und neuen privaten Ordner verwenden, öffentlichen Schlüssel zusätzlich aufnehmen, Übergangsrelease noch mit altem Schlüssel signieren und mit beiden öffentlichen Schlüsseln verteilen. Erst danach CI auf den neuen Schlüssel umstellen. Ein kompromittierter Schlüssel erfordert einen separat vertrauenswürdigen Recovery-Weg. Ein erstmaliger Client erkennt ohne zusätzlich signiertes zeitgebundenes Channel-Verzeichnis kein dauerhaft vorenthaltenes neueres Release.

Für WordPress.org den Aufruf `Update\Provider::init()`, Update-Klassen, Updateskript und `Update URI` aus dem Store-Build entfernen. Zuvor eigene Angebote, Auto-Update-Auswahl und Updatezustand gezielt bereinigen; fremde Angebote erhalten. Bestehende Installationen erhalten den Wechsel als letzte signierte Übergangsrelease. Kein automatischer Wechsel aufgrund eines gleichnamigen Store-Eintrags.

## Bedienung und Fehler

Unter **Plugins → Installierte Plugins** zeigt ausschließlich die Zeile von SW Securelogin den lokalen Prüfstatus und „Jetzt prüfen“. Berechtigte Backend-Sitzungen prüfen nach vollständigem Seitenaufbau höchstens einmal täglich; Fehler zählen mit. Manuelle Prüfung benötigt Nonce, `update_plugins` und mindestens 60 Sekunden Abstand. Multisite benötigt Netzwerkverwaltung; `admin-ajax.php` wird gesondert anhand Netzwerkrechten geprüft. Die Authentifizierungsfunktionen bleiben weiterhin auf Single-Site beschränkt.

Installation ausschließlich über WordPress-Einzel-, AJAX- oder Sammelupdate. Automatische Installation bleibt auch bei alten Angeboten gesperrt. Nach sieben Tagen ohne erfolgreichen Check muss erneut geprüft werden. Bei fehlendem Schlüssel/Sodium/ZipArchive, falscher Signatur, manipuliertem ZIP, veraltetem Angebot, inkompatiblen Voraussetzungen oder verweigertem Lock bleibt die Installation gesperrt. Kein GitHub-Token erforderlich; private Repositorys werden nicht unterstützt.

Deaktivieren entfernt nur eigene Angebote und Auto-Update-Auswahl, bevor Schutz-Hooks entfallen. Zustände und Höchstversion bleiben für Reaktivierung erhalten. Ohne alten Updater gibt es keine alten Update-Cronjobs zu entfernen; `swsl_cleanup` ist eine unabhängige Wartungsaufgabe. Beim ausdrücklich erlaubten Uninstall werden eigene Updateoptionen mit entfernt.

Manuell in Wegwerf-WordPress prüfen: Pluginübersicht öffnen → ohne Schlüssel Sperrstatus; mit vorab verteiltem Public-Key prüfen → Status und verifiziertes Angebot; sofort erneut klicken → Intervallfehler. Einzel-/AJAX-/Sammelupdate installieren jeweils das geprüfte Paket. Nach Deaktivierung verschwindet nur das eigene Angebot; andere SW-Plugins bleiben bedienbar. [Testaufbau und Nachweise](TESTING.md).
