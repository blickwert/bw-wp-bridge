# Wartungsbericht erstellen (Quartal / Jahr)

Prompt für Claude Code am Ende eines Abrechnungszeitraums eines Wartungsvertrags. Kurzform zum Kopieren steht auf der
Website unter *Werkzeuge › Wartungsprotokoll* (unten, „Abschlussbericht mit Claude“).

```text
Erstelle den Wartungsbericht für <Website> für den Zeitraum <vorheriger Zeitraum | Vertragsjahr | JJJJ-MM-TT bis JJJJ-MM-TT>
nach prompts/wartungsbericht.md. Ausgabe als Markdown, nichts auf der Website ändern.
```

## Regeln

- **Nur lesen.** Für den Bericht nichts auf der Website ändern (keine Updates, keine Einstellungen). Empfehlungen gehören in den Bericht.
- **Nur belegte Aussagen.** Jede Aussage über durchgeführte Arbeiten stammt aus dem Protokoll (`report`), jede Aussage zum
  Zustand aus der Prüfung (`audit` im Report) oder aus einer Stichprobe, die im Bericht genannt wird. Fehlt etwas im
  Protokoll (z. B. Arbeiten vor Einführung des Protokolls), steht das als Hinweis im Bericht und wird nicht geraten.
- **Für Kund:innen verständlich.** Kurze Sätze, keine Routen, IDs oder Hooks im Hauptteil; Technisches in den Anhang.
- Der fertige Bericht kommt **nicht** in das Repo `bw-wp-bridge`. Ausgabe im Chat bzw. als Datei/Dokument, wohin die
  Nutzerin oder der Nutzer es möchte (z. B. Projekt-Repo der Website).

## Ablauf

1. **Website und Zeitraum klären.** `wp_bridge.py sites`, dann `wp_bridge.py --site <name> status`.
   Zeitraum: `--period previous` (abgelaufener Abrechnungszeitraum laut Einstellungen der Website), `--period year` /
   `previous-year` (Vertragsjahr) oder `--from JJJJ-MM-TT --to JJJJ-MM-TT`. Steht kein Zeitraum in der Anfrage: den
   vorherigen Zeitraum nehmen und das im Bericht sagen.
2. **Daten holen.**
   ```bash
   wp_bridge.py --site <name> report --period previous -o wartung.json
   ```
   Ohne Zugang zur Website: die im Backend heruntergeladene JSON-Datei („Daten für Claude“) verwenden.
   Inhalt: `period`, `kpis`, `by_area`, `by_via`, `timeline`, `sections.updates`, `sections.security`
   (`events`, `failed_logins`, `logins`), `sections.seo`, `seo_analysis` (`score`, `categories`, `tasks`, `pages`, `broken_links`, `site`),
   `sections.changes` (`objects`, `events`), `notes`,
   `audit` (`security`, `seo` mit Status `ok|info|warn|fail`), `audit_snapshots`, `scan` (Dateiüberwachung, `integrity.issues`,
   `vulns.items` mit Schweregrad und `fixed_in`), `versions`. Dateiänderungen und Funde stehen außerdem in `sections.security.events`
   (Kategorien `files`, `integrity`, `vuln`); Einzelheiten mit `wp_bridge.py scan-files --period … --unexpected`.
   Bei `truncated: true` zusätzlich `wp_bridge.py history --period … --area … --limit 1000` je Bereich lesen.
3. **Stichproben (optional, lesend).** `wp_bridge.py plugins` (aktuelle Versionen), `wp_bridge.py render <id> -q …`
   für geänderte Seiten, `wp_bridge.py search …`. Nur wenn nötig, um eine Aussage zu belegen.
4. **Bericht schreiben** (Aufbau unten). Notizen (`notes`) sind die wichtigste Quelle für „Was wurde gemacht“; die
   automatischen Einträge ergänzen und belegen sie. Gleichartige Einträge zusammenfassen (z. B. „12 Plugin-Updates“ mit Liste im Anhang).
5. **Jahresbericht** (`year`/`previous-year` oder ausdrücklich gewünscht): zusätzlich die **jährliche Sicherheitsanalyse**
   (Abschnitt 6) – ausführlicher, mit Vergleich zwischen erstem und letztem Schnappschuss (`audit_snapshots`) und den
   Quartalen (`timeline`).

## Aufbau des Berichts

```markdown
# Wartungsbericht <Website> – <Zeitraum>

<Adresse> · Zeitraum <von> bis <bis> · erstellt am <Datum>

## 1. Zusammenfassung
3–5 Sätze: Zustand der Website, wichtigste Arbeiten, wichtigste offene Punkte. Danach eine Kennzahlenzeile:
Updates · Sicherheitsereignisse · fehlgeschlagene Anmeldungen · manuelle Änderungen · Aufwand (aus den Notizen).

## 2. Durchgeführte Arbeiten
### Updates
Core, Plugins, Themes mit von → nach, Datum; automatische Updates kennzeichnen.
### Sicherheit
Benutzer/Rollen/Anwendungspasswörter, Datei-Editor, kritische Einstellungen; Anmeldungen der Administratoren;
Dateiüberwachung (unerwartete Änderungen, PHP an ungewöhnlicher Stelle, Abweichungen vom Original) und Schwachstellen
(gefunden / behoben im Zeitraum);
fehlgeschlagene Anmeldungen (Summe, Spitzentag, häufigste Benutzernamen) mit Einordnung.
### SEO
Geänderte SEO-Einstellungen und SEO-Notizen.
### Inhalte und manuelle Änderungen
Welche Seiten/Bereiche geändert wurden (aus `sections.changes.objects`), wer bzw. wie (Backend, Claude).
### Notizen und Aufwand
Tabelle: Datum · Bereich · Tätigkeit · Minuten · Ticket. Summe des Aufwands.

## 3. Zustand Sicherheit
Ergebnis der Prüfung: zuerst `fail`, dann `warn`; je Punkt eine verständliche Erklärung und was zu tun ist.

## 4. Zustand SEO
Wie 3, für SEO. Die SEO-Punktzahl aus `seo_analysis.score` nennen (je Bereich aus `categories`) und die wichtigsten Aufgaben aus
`seo_analysis.tasks` in verständlicher Sprache beschreiben, mit der Zahl der betroffenen Seiten. Defekte interne Links
(`broken_links`) nennen. Fehlt `seo_analysis.score`, steht das als Hinweis im Bericht; dann gilt nur die Prüfung aus `audit`.

## 5. Empfehlungen für die kommende Laufzeit
Priorisierte Liste (hoch / mittel / niedrig), je Empfehlung: was, warum, ungefährer Aufwand.
SEO-Aufgaben mit Status `fail` zuerst (hoch), `warn` danach (mittel). Offene Punkte aus der Prüfung, wiederkehrende Muster aus dem Protokoll (z. B. viele Fehlanmeldungen → Anmeldeschutz),
anstehende größere Updates (PHP, Major-Versionen).

## 6. Jährliche Sicherheitsanalyse (nur im Jahresbericht)
- Entwicklung über das Jahr (erster vs. letzter Schnappschuss, Quartale)
- Benutzer und Zugänge: Administratoren, Anwendungspasswörter (Alter, letzte Nutzung), Dienstbenutzer
- Software-Stand: WordPress, PHP (Supportende), Plugins (inaktiv, lange ohne Update, Ersatz nötig)
- Konfiguration: HTTPS, Datei-Editor, Debug, XML-RPC, Registrierung, Bridge-Freigaben
- Dateien: unerwartete Änderungen im Jahr, Abweichungen vom Original, Malware-Hinweise – jeweils mit Ergebnis der Klärung (Notizen)
- Schwachstellen: im Jahr gefunden und behoben (Dauer bis zur Behebung), offene Funde mit Schweregrad
- Angriffe: fehlgeschlagene Anmeldungen im Jahresverlauf
- Bewertung (gut / mit Empfehlungen / handlungsbedürftig) und Maßnahmenplan fürs nächste Vertragsjahr

## Anhang
Vollständige Update-Liste, technische Details, Datenquelle (Bridge-Version, Zeitpunkt des Abrufs).
```

## Laufend mitschreiben

Damit der Bericht am Ende vollständig ist, bei jeder Arbeit an einer Website eine Notiz schreiben:

```bash
wp_bridge.py history-note "Kontaktformular: Empfänger auf office@… umgestellt" --area changes --minutes 15 --ticket T-12
wp_bridge.py history-note "Meta-Beschreibungen für 6 Leistungsseiten ergänzt" --area seo --minutes 45
wp_bridge.py history-note "Nachgetragen: Backup-Test" --area security --date 2026-10-02
```

Bereiche: `updates`, `security`, `seo`, `changes`. Was Claude über die Bridge ändert, protokolliert die Website
ohnehin; die Notiz sagt, **warum** und mit welchem Aufwand.
