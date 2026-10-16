# BW Brevo Bridge

Zweites Plugin zur **BW WP Bridge**: verbindet WordPress, Claude und **Brevo** (früher Sendinblue).
Es nutzt den Namespace `bw-bridge/v1/brevo/…` – damit gelten Anmeldung (Anwendungspasswort), Verbindungsschlüssel und der Client `tools/wp_bridge.py` unverändert. Läuft aber auch ohne die Bridge.

> **Hinweis:** „Prevo“ gibt es als Newsletter-Dienst nicht; angenommen ist Brevo. Die Aufrufe folgen der Brevo-API v3 (Kampagnen, Kontakte, Ereignisse, Webhooks) und sind hier gegen eine **simulierte** API getestet (`php tests/test-brevo.php`), noch nicht gegen ein echtes Konto. Vor dem ersten Einsatz mit einem Test-Konto/-Liste durchspielen.

## Was es kann

| Bereich | Funktion |
|---|---|
| **Kampagnen** | Beitragstyp *Newsletter* mit Tab-Feld („Inhalt“, „Versand“, „Brevo & Analyse“). Text im normalen Editor, Betreff/Vorschautext/Absender/Listen/Versandzeit in den Tabs. Per Klick oder API: *push* (Entwurf in Brevo anlegen/aktualisieren) → *test* (Testmail) → *send* / *schedule*. |
| **Zustellbarkeit** | Webhook-Empfänger für Zustellung, Bounces, Beschwerden, Abmeldungen, Klicks (gespeichert werden nur Hash der Adresse + Domain). Täglicher Abgleich der Brevo-Kampagnenstatistik. Raten (Zustell-, Bounce-, Beschwerde-, Klick-, Abmelderate), Warnungen bei Schwellwerten (Beschwerden ≥ 0,1 / 0,3 %, Hard Bounces ≥ 2 %, Zustellrate < 95 %), Probleme je Postfachanbieter. Seite *Newsletter › Zustellbarkeit*. |
| **Automationen** | Workflows baut man in Brevo (die API legt keine an). Gesteuert wird, *wann* sie starten: WordPress-Auslöser (Registrierung, Login, WooCommerce-Bestellung; erweiterbar per Filter `bwb_triggers`) senden ein Brevo-**Ereignis**; zusätzlich `POST brevo/events` für einzelne Ereignisse. In Brevo: Automation › Auslöser „Ereignis“ mit demselben Namen. |

## Layout getrennt vom Inhalt

Das Design bleibt in Brevo, WordPress liefert nur den Inhalt. Im Brevo-Layout (eine **Vorlage** oder eine **bestehende Kampagne**) stehen Platzhalter in doppelten eckigen Klammern:

| Platzhalter | Wird ersetzt durch |
|---|---|
| `[[bw_content]]` | Inhalt des Newsletter-Beitrags (**Pflicht**) |
| `[[bw_title]]`, `[[bw_subject]]`, `[[bw_preheader]]` | Titel, Betreff, Vorschautext |
| `[[bw_date]]` | Datum der „Versandzeit“ (sonst heute) im Datumsformat der Website |
| `[[bw_var:name]]` | Wert aus dem Feld „Weitere Platzhalter“ (Zeilen `name=wert`); `…_url` wird als Adresse bereinigt, `…_html` darf HTML enthalten, sonst Text |

Im Newsletter-Beitrag (Tab *Inhalt*) wählst du das **Layout**; ein Standard-Layout gilt für alle (*Newsletter › Brevo*). Beim Übertragen holt das Plugin das Layout aus Brevo, ersetzt die Platzhalter und legt daraus eine **neue** Kampagne an. Fehlt `[[bw_content]]` oder hat ein Platzhalter keinen Wert, wird nichts übertragen. Mit *Vorschau* (Button bzw. `POST brevo/campaigns/<id>/preview`) siehst du das fertige Mail-HTML, ohne etwas zu senden. Brevos eigene `{{ … }}`-Platzhalter und der Abmeldelink des Layouts bleiben unberührt. Ohne gewähltes Layout gilt das einfache eingebaute Layout.

Hinweis: Ob Brevo das HTML einer Vorlage bzw. Kampagne über die API ausliefert, hängt vom Editor-Typ ab (HTML-/Rich-Text-Vorlagen sind unkritisch). Eine **bestehende Kampagne als Layout** ist der verlässlichere Weg; beides mit dem Testkonto prüfen.

## Sicherheit (wichtig, weil es echte Empfänger betrifft)

- **Live-Aktionen sind standardmäßig gesperrt** (nur im Backend freizugeben: *Newsletter › Brevo*, nicht über die API). Ohne Freigabe: Entwürfe und Testmails *an die Testempfänger* funktionieren, *send*/*schedule*/Ereignisse sind Probelauf und erscheinen nur in `brevo/outbox`. Hart sperren: `define( 'BW_BREVO_LIVE_DISABLED', true );`
- Senden nur, wenn **genau dieser Inhalt vorher als Testmail** verschickt wurde.
- Der Entwurf wird **nie** mit Versandzeit angelegt (sonst würde Brevo ihn einplanen); die Zeit geht erst bei *schedule*.
- Versendete/geplante Kampagnen werden nicht mehr überschrieben (409).
- Mail-HTML ohne Abmeldelink wird nicht übertragen.
- Automations-Ereignisse nur mit gespeicherter **Einwilligung** (Meta `bwb_consent`, Filter `bwb_has_consent`); abschaltbar, wenn sie anderweitig belegt ist.
- API-Schlüssel am besten per `define( 'BW_BREVO_API_KEY', '…' );` in der wp-config.php. Webhook-Adresse enthält einen geheimen Token.
- Protokoll und Ereignisdaten ohne E-Mail-Klartext; Ereignisse werden nach 180 Tagen gelöscht.

## Einrichtung

1. Ordner `bw-brevo-bridge/` nach `wp-content/plugins/` (oder ZIP: `cd companion && zip -r bw-brevo-bridge.zip bw-brevo-bridge -x '*/tests/*'`), aktivieren.
2. *Newsletter › Brevo*: API-Schlüssel (oder wp-config), Absender (in Brevo verifiziert, SPF/DKIM/DMARC!), Standard-Listen-IDs, Testempfänger, Fußzeile.
3. *Webhook bei Brevo anlegen* (Button oder `wp_bridge.py post bw-bridge/v1/brevo/webhook/register`).
4. Automationen: Auslöser aktivieren, Ereignisnamen in Brevo-Workflows verwenden.

## Mit Claude

```bash
wp_bridge.py get  bw-bridge/v1/brevo/status                  # API erreichbar? Live gesperrt? Webhook-Adresse
wp_bridge.py get  bw-bridge/v1/brevo/lists                   # Listen-IDs
wp_bridge.py get  bw-bridge/v1/brevo/bases                   # Layouts: Vorlagen (t12) und Kampagnen (c55)
wp_bridge.py post bw-bridge/v1/brevo/campaigns/42/preview    # fertiges HTML ansehen, ohne Übertragung
wp_bridge.py post wp/v2/bw_newsletter --json '{"title":"Herbst","status":"draft","content":"<h2>…</h2>","meta":{"bwb_subject":"…","bwb_preheader":"…","bwb_lists":"3"}}'
wp_bridge.py post bw-bridge/v1/brevo/campaigns/42/push       # Entwurf in Brevo
wp_bridge.py post bw-bridge/v1/brevo/campaigns/42/test       # Testmail an die Testempfänger
wp_bridge.py post bw-bridge/v1/brevo/campaigns/42/send       # Probelauf, solange nicht freigegeben
wp_bridge.py post bw-bridge/v1/brevo/campaigns/42/schedule --json '{"at":"2026-11-03 09:00"}'
wp_bridge.py get  bw-bridge/v1/brevo/campaigns               # Status + Statistik je Newsletter
wp_bridge.py get  bw-bridge/v1/brevo/stats --query days=30   # Gesamt, Raten, Warnungen, Webhook-Ereignisse je Domain
wp_bridge.py post bw-bridge/v1/brevo/stats/sync
wp_bridge.py post bw-bridge/v1/brevo/events --json '{"email":"…","event":"wp_user_register","consent_confirmed":true}'
wp_bridge.py get  bw-bridge/v1/brevo/rules                   # Auslöser; brevo/outbox = Protokoll
```

## Grenzen

- Öffnungsraten sind durch Apple Mail Privacy Protection zu hoch; Klicks und Zustellrate sind verlässlicher.
- Ohne gewähltes Brevo-Layout gilt ein schlichtes Standard-HTML (Filter `bwb_email_html` zum Anpassen). Elementor-Layouts sind für Mails nicht geeignet.
- Platzhalter für den Abmeldelink: `{{ unsubscribe }}` – gegen die aktuelle Brevo-Doku prüfen, falls das Konto andere Tags verlangt.
- Workflows selbst (Schritte, Bedingungen, Texte) lassen sich per API nicht anlegen oder ändern.
