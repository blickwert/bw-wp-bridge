# BW WP Bridge

Ermöglicht Claude Code (oder jedem anderen Skript), eine WordPress-Installation über die REST-API zu bearbeiten:
Seiten, Beiträge, CPT-Einträge, Medien, Menüs, Benutzer und Einstellungen über die Standard-API von WordPress,
dazu über dieses Plugin:

| Route (`/wp-json/bw-bridge/v1/…`) | Zweck |
|---|---|
| `GET status` | Website-Kennung, Schlüsselstatus, Versionen (WP, Elementor, Pro), aktives Kit, Theme, Stand der Freigaben |
| `GET/POST/DELETE theme/files` | Theme-Dateien auflisten, lesen, schreiben, löschen – **nur nach Freigabe im Backend** (siehe unten) |
| `GET plugins` | Installierte Plugins mit Status und verfügbarem Update |
| `POST plugins/install` | Plugin installieren (`slug`, `url` oder `zip_base64`; optional `activate`, `overwrite`) – **nur nach Freigabe im Backend** (siehe unten) |
| `POST plugins/update` | Plugin aktualisieren (`plugin`: `ordner/datei.php`) – nur nach Freigabe |
| `GET/POST theme/backups` | Sicherungen einer Theme-Datei auflisten bzw. zurückspielen |
| `GET/POST elementor/{id}` | Elementor-Layout einer Seite/eines Beitrags/einer Vorlage lesen bzw. speichern (klassische und atomare Widgets); Speichern legt vorher automatisch eine Sicherung an, `"dry_run": true` liefert nur einen Textvergleich alt/neu |
| `POST elementor/theme-builder/refresh` | Elementor Pro Theme Builder: Anzeigebedingungen aller Vorlagen neu einlesen und die aktuelle Zuordnung ausgeben (nötig, wenn eine Vorlage oder ihre Bedingung per API angelegt/gesetzt wurde) |
| `GET/POST elementor/{id}/texts` | alle Texte einer Seite mit Widget-ID und Pfad lesen (`?q=` filtert) bzw. einzelne Texte gezielt setzen |
| `GET elementor/{id}/backups`, `POST elementor/{id}/restore` | die letzten 10 automatischen Layout-Sicherungen auflisten bzw. eine zurückspielen |
| `GET history`, `POST history/notes` | Wartungsprotokoll lesen (Zeitraum, Bereich, Volltext) bzw. eine Notiz schreiben (Tätigkeit, Bereich, Aufwand, Ticket) |
| `GET history/report` | alle Daten für den Wartungsbericht eines Zeitraums: Kennzahlen, Verlauf, Updates, Sicherheit, SEO, Änderungen, Notizen, Prüfung |
| `GET audit` | Sicherheits- und SEO-Prüfung (aktueller Stand, nur lesend) |
| `GET/POST seo` | SEO-Analyse der Seiten: Stand lesen bzw. Prüfung jetzt starten (nur lesend) |
| `GET/POST scan` | Dateiüberwachung, Integritätsprüfung und Schwachstellen: Stand lesen bzw. Prüfung jetzt starten |
| `GET acf/schema` | ACF-Aufbau nur lesend: Feldgruppen mit allen Feldern, Platzierungsregeln, eigene Post Types und Taxonomien (`group` filtert nach Titel/Schlüssel) |
| `GET search?q=…` | Seiten, Beiträge, Produkte, Post-Meta und Elementor-Texte durchsuchen (`types`, `lang`, `meta`, `limit`) |
| `GET/POST translations/{id}` | WPML: Übersetzungen eines Beitrags lesen (`{ de: 34, en: 12 }`) bzw. den Beitrag mit `{ "translation_of": 12 }` (und optional `"language": "de"`) als Übersetzung eines anderen verbinden; verweigert (409), wenn in dieser Sprache schon eine andere Übersetzung existiert. Nur mit aktivem WPML (`status` zeigt es im Feld `wpml`); ohne WPML melden die Routen das und tun nichts |
| `GET render/{id}` | sichtbarer Text der Seite im Frontend (`?q=` filtert Zeilen) |
| `GET/POST meta/{id}` | Post-Meta lesen/setzen/löschen, auch für Felder, die ein Plugin nicht in der REST-API freigibt (`"dry_run"` möglich) |
| `POST batch` | bis zu 50 REST-Aufrufe (auch `wp/v2/…`, `wc/v3/…`) in einer Anfrage; jede Operation läuft mit den Rechteprüfungen ihrer Route |
| `GET/POST elementor/kit` | Global Colors, Global Fonts, Theme Style, Layout (Merge oder Ersetzen) |
| `POST elementor/templates` | Elementor-Vorlagen-JSON in die Vorlagen-Bibliothek importieren |
| `POST elementor/clear-cache` | Elementor-CSS neu erzeugen |
| `GET post-types`, `POST/DELETE post-types/{slug}` | eigene Post Types anlegen/ändern/löschen (ohne Code) |
| `GET taxonomies`, `POST/DELETE taxonomies/{slug}` | eigene Taxonomien anlegen/ändern/löschen |

Alle Routen verlangen einen angemeldeten **Administrator**. Die Anmeldung läuft über WordPress-**Anwendungspasswörter**.

## Einrichtung

1. Plugin installieren, eine der Varianten:
   - Repo nach `wp-content/plugins/bw-wp-bridge/` klonen bzw. kopieren und aktivieren,
   - ZIP hochladen (*Plugins › Installieren › Plugin hochladen*): `git archive --prefix=bw-wp-bridge/ -o bw-wp-bridge.zip HEAD`
     (lässt `tools/`, `tests/` und `prompts/` weg),
   - oder als Must-Use-Plugin: den Ordner `bw-wp-bridge/` nach `wp-content/mu-plugins/` legen und dort eine Datei
     `bw-wp-bridge-loader.php` mit dem Inhalt `<?php require WPMU_PLUGIN_DIR . '/bw-wp-bridge/bw-wp-bridge.php';` anlegen.
2. In WordPress unter *Benutzer › Profil › Anwendungspasswörter* ein Passwort erzeugen
   (am besten für einen eigenen Admin-Benutzer, z. B. `support`).
3. In der Claude-Code-Cloud-Umgebung (*Titelleiste › Umgebung › Edit*):
   - **Network access:** Domain des Dev-Servers erlauben
   - **Umgebungsvariablen:** `WP_URL`, `WP_USER`, `WP_APP_PASSWORD`,
     bei `.htpasswd`-Schutz zusätzlich `WP_BASIC_AUTH=benutzer:passwort`
4. Neue Session starten.

Bei `.htpasswd`-Schutz schickt der Client das Anwendungspasswort als `X-WP-Authorization`-Header,
das Plugin reicht es an WordPress weiter. Ein Schutz per IP-Sperre funktioniert nicht
(Cloud-IPs wechseln).

Zum Abschalten ohne Deaktivieren: `define( 'BW_WP_BRIDGE_DISABLED', true );` in der `wp-config.php`.

> Nur für Dev-/Staging-Server gedacht. Wer das Anwendungspasswort hat, hat Admin-Rechte über die API.
> Das Passwort lässt sich im Profil jederzeit widerrufen.

## Texte, Suche, Sicherungen (ab 1.2)

Damit sich Inhalte schnell und ohne Handarbeit im Elementor-JSON ändern lassen:

- **Texte statt Layout:** `elementor-texts` zeigt jeden Text mit Widget-ID, Widget-Typ und Pfad (`settings.title`,
  `settings.editor`, `settings.items[11].item_title` …). `elementor-set` ändert nur diese Texte; Stile, Klassen und alles
  andere bleiben unverändert. Atomare Widgets (`{ "$$type": "escaped-html", "value": … }`) und klassische Widgets werden gleich behandelt.
  Mit `expect` wird nur geändert, wenn der aktuelle Text genau so lautet; legt keine neuen Felder an; bei einem Fehler in einer
  Änderung wird nichts gespeichert.
- **Probelauf:** `--dry-run` bei `elementor-set`, `elementor-put` und `meta-set` zeigt alt/neu, ohne etwas zu speichern.
- **Sicherungen:** Jedes Speichern eines Layouts (`elementor-put`, `elementor-set`, Wiederherstellen) sichert vorher den alten Stand
  als Post-Meta `_bw_bridge_el_backup` (die letzten 10 je Seite). `elementor-backups` listet sie, `elementor-restore` spielt eine zurück
  (und sichert dabei den aktuellen Stand). Beim Löschen des Plugins werden sie entfernt.
- **Suche:** `search` durchsucht Titel, Inhalt, Auszug, Post-Meta und die Elementor-Texte aller Beiträge (außer Papierkorb) und
  nennt je Treffer die Fundstelle (bei Elementor Widget-ID und Pfad). Elementor speichert Text JSON-escaped (`ü` als `\u00fc`);
  das wird berücksichtigt. Mit `--lang de` nur Beiträge dieser WPML-Sprache; `translations` liefert die Zuordnung der Sprachversionen.
- **Elementor-Pro-Formulare:** Beschriftungen, Auswahloptionen, Erfolgs-/Fehlermeldungen sowie Empfänger, Absender, Betreff und Text der Mails
  (`email_to`, `email_content_2` …) erscheinen in `elementor-texts` und lassen sich mit `elementor-set` ändern – z. B. den Empfänger beim Umzug von der Dev- auf die Live-Seite.
- **Prüfen im Frontend:** `render` holt die Seite von der eigenen URL und gibt den sichtbaren Text zeilenweise aus.
- **Stapel:** `batch` führt mehrere Aufrufe in einer Anfrage aus (stoppt standardmäßig beim ersten Fehler).
- **Zeitlimit:** `--timeout 300` (vor dem Befehl) oder `WP_TIMEOUT` für langsame Aufrufe.

```bash
wp_bridge.py search "Impressum" --lang de
wp_bridge.py elementor-texts 12 -q Kontakt
wp_bridge.py elementor-set 12 8db1a15 settings.title "Neuer Titel" --dry-run
wp_bridge.py elementor-set 12 --file aenderungen.json      # [{"widget_id","path","value","expect"?}, …]
wp_bridge.py elementor-backups 12 && wp_bridge.py elementor-restore 12
wp_bridge.py translation-link 34 --of 12             # Beitrag 34 als Übersetzung von Beitrag 12 verbinden (WPML)
wp_bridge.py meta-get 56 --prefix _shop_
wp_bridge.py meta-set 56 --set _shop_hinweis=Text
wp_bridge.py batch operationen.json    # {"operations":[{"method":"POST","path":"wp/v2/pages/12","body":{…}}, …]}
```

## Wartungsprotokoll und Wartungsbericht (ab 1.7)

Für Wartungsverträge: Die Bridge schreibt mit, was auf der Website passiert, und liefert am Ende eines
Abrechnungszeitraums (Quartal, Halbjahr, Jahr) die Daten für den Wartungsbericht. Es ist ein Protokoll zum
**Nachlesen**, kein Zurücksetzen. Angelehnt an die Hooks von [Simple History](https://wordpress.org/plugins/simple-history/),
aber nur mit dem, was für den Bericht zählt, und mit langer Aufbewahrung.

**Was aufgezeichnet wird** (Bereiche wie im Bericht):

| Bereich | Ereignisse |
|---|---|
| Updates | WordPress, Plugins und Themes aktualisiert (von → nach), installiert, aktiviert, deaktiviert, gelöscht; Übersetzungen; automatische Updates (Cron) |
| Sicherheit | Anmeldungen, fehlgeschlagene Anmeldungen (je Tag und Benutzername zusammengefasst), Anwendungspasswörter angelegt/gelöscht/fehlgeschlagen, Benutzer angelegt/gelöscht, Rollenwechsel, Passwort zurückgesetzt, Datei-Editor im Backend, kritische Einstellungen (`siteurl`, `home`, `admin_email`, Registrierung, Standardrolle), Schreibzugriffe auf Theme-Dateien über die Bridge |
| SEO | Indexierung (`blog_public`), Permalinks, Titel/Untertitel, Startseite |
| Manuelle Änderungen | Inhalte veröffentlicht/angelegt/bearbeitet/zurückgezogen/gelöscht (Bearbeitungen je Beitrag, Benutzer und Tag zusammengefasst), weitere Einstellungen, jeder erfolgreiche Schreibzugriff über die Bridge oder mit Anwendungspasswort |
| Notizen | was gemacht wurde und warum, mit Bereich, Aufwand (Minuten), Ticket und Schlagworten – über `history-note` oder im Backend |

Jeder Eintrag sagt, **wie** er zustande kam: `claude`, im Bericht „Support“ (Dienstbenutzer `support` bzw. früher `claude`, oder dieser Client, erkennbar am
User-Agent `bw-wp-bridge-client`), `backend`, `web` (Anmeldeseite/Website), `app` (anderes Anwendungspasswort), `cron`, `cli`.
Probeläufe (`dry_run`) und Lesezugriffe werden nicht protokolliert.

**Backend:** *Werkzeuge › Wartungsprotokoll* zeigt den Bericht eines Zeitraums mit Kennzahlen (die Kacheln sind Links und springen
zu ihrem Abschnitt), Aktivität je Woche/Monat als Grafik, „Wer und wie“, Sicherheits- und SEO-Prüfung, SEO-Analyse der Seiten (ab 1.8),
Updates, Sicherheit, SEO, manuellen Änderungen und Notizen, dazu ein Formular für
Notizen, das vollständige Protokoll mit Filter, „Drucken / PDF“ und „Daten für Claude (JSON)“.

**Einstellungen** (*Einstellungen › BW WP Bridge › Wartungsprotokoll*):

| Einstellung | Standard | Bedeutung |
|---|---|---|
| Abrechnungszeitraum | Quartal | Monat, Quartal, Halbjahr oder Jahr, ab Vertragsbeginn (leer = Kalenderzeiträume). Das Vertragsjahr für die jährliche Sicherheitsanalyse rechnet ab demselben Datum |
| Tage in der Datenbank | 365 | ältere Einträge wandern monatsweise in gzip-Dateien (JSON Lines) unter `wp-content/uploads/bw-bridge-log-<zufällig>/` (per `.htaccess` gesperrt) und werden für Berichte von dort mitgelesen |
| Aufbewahrung gesamt | 1095 Tage | danach werden Einträge und Archivdateien gelöscht; 0 = unbegrenzt |

**Datenbankfreundlich:** eine eigene Tabelle `{prefix}bw_bridge_log` mit Indizes auf Zeit und Bereich; häufige Ereignisse
werden je Tag zu einem Eintrag mit Zähler zusammengefasst; Archivieren und Aufräumen laufen einmal täglich per Cron
(höchstens 50 000 Einträge pro Lauf). Einmal pro Woche legt der Cron außerdem einen kompakten Schnappschuss der Prüfung ab,
damit der Jahresbericht die Entwicklung zeigen kann.

**Datenschutz:** IP-Adressen werden nur gekürzt gespeichert (IPv4 /24, IPv6 /48), bei Cron und WP-CLI gar nicht.
Die Aufbewahrungsfrist gehört in den Wartungsvertrag bzw. den AV-Vertrag. Über die API lassen sich Einträge weder
ändern noch löschen, nur Notizen hinzufügen. Abschalten: `define( 'BW_WP_BRIDGE_LOG_DISABLED', true );`
Beim Löschen des Plugins wird die Tabelle entfernt; Archivdateien bleiben.

**Prüfung** (`audit`): Sicherheit – WordPress-, PHP-, Plugin- und Theme-Updates, inaktive Plugins, automatische Updates,
Administratoren, Benutzer „admin“, Anwendungspasswörter (unbenutzt seit 180 Tagen), Registrierung/Standardrolle, Datei-Editor, Debug,
`debug.log`, HTTPS, XML-RPC, Tabellenpräfix, Website-Zustand (letzter Lauf), Bridge-Freigaben und -Schlüssel, fehlgeschlagene Anmeldungen;
SEO – Indexierung, Permalinks, Untertitel, Website-Icon, SEO-Plugin (Yoast, Rank Math, SEOPress, AIOSEO, The SEO Framework), Sitemap,
fehlende Meta-Beschreibungen, noindex, Bilder ohne Alternativtext, Inhalte ohne Titel. Nur günstige Abfragen, keine HTTP-Anfragen.

### Dateiüberwachung und Schwachstellen-Scan

Läuft täglich per Cron in Schritten (je höchstens 20 Sekunden, Filter `bw_bridge_scan_budget`; der nächste Schritt folgt nach 30 Sekunden)
und schreibt Ergebnisse ins Wartungsprotokoll (Bereich Sicherheit) und in die Prüfung. Einzeln abschaltbar unter
*Einstellungen › BW WP Bridge*, hart abschalten: `define( 'BW_WP_BRIDGE_SCAN_DISABLED', true );`

- **Dateiänderungen:** Pfad, Größe, Änderungszeit und SHA-1 der Code-Dateien (`php`, `js`, `html`, `.htaccess`, `.user.ini` …) von Core,
  Plugins, Themes, Must-Use-Plugins und Drop-ins/Sprachdateien sowie aller PHP-Dateien in `uploads` und sonstigen Ordnern in `wp-content`
  (eigene Tabelle `{prefix}bw_bridge_files`). Neu gerechnet wird nur, wo sich Größe oder Zeit geändert haben. Je Bereich und Lauf ein Eintrag:
  **erwartet**, wenn sich die Version geändert hat oder seit dem letzten Abgleich ein Update protokolliert wurde, sonst **unerwartet** (Warnung).
  Ausgelassen: `cache`, `upgrade`, Backup-Ordner, `.git`, `node_modules`, Sicherungen der Bridge.
- **PHP an ungewöhnlicher Stelle** (`uploads`, sonstige Ordner) wird immer gemeldet – nur gemeldet, nichts verschoben oder gelöscht.
- **Integrität:** Core gegen die Prüfsummen von api.wordpress.org, Plugins aus dem Verzeichnis gegen downloads.wordpress.org/plugin-checksums
  (bei Änderungen, neuer Version, sonst wöchentlich). Gemeldet werden geänderte Dateien und zusätzliche PHP-Dateien. Gekaufte Plugins
  (nicht im Verzeichnis) und Themes werden nur auf Änderungen überwacht.
- **Malware-Hinweise:** wenige eindeutige Muster in neuen/geänderten PHP-Dateien (`eval(base64_decode(…))`, `eval($_POST…)`,
  `$_GET[…]($_GET…)`, `preg_replace …/e`, bekannte Webshell-Namen, sehr lange Base64-Zeichenketten). Ersetzt keinen Malware-Scanner.
- **Schwachstellen:** Core, Plugins und Themes gegen [wpvulnerability.net](https://www.wpvulnerability.net/) (öffentlich, ohne Schlüssel;
  bündelt CVE, Wordfence, Patchstack, WPScan u. a.; Antworten 12 Stunden zwischengespeichert). Übermittelt werden nur Slugs und die
  WordPress-Version. Neue und behobene Schwachstellen erscheinen im Protokoll, im Wartungsbericht mit Schweregrad und Version mit der Behebung.
  Im Verzeichnis geschlossene Plugins werden ebenfalls gemeldet.

```bash
wp_bridge.py scan                          # Stand: Dateien, Integrität, Schwachstellen
wp_bridge.py scan --start                  # jetzt prüfen (größere Websites werden im Hintergrund fertig)
wp_bridge.py vulns
wp_bridge.py scan-files --period previous --unexpected
```

**Abschlussbericht:** Am Ende des Zeitraums den Prompt aus [`prompts/wartungsbericht.md`](prompts/wartungsbericht.md) verwenden
(Kurzform steht auch auf der Wartungsseite). Claude holt mit `report` alle Daten und schreibt daraus den Bericht als Markdown
zum Weiterverarbeiten: Zusammenfassung, durchgeführte Arbeiten, Zustand Sicherheit und SEO, Empfehlungen für die kommende Laufzeit
und einmal im Jahr die ausführliche Sicherheitsanalyse.

**Wartungs-Agent (ab 1.8):** [`prompts/wartung-agent.md`](prompts/wartung-agent.md) beschreibt, wie ein Claude-Agent
(z. B. als geplante Aufgabe) die Befunde einer oder aller eingetragenen Websites prüft, die sicheren Punkte selbst behebt
(Plugin-Updates, Scans, fehlende Meta-Beschreibungen …), jede Änderung mit Notiz protokolliert und alles Übrige als
Entscheidung mit Empfehlung meldet.

```bash
wp_bridge.py history --period previous --area updates     # current | previous | year | previous-year, oder --from/--to
wp_bridge.py history -q Kontakt --via claude
wp_bridge.py history-note "Kontaktformular-Empfänger umgestellt" --area changes --minutes 15 --ticket T-12
wp_bridge.py report --period previous -o wartung.json      # Daten für den Wartungsbericht
wp_bridge.py audit                                          # Sicherheits- und SEO-Prüfung
```

### SEO-Analyse der Seiten (ab 1.8)

Prüft die veröffentlichten Seiten im Frontend, ähnlich einem SEO-Check wie Seobility, und liefert eine Punktzahl je Bereich
und insgesamt (0–100 %), eine Aufgabenliste und die Befunde je Seite. Nur lesend, es wird nichts auf der Website geändert.

- **Seiten:** Startseite zuerst, dann Seiten, dann die neuesten Beiträge anderer Inhaltstypen; höchstens 50 (Filter
  `bw_bridge_seo_max_pages`). Je Seite fünf Bereiche: **Meta-Angaben** (Titel, Beschreibung, Canonical, noindex, Sprache, Zeichensatz,
  Viewport, Open Graph), **Seitenqualität** (Wortanzahl, Bilder ohne alt-Attribut, strukturierte Daten, gleicher Titel bzw. gleiche
  Beschreibung auf mehreren Seiten), **Seitenstruktur** (H1 und Überschriften, Adresse der Seite), **Links** (interne und externe Links,
  Linktexte, defekte interne Links) und **Server** (HTTP-Status, Antwortzeit, Größe des HTML, Weiterleitungen, unsichere http-Inhalte).
- **Website-weit:** robots.txt, XML-Sitemap (auch in der robots.txt angegeben), Weiterleitung von http auf https, www/non-www,
  Status der 404-Seite, Favicon. Interne Links werden höchstens 150 je Lauf geprüft.
- **Ergebnis:** Punktzahl je Bereich und gesamt, Aufgabenliste (gleiche Befunde über alle Seiten zusammengefasst, mit der Zahl der
  betroffenen Seiten) und Befunde je Seite. Im Wartungsprotokoll erscheint je Lauf ein Eintrag, in der Prüfung der Punkt „SEO-Analyse“.
- **Ablauf:** wöchentlich per Cron in Schritten mit Zeitbudget (je höchstens 20 Sekunden, Filter `bw_bridge_seo_budget`).
  Sofort starten: „Jetzt prüfen“ im Bericht, `POST bw-bridge/v1/seo` oder `wp_bridge.py seo --start`.
- **Serverschutz:** Dev-Server mit `.htpasswd` antworten sonst mit 401. Die Abrufparameter lassen sich über den Filter
  `bw_bridge_seo_request_args` anpassen (z. B. einen Header für die Anmeldung setzen).
- Hart abschalten: `define( 'BW_WP_BRIDGE_SEO_DISABLED', true );`

```bash
wp_bridge.py seo                        # Stand: Punktzahl, Bereiche, Aufgaben
wp_bridge.py seo --start                # jetzt prüfen (läuft in Schritten weiter)
wp_bridge.py seo --pages --tasks 30     # zusätzlich Seiten mit Befunden, mehr Aufgaben
```

### Bericht per E-Mail (ab 1.8)

Am ersten Tag nach Ende eines Abrechnungszeitraums (Rhythmus wie oben eingestellt) schickt die Bridge den Wartungsbericht
automatisch per E-Mail: eine HTML-Zusammenfassung mit Kennzahlen, Sicherheits- und SEO-Prüfung, wichtigsten Aufgaben und Notizen,
dazu die JSON-Datei „Daten für Claude“ als Anhang.

- **Empfänger:** *Einstellungen › BW WP Bridge › Wartungsprotokoll › „Bericht per E-Mail“*, kommagetrennt. Standard ist
  `maintenancereport@blickwert.at`. Der Versand ist standardmäßig eingeschaltet und lässt sich dort abschalten.
- **Erster Lauf:** Nach Installation oder Update wird nur der vorherige Zeitraum als erledigt markiert. Für Zeiträume vor der
  Einrichtung wird also kein Bericht verschickt.
- **Fehlversuche:** Schlägt der Versand fehl, wird am nächsten Tag erneut versucht, insgesamt höchstens drei Mal je Zeitraum.
  Versand und Fehlschläge stehen im Wartungsprotokoll (Bereich System, Kategorie Bericht).
- **Von Hand:** „Testbericht jetzt senden“ in den Einstellungen (enthält den laufenden Zeitraum und nutzt die gespeicherten
  Empfänger, also zuerst speichern) bzw. „Per E-Mail senden“ im Bericht.
- Der Versand läuft über `wp_mail`. Die Website braucht also eine funktionierende E-Mail-Zustellung (ein SMTP-Plugin wird empfohlen).

## Verbindungsschlüssel (empfohlen)

Mit dem Verbindungsschlüssel verbindest du **genau eine Website** mit dem Client. Er schützt vor Verwechslungen (eine Umgebung zeigt versehentlich auf eine andere
Website) und ist ein zweiter Faktor neben dem Anwendungspasswort.

1. Einstellungen › BW WP Bridge › **Verbindungsschlüssel › „Schlüssel und Zugangsdaten erzeugen“**. Das Plugin legt dabei den Administrator-Benutzer `support` (`support@blickwert.at`, Anzeigename „Support“; anderer Name per `define( 'BW_WP_BRIDGE_SERVICE_LOGIN', '…' )`) an, falls er fehlt, und erzeugt für ihn das Anwendungspasswort „Wartung“ (frühere Passwörter „Wartung“ oder „claude-code“ werden ersetzt). Ein vorhandener alter Dienstbenutzer `claude` wird weiter verwendet und lässt sich dort per Knopf in `support` umbenennen (danach `WP_<NAME>_USER` im Client anpassen) und zeigt **einmalig** einen fertigen Block mit `WP_<NAME>_URL`, `_USER`, `_APP_PASSWORD`, `_BRIDGE_KEY` und `_BRIDGE_SITE` zum Kopieren an. In der Datenbank liegt nur ein Hash des Schlüssels.
2. Im Client eintragen: `WP_BRIDGE_KEY=bwk_…` (zusammen mit `WP_URL`, `WP_USER`, `WP_APP_PASSWORD`). Nicht in einen Chat einfügen.
3. Optional die **Kennung** der Website (8 Zeichen, nicht geheim, steht auf der Einstellungsseite) als `WP_BRIDGE_SITE` eintragen: Der Client prüft dann vor jeder
   Anfrage, ob er mit der richtigen Website verbunden ist, und bricht sonst ab, bevor etwas gesendet wird.

- Ohne Schlüssel (oder mit falschem) antworten alle Bridge-Routen mit `403 bw_bridge_key` und nennen Website und Kennung. Ausgenommen sind `status` (zeigt dann nur Name, Adresse, Kennung) und `auth-check`.
- `wp_bridge.py status` zeigt: `Verbunden mit: <Name> (<Adresse>) · Kennung … · Schlüssel ok`.
- Standardmäßig gilt die Prüfung nur für die Bridge-Routen. Mit „Schlüssel für alle REST-Anfragen“ wird sie auf jede REST-Anfrage ausgedehnt, die per Anwendungspasswort kommt.
- „Neuen Schlüssel erzeugen“ macht den alten ungültig (die Kennung bleibt). „Schlüssel entfernen“ schaltet die Prüfung wieder aus. Ist kein Schlüssel eingerichtet, ändert sich nichts.
- Die Einstellungen sind nicht über die REST-API änderbar.

## Mehrere Websites

Die Bridge ist für mehrere Websites gedacht. Der Client (`tools/wp_bridge.py`) liest pro Website einen **eigenen Satz** Umgebungsvariablen. `<NAME>` ist ein frei gewählter Name in Großbuchstaben (Ziffern und `_` erlaubt):

| Variable | Bedeutung |
|---|---|
| `WP_<NAME>_URL` | Adresse der Website |
| `WP_<NAME>_USER`, `WP_<NAME>_APP_PASSWORD` | Benutzer und Anwendungspasswort |
| `WP_<NAME>_BRIDGE_KEY` | Verbindungsschlüssel (siehe oben), empfohlen |
| `WP_<NAME>_BRIDGE_SITE` | Kennung der Website, empfohlen |
| `WP_<NAME>_BASIC_AUTH`, `WP_<NAME>_TIMEOUT` | optional |

Beispiel: `WP_SOULDATE_URL`, `WP_SOULDATE_USER`, `WP_SOULDATE_APP_PASSWORD`, `WP_SOULDATE_BRIDGE_KEY`, `WP_SOULDATE_BRIDGE_SITE` und dasselbe mit `WP_APPA_…`.

- Auswahl: `wp_bridge.py --site souldate …` oder `WP_SITE=souldate`.
- **Sicherheitsregel:** Sind mehrere Websites eingetragen und keine gewählt, bricht der Client ab, ohne etwas zu senden. Bei mehreren Websites steht auf der Fehlerausgabe zusätzlich `Website: <Name> (<Adresse>)`.
- `wp_bridge.py sites` listet die eingetragenen Websites (ohne Geheimnisse, ohne Verbindung).
- Der bisherige einzelne Satz (`WP_URL`, `WP_USER`, `WP_APP_PASSWORD`, …) funktioniert unverändert und heißt dann `default`.

## Plugins installieren und aktualisieren (optional)

Mit der Freigabe **„Plugins installieren und aktualisieren“** (Einstellungen › BW WP Bridge, **standardmäßig aus**) kann die Bridge
Plugins einspielen – auch eigene oder gekaufte, die nicht im wordpress.org-Verzeichnis liegen. Das Auflisten (`plugins`) geht immer.

| Quelle | Aufruf |
|---|---|
| wordpress.org | `plugin-install --slug wordpress-seo --activate` |
| https-ZIP (z. B. Release-Datei) | `plugin-install --url https://…/plugin.zip --activate` |
| lokale ZIP-Datei | `plugin-install --zip plugin.zip --activate` |
| vorhandenes Plugin ersetzen | zusätzlich `--overwrite` (Update oder Downgrade aus einem ZIP) |
| Update aus dem Verzeichnis | `plugin-update ordner/datei.php` |

- Nur Administratoren (`manage_options`) mit den Rechten `install_plugins` (Überschreiben/Aktualisieren: `update_plugins`, Aktivieren: `activate_plugins`).
  Entfällt bei `DISALLOW_FILE_MODS` in der wp-config.php. Hart abschalten: `define( 'BW_WP_BRIDGE_PLUGINS_DISABLED', true );`
- Installiert wird mit dem WordPress-Upgrader wie im Backend. Vorher wird das ZIP geprüft: nur `https`, keine Zugangsdaten in der Adresse,
  keine internen Adressen (`wp_safe_remote_get`), höchstens 50 MB, ZIP-Kennung, keine Einträge mit `..` oder absolutem Pfad.
- Optional nur bestimmte Hosts erlauben: `add_filter( 'bw_bridge_plugin_allowed_hosts', fn() => [ 'github.com' ] );`
- Die Freigabe lässt sich nur im Backend ändern, nicht über die API.

> Wer das Anwendungspasswort hat und die Freigabe eingeschaltet findet, kann beliebigen PHP-Code auf der Website ausführen
> (jedes Plugin ist Code). Nur auf Dev-/Staging-Servern einschalten und danach wieder ausschalten.

## Theme-Dateien lesen und schreiben (optional)

Damit sich z. B. WooCommerce-E-Mail-Templates (`woocommerce/emails/…`) oder Übersetzungsdateien im Child-Theme
bearbeiten lassen, kann die Bridge Dateien des aktiven Themes lesen und schreiben. **Standardmäßig ist das aus.**

**Freischalten** (nur im Backend, nicht über die API): *Einstellungen › BW WP Bridge*

| Option | Bedeutung |
|---|---|
| Theme-Dateien lesen | Ordner auflisten, Dateien lesen |
| Theme-Dateien schreiben | Dateien anlegen, ändern, löschen; setzt „lesen“ voraus |
| Parent-Theme einbeziehen | zusätzlich das Parent-Theme (`--parent` im Client bzw. `theme=parent`) |

**Berechtigungen und Schutz**
- Nur Administratoren (`manage_options`); Schreiben, Löschen und Wiederherstellen zusätzlich mit `edit_themes`.
  Ist in der `wp-config.php` `DISALLOW_FILE_EDIT` oder `DISALLOW_FILE_MODS` gesetzt, ist Schreiben gesperrt.
- Hart abschalten, egal was im Backend steht: `define( 'BW_WP_BRIDGE_FILES_DISABLED', true );`
- Pfade sind relativ zum Theme-Ordner. Nicht möglich: `..`, absolute Pfade, versteckte Dateien, `.git`, `node_modules`,
  `vendor`, Symlinks aus dem Theme heraus.
- Schreiben nur für `php, css, js, json, html, txt, md, po, pot, mo, svg, xml, twig`, bis 1 MB (Lesen bis 2 MB).
- **PHP-Dateien werden vor dem Speichern auf Syntaxfehler geprüft** (ohne Ausführung); bei einem Fehler wird nichts geschrieben.
- **Vor jedem Überschreiben oder Löschen** entsteht eine Sicherung unter `wp-content/uploads/bw-bridge-backups-<Kennung>/<theme>/…`
  (nicht erratbarer Ordnername, zusätzlich per `.htaccess` gesperrt). Mit `theme-restore` lässt sie sich zurückspielen.
- Geschrieben wird atomar (Temporärdatei, dann Umbenennen); optional `expected_sha1`, damit nichts überschrieben wird,
  was sich seit dem Lesen geändert hat.

> Wer das Anwendungspasswort hat und „schreiben“ eingeschaltet findet, kann PHP-Code im Theme ändern. Nur auf Dev-/Staging-Servern
> einschalten und nach der Arbeit wieder ausschalten.

```bash
wp_bridge.py status                                  # theme_files: read / write / parent_theme
wp_bridge.py theme-ls woocommerce/emails -r
wp_bridge.py theme-get woocommerce/emails/customer-new-account.php -o alt.php
wp_bridge.py theme-put woocommerce/emails/customer-new-account.php neu.php
wp_bridge.py theme-backups woocommerce/emails/customer-new-account.php
wp_bridge.py theme-restore woocommerce/emails/customer-new-account.php
```


## Aufbau

```text
bw-wp-bridge.php                          Plugin-Kopf, Konstanten, Autoloader, Start
uninstall.php                             räumt die Freigaben beim Löschen des Plugins weg
includes/auth-bootstrap.php               läuft beim Laden: Anwendungspasswort hinter .htpasswd / CGI
includes/class-bw-bridge.php              Kern: Module starten, Rechteprüfung, Route status
includes/class-bw-bridge-auth.php         Route auth-check (Diagnose der Anmeldung)
includes/class-bw-bridge-elementor.php    Layouts (mit Sicherungen und Probelauf), Kit, Vorlagen-Import, CSS-Cache
includes/class-bw-bridge-elementor-texts.php  Texte lesen/setzen/vergleichen (reine Logik, ohne WordPress testbar)
includes/class-bw-bridge-search.php       Suche, WPML-Zuordnung, Frontend-Text
includes/class-bw-bridge-meta.php         Post-Meta lesen/schreiben
includes/class-bw-bridge-batch.php        Stapelaufrufe
includes/class-bw-bridge-content-types.php  eigene Post Types und Taxonomien
includes/class-bw-bridge-theme-files.php  Theme-Dateien lesen/schreiben, Sicherungen
includes/class-bw-bridge-log.php          Wartungsprotokoll: Tabelle, Einträge, Archiv, Aufbewahrung, Abrechnungszeiträume
includes/class-bw-bridge-log-wp.php       Wartungsprotokoll: WordPress-Ereignisse und REST-Schreibzugriffe aufzeichnen
includes/class-bw-bridge-history.php      Routen history, history/notes, history/report; Berichtsdaten
includes/class-bw-bridge-audit.php        Route audit: Sicherheits- und SEO-Prüfung, wöchentlicher Schnappschuss
includes/class-bw-bridge-seo.php          Route seo: SEO-Analyse der Seiten (Prüfung je Seite, website-weite Punkte, Score, Aufgaben)
includes/class-bw-bridge-report-mail.php  Wartungsbericht per E-Mail (automatisch je Zeitraum, Testversand, Fehlversuche)
includes/class-bw-bridge-scan.php         Route scan: Dateiüberwachung, Integrität, Schwachstellen (wpvulnerability.net)
admin/class-bw-bridge-settings.php        Einstellungsseite und Freigaben
admin/class-bw-bridge-maintenance.php     Werkzeuge › Wartungsprotokoll (Bericht mit Grafik, Protokoll, Notizen)
tools/wp_bridge.py                        Kommandozeilen-Client
tests/                                    Tests (siehe unten)
```

Neue Funktionen kommen als eigene Klasse in `includes/` (bzw. `admin/`), werden in der Liste im Autoloader von
`bw-wp-bridge.php` eingetragen und in `BW_WP_Bridge::register_routes()` bzw. `init()` eingehängt.

## Client

`tools/wp_bridge.py` braucht nur Python 3 (keine Pakete):

```bash
wp_bridge.py status
wp_bridge.py get wp/v2/pages --query per_page=100 --query _fields=id,slug,title
wp_bridge.py post wp/v2/pages --json '{"title":"Kontakt","status":"draft"}'
wp_bridge.py page-from-template templates/page-home.json --title Home --slug home --status publish
wp_bridge.py elementor-get 42 -o seite.json        # Layout lesen
wp_bridge.py elementor-put 42 seite.json           # Layout schreiben
wp_bridge.py kit-put kit-site-settings.json        # Global Colors/Fonts
wp_bridge.py template-import templates/header-hauptnavigation.json
wp_bridge.py cpt-set event --json '{"label":"Veranstaltungen","menu_icon":"dashicons-calendar","rewrite":{"slug":"events"}}'
wp_bridge.py tax-set event_category --json '{"object_types":["event"],"args":{"label":"Kategorien"}}'
wp_bridge.py post wp/v2/event --json '{"title":"Fachtagung 2027","status":"publish"}'
```

## Getestet

Ohne WordPress-Installation, in einer simulierten Umgebung (`php tests/…`):
- `php tests/test-theme-files.php`: Theme-Dateizugriff (Rechte, Pfadschutz, Symlinks, Syntaxprüfung, Sicherung, Wiederherstellen).
- `php tests/test-elementor-texts.php`: Texte auslesen/setzen/vergleichen (atomare und klassische Widgets, Wiederholer, Fehlerfälle).
- `php tests/test-bridge-content.php`: Sicherungen, Wiederherstellen, Probelauf, Meta, Stapel, Suche, WPML-Zuordnung
  (simulierte Umgebung, prüft u. a., dass die SQL-Platzhalter der Suche zu den Parametern passen).
- `php tests/test-seo.php`: SEO-Prüfung je Seite, website-weite Prüfungen, robots.txt, Zusammenfassung und Score, Ablauf mit Linkprüfung.
- `php tests/test-report-mail.php`: Empfänger, automatischer Versand einmal je Zeitraum, JSON-Anhang, Fehlversuche, HTML-Escaping.
- `php tests/test-history.php`: Wartungsprotokoll (IP-Kürzung, Zeiträume, Zusammenfassen, Notizen, REST-Schreibzugriffe,
  Archiv schreiben/lesen, Aufbewahrung, Berichtsdaten).
- `php tests/test-scan.php`: Dateiüberwachung in einer simulierten Installation (Ersterfassung, unerwartete/erwartete Änderungen,
  nur Zeitstempel, PHP in uploads, Malware-Muster, Integrität inkl. zusätzlicher Core-Datei, gelöschtes Plugin, Zeitbudget, Schwachstellen).
- `php tests/registrations.php --check`: Hooks, REST-Routen, Einstellungen und Menü bleiben bei Umbauten unverändert
  (nach gewollten Änderungen `php tests/registrations.php > tests/registrations.expected.json`).

Lokal mit WordPress 7.2-alpha, Elementor 4.4 und Hello Elementor (ohne Elementor Pro):
Status, CPT und Taxonomie samt Einträgen über `wp/v2/…`, Kit-Update, Seite aus Template,
Lesen und Schreiben eines Layouts, Vorlagen-Import, falsches Passwort (401) und die `.htpasswd`-Variante.
