# Prompt: APPA auf dem Dev-Server einrichten

Diesen Text als erste Nachricht in eine **neue** Claude-Code-Cloud-Session kopieren.
Die Session mit den Repos `blickwert/bw-wp-bridge` und `blickwert/appa-data` starten.

**Voraussetzungen**
- Das Plugin *BW WP Bridge* ist auf dem Dev-Server aktiv.
- Die Cloud-Umgebung hat unter Network access **Custom** mit `dev.blickwert.at`.
- Die Cloud-Umgebung hat die Umgebungsvariablen `WP_URL=https://dev.blickwert.at/wp/2603-appa`, `WP_USER` und `WP_APP_PASSWORD`.

---

```text
Richte die APPA-Website auf meinem WordPress-Dev-Server ein. Arbeite direkt per REST-API
mit dem Client tools/wp_bridge.py aus blickwert/bw-wp-bridge (siehe dessen README).
Zugangsdaten liegen in WP_URL, WP_USER, WP_APP_PASSWORD – gib sie nie aus und frag mich
nie danach. Die Templates liegen in blickwert/appa-data unter elementor/
(README dort lesen; build.py erzeugt templates/*.json und kit-site-settings.json).

Ich will ausschließlich klassische Elementor-Widgets und Flexbox-Container, keine
V4/Atomic-Elemente und kein HTML-Widget.

Aufgaben, in dieser Reihenfolge:

1. Verbindung prüfen: `wp_bridge.py status`. Melde WordPress-, Elementor- und
   Elementor-Pro-Version, aktives Theme und aktives Kit. Fehlt Pro oder ist das Theme nicht
   Hello Elementor, sag es mir, bevor du weitermachst.
2. Bestand aufnehmen: vorhandene Seiten, Menüs und Elementor-Vorlagen auflisten.
   Nichts löschen oder überschreiben, ohne mich zu fragen.
3. Kit: kit-site-settings.json per `kit-put` einspielen (Merge, nicht ersetzen). Danach
   prüfen, dass die 12 Global Colors und die Global Fonts im Kit stehen.
4. Seiten anlegen (Status: Entwurf), jeweils mit `page-from-template`:
   Home (home), Über uns (ueber-uns), Veranstaltungen (veranstaltungen),
   Mitgliedschaft (mitgliedschaft), Kontakt (kontakt),
   Mitgliederbereich (mitgliederbereich),
   Mitgliederbereich – Übersicht (mitgliederbereich-uebersicht).
   Prüfe danach die tatsächlichen Slugs; weichen sie ab, passe die Links an
   (URL-Tabelle in elementor/build.py), baue neu und spiele die betroffenen Seiten neu ein.
5. Theme-Builder: Header, Footer (Home) und Footer (kompakt) per `template-import`
   importieren. Lege die Anzeigebedingungen nicht selbst fest; nenne mir die
   gewünschten Bedingungen aus der README von appa-data, ich setze sie im Editor.
6. Blöcke „CTA Mitglied werden“ und „Seitenkopf“ als Vorlagen importieren.
7. Menü „Hauptmenü“ mit Home, Über uns, Veranstaltungen, Mitgliedschaft, Kontakt anlegen
   (wp/v2/menus und wp/v2/menu-items). Wenn das Theme eine Menüposition hat, es dort zuweisen.
8. Startseite: Home als statische Startseite setzen (wp/v2/settings: show_on_front=page,
   page_on_front=<ID>). Vorher fragen, falls schon eine andere Startseite gesetzt ist.
9. Kontrolle: Rufe jede Seite im Frontend ab (als eingeloggter Benutzer, da Entwürfe)
   bzw. veröffentliche sie erst nach meiner Freigabe. Mach Screenshots bei 1280 px und 390 px
   und vergleiche sie mit APPA-Mockup/ (Vergleichsbilder in elementor/vergleich/).
   Berichte Abweichungen, besonders bei Pro-Widgets (Nav Menu, Formular, Login,
   Share Buttons, Sticky, Dynamic Tags), die vorher nicht getestet werden konnten.
10. Korrekturen, die nötig sind, in build.py umsetzen (nicht nur auf dem Server), neu bauen,
    einspielen und als PR in blickwert/appa-data einreichen.

Zum Schluss: kurze Übersicht, was angelegt wurde (mit IDs und Links), was noch
offen ist und was ich im Elementor-Editor selbst tun muss.
```
