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
   (am besten für einen eigenen Admin-Benutzer, z. B. `claude`).
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

## Verbindungsschlüssel (empfohlen)

Mit dem Verbindungsschlüssel verbindest du **genau eine Website** mit dem Client. Er schützt vor Verwechslungen (eine Umgebung zeigt versehentlich auf eine andere
Website) und ist ein zweiter Faktor neben dem Anwendungspasswort.

1. Einstellungen › BW WP Bridge › **Verbindungsschlüssel › „Schlüssel erzeugen“**. Der Schlüssel (`bwk_…`) wird **nur einmal** angezeigt, in der Datenbank liegt nur ein Hash.
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
admin/class-bw-bridge-settings.php        Einstellungsseite und Freigaben
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
- `php tests/registrations.php --check`: Hooks, REST-Routen, Einstellungen und Menü bleiben bei Umbauten unverändert
  (nach gewollten Änderungen `php tests/registrations.php > tests/registrations.expected.json`).

Lokal mit WordPress 7.2-alpha, Elementor 4.4 und Hello Elementor (ohne Elementor Pro):
Status, CPT und Taxonomie samt Einträgen über `wp/v2/…`, Kit-Update, Seite aus Template,
Lesen und Schreiben eines Layouts, Vorlagen-Import, falsches Passwort (401) und die `.htpasswd`-Variante.
