---
name: brevo-bridge
description: Newsletter-Kampagnen, Zustellbarkeits-Auswertung und Automations-Auslöser für Brevo über das WordPress-Plugin BW Brevo Bridge steuern (Client tools/wp_bridge.py). Verwenden, wenn es um Newsletter anlegen/testen/versenden/planen, Brevo-Statistiken, Bounces/Beschwerden/Zustellbarkeit oder Brevo-Automationen aus WordPress geht.
---

# Brevo Bridge bedienen

Das Plugin `bw-brevo-bridge` läuft auf der WordPress-Website und spricht mit Brevo (API v3). Claude erreicht es über den Client `tools/wp_bridge.py` (Namespace `bw-bridge/v1/brevo/…`; Anmeldung per Anwendungspasswort, optional Verbindungsschlüssel). Websiteauswahl: `wp_bridge.py --site <name> …`. Bei mehreren eingetragenen Websites nie raten, immer nachfragen.

## Regeln (nicht verhandelbar)

1. **Nie versenden, planen oder Ereignisse auslösen, ohne dass die Person es in dieser Session ausdrücklich verlangt hat** – und erst nach einem Testmail-Durchlauf. `send`/`schedule` betreffen echte Empfänger und sind nicht rückgängig zu machen.
2. Live-Aktionen sind in WordPress gesperrt, solange die Person sie nicht unter *Newsletter › Brevo* freigibt. Das lässt sich per API nicht ändern. Antwortet `send` mit `dry_run: true`, ist das gewollt: der Person sagen, dass die Freigabe fehlt, nicht umgehen.
3. Zum Testen nur die Testempfänger aus den Einstellungen. Keine fremden Adressen eintragen.
4. Einwilligung (DSGVO): Ereignisse nur mit `consent_confirmed: true`, wenn die Person bestätigt hat, dass die Einwilligung vorliegt. Nie selbst annehmen.
5. API-Schlüssel, Verbindungsschlüssel und Webhook-Token nie in Chat, Commit oder Log ausgeben.
6. Versendete oder geplante Kampagnen lassen sich nicht ändern (409). Für eine neue Ausgabe einen neuen Newsletter-Beitrag anlegen.

## Ablauf: Newsletter

```bash
wp_bridge.py get  bw-bridge/v1/brevo/status            # API ok? live? Absender, Standard-Listen
wp_bridge.py get  bw-bridge/v1/brevo/lists             # Listen-IDs und Größen
wp_bridge.py post wp/v2/bw_newsletter --json '{"title":"…","status":"draft","content":"<h2>…</h2><p>…</p>","meta":{"bwb_subject":"…","bwb_preheader":"…","bwb_lists":"3"}}'
wp_bridge.py post bw-bridge/v1/brevo/campaigns/<id>/push     # Entwurf nach Brevo (versendet nichts)
wp_bridge.py post bw-bridge/v1/brevo/campaigns/<id>/test     # Testmail an die Testempfänger
# Person prüft die Testmail → erst dann, auf ausdrücklichen Wunsch:
wp_bridge.py post bw-bridge/v1/brevo/campaigns/<id>/send
wp_bridge.py post bw-bridge/v1/brevo/campaigns/<id>/schedule --json '{"at":"2026-11-03 09:00"}'   # Website-Zeit, ≥ 5 Min. Zukunft
```

Meta-Felder (alle optional, Rückfall auf die Einstellungen): `bwb_subject`, `bwb_preheader` (≤ 150 Zeichen), `bwb_sender_name`, `bwb_sender_email`, `bwb_reply_to`, `bwb_lists` (IDs kommagetrennt), `bwb_scheduled_at`, `bwb_tag`.
Layout: Das Design liegt in Brevo (Vorlage `t<ID>` oder bestehende Kampagne `c<ID>`, siehe `GET brevo/bases`), im Meta-Feld `bwb_base` (Standard in den Einstellungen). Das Layout enthält `[[bw_content]]` (Pflicht), optional `[[bw_title]]`, `[[bw_subject]]`, `[[bw_preheader]]`, `[[bw_date]]` und `[[bw_var:name]]`; Werte für `[[bw_var:…]]` stehen als Zeilen `name=wert` im Meta-Feld `bwb_vars`. Vor `push`/`test` immer `POST brevo/campaigns/<id>/preview` ausführen und prüfen, ob alle Platzhalter gefüllt sind. Fehler `bwb_template` nennt fehlende Platzhalter/Werte; `bwb_base` = Layout nicht abrufbar oder ohne HTML.
Inhalt: einfaches HTML im Beitragsinhalt (keine Elementor-Layouts). Der Abmeldelink `{{ unsubscribe }}` und die Fußzeile kommen automatisch dazu. Nach jeder Textänderung erneut `test`, sonst blockiert `send` (409 `bwb_untested`).

## Auswertung / Zustellbarkeit

```bash
wp_bridge.py post bw-bridge/v1/brevo/stats/sync               # Statistik aller Kampagnen neu holen
wp_bridge.py get  bw-bridge/v1/brevo/campaigns                # Status + Raten je Newsletter
wp_bridge.py get  bw-bridge/v1/brevo/stats --query days=30    # Gesamt, Warnungen, Webhook-Ereignisse, Probleme je Domain, häufige Gründe
```

Einordnen, nicht nur Zahlen nennen: Beschwerden ≥ 0,1 % (Grenze Gmail/Yahoo 0,3 %), Hard Bounces ≥ 2 %, Zustellrate < 95 % sind Handlungsbedarf (Liste bereinigen, SPF/DKIM/DMARC der Absenderdomain prüfen, Einwilligungen prüfen). Öffnungsraten sind durch Apple Mail Privacy Protection verzerrt – Klicks und Zustellrate betonen. Unter 100 Empfängern sind Raten kaum aussagekräftig. Sind keine Webhook-Ereignisse da: `wp_bridge.py post bw-bridge/v1/brevo/webhook/register`.

## Automationen

Workflows selbst legt die Brevo-API nicht an – die baut die Person in Brevo (Auslöser „Ereignis“). Hier steuern wir nur, wann sie starten:

```bash
wp_bridge.py get  bw-bridge/v1/brevo/rules      # WordPress-Auslöser → Brevo-Ereignisname (Änderung nur im Backend)
wp_bridge.py get  bw-bridge/v1/brevo/outbox     # Protokoll der letzten Aktionen/Probeläufe
wp_bridge.py post bw-bridge/v1/brevo/events --json '{"email":"…","event":"wp_user_register","properties":{"plan":"pro"},"consent_confirmed":true}'
```

Neue WordPress-Auslöser: Filter `bwb_triggers` (+ `bwb_trigger_context` liefert E-Mail, Eigenschaften, Einwilligung) in einem Mini-Plugin oder Theme-Code ergänzen.

## Fehlerbilder

- `bwb_no_key` → API-Schlüssel fehlt (wp-config `BW_BREVO_API_KEY` oder Einstellungen).
- `bwb_api` mit Brevo-Meldung → meist Absender nicht verifiziert, Liste existiert nicht, Schlüssel ohne Marketing-Rechte.
- `bwb_invalid` → fehlende Angaben/Abmeldelink; Text nennt, was fehlt.
- `bwb_locked` (409) → Kampagne schon versendet/geplant.
- `403 bw_bridge_key` → Verbindungsschlüssel fehlt oder falsche Website.

## Beim ersten Einsatz prüfen (noch nie gegen ein echtes Konto gelaufen)

Mit einer kleinen Testliste (2–3 eigene Adressen) durchspielen: push → test → send. Dabei gegen https://developers.brevo.com prüfen: Platzhalter für den Abmeldelink, Feld `previewText`, Namen der Webhook-Ereignisse (landen unbekannte als `other:<name>` in der Auswertung), Form von `POST /events` und `POST /contacts`. Abweichungen im Plugin korrigieren und `php tests/test-brevo.php` anpassen.
