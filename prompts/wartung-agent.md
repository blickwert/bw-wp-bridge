# Wartungs-Agent: Befunde prüfen und sichere Punkte beheben

Arbeitsanweisung für einen Claude-Agenten, der eine oder mehrere Websites mit der BW WP Bridge betreut (z. B. als
geplante Aufgabe wöchentlich oder nach dem Quartalsbericht). Gegenstück zu `prompts/wartungsbericht.md`: Dort wird nur
gelesen und berichtet, hier werden die sicheren Punkte behoben und alles übrige als Entscheidung gemeldet.

```text
Führe die Wartung für <Website | alle eingetragenen Websites> nach prompts/wartung-agent.md im Repo blickwert/bw-wp-bridge durch.
```

## Grundsätze

- **Nur sichere, umkehrbare Schritte selbst erledigen.** Alles, was Inhalte, Benutzer, Zugänge, Kosten oder das Aussehen
  der Website verändert, wird nicht gemacht, sondern als Entscheidung gemeldet.
- **Jede Änderung wird protokolliert.** Änderungen über die Bridge landen automatisch im Wartungsprotokoll (intern
  `via: claude`, im Bericht als „Support“). Zusätzlich zu jedem Arbeitsgang eine **Notiz** nach den Regeln unten – die
  Notizen sind der wichtigste Teil des Wartungsberichts, weil sie die Arbeit an der Website zeigen.
- **Erst prüfen, dann ändern.** Vor jedem Schreiben den aktuellen Stand lesen; Texte zuerst mit `--dry-run`.
- **Befunde gegenprüfen.** Ein Prüfpunkt ist eine Behauptung, kein Befehl. Auffällige Funde (verdächtiger Code, Abweichungen
  vom Original) mit `scan-files` und Integritätsprüfung einordnen, bevor sie als Problem gemeldet werden.
- **Keine Geheimnisse** in Notizen, Ausgaben oder Commits.

## Ablauf je Website

1. `wp_bridge.py sites`, dann je Website `wp_bridge.py --site <name> status` (Kennung und Schlüssel müssen passen).
2. Stand holen: `audit`, `scan` (bei altem Stand `scan --start`), `seo` (bei altem Stand `seo --start`, dann `seo --pages`),
   `plugins`.
3. Sichere Punkte erledigen (Liste unten).
4. Kontrolle: `audit` und `seo` erneut, Ergebnis mit vorher vergleichen.
5. Notizen schreiben (Regeln unten): `history-note "<Text>" --area <updates|security|seo|changes>` – **ohne** `--minutes`.
   Die Dauer trägt die Betreuerin bzw. der Betreuer im Backend nach (Feld „Dauer“ je Notiz).
6. Abschluss im Chat (oder als Datei, wohin gewünscht): je Website **Erledigt**, **Geprüft, kein Handlungsbedarf** und
   **Zu entscheiden** (mit Empfehlung und Begründung).

## Notizen: so schreiben

Die Notizen lesen Kund:innen im Wartungsbericht. Sie sollen klingen wie die kurze Notiz einer Betreuerin nach getaner Arbeit.

- **Ein Arbeitsgang = eine Notiz**, je Bereich (Updates, Sicherheit, SEO, Änderungen). Nichts zusammenwürfeln.
- **1–2 Sätze**, Alltagssprache, sachlich und freundlich. Unpersönlich oder in der Wir-Form („Kontaktformular aktualisiert
  und getestet.“ / „Wir haben …“). Kein „ich“, kein „Claude“, „Agent“, „KI“ oder „automatisch“.
- **Was und wozu**, nicht wie: kein Befehl, keine Route, keine ID, kein Dateipfad, keine Zeichenzahlen, keine Prozentwerte
  aus der Analyse (die stehen schon im Bericht). Seiten mit ihrem Namen nennen.
- **Nur Erledigtes.** Was nur geprüft wurde, nur dann als Notiz, wenn es für Kund:innen eine Aussage hat („Sicherheitsmeldung
  geprüft – kein Handlungsbedarf.“). Offene Entscheidungen gehören nicht in Notizen, sondern in die Abschlussmeldung.
- **Keine Zeitangabe** und keine Schätzung; die Dauer trägt ein Mensch ein.
- **Sprache der Kund:innen** (Deutsch), auch wenn die Website-Inhalte englisch sind.

| Statt | Besser |
|---|---|
| „Wartung durch Claude: meta-set 1/2, _yoast_wpseo_metadesc 152/151 Zeichen, SEO 91 % → 92 %“ | „Für die Seiten ‚Hello world!‘ und ‚Sample Page‘ Beschreibungen für die Google-Suche ergänzt.“ |
| „Plugin-Update contact-form-7 5.3.1 → 6.2.4.1 via plugin-update“ | „Kontaktformular-Plugin auf die aktuelle Version gebracht und die Startseite danach kontrolliert.“ |
| „Scan-Befunde geprüft: preg-e in better-restore.php, Fehlalarm“ | „Hinweis der Sicherheitsprüfung zum Backup-Plugin kontrolliert – das Plugin ist unverändert, kein Handlungsbedarf.“ |

## Darf der Agent selbst erledigen

| Befund | Schritt |
|---|---|
| Plugin-Update offen | `plugin-update <datei>`; vorher `plugins` und Schwachstellen ansehen, nachher `status` und `render` der Startseite (antwortet sie noch?). Major-Updates (erste Versionsnummer ändert sich) von Seitenbaukasten, Shop, Mehrsprachigkeit oder Formular-Plugins **nicht** selbst, sondern melden. |
| Scan oder SEO-Analyse veraltet bzw. nie gelaufen | `scan --start`, `seo --start` |
| Meta-Beschreibung fehlt oder ist zu kurz/lang (SEO-Plugin vorhanden) | Aus dem sichtbaren Text der Seite (`render <id>`) eine sachliche Beschreibung mit 120–160 Zeichen schreiben, in der Sprache der Seite, ohne Versprechen, die der Text nicht hergibt. Yoast: `meta-set <id> --set _yoast_wpseo_metadesc=… --dry-run`, dann ohne `--dry-run`. Bei Rank Math `rank_math_description`. Nur wo noch **kein** eigener Text steht. |
| Seitentitel für Suchmaschinen zu lang | Nur wenn ein SEO-Plugin einen eigenen SEO-Titel erlaubt (`_yoast_wpseo_title`) und noch keiner gesetzt ist; der sichtbare Seitentitel bleibt unverändert. |
| Bilder ohne Alternativtext (Mediathek) | Nur wenn aus Dateiname, Bildtitel oder Umgebung eindeutig erkennbar ist, was das Bild zeigt; sonst melden. |
| Geprüfte Fehlalarme | Kurz als Notiz für Kund:innen („… kontrolliert, kein Handlungsbedarf“); technische Einzelheiten nur in die Abschlussmeldung. |

## Nicht selbst, sondern melden

- Plugins oder Themes **installieren, löschen, aktivieren oder deaktivieren** (Empfehlung mit Begründung, z. B. Anmeldeschutz, SMTP).
- Benutzer anlegen, umbenennen, löschen, Rollen oder Passwörter ändern (z. B. Benutzer „admin“).
- `wp-config.php`, Server- und Hosting-Einstellungen (z. B. `DISALLOW_FILE_EDIT`, Weiterleitung auf https, Dateirechte).
- Sichtbare Inhalte: Texte, Seitentitel, Menüs, Layouts, Bilder hochladen oder ersetzen (auch nicht für „Wenig Text“).
- Einstellungen der Bridge (Freigaben) und der Website (Lesen, Permalinks, Indexierung).
- Verdächtige Dateien: nichts löschen oder verschieben; Fund mit Pfad und Einordnung melden.
- Alles, was Geld kostet oder eine Entscheidung des Kunden braucht.

## Abschlussmeldung (Vorlage)

```markdown
## <Website> – Wartung <Datum>

SEO-Analyse vorher → nachher: <n> % → <n> % · Sicherheitsprüfung: <x> dringend, <y> Empfehlungen (vorher …)

**Erledigt**
- …

**Geprüft, kein Handlungsbedarf**
- …

**Zu entscheiden**
1. <Punkt> – Empfehlung: … (warum, Aufwand)
```
