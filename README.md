# BW WP Bridge

Ermöglicht Claude Code (oder jedem anderen Skript), eine WordPress-Installation über die REST-API zu bearbeiten:
Seiten, Beiträge, CPT-Einträge, Medien, Menüs, Benutzer und Einstellungen über die Standard-API von WordPress,
dazu über dieses Plugin:

| Route (`/wp-json/bw-bridge/v1/…`) | Zweck |
|---|---|
| `GET status` | Versionen (WP, Elementor, Pro), aktives Kit, Theme |
| `GET/POST elementor/{id}` | Elementor-Layout einer Seite/eines Beitrags/einer Vorlage lesen bzw. speichern (klassische Widgets) |
| `GET/POST elementor/kit` | Global Colors, Global Fonts, Theme Style, Layout (Merge oder Ersetzen) |
| `POST elementor/templates` | Elementor-Vorlagen-JSON in die Vorlagen-Bibliothek importieren |
| `POST elementor/clear-cache` | Elementor-CSS neu erzeugen |
| `GET post-types`, `POST/DELETE post-types/{slug}` | eigene Post Types anlegen/ändern/löschen (ohne Code) |
| `GET taxonomies`, `POST/DELETE taxonomies/{slug}` | eigene Taxonomien anlegen/ändern/löschen |

Alle Routen verlangen einen angemeldeten **Administrator**. Die Anmeldung läuft über WordPress-**Anwendungspasswörter**.

## Einrichtung

1. Plugin installieren, eine der Varianten:
   - Repo nach `wp-content/plugins/bw-wp-bridge/` klonen bzw. kopieren und aktivieren,
   - ZIP hochladen (*Plugins › Installieren › Plugin hochladen*); `git archive` lässt `tools/` weg:
     `git archive --prefix=bw-wp-bridge/ -o bw-wp-bridge.zip HEAD`,
   - oder nur `bw-wp-bridge.php` nach `wp-content/mu-plugins/` legen.
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

Lokal mit WordPress 7.2-alpha, Elementor 4.4 und Hello Elementor (ohne Elementor Pro):
Status, CPT und Taxonomie samt Einträgen über `wp/v2/…`, Kit-Update, Seite aus Template,
Lesen und Schreiben eines Layouts, Vorlagen-Import, falsches Passwort (401) und die `.htpasswd`-Variante.
