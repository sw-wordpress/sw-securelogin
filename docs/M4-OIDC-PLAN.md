# M4 — Externer OIDC-Login: Umsetzung und Sicherheitsabnahme

Stand: 10.11.2026. **Isolierte Implementierungsbausteine begonnen; technische M4.0-Freigabegates weiterhin offen.**
Grundlage: [Gesamtplan](../PLAN.md), [Codevorgaben](../CODE-STYLE.md),
[M3-Abnahmen](RELEASE.md). Nutzerentscheidung: **Keycloak, Google und ein Custom-Provider
mit manuell konfigurierbaren URLs**. Die folgenden Betriebsentscheidungen sind abgestimmt;
die vorgeschlagenen technischen Verträge und verbleibenden Designnachweise sind Gegenstand
der finalen Review beziehungsweise M4.0.

## Abgestimmte Betriebsentscheidungen

Implementierungsstart: Der isolierte Konfigurationsbaustein `ProviderTrust` ist als
erster Schritt umgesetzt; sein genauer Vertrag steht in [ADR 007](ADR-007-oidc-provider-trust.md).
`ProviderSecrets` ergänzt die providergebundene Secret-Auflösung nach erneuter
Betreiberfreigabe bei jedem Zugriff. Beide Bausteine sind noch nicht an Bootstrap,
wp-config oder HTTP angeschlossen und ersetzen keine M4.0-Protokoll-/Transportnachweise.
Google bleibt erstes Integrationsziel. Die Fortsetzung der Implementierung ist beauftragt;
offene technische Sicherheitsentscheidungen bleiben vor ihrer Umsetzung abzustimmen.
Der erste Bibliotheks-/Transport-Spike mit reproduzierbaren Erfolgen und offenen
Integrationslücken steht in [ADR 008](ADR-008-oidc-library-transport-spike.md).
Er gibt weder den untersuchten Client noch einen produktiven Netzwerktransport frei.
Fortsetzung: Der isolierte konfigurierte Adapter weist die vier beobachteten
Default-API-Lücken ab; 56 Negativfälle sind geprüft. Die Mozart-Hürden sind inzwischen
im Buildadapter behoben und mit präfixierten OIDC-Signatur-/Kollisionsfällen unter
PHP 8.2/8.3 geprüft. Buildgrenzen stehen in [ADR 003](ADR-003-mozart.md), verbleibende
Bibliotheks-/Protokollnachweise in ADR 008.

- **Keycloak:** öffentliche HTTPS-Erreichbarkeit oder Zugriff über VPN unterstützen.
  Browser und WordPress-Server müssen die jeweils benötigten Endpunkte erreichen können.
  VPN-Zugriff bedeutet keine Ausnahme von TLS-, Issuer- oder SSRF-Prüfungen.
- **Secrets:** ausschließlich durch den Betreiber in `wp-config.php` bereitstellen.
  Keine Eingabe oder Speicherung von Client-Secrets in der Administrationsoberfläche
  oder Datenbank; dort stehen nur Providerkonfiguration und Secret-Referenzen.
- **Lokale Security-/MFA-Plugins:** zunächst keine als unterstützte Kombination einplanen.
  Das bedeutet keine Umgehung später installierter Authentifizierungsrichtlinien:
  unbekannte relevante Hooks sperren OIDC, bis ihre Integration geprüft ist.
- **Secret-Zielfreigabe (Review 1):** verbindliche Betreiberfreigabe in `wp-config.php`
  auch für öffentliche Ziele; die Adminoberfläche darf diese Grenze nicht erweitern.
- **Zugangserhalt (Review 12):** Benutzer dürfen durch eine vom Plugin angebotene
  Änderung nicht ihren letzten nachgewiesen nutzbaren Zugang verlieren. Vor dem
  Trennen ist ein verbleibender lokaler Zugang frisch nachzuweisen.
- **Reihenfolge (Review 15):** Google zuerst, danach Keycloak, anschließend Custom/VPN.
  Ein eigener Keycloak steht derzeit nicht zur Verfügung; dessen reale Abnahme ist
  keine Voraussetzung für den ersten vollständigen Google-Ablauf.

Die folgenden Abschnitte konkretisieren diese Entscheidungen. Die Auswahl von Bibliothek,
HTTP-Transport und kryptografischem Speicherformat bleibt eine nachzuweisende M4.0-Aufgabe.

## Ziel und Umfang

Bestehende WordPress-Benutzer können ihre externe Identität im eigenen Profil nach
frischer Passwort-/Passkey-Bestätigung verknüpfen und sich anschließend über den
gewählten Provider ins Backend anmelden. Unbekannte externe Identitäten erhalten
keinen WordPress-Zugang. Lokale Konten, Rollen und Capabilities bleiben maßgeblich.

M4 umfasst mehrere konfigurierte Provider, Verknüpfen/Trennen, Login, Datenschutz,
Recovery und Release-Prüfungen. Keine automatische Kontoregistrierung, Zusammenführung
nach E-Mail, Claim-basierte Rollenvergabe, OIDC-only-Pflicht, Frontend-Widgets oder
eigener OAuth-/OIDC-Server. Passwort und Passkeys bleiben als lokale Zugänge erhalten.
Provider-Abmeldung und WordPress-Abmeldung sind zunächst unabhängig; keine Behauptung
eines Single Logout oder eines sofortigen Sitzungswiderrufs nach IdP-Kontosperrung.

Die noch offenen M3-Geräte-/Betriebsabnahmen und Findings werden nicht als erledigt
markiert. M4-Planung ist möglich; eine Veröffentlichung benötigt zusätzlich deren
Klärung. Der Ausgangsstand ist der gemergte M3-Code `bb51776`.

## Provider und Konfiguration

| Modus | Geplanter Umfang | Sicherheitsgrenze |
| --- | --- | --- |
| Keycloak | Expliziter Realm-Issuer, Discovery, registrierter vertraulicher Web-Client mit PKCE | Keine dynamische Realm-/Issuerwahl aus Loginparametern |
| Google | Festgelegtes OIDC-Profil, Discovery, eigener Web-Client | Google-spezifische dokumentierte Issuerregeln vorab prüfen; keine globale Lockerung des Issuervergleichs |
| Custom | Entweder Discovery über konfigurierten Issuer oder manuelle Authorization-, Token- und JWKS-URLs | Issuer bleibt Pflicht; dieselben Protokollprüfungen und Netzwerkgrenzen wie bei den anderen Providern |

Administratoren mit `manage_options` bearbeiten Anzeigename, Client-ID, Secret-Referenz,
Issuer, Modus, Endpunkte und Aktivstatus. Änderungen brauchen Nonce und POST; Secrets
werden nie zurückgelesen oder im HTML vorausgefüllt. Konfiguration wird validiert und
getestet, bevor ein Provider für Benutzer sichtbar wird. Discovery darf manuelle Werte
nicht still überschreiben. Kein WebFinger oder freie Discovery aus Benutzereingaben.
Die UI meldet ausschließlich, ob die referenzierte Secret-Konfiguration vorhanden und
formal gültig ist. Sie kann Secrets weder anzeigen noch über Export-/REST-Antworten ausgeben.
Secret-Referenzen dürfen ausschließlich auf den zur unveränderlichen Provider-ID
gehörenden Eintrag zeigen; keine Auswahl des Secrets eines anderen Providers.

**Review 1 — entschieden:** Eine separate Betreiberfreigabe in
`wp-config.php` bindet die Secret-Verwendung auch bei öffentlichen Zielen an Provider-ID,
Issuer, Client-ID und exakte Token-Endpunkt-URL. Die private Netzwerkallowlist ersetzt
diese Bindung nicht. Außerhalb der Freigabe wird weder ein Secret gesendet noch ein
Provider aktiviert. Änderungen erfordern erneute Betreiberfreigabe und entwerten alte
Transaktionen. Diese zusätzliche Grenze schützt gegen reine UI-Konfigurationsmanipulation,
nicht gegen Administratoren mit beliebiger PHP-Codeausführung. Auch Discovery-,
Authorization- und JWKS-Ziele gehören zur freigegebenen Providerkonfiguration: eine
Änderung darf weder Secrets umlenken noch fremde Signaturschlüssel als vertrauenswürdig
einführen. Abweichungen sperren bis zur erneuten Betreiberfreigabe; kein UI-Override.

Authorization-, Token- und JWKS-Endpunkte können bei legitimen Providern verschiedene
Hosts haben. Deshalb keine pauschale Same-Host-Annahme: explizit geprüfte Ziele je
Provider festlegen und Änderungen erneut prüfen. Endpunkte werden niemals aus
Callbackparametern oder JWT-Headern übernommen.

Discovery und manuelle Konfiguration sind getrennte Modi. Im Discovery-Modus muss
der gelieferte Issuer exakt dem freigegebenen Profil entsprechen; geänderte Endpunkte
werden nicht automatisch übernommen, sondern sperren bis zu erneuter Prüfung/Freigabe.
Im manuellen Modus gibt es keine parallele automatische Discovery-Übernahme; ein
expliziter Vergleich darf Unterschiede anzeigen, aber keine Sicherheitswerte mischen.
Ein Issuer-Mismatch scheitert immer; ein Bestätigungsdialog allein legitimiert keinen
falschen Issuer. Custom erhält eine explizite profilbezogene Algorithmusallowlist aus
den in M4.0 geprüften asymmetrischen Verfahren; kein Wert „alle“, `none` oder automatischer
Algorithmuswechsel anhand eines eingehenden Tokens.

### Google-Profil: verifizierter Stand der Review

Google dokumentiert die ID-Token-Issuer `accounts.google.com` und
`https://accounts.google.com`. Nur dieses Profil darf die beiden nach vollständiger
Tokenprüfung auf die eine kanonische Identität `https://accounts.google.com` abbilden;
Discovery und Konfiguration bleiben an diesen HTTPS-Issuer gebunden. Kein allgemeines
Normalisieren fremder Issuer. `sub` bleibt der Identitätsschlüssel; `hd`, `email_verified`,
E-Mail und Name erlauben weder Linking noch Rollenvergabe. Nicht benötigte Claims werden
nicht gespeichert. Quelle: [Google OIDC](https://developers.google.com/identity/openid-connect/openid-connect).

**Korrektur zu Review 4:** Das am 06.11.2026 geprüfte
[Google-Discovery-Dokument](https://accounts.google.com/.well-known/openid-configuration)
enthält `authorization_response_iss_parameter_supported: true`. Daher gilt aktuell
auch für Google: fehlendes oder falsches Callback-`iss` ablehnen. Die konkrete Antwort
im echten Integrationslauf prüfen; bei Widerspruch Profil nicht freigeben, keine still
gelockerte Prüfung. Der providerbezogene Callback bleibt zusätzliche Mix-up-Grenze.

Google erlaubt ausdrücklich localhost-Redirects für Entwicklung; die Review-Aussage
„nur öffentliche Domains/HTTPS“ ist zu pauschal. Das Plugin hält seinen eigenen
HTTPS-Zwang aufrecht: echte Google-Abnahme auf registrierter HTTPS-Testdomain oder
zulässigem HTTPS-localhost-Callback mit passender Clientkonfiguration. Kein genereller
HTTP-Entwicklungsmodus. Quelle: [Google Redirect-Regeln](https://developers.google.com/identity/protocols/oauth2/web-server#uri-validation).

## Erreichbarkeit: öffentlich und über VPN

| Verbindung | Verantwortlich | Voraussetzung |
| --- | --- | --- |
| Browser → Keycloak-Authorization/Login | Benutzergerät | Öffentliche HTTPS-URL oder aktive VPN-Verbindung einschließlich passender DNS-Auflösung |
| WordPress → Discovery, Token, JWKS | WordPress-Server | Eigene Netzwerkroute und DNS-Auflösung; das VPN des Benutzers stellt diese Verbindung nicht automatisch her |
| Browser → WordPress-Callback | Benutzergerät | Die exakt registrierte HTTPS-Callback-URI muss nach der IdP-Anmeldung erreichbar sein |

Ein ausschließlich im privaten Netz erreichbarer IdP funktioniert für Benutzer mit
entsprechendem VPN-Zugang, sofern auch der WordPress-Server seine Endpunkte erreicht.
Ein Browser ohne diesen Zugang kann dessen Loginseite nicht verwenden. Beide Betriebsarten
verwenden einen fest konfigurierten Issuer; kein Wechsel auf andere Issuer, IP-URLs oder
ungeprüfte Endpunkte bei VPN-/DNS-Ausfall. Split-DNS darf die Issueridentität nicht ändern.

Öffentliche Endpunkte verwenden standardmäßig die öffentliche Netzwerkpolicy. Für VPN-
Ziele mit privaten Adressen benötigt der Betreiber zusätzlich eine enge serverseitige
Freigabe in `wp-config.php`: je stabiler Provider-ID die zulässigen HTTPS-Hosts/Ports und
privaten Zielnetze. Die UI darf diese Grenze nicht erweitern. Auch bei privaten Freigaben
bleiben Loopback, Link-Local, Cloud-Metadata und nicht freigegebene Ziele gesperrt;
Rebinding-/Redirect-Schutz gilt weiterhin. Ein privater DNS-Eintrag allein ist keine Freigabe.

Die konkreten URLs, DNS-Ergebnisse, Ports und Netze werden bei der Einrichtung geprüft;
sie sind Deploymentdaten und keine weitere allgemeine Produktentscheidung. Vertrauenswürdige
interne CAs können über eine betreiberseitig gepflegte Trust-Konfiguration angebunden werden;
Zertifikatsprüfung wird auch im VPN niemals deaktiviert. Verbindungsfehler verhindern nur
den betroffenen OIDC-Vorgang; lokale Anmeldemethoden bleiben erreichbar.

## Secret-Vertrag für `wp-config.php`

Zur Review vorgeschlagene Konfigurationsschnittstelle; noch nicht implementiert:

| Konfiguration | Inhalt / Verwendung |
| --- | --- |
| `SWSL_OIDC_CLIENT_SECRETS` | Zuordnung stabiler Provider-IDs zu den jeweiligen Client-Secrets; keine vom Request auswählbaren Konstanten- oder Dateinamen |
| `SWSL_OIDC_TRANSACTION_KEYS` | Schlüsselbund mit expliziten Schlüssel-IDs für authentifiziert verschlüsselte kurzlebige OIDC-Transaktionen |
| `SWSL_OIDC_ACTIVE_TRANSACTION_KEY` | Aktive Schlüssel-ID für neue Transaktionen; alte Schlüssel höchstens zum Abschluss noch gültiger Vorgänge vorhalten |
| `SWSL_OIDC_PRIVATE_ENDPOINT_ALLOWLIST` | Enge providerbezogene Netzwerkfreigaben für VPN-Endpunkte gemäß vorherigem Abschnitt; keine generelle Freigabe privater Netze |
| `SWSL_OIDC_PROVIDER_TRUST` | Betreiberfreigabe je unveränderlicher Provider-ID: Issuer, Client-ID und exakte freigegebene Endpunkt-URLs; Voraussetzung für Secret-Verwendung und Vertrauen in Provider-Metadaten/Schlüssel |

Die Konstanten werden als native PHP-Arrays über `define()` vorgesehen; die aktive
Schlüssel-ID ist ein String. Kein zusätzliches JSON-/Dateipfadformat aus Requestdaten.
Für Schlüsselmaterial wird ein streng validiertes Base64-Format vorgesehen; genaue
Bytezahl und Algorithmus werden zusammen in M4.0 festgelegt. Die zusätzliche Betreiber-
freigabe ist durch Review 1 beschlossen; ihr hier vorgeschlagener Konstantenname und
das genaue Array-Schema werden mit den übrigen Konfigurationsformaten in M4.0 fixiert.

Alle Werte werden vor dem Laden des Plugins in `wp-config.php` definiert. Provider-ID,
Format, Schlüsselstärke und erlaubte Zuordnungen serverseitig strikt validieren. Fehlende
oder ungültige Einträge halten den betroffenen Provider deaktiviert. Kein automatisch
generierter Ersatzschlüssel, keine Ableitung aus WordPress-Salts und kein Klartext-Fallback.
Google und jeder Custom-Provider erhalten eigene explizite Einträge wie Keycloak.

**Kurzlebiger Speicher:** Der PKCE-Verifier muss für den serverseitigen Codeaustausch
wiederherstellbar sein. Er darf in der Datenbank ausschließlich authentifiziert
verschlüsselt liegen. Schlüssel liegen nur in `wp-config.php`; die Datenbank enthält
Schlüssel-ID, erforderliche Verschlüsselungsmetadaten und Ciphertext. Zweck, Provider,
Revision und Transaktionsbindung werden kryptografisch an den gespeicherten Kontext
gebunden. Eine geprüfte AEAD-Implementierung verwenden; Algorithmus, Format, Nonce-
Erzeugung und verbindliche Testvektoren werden in der M4.0-ADR festgelegt. Keine selbst
entwickelte Kryptografie und keine Verschlüsselung nur mit der öffentlich gespeicherten
`state`-Kennung. Kurzlebige Vorgänge nach Verbrauch/Ablauf löschen.

**Rotation und Betrieb:** Client-Secret-Wechsel erfordert eine betreiberseitige Aktualisierung
und entwertet Vorgänge mit der alten Konfigurationsrevision. Die Revisionsprüfung muss
auch Änderungen aus `wp-config.php` erfassen, ohne Secrets in Logs oder öffentlichen
Versionskennungen offenzulegen. Transaktionsschlüssel werden über Schlüssel-IDs rotiert.
Ein einfacher Secret-Hash als Revision ist ausgeschlossen: die vertrauliche
Konfigurationsbindung wird über HMAC mit einem getrennt zweckgebundenen Schlüssel aus
dem Transaktionsschlüsselmaterial gebildet. Schlüssel-ID, kanonische Serialisierung und
relevante Konfigurationswerte gehören zum freizugebenden ADR-Vertrag. Keine öffentliche
Ausgabe dieser HMAC-Werte und keine ungeschützten Secret-Prüfsummen in der Datenbank.
Fehlt ein alter Schlüssel, scheitern betroffene Vorgänge und werden neu gestartet.
Der Verlust eines Transaktionsschlüssels darf bestehende Identitätslinks und lokale
WordPress-Zugänge nicht löschen. Schlüssel- und Client-Secret-Rotation getrennt testen.

Dateizugriffe und Backups von `wp-config.php` entsprechend schützen; weder Secrets noch
Schlüssel in Repository, Supportexport oder Diagnoseansicht übernehmen. Ein reines
Datenbankbackup enthält keine Entschlüsselungsschlüssel. Ein vollständiges Backup mit
`wp-config.php` enthält sie hingegen und muss entsprechend geschützt werden. Restore von
Konfiguration und Datenbank abstimmen; wiederhergestellte alte Logintransaktionen vor
erneuter Nutzung verwerfen. Ein späterer Wechsel zu einer Secret-Verwaltung in der UI
oder Datenbank wäre eine eigene Designänderung außerhalb von M4.

## Sicherheitsinvarianten und Ablauf

1. Nur Authorization Code Flow mit `response_type=code`, PKCE S256 und verpflichtendem
   `nonce`; kein Implicit-/Hybrid-/Password-Flow und kein Downgrade bei fehlendem PKCE.
   Vorschlag: vertraulicher Web-Client, minimale Scopes `openid`; `profile`/`email` nur
   bei belegtem UI-Bedarf. Keine Refresh Tokens oder UserInfo-Abfragen im ersten Umfang.
   Ein zurückgegebenes Access Token gilt nicht als Identitätsnachweis und wird verworfen.
2. Start nimmt ausschließlich eine aktive interne Provider-ID entgegen. Einmalige
   kryptografische Werte für `state`, `nonce` und Code Verifier; getrennte Zwecke
   `login`/`link`, Browserbindung und Ablauf. Vorschlag: maximal fünf Minuten; beim
   Linking zusätzlich höchstens Restlaufzeit der konsumierten Reauthentication.
   Der Start ist gegen Login-CSRF abzusichern; Linking braucht lokale Sitzung,
   Berechtigung und Nonce. Ein öffentlicher WordPress-Gastnonce allein genügt nicht.
   Konkret vorgesehener Mechanismus: ungecachte HTTPS-Loginseite mit zufälligem,
   browsergebundenem Starttoken und separatem Secure-/HttpOnly-/Host-only-OIDC-Cookie;
   Start ausschließlich per POST mit exakt geprüftem lokalem Origin und Token.
   Token/Cookie werden serverseitig kryptografisch gebunden; ein Token allein ist
   nicht in einem anderen Browser nutzbar. GET erzeugt keine Logintransaktion und
   automatische Cross-Site-POSTs können keinen gültigen Start autorisieren. Das
   konkrete Tokenformat und Mehrfach-Tab-Verhalten sind in M4.0 nachzuweisen.
3. Transaktion bindet Provider-ID, exakten Issuer, Client-ID, Callback-URI,
   Konfigurationsrevision, internes Redirectziel und Zweck. Linking bindet zusätzlich
   lokalen Benutzer und ursprüngliche WordPress-Sitzung. `state` nur als Hash speichern;
   Code Verifier ist zum Austausch nötig und darf nicht bloß gehasht werden: hierfür
   geschützten kurzlebigen Speicher gemäß freigegebenem Secret-Konzept vorsehen.
4. Empfehlung für M4: eigene, unverwechselbare Callback-URI je Provider, zentral aus
   gepinnter HTTPS-Konfiguration erzeugt. Vor Tokenaustausch stimmen Callback, Provider
   und erwarteter Issuer überein. `iss` gemäß RFC 9207 prüfen; wenn dessen Unterstützung
   angekündigt ist, fehlendes `iss` ablehnen. Kein Rückfall auf einen gemeinsamen,
   ungebundenen Callback. Der konkrete WordPress-Routingweg wird in M4.0 festgelegt.
5. Callback akzeptiert nur das freigegebene Antwortformat; Vorschlag: Top-Level-GET
   mit `response_mode=query`. Doppelte Parameter, Arrays, überlange Werte, unerwartete
   Tokenfelder und widersprüchliche Code-/Fehlerantworten ablehnen. Browserbindung und
   Ablauf prüfen, Transaktion atomar konsumieren, dann erst den Code austauschen.
   Parallel-/Replay-Callbacks können höchstens einmal fortfahren. Nach Timeout ist ein
   neuer Start nötig; keine automatische erneute Code-Einlösung.
6. Tokenaustausch ausschließlich serverseitig an den gespeicherten geprüften Endpunkt,
   mit ursprünglicher Callback-URI, Code Verifier und erlaubter Client-Authentifizierung.
   Erst nach vollständiger ID-Token-Prüfung lokale Identität auflösen. Linking legt nur
   eine Zuordnung an und meldet niemanden um; Login erzeugt nur für die zugeordnete,
   weiterhin berechtigte Person eine WordPress-Sitzung.
7. Login/Linking müssen auch unmittelbar vor Abschluss Providerstatus, Revision,
   lokalen Benutzer und Zweck erneut prüfen. Deaktivieren, Löschen oder relevante
   Konfigurationsänderungen entwerten laufende Vorgänge. Keine Tokens/Codes in Logs,
   Browser-Storage oder Fehlermeldungen. Callback ohne Drittressourcen, `no-store`,
   `Referrer-Policy: no-referrer`; anschließend Weiterleitung auf eine bereinigte URL.
   Betreiber müssen Callback-Querys in Proxy-/Access-/APM-Logs ausnehmen.

OIDC-Grundlage: [Core, Code Flow und Tokenvalidierung](https://openid.net/specs/openid-connect-core-1_0.html#CodeFlowAuth),
[OAuth Security BCP](https://www.rfc-editor.org/rfc/rfc9700.html),
[Issuer im Callback](https://www.rfc-editor.org/rfc/rfc9207.html).
Die konkrete strengere Auswahl oben ist ein Projektvorschlag, keine Aussage, dass
OIDC Core alle anderen dort beschriebenen Betriebsarten verbietet.

### Ressourcen-, Fehler- und Zeitgrenzen (Review-Vorschläge für M4.0)

Vor einer Transaktionsanlage Limits pro direktem Peer, Browserbindung, Provider und
Site durchsetzen; keine ungeprüften Forwarded-IP-Header. Zusätzlich harte globale und
browserbezogene Obergrenzen offener Transaktionen atomar prüfen. Bereinigung arbeitet
begrenzt in Batches; volle Speicherbudgets lehnen neue Starts ab und erzwingen keinen
unbegrenzten Cleanup im öffentlichen Request. Dimensionierung und DoS-Tests gehören
in M4.0; eine Cookie-Bindung ersetzt keine globalen Limits.

Konkrete Ausgangswerte zur Review: 5 Starts/Minute je Browser, 20/Minute je Peer,
100/Minute je Provider, 200/Minute je Site; höchstens 5 offene Vorgänge pro Browser und
1.000 pro Site; Cleanup-Batches höchstens 100 Datensätze. Diese Werte sind keine schon
freigegebenen Betriebsparameter: VPN-/NAT-Szenarien, Last und atomare Begrenzung testen.

Für unbekannte Identität, lokalen Kontoausschluss und Auth-Policy-Veto dieselbe öffentliche
Fehlerklasse, Status-/Redirectform und bereinigte Zielseite vorsehen. Keine Identitätsdaten
zur Fehlererklärung ausgeben. Vergleichbare lokale Verarbeitungspfade und Timingtests
vorsehen, ohne konstante Netzwerklaufzeit oder vollständige Timing-Ununterscheidbarkeit
zu versprechen. Intern nur begrenzte Reason-Codes/Aggregate wie `identity_unlinked`,
`account_denied` und `policy_denied`; weder Token-/Subject-/E-Mail-Werte noch rohe
Providerfehler protokollieren. Zugriff, Limits und Retention des Audits festlegen.

Zeitvorschlag: 60 Sekunden Claim-Leeway, höchstens 300 Sekunden seit `iat` und 300
Sekunden Transaktionslaufzeit; `exp` und vorhandenes `nbf` weiterhin prüfen. Linking
bleibt zusätzlich auf die lokale Reauth-Restlaufzeit begrenzt. Kein Verlängern durch
einen Retry. Bei erkennbaren Uhrsprüngen oder bekannter fehlerhafter Zeitsynchronisation
OIDC sperren statt die Toleranz zu vergrößern. PHP kann einen NTP-Ausfall nicht zuverlässig
allein erkennen: synchronisierte Serverzeit ist Betriebsvoraussetzung; Uhrsprung-,
Grenzwert- und Suspend/Resume-Tests gehören zur ADR-Abnahme.

## Vier besonders kritische Grenzen

**Kontenverknüpfung:** ausschließlich exakte externe Identität aus Issuer und Subject,
niemals E-Mail oder Anzeigename. Case-sensitive Speicherung/Vergleich und ein atomarer
Eindeutigkeitszwang verhindern doppelte oder konkurrierende Zuordnung zu zwei Benutzern.
Keine Verbindung fremder Konten durch Administratoren im normalen Profilablauf.
Lokale Identität und Sitzung beim Callback erneut prüfen; eine zwischenzeitlich
abgemeldete oder gewechselte Sitzung kann keine Verknüpfung fertigstellen. Trennen
braucht erneut frische lokale Reauthentication. IdP-Login allein erteilt keine lokale
Management-Reauthentication. Client-/Issuerwechsel dürfen keine Identitäten umhängen.

**Review 12 — entschieden:** Das Trennen verlangt ausdrücklich frische lokale
Passwort-/Passkey-Reauthentication verlangen; OIDC allein genügt nicht. Damit wird ein
gerade nutzbarer lokaler Zugang nachgewiesen, nicht bloß aus vorhandenen Kontofeldern
abgeleitet. Vorschlag für Grenzen: höchstens fünf aktive Links pro Benutzer, davon
einer je Providerinstanz. E-Mail-Änderungen verändern keinen Link; Benutzerlöschung
entfernt alle zugehörigen Links/Transaktionen. Ein späterer Recovery-Prozess für Konten
ohne nutzbaren lokalen Zugang wäre eine separate Funktion, kein stiller Unlink-Bypass.

Der Nachweis muss einen **nach der Änderung verbleibenden** Zugang betreffen. Ein
Passkey, der gerade widerrufen werden soll, kann nicht allein den Zugangserhalt belegen.
Die Anforderung ist deshalb auch bei betroffenen bestehenden Passkey-Verwaltungsaktionen
zu berücksichtigen. Gleichzeitige Lösch-/Trennaktionen dürfen nicht jeweils denselben
verbleibenden Zugang voraussetzen und zusammen alle Zugänge entfernen: Zustand und
Nachweis unter einer gemeinsamen benutzerbezogenen Sperre unmittelbar vor dem Schreiben
erneut prüfen. Wird ein Zugang ersetzt, muss der neue zuerst erfolgreich verifiziert sein.

Die Schutzregel betrifft reguläre Benutzeraktionen des Plugins; sie ist keine Garantie
gegen vergessene Passwörter, verlorene Geräte, IdP-Ausfälle oder Änderungen außerhalb
des Plugins. Keine ungefragten globalen Eingriffe in WordPress-Passwortwechsel. Routine-
Provideränderungen müssen betroffene Links/Zugänge vorab ausweisen und einen sicheren
Umstieg planen. Eine notwendige Notfalldeaktivierung eines kompromittierten Providers
muss möglich bleiben; sie benötigt den dokumentierten lokalen Recovery-Weg. Bewusste
Kontolöschung und ausdrücklich freigegebene Deinstallation sind gesonderte Lebenszyklen.

Provider-IDs sind unveränderliche, zufällige Instanz-IDs statt bearbeitbarer Namen.
Löschen und Neuanlegen erzeugt eine neue ID, auch bei gleichem Anzeigenamen. Links
binden zusätzlich Issuer und Client-ID; Änderungen dieser Sicherheitsidentität erfordern
eine neue Providerinstanz und erneutes explizites Linking. Es gibt keine automatische
Vererbung alter Links oder Secret-Referenzen. Ein Eindeutigkeitszwang für kanonischen
Issuer und Subject gilt weiterhin über Providerinstanzen hinweg.

**ID-Token/JWKS:** etablierte geprüfte Bibliothek; feste freigegebene Signaturalgorithmen
je Profil statt Vertrauen auf `alg`. Signatur, Schlüsseltyp/-verwendung, `iss`, `sub`,
`aud`, `azp`, `exp`, `iat`, `nonce` sowie vorhandene relevante Zeitclaims strikt prüfen.
Zeitabweichung, maximale Tokenalter und Mehrfach-Audience-Regeln in M4.0 festlegen.
`none`, Algorithmusverwechslung, mehrdeutige JSON-/Headerdaten und Schlüssel aus
`jku`/`x5u` ablehnen. `kid` wählt nur aus vertrauenswürdigen Provider-JWKS.
Cache an Provider und Revision binden; bei unbekanntem Schlüssel höchstens kontrolliert
nachladen, mit Limits gegen Fetch-Stürme. Cache-/Rotationsregeln und Ablaufgrenzen
explizit testen; keine unbegrenzte Nutzung alter Schlüssel bei Netzwerkausfall.
Siehe [JWT BCP](https://www.rfc-editor.org/rfc/rfc8725.html).

**Ausgehende HTTP-Anfragen/SSRF:** Discovery, JWKS und Tokenaustausch verwenden denselben
geprüften Transport, auch wenn eine Bibliothek intern HTTP macht. Nur HTTPS, gültige
Zertifikate, erlaubte Ports, keine URL-Credentials/Fragmente und keine automatischen
Redirects. Vor jeder Verbindung IPv4/IPv6 und sämtliche DNS-Ergebnisse prüfen;
Loopback, Link-Local, Metadata- und sonstige nicht freigegebene Netze blockieren.
DNS-Prüfung und tatsächliche Verbindung dürfen bei Rebinding nicht auseinanderfallen:
geprüfte Zieladresse mit korrektem TLS-Hostnamen binden oder eine nachweislich passende
Egress-Grenze einsetzen. `wp_safe_remote_get()` allein belegt diese Eigenschaft nicht.
Transport, Timeouts, Antwort-/JSON-Größen, DNS-Verhalten und Circuit-/Rate-Limits werden
vor Implementierung spezifiziert und mit einem adversarialen Testserver geprüft.
VPN-Keycloak wird über die oben beschriebene enge Host-/Netzfreigabe unterstützt;
niemals ein globaler „SSRF-Schutz aus“-Schalter. Die Bibliotheks-/Transportauswahl muss
beide Netzwerkpolicies nachweislich durchsetzen können, auch bei Split-DNS und IPv6.
Siehe [OWASP SSRF Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html)
und [Discovery](https://openid.net/specs/openid-connect-discovery-1_0.html).

**Lokale Sicherheit und Secrets:** gemeinsame WordPress-Sitzungsausstellung weiterverwenden,
aber keine lokalen MFA-/Security-Adapter als M4-Lieferumfang zusichern. Die erste
Abnahme erfolgt ohne solche Plugins. Negativtests mit unbekannten Auth-Hooks beweisen
weiterhin die Sperre; spätere Adapter benötigen einen eigenen Review mit dem konkreten
Plugin. Keine pauschale MFA-Erfüllung aus `amr`/`acr` ableiten. WordPress-Kontoprüfungen,
Capability-Grenzen und Session-Vetos bleiben auch ohne Drittplugins wirksam.
Client-Secrets und Transaktionsschlüssel werden ausschließlich über `wp-config.php`
bereitgestellt; der vorstehende Vertrag regelt Speicherung, Rotation und Restore.
DB-Backup-Diebstahl, kompromittierter IdP, kompromittierter WordPress-Server und bösartige
Administratoren sind unterschiedliche Bedrohungen; lokaler Server-Code kann die
Protokollkontrollen umgehen. Der Plan verspricht keine Sicherheit bei vollständiger
Serverkompromittierung.

### Technische Freigabegates aus der Review

- **Kryptografie:** PHP 8.2 garantiert keine aktivierte Sodium-Extension. Sie ist seit
  PHP 7.2 gebündelt, muss aber verfügbar/aktiviert sein; siehe
  [PHP-Installationsdokumentation](https://www.php.net/manual/en/sodium.installation.php).
  M4.0 muss native `ext-sodium` gegen den bereits verwendeten OpenSSL-Stack bewerten.
  Die gewählte AEAD-Voraussetzung wird explizit geprüft; fehlt sie, bleibt OIDC aus.
  Kein stiller `sodium_compat`- oder unverschlüsselter Fallback, keine globale Änderung
  der Passkey-Voraussetzungen durch diese Konzeptreview.
- **Bibliothek und Transport:** zuerst einen gepflegten OIDC-Client mit injizierbarem,
  geprüftem Transport evaluieren, etwa über PSR-18. Ein Transportproblem autorisiert
  keinen selbst entwickelten OIDC-Protokollvalidator. Falls nur JWT/JWKS-Bausteine
  praktikabel erscheinen, braucht diese Abweichung von CODE-STYLE.md vor Umsetzung
  eine eigene Architekturfreigabe. Spike inklusive Mozart, Signaturen, dynamischer
  Imports und tatsächlicher Netzwerkzugriffe als frühes M4.0-Gate.
- **DNS-/TLS-Verbindung:** cURL und alle tatsächlich verwendeten HTTP-Transports separat
  prüfen. WordPress-Hooks wie `http_api_curl` sind allein kein Nachweis wirksamen
  Pinning; Streams dürfen nicht unbemerkt übernehmen. Ohne nachgewiesenen sicheren
  Transport oder ausdrücklich geprüfte betreiberseitige Egress-Grenze bleibt OIDC
  deaktiviert. Unterstützte Plattformen und nötige Extensions anschließend dokumentieren.
- **Routing/Cache:** `admin-post.php` mit eigener Action, `wp-login.php`-Action und eine
  REST-Lösung mit eigenständig geprüfter Cookie-/Sitzungsbindung vergleichen. REST
  scheidet nicht grundsätzlich aus; eine fehlende REST-Nonce darf nur nicht als
  authentifizierte lokale Sitzung gelten. Bestehende Login-URL-Plugins beachten.
  Keine Rewrite-/Flush-Abhängigkeit ohne belegten Nutzen. CDN-/Page-Cache-Ausnahmen
  für Startseite, Start-POST und Callback sind verpflichtende Betriebsbedingungen;
  `no-store` allein korrigiert keine explizit überschreibenden Edge-Regeln.
- **Multisite:** bestehende Single-Site-Grenze bleibt für M4 erhalten. Keine OIDC-Routen,
  Migrationen oder Secret-Verwendung bei Netzwerk-/Site-Aktivierung in Multisite;
  stattdessen Hinweis. Keine neue netzwerkweite Identitätsarchitektur in diesem Umfang.

## Wiederverwendung und notwendige Anpassungen im aktuellen Code

- `LoginSessionIssuer`, `MigrationRunner`, `RateLimiter` und atomare
  `TransactionRepository`-Mechanik nutzen; keine parallele Sitzungsimplementierung.
  Transaktionspayloads sind derzeit unverschlüsseltes JSON: PKCE-Geheimnisse dort
  nicht ungeprüft ablegen. OIDC-Zwecke und Limits getrennt von Passkeys halten.
- `BrowserBinding` nutzt derzeit `SameSite=Strict`. Für den Cross-Site-Callback eine
  separate OIDC-Bindung vorsehen; Vorschlag für GET: Secure/HttpOnly/host-only/Lax.
  Das Passkey-Cookie bleibt Strict. Parallel-Tabs, fehlende Cookies und Ablauf testen.
- `AuthPolicy` und Fehlertexte enthalten Passkey-Semantik, insbesondere
  `swsl_passkey_auth_compatible`. Vor Wiederverwendung methodenspezifisch erweitern;
  bestehende Hooks kompatibel halten und Passkey-Freigaben nicht auf OIDC übertragen.
- `Reauthentication` erfordert eine lokale Cookie-Sitzung. Den Callback-Routingweg so
  wählen, dass diese zuverlässig geprüft wird; REST-Gastkontext ersetzt sie nicht.
- `SettingsRepository` erlaubt aktuell nur Passkeys und Schema 2; Passkey-Speicherung
  schreibt die Modulliste neu. Koexistenz und Schemaanforderungen vor OIDC ergänzen,
  damit das Speichern eines Moduls nicht das andere deaktiviert.
- Neue Provider-/Identitätspersistenz versioniert und additiv migrieren. Exakte
  Tabellen, Indizes, Limits und Retention in M4.0 spezifizieren. Benutzerlöschung,
  Export/Erasure, Cron, datensparsames Audit und die expliziten Uninstall-Allowlists
  aus [ADR 006](ADR-006-uninstall.md) im selben Arbeitspaket erweitern.

## Arbeitspakete und jeweilige Abnahme

| Paket | Liefergegenstand | Voraussetzung / Abnahme |
| --- | --- | --- |
| M4.0 — Design | OIDC-Threat-Model; ADRs für Bibliothek/Transport, wp-config-Secrets und AEAD-Format, Callback/Cookies, Linking und lokale Auth-Policy; Datenmodell und Parametergrenzen | Betriebsentscheidungen dieses Konzepts umgesetzt; Bibliothek anhand echter Signatur-/Negativfälle, Lizenz, PHP/WP und Mozart geprüft; öffentliche und VPN-Netzwerkpolicy nachgewiesen; noch keine öffentlichen Loginrouten |
| M4.1 — Infrastruktur | Modulkoexistenz, additive Migrationen, geschützte Providerverwaltung, Secret-Anbindung, geprüfter HTTP-Transport und Discovery/JWKS | Berechtigungs-/CSRF-/Storage-/SSRF-Tests erfolgreich; ungültiger Provider bleibt deaktiviert; Passkeys unverändert funktionsfähig |
| M4.2 — Transaktionen/Validator | PKCE, Browser-/Zweckbindung, Callback, einmaliger Verbrauch, Bibliotheksvalidierung, begrenzter JWKS-Cache | Angriffsfixture kann jeden Prüfschritt isoliert verletzen; kein Fehler erzeugt Sitzung oder Identitätslink |
| M4.3 — Verknüpfen/Trennen | Eigene Profilansicht, frische lokale Reauth, atomare Identitätszuordnung, Datenschutz-/Uninstall-Integration | Cross-Account-Linking, Sitzungswechsel und parallele Zuordnung scheitern; Trennen erhält lokale Recovery |
| M4.4 — Login | Providerbuttons auf bestehender Loginseite, Account-Auflösung, gemeinsame Sitzungsausstellung, interne Weiterleitung | Nur bereits verknüpfte berechtigte Konten; keine automatische Registrierung; MFA-Veto und Cookiefehler hinterlassen keine neue gültige Sitzung |
| M4.5 — Interoperabilität/Release | Keycloak öffentlich/über VPN, Google und manuelle Custom-Konfiguration; wp-config-Betriebshandbuch, Übersetzungen, E2E, Review | Reales finales ZIP, ganze Qualitätsmatrix und Security-Abnahme ohne lokale Security-Plugins erfolgreich; unbekannte Auth-Hooks sperren; offene High/Critical-Findings blockieren Veröffentlichung |

Pro Paket kleine reviewbare PRs mit Positiv-, Negativ- und Regressionstests; Modul
standardmäßig aus. M4.2–M4.4 zunächst nur in kontrollierten Testumgebungen aktivieren.
Fehlgeschlagene Migration/fehlende Schlüssel dürfen keinen halb aktiven Login erzeugen.

**Review 15 — entschieden:** Nach M4.0 zuerst einen vollständigen
vertikalen Durchstich M4.1–M4.4 mit Google abnehmen, danach Keycloak und
anschließend manuelle Custom-Endpunkte/VPN. Secret-Zielbindung, SSRF-Grundschutz und
lokale Auth-Policy sind bereits für den ersten Durchstich zwingend. Der finale
M4.5-Umfang umfasst weiterhin alle vereinbarten Modi; keine Teilfreigabe als vollständiges M4.
Google-Clientregistrierung, Consent-Konfiguration und registrierter HTTPS-Callback
gehören deshalb zum ersten Testaufbau. Isolierte CI verwendet weiterhin einen
kontrollierbaren OIDC-Testserver; echte Google-Tests erfolgen separat mit Testkonten.
Keycloak-Abnahmen folgen, sobald eine geeignete Testinstanz verfügbar ist; sie werden
nicht aus erfolgreicher Google- oder Mock-Integration abgeleitet.

M3 blockiert keine isolierte Design-/Testarbeit in M4.0–M4.3. Vor Aktivierung irgendeiner
OIDC-Route außerhalb kontrollierter Testumgebungen müssen offene High/Critical-Findings
an den gemeinsam verwendeten Sicherheitsgrenzen geklärt und deren Regressionstests grün
sein. Einordnung/Disposition der Findings dokumentieren, nicht aus einem grünen New-Code-
Gate ableiten. Sonstige offene M3-Geräte-/Betriebsabnahmen bleiben Release-Gates.

M4.0 ergänzt `THREAT-MODEL.md` und spezifiziert Datenkategorien/Retention; M4.3 ergänzt
`PRIVACY.md`, Export/Erasure und Benutzerlöschung vor Speicherung realer Identitätslinks.
Issuer/Subject werden als personenbezogene Daten behandelt; `email`/`name` nicht ohne
gesonderten Bedarf persistieren. Keine pauschale Rechtsgrundlage für jede Installation
behaupten: der Betreiber muss Zweck und anwendbare Rechtsgrundlage bestimmen; das Plugin
liefert die technische Datenschutzauskunft. Persistente Links bis Trennen/Benutzerlöschung,
kurzlebige Transaktionen höchstens bis Ablauf plus begrenztem Cleanup-Verzug vorsehen.
M4.1 plant eine wertfreie Konfigurationsstatusseite und M4.5 die Erweiterung von
`OPERATIONS.md`: PHP-Array-Beispiele, geprüfte Schlüsselgenerierung per CLI ohne Secrets
als Kommandoargumente, Rotation, Backup/Restore und Netzwerk-/Cache-Prüfung. Die exakten
Generierungsbefehle werden erst nach Festlegung des AEAD-Verfahrens veröffentlicht.

## Verbindliche Angriffstests

| Angriff / Störung | Erwartetes Ergebnis |
| --- | --- |
| Login-CSRF, fremder Browser, fehlendes Cookie, falsches/fehlendes/abgelaufenes `state` oder `nonce` | Kein Login und kein Link |
| Wiederholter oder paralleler Callback; Code aus anderem Vorgang; falscher Verifier | Höchstens ein gültiger Abschluss; Fehler ohne Sitzung |
| Mix-up zwischen zwei Providern, falscher Callback, falsches `iss`, Wechsel der Providerrevision | Abbruch vor falschem Tokenaustausch bzw. vor lokalem Abschluss |
| `alg=none`, falsche Signatur, HS/RS-Verwechslung, falsches `aud`/`azp`, abgelaufene/zukünftige Claims, doppelte Claims | Ablehnung durch Bibliothek und Profilregeln |
| Unbekanntes/mehrdeutiges `kid`, bösartiges `jku`/`x5u`, JWKS-Rotation und Fetch-Sturm | Keine fremden Fetches, begrenzte Refreshs, Fehler geschlossen |
| Gleiche E-Mail bei zwei Identitäten; paralleles Linking auf zwei WP-Konten; lokaler Sitzungswechsel | Keine Übernahme oder automatische Zusammenführung |
| IPv4-/IPv6-/DNS-Rebinding, Redirect auf Metadata/IP, riesige/zu langsame Antworten, manipulierter Custom-Endpunkt | Verbindung verweigert oder sicher begrenzt; kein Secret verlässt die Freigabe |
| Keycloak nur über VPN; Browser oder WP-Server ohne Route; Split-DNS; privates Ziel außerhalb der wp-config-Freigabe | Fehler ohne Netzwerk-/Issuer-Fallback; Zugriff auf freigegebene VPN-Ziele funktioniert bei gültigem TLS |
| Datenbankfehler, fehlender/rotierter Schlüssel, beschädigte Ciphertexte, Token-Timeout, IdP-Ausfall | Neutraler Fehler, keine Klartext-/unsichere Ersatzlösung; lokale Zugänge funktionieren |
| Client-Secret-/Schlüsselwechsel in wp-config, Restore alter Transaktionen, Diagnose-/Exportaufrufe | Alte Revisionen/Transaktionen entwertet; keine Client-Secrets, Schlüssel oder Klartext-Verifier in Datenbank, UI, Export oder Logs; verschlüsselter Verifier bleibt einzig im freigegebenen kurzlebigen Speicher |
| Provider/Modul deaktiviert oder Benutzer/Link während Login gelöscht; MFA-/Session-Veto | Keine neue Sitzung; vorhandene unabhängige WP-Sitzungen werden nicht pauschal gelöscht |
| Callback-Abbruch, Proxy/CDN-Cache, falsche Host-/Forwarded-Header, externe Redirectziele | Keine Daten-/Codeweitergabe, keine offene Weiterleitung, Passwortfallback benutzbar |
| Öffentlicher Angreifer-Token-Endpunkt, fremde JWKS-URL oder Auswahl einer fremden Secret-Referenz über die UI | Kein Secret-Versand und kein Schlüsselvertrauen ohne passende Betreiberfreigabe; kein UI-Override |
| Cross-Site-Start, fremder Browser-Starttoken, erschöpfte Peer-/Provider-/Site-/Speicherbudgets | Keine neue Transaktion; keine unbeschränkte Cleanup-/Datenbankarbeit |
| Letzten externen Link ohne nutzbaren lokalen Zugang trennen; Provider löschen und unter gleichem Namen neu anlegen | Kein Unlink ohne verbleibenden lokal nachgewiesenen Zugang; neue Providerinstanz erbt keine Links |
| Letzten Passkey mit genau diesem Passkey als einzigem Nachweis widerrufen; parallele Zugangsänderungen | Änderung abweisen; atomare Prüfung erhält mindestens einen unabhängig nachgewiesenen verbleibenden Zugang |

CI: bestehende PHP-/WP-/DB-Matrix, PHPStan 8 ohne Baseline/Ignores, WPCS, PHP-/JS-Lint,
Audits und SonarQube; PHP und JavaScript jeweils >80 % Zeilenabdeckung über den gesamten
Produktivcode. Signierte Tokenfixtures und ein kontrollierbarer OIDC-Testserver ergänzen
die Tests gegen echte Provider. Docker-E2E mit zwei Issuern, parallelen Browserkontexten,
Fehlerantworten und Rotation bevorzugen. Eigene Test-CA/enge Testnetzfreigabe sind kein
unsicherer Produktionsmodus. Reale Keycloak-/Google-Tests getrennt von isolierter CI
protokollieren; Zugangsdaten nicht an Fork-PRs geben. Custom-Modus zusätzlich mit
manuellen Endpunkten und einem zweiten unabhängigen OIDC-Testserver nachweisen.

Manuelle Abnahme: lokal anmelden, Identität frisch bestätigt verknüpfen, abmelden,
über den Provider ins Backend gelangen; zweite Person mit gleicher E-Mail darf keinen
Zugang erhalten. Danach Link trennen bzw. Provider abschalten: OIDC scheitert neutral,
Passwort und Passkeys funktionieren weiter. Aufbewahrung, Wiederherstellung, IdP-Ausfall,
Client-Secret-Rotation und Logs prüfen. E2E-Erfolg ersetzt keinen Sicherheitsreview.

## Checkliste für die finale Konzeptreview

- [ ] Umfang bestätigen: Keycloak öffentlich/VPN, Google und Custom; bestehende Konten,
  explizites Linking, keine automatische Registrierung oder E-Mail-Zusammenführung.
- [ ] Netzwerkvertrag bestätigen: Browser- und Servererreichbarkeit separat;
  VPN-Freigaben ausschließlich betreiberseitig und eng begrenzt; kein SSRF-/TLS-Bypass.
- [ ] wp-config-Vertrag einschließlich zusätzlicher Transaktionsschlüssel, Rotation,
  Backup/Restore und fehlender UI-Secretverwaltung bestätigen.
- [ ] Erstabnahme ohne lokale Security-/MFA-Plugins und Sperre unbekannter Auth-Hooks
  bestätigen; spätere Kompatibilität wird gesondert geprüft.
- [ ] Reihenfolge M4.0–M4.5 und die Sicherheits-/Regressionstest-Abnahmen bestätigen.

Die Entscheidungen zu Review 1, 12 und 15 sind getroffen: Betreiberfreigabe verbindlich,
letzten nutzbaren Zugang schützen, Google → Keycloak → Custom/VPN. Die offenen Häkchen
betreffen die vollständige Konzept-/Detailprüfung und bestätigen keine erneut offenen
Betriebsfragen zu diesen drei Punkten.

Nach der Konzeptreview beginnt **M4.0**. Dort sind Bibliothek/HTTP-Transport, das konkrete
AEAD- und Datenformat, Callback-Routing sowie numerische Limits/Cache-/Zeitregeln anhand
von Tests als ADRs abzuschließen, bevor ihre Produktionsimplementierung beginnt. Diese
Nachweise sind noch offen; der Status „zur finalen Review“ behauptet weder eine fertige
Detailarchitektur noch implementierte OIDC-Sicherheit. Es werden mit dieser Aktualisierung
keine Abhängigkeiten installiert und keine OIDC-Funktionen aktiviert.

## Bearbeitung der Review-Punkte (06.11.2026)

Die Originalanmerkungen darunter bleiben als Review-Nachweis erhalten. Diese Tabelle
unterscheidet Dokumentationskorrekturen, offene Entscheidungen und noch nicht erbrachte
M4.0-Nachweise. „Eingearbeitet“ bedeutet ausdrücklich nicht implementiert oder getestet.

| Nr. | Prüfung / Bearbeitung | Status |
| --- | --- | --- |
| 1 | Öffentliche Token-Ziele und Issuer/Client verbindlich an Betreiberfreigabe binden; weitere vertrauensrelevante Endpunkte ebenfalls absichern. Secret-Referenzen providergebunden. | Vom Nutzer bestätigt; Format/Nachweise in M4.0 |
| 2 | Browsergebundener Starttoken, separates Cookie, POST/Origin-Prüfung und atomare Speicher-/Rate-Budgets beschrieben; konkrete Ausgangswerte benannt. | Konzept ergänzt; Formate, Werte und Lastnachweis in M4.0 freizugeben |
| 3 | Plain-Hash ausgeschlossen; zweckgebundene HMAC-Konfigurationsbindung einschließlich Schlüssel-ID vorgesehen. | Konzept korrigiert; genaue Serialisierung/Schlüsselableitung in ADR |
| 4 | Google-Issuerprofil konkretisiert; aktuelles Discovery kündigt RFC 9207 an. Localhost ist bei Google nicht generell ausgeschlossen. | Zwei Review-Sachangaben korrigiert und mit Primärquellen belegt; echte Integration weiter offen |
| 5 | Discovery/manuell strikt getrennt; Issuer-Mismatch scheitert; Endpunktwechsel gesperrt bis Freigabe; kein Custom-Algorithmuswert „alle“. | Eingearbeitet; konkrete geprüfte Verfahren in M4.0 |
| 6 | Einheitliche öffentliche Fehlerklasse, vergleichbare lokale Pfade/Timingtests und getrennte datensparsame Reason-Codes spezifiziert. | Eingearbeitet; keine Garantie konstanter Netzwerklaufzeit |
| 7 | Gebündelte Extension ist nicht garantiert aktiviert. Native AEAD-Voraussetzung explizit prüfen, keine stille Kompatibilitätsschicht. | Sachangabe korrigiert; Sodium/OpenSSL-Auswahl bleibt M4.0-Gate |
| 8 | Früher Bibliotheks-/Mozart-/Transport-Spike; vollständiger OIDC-Client bevorzugt. JWT-Bausteine rechtfertigen keinen ungeprüften eigenen Protokollvalidator. | Eingearbeitet; Abweichung erfordert eigene Architekturfreigabe |
| 9 | Tatsächlichen Verbindungsweg und alle Fallbacks prüfen; ohne sicheren Transport bzw. ausdrücklich geprüfte Egress-Grenze kein OIDC. | M4.0-Machbarkeitsgate, derzeit kein Sicherheitsnachweis |
| 10 | Routingkandidaten einschließlich admin-post prüfen; REST nicht pauschal unmöglich, aber Sitzung eigenständig prüfen. Edge-/Page-Cache-Ausnahmen aufgenommen. | Präzisiert; Routingentscheidung und Cache-Tests bleiben M4.0 |
| 11 | Vorhandene Single-Site-Einschränkung ausdrücklich auf OIDC übertragen. | Bestehende Produktgrenze klargestellt, kein neuer Multisite-Umfang |
| 12 | Frischer Nachweis eines verbleibenden lokalen Zugangs; letzten Zugang bei Benutzeraktionen nicht entfernen/unbrauchbar machen. Parallele Änderungen und letzter Passkey einbezogen. | Schutzregel vom Nutzer bestätigt; atomare Umsetzung und Limits in M4.0 spezifizieren |
| 13 | Konkrete Zeitvorschläge und Uhrsprungtests; Zeitsynchronisation als Betreiberpflicht, keine behauptete automatische NTP-Erkennung. | Eingearbeitet; Zahlen in M4.0 freizugeben |
| 14 | Unveränderliche zufällige Providerinstanz-ID; Issuer/Client-Bindung und keine Wiederverwendung/Vererbung bei Neuanlage. | Eingearbeitet; Schema/atomare Constraints in M4.0 |
| 15 | Google-Durchstich zuerst, danach Keycloak, dann Custom/VPN; Basis-SSRF-/Secret-Schutz von Beginn an. | Nutzerentscheidung übernommen; Keycloak derzeit nicht verfügbar, Gesamtumfang unverändert |
| 16 | Native PHP-Arrays/String, Base64-Schlüsselvertrag, Statusseite und CLI-/Betriebsanleitung eingeplant. | Eingearbeitet; genaue Schlüssellänge/Generierung erst mit AEAD-Auswahl |
| 17 | Isolierte Entwicklung möglich; gemeinsam relevante High/Critical-Findings und Regressionen vor nicht isolierter Route klären; übrige M3-Gates bleiben vor Release. | Abnahmegrenzen präzisiert, keine Findings geschlossen |
| 18 | THREAT-MODEL in M4.0, PRIVACY/Export/Erasure in M4.3 vor Echtdaten; minimale Claims und Retention. Rechtsgrundlage installationsbezogen durch Betreiber. | Aufgaben konkretisiert, keine Rechtsgrundlage pauschal festgelegt |

**Weiteres Vorgehen:** Die Rückfragen 1, 12 und 15 sind beantwortet. Als nächster Schritt
folgt M4.0 mit Google als erstem Integrationsziel: noch offene technische Konzeptvorschläge
prüfen und Designnachweise/ADRs erstellen. Die bestätigten Entscheidungen sind Vorgaben
für die spätere Umsetzung; diese Dokumentationsaktualisierung implementiert noch keine
OIDC-Funktion, installiert keine Bibliothek und aktiviert keine Loginroute.

## Offene Punkte / Probleme (Review-Anmerkungen)

Originalanmerkungen aus der Durchsicht, als Nachweis unverändert erhalten. Der aktuelle
Bearbeitungsstand und korrigierte Sachangaben stehen in der Tabelle darüber.

### Sicherheitsrelevant

1. **Client-Secret-Exfiltration durch Providerkonfiguration.** Admins (`manage_options`)
   dürfen Endpunkte und Secret-Referenz bearbeiten, die wp-config-Allowlist deckt aber nur
   *private* Ziele ab. Ein Admin (oder eine übernommene Admin-Sitzung) könnte den
   Token-Endpunkt eines Providers auf einen öffentlichen Angreiferhost umbiegen; WordPress
   sendet dann das Client-Secret dorthin. Der Abschnitt „bösartige Administratoren" nimmt
   das teilweise aus dem Schutzumfang, hier ist aber ein günstiger Schutz möglich.
   Vorschlag: Secret-Verwendung an gepinnte Issuer-/Endpunkt-Hosts aus `wp-config.php`
   binden (öffentliche Hosts ebenfalls per Allowlist) oder Endpunktänderungen als neue
   Revision behandeln, die das Secret bis zur erneuten Betreiberfreigabe sperrt.
   Entscheidung erforderlich.
2. **Schutz des unauthentifizierten Starts ist nicht spezifiziert.** „Gegen Login-CSRF
   absichern, Gastnonce genügt nicht" nennt keinen Mechanismus (z. B. POST mit
   browsergebundenem Token aus Cookie). Zusätzlich erzeugt jeder anonyme Start eine
   Transaktionszeile: DB-Flooding und Rate-Limits (pro IP/Provider/global, Obergrenze
   offener Transaktionen, Cleanup-Last) fehlen noch.
3. **Konfigurationsrevision aus Secrets.** Ein einfacher Hash des Client-Secrets in
   Transaktionen/DB ermöglicht Offline-Raten bei schwachen Secrets. Revision nur als
   HMAC mit Transaktionsschlüssel (nicht als plain Hash) bilden und das in der ADR festlegen.
4. **Google-Besonderheiten:** Issuer-Regeln (Google akzeptiert/liefert
   `accounts.google.com` und `https://accounts.google.com`), Claims wie `hd`/`email_verified`
   dürfen nie für Zuordnung genutzt werden, Google unterstützt kein RFC 9207 `iss`
   im Callback (Regel „fehlendes `iss` ablehnen" greift nur bei Ankündigung, das im
   Profil explizit festhalten). Redirect-URIs bei Google nur öffentliche Domains/HTTPS:
   lokale Entwicklungsumgebung benötigt separaten Testweg.
5. **Custom-Modus: Discovery vs. manuelle Werte.** „Discovery darf manuelle Werte nicht
   still überschreiben" ist unvollständig: Bei Abweichung (z. B. anderer `issuer` oder
   `jwks_uri`) muss der Provider verbindlich *fehlschlagen* oder die Abweichung zur
   Bestätigung anzeigen. Außerdem Regeln für erlaubte Signaturalgorithmen im Custom-Profil
   (feste Allowlist, kein Konfigurationswert „alle") definieren.
6. **Fehlerunterscheidung/Enumeration.** Unbekannte Identität, deaktiviertes Konto und
   MFA-Veto müssen für den Angreifer neutral und zeitlich ähnlich wirken, für Admins
   aber im (datensparsamen) Audit unterscheidbar sein. Beides ist bisher nicht festgelegt.

### Technisch / Architektur

7. **Verifier-Verschlüsselung:** PHP 8.2 ist Mindestversion, libsodium daher vorhanden;
   dennoch in der ADR prüfen, ob `ext-sodium` zwingend vorausgesetzt wird (Hosting ohne
   Extension nutzt nur `sodium_compat`, langsamer und mit anderen Garantien) und wie das
   Plugin ohne Extension reagiert (Provider deaktiviert statt Fallback).
8. **Bibliotheksauswahl und Mozart:** Die meisten OIDC-Bibliotheken bringen einen eigenen
   HTTP-Client mit; der geforderte gebundene DNS-/TLS-Transport lässt sich damit oft nur
   über einen injizierbaren PSR-18-Client erreichen. Das spricht für „nur JWT/JWKS-Bibliothek
   + eigener Protokollcode" statt Komplettclient. Frühes Spike-Ergebnis in M4.0 nötig, da es
   Aufwand und Abhängigkeiten von M4.1/M4.2 bestimmt.
9. **IP-gebundener Transport in WordPress:** `wp_remote_*` (WP_Http/cURL) erlaubt kein
   sauberes Pinning per `CURLOPT_RESOLVE` ohne Eingriff in Hooks (`http_api_curl`).
   Fallback auf Streams/Requests ist nicht ausreichend. Machbarkeit (auch ohne cURL)
   ist ein Gate für M4.0; falls nicht zuverlässig lösbar, ist die Alternative
   „nachweisliche Egress-Grenze" betreiberseitig und muss dann als Betriebsvoraussetzung
   dokumentiert werden.
10. **Callback-Routing und Caching:** Kandidat `wp-login.php`-Aktion oder eigener
    Rewrite-Endpunkt vs. REST. REST scheidet wegen fehlender Cookie-Auth ohne Nonce aus
    (Plan nennt das bereits); Rewrite-Regeln erfordern Flush bei Aktivierung/Update und
    sind bei Multisite/Permalink „Plain" heikel. Cache-Exclusion für Page-Cache-Plugins
    und CDNs dokumentieren (`Cache-Control: no-store` allein schützt nicht vor
    Edge-Regeln).
11. **Multisite:** Nicht erwähnt. Entscheidung nötig: nicht unterstützt (Aktivierung
    sperren/Hinweis) oder Identitäts-/Providerdaten pro Site vs. netzwerkweit.
12. **Pro Benutzer mehrere Links und Lockout:** Maximalzahl Links je Benutzer, Verhalten bei
    Benutzerlöschung/E-Mail-Änderung, und Schutz davor, dass Trennen den letzten
    *funktionierenden* lokalen Zugang entfernt (z. B. Benutzer mit unbekanntem
    Passwort, Passkeys nur auf verlorenem Gerät) sind nicht beschrieben.
13. **Zeit/Uhren:** Toleranz für `iat`/`exp`/`nbf` hängt von der Serveruhr ab; Verhalten
    bei Zeitabweichung (NTP-Ausfall) und maximal akzeptierte Tokenalter in ADR
    mit konkreten Zahlen festlegen (derzeit nur angekündigt).
14. **Provider-ID-Stabilität:** IDs sind Schlüssel für wp-config, Callback-URI,
    Identitätslinks und IdP-Registrierung. Umbenennen/Löschen/Neuanlegen mit gleicher ID
    darf keine Links erben (Client-/Issuerwechsel hängt nichts um): Modell für
    unveränderliche ID + Issuer/Client-Bindung je Link ergänzen.

### Prozess / Umfang

15. **Umfang von M4 ist groß** (drei Providermodi, VPN/private Netze, AEAD, eigener
    Transport, Settings-Umbau). Vorschlag: Keycloak (öffentlich) zuerst als vertikaler
    Durchstich bis M4.4, danach Google, danach Custom mit manuellen Endpunkten und
    VPN-Allowlist. Das reduziert das Risiko von M4.1 und trennt die SSRF-Policy-Erweiterung
    vom Basisfluss. Entscheidung gewünscht.
16. **Betriebsaufwand für Betreiber:** Vier wp-config-Konstanten mit eigenem Schlüsselformat
    sind ohne Hilfe fehleranfällig. Statusseite (nur „vorhanden/gültig", keine Werte),
    Generierungsanleitung (`php -r`/WP-CLI) und Validierungs-Hinweis in
    [OPERATIONS.md](OPERATIONS.md) einplanen; Format der Konstanten (Array vs. JSON,
    `define()`-Typen unter PHP 8.2) festlegen.
17. **Abhängigkeit von offenen M3-Punkten:** Release verlangt Klärung der offenen
    M3-Geräte-/Betriebsabnahmen. Prüfen, ob M4.1–M4.3 darauf aufsetzen dürfen oder ob ein
    Gate vor M4.2 (erste öffentliche Route) sinnvoll ist.
18. **Datenschutz:** Gespeichert werden Issuer + Subject (pseudonyme personenbezogene
    Daten) und ggf. Audit-Ereignisse. Aufnahme in [PRIVACY.md](PRIVACY.md) (Zweck,
    Rechtsgrundlage, Retention, Export/Erasure) und in
    [THREAT-MODEL.md](THREAT-MODEL.md) ist als Aufgabe von M4.0/M4.3 explizit zu führen;
    `email`/`name` aus dem Token möglichst gar nicht speichern.
