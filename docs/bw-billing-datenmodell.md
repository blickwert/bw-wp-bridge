# bw Billing – Datenmodell (Bestandsaufnahme)

Stand: 2026-10-20, ausgelesen von https://bework.blickwert.at/ (ACF 6.7, Plugin `bw-billing` 1.0.0) über die Bridge-Route `acf/schema`, gegengeprüft mit dem ACF-Export.

Format je Eintrag: **Schwerpunkt (cpt)** → **Querverbindung (tax)** → **CF (acf)**. Pfeil `→` an einem Feld = Verweis auf einen anderen CPT oder eine Taxonomie.

## Überblick

| CPT (Slug) | Bezeichnung | Taxonomien (registriert) | ACF-Gruppen |
|---|---|---|---|
| `bw-in-invoice` | Eingangsrechnungen | – | Billing Eingangsrechnung |
| `timetable` | Timetable | – | Timetable |
| `bw-billing-offers` | Angebote | `bw-billing-offerstype` | Billing_offert |
| `bw-billing-invoice` | Rechnungen | – | Billing_invoice, Billing_invoice aside |
| `bw-billing-orders` | Aufträge | – | Billing Aufträge |
| `bw-billing-clients` | Kunden | – | billing_client |
| `bw-billing-documents` | Dokumente | – | Billing Documents aside, Billing Documents normal |
| `bw-billing-templates` | Templates | – | – |
| `bw-billing-texts` | Textvorlagen | – | billing_texts |
| `bw-billing-articles` | Artikel | – | – |
| `bw-billing-tickets` | Support Tickets | – | Billing Documents aside, Billing Support Ticket |
| `bw-billing-changes` | Projekt-Issues | – | Billing Changes |
| `bw-todo` | Todos | – | – |
| `bw-billing-email` | **nicht registriert** | – | billing_email |

## Taxonomien

- `bw-billing-offerstype` – Auftragstypen, flach, registriert für: `bw-billing-offers`

## Schwerpunkte

### Eingangsrechnungen (cpt) `bw-in-invoice`

**Querverbindung (tax):** keine registriert

**CF (acf)** – Gruppe „Billing Eingangsrechnung“ (`group_6789fb2aeeef9`)

- **Datum** `bw-in_invoice-date` (date_picker) – Rückgabe: y-m-d
- **Buchungstext** `bw-in_invoice-title` (text)
- **Kostenarten** `bw-in_invoice-type` (select) – Rückgabe: array; Werte: office=Bürobedarf, education=Fortbildung und Fachliteratur, subcontractors=Fremdleistungen, rent=Miete und Betriebskosten, staff=Personalkosten, repairs=Reparaturen und Wartung, travel=Reise- und Fahrtkosten, miscellaneous=Sonstige Betriebsausgaben, tax_advisory=Steuer- und Rechtsberatung, phone_internet=Telefon- und Internetkosten, insurance=Versicherungen, advertising=Werbe- und Marketingkosten, vehicle=Fahrzeugkosten, low-value-asset=Geringwertiges Wirtschaftsgut, afa=Anschaffungen (AfA) und Investitionen, banking=Bank- und Finanzkosten
- **Priv. Anteil** `bw-in_invoice-priv_share` (select) – Rückgabe: value; Werte: 30=30%, 50=50%; Standard: 30; sichtbar wenn: `bw-in_invoice-type` == phone_internet ODER `bw-in_invoice-type` == vehicle
- **Ausgaben (Brutto)** `bw-in_invoice-value_gross` (text)
- **Ust.** `bw-in_invoice-tax` (select) – Rückgabe: value; Werte: 20=20%, 10=10%, 0=0%; Standard: 20; sichtbar wenn: `bw-in_invoice-type` != staff UND `bw-in_invoice-type` != insurance UND `bw-in_invoice-type` != banking
- **Rechnung** `bw-in_invoice-file` (file) – Rückgabe: id

### Timetable (cpt) `timetable`

**Querverbindung (tax):** keine registriert

**CF (acf)** – Gruppe „Timetable“ (`group_68107c73dc474`)

- **Date** `date` (date_picker) – Rückgabe: d/m/Y
- **Timetable** `timetable` (repeater)
  - **Job** `job` (group)
    - **Client** `client` (post_object) – → CPT `bw-billing-clients`; Rückgabe: id
    - **time-start** `time-start` (time_picker) – Rückgabe: H:i
    - **time-end** `time-end` (time_picker) – Rückgabe: H:i
  - **Work** `work` (group)
    - **Todo** `todo` (textarea)
- **Todo** `todos` (wysiwyg)

### Angebote (cpt) `bw-billing-offers`

**Querverbindung (tax):** `bw-billing-offerstype`

**CF (acf)** – Gruppe „Billing_offert“ (`group_66b1e19ab2404`)

- **Kunde** `bw-billing-invoice-client_id` (post_object) – → CPT `bw-billing-clients`; Rückgabe: id; Hinweis: Neuen Kunden anlegen
- **Angebotsnr.** `bw-billing-offer-number` (text)
- **Beschreibung** `bw-billing-offer-intro` (textarea)
- **Artikel Pos.** `bw-billing-offer_article` (repeater)
  - **Artikel** `artikel` (textarea)
  - **Betrag** `price` (textarea) – Standard: Menge
€ / Einheit
(Rabatt)
(Ust)
- **Anmerkung** `bw-billing-offer-note` (textarea)
- **Gesamt-Rabatt** `bw-billing-offer-discount_rate` (text) – Hinweis: Folgende Formate erlaubt: 50 | 50.- | 50,00 | 50%
- **Vorlagen** `bw-billing-offer-templates` (select) – Rückgabe: value
- **Netto** `bw-billing-offer-total_net` (text)
- **Brutto** `bw-billing-offer-total_gross` (text)
- **Ust.** `bw-billing-offer-tax_rate` (text)

### Rechnungen (cpt) `bw-billing-invoice`

**Querverbindung (tax):** keine registriert

**CF (acf)** – Gruppe „Billing_invoice“ (`group_66b1dc151d572`)

- **Einleitungstext** `bw-billing-invoice-intro` (textarea) – Standard: Wir danken für Ihren Auftrag und stellen folgenden Dienstleistung in Rechnung.
- **Artikel Pos. new** `bw-billing-invoice_article` (repeater)
  - **Artikel** `article` (textarea)
  - **Betrag** `price` (textarea) – Standard: Menge
€ / Einheit
(Rabatt)
(Ust)
  - **Datum** `date` (date_picker) – Rückgabe: d/m/Y
- **Anmerkung** `bw-billing-invoice-note` (textarea) – Standard: Wenn noch nicht geschehen, überweisen Sie den Rechnungsbetrag bis zum [xxx] auf folgendes Konto:
Name:  [xxx]
IBAN: [xxx]
BIC: [xxx]
Betrag: [xxx]
Verwendungszweck/Kundendaten: [xxx]
- **Artikel Pos.** `bw_billing_invoice-article` (repeater)
  - **Artikel** `artikel` (textarea)
  - **Preis** `preis` (textarea)

**CF (acf)** – Gruppe „Billing_invoice aside“ (`group_67a9d37187260`)

- **Datum** `bw-billing-invoice-date` (date_picker) – Rückgabe: d/m/Y
- **Kunde** `bw-billing-relation-client_id` (post_object) – → CPT `bw-billing-clients`; Rückgabe: id; Hinweis: Neuen Kunden anlegen
- **Angebot** `bw-billing-relation-offer_id` (post_object) – → alle CPT (nicht eingeschränkt); Rückgabe: object
- **Rechnungsnr.** `bw-billing-invoice-number` (text)
- **Zahlungsart** `bw-billing-invoice-payment` (select) – Rückgabe: value; Werte: banking=Überweisung, bar=Barzahlung
- **Fälligkeit Tage** `bw-billing-invoice-expiry` (number) – Standard: 10
- **Netto** `bw-billing-invoice-total_net` (text)
- **Brutto** `bw-billing-invoice-total_gross` (text)
- **Ust.** `bw-billing-invoice-tax_rate` (text)
- **Gesamt-Rabatt** `bw-billing-invoice-discount_rate` (text) – Hinweis: Folgende Formate erlaubt: 50 | 50.- | 50,00 | 50%

### Aufträge (cpt) `bw-billing-orders`

**Querverbindung (tax):** keine registriert
- über Feld `bwork-orders-type` → `bw-billing-offerstype` (multi_select)

**CF (acf)** – Gruppe „Billing Aufträge“ (`group_677fbbc8b1c93`)

- *— Tab: Auftragsdaten —*
- **Kunde** `bwork-relation-client_id` (post_object) – → CPT `bw-billing-clients`; Rückgabe: id; Hinweis: Neuen Kunden anlegen
- **Auftragstyp** `bwork-orders-type` (taxonomy) – → TAX `bw-billing-offerstype`; Auswahl: multi_select; Rückgabe: id
- **Projekt Zeitraum** `bwork-orders-timeline` (text) – Hinweis: Bei Wartungsvertrag oder fortlaufender Wartung nur Projektbeginn angeben
- **Projekt Zeitraum von** `bwork-orders-timeline_from` (date_picker) – Rückgabe: Y-m-d; Hinweis: Bei Wartungsvertrag oder fortlaufender Wartung nur Projektbeginn angeben
- **Projekt Zeitraum bis** `bwork-orders-timeline_to` (date_picker) – Rückgabe: Y-m-d; Hinweis: Bei Wartungsvertrag oder fortlaufender Wartung nur Projektbeginn angeben
- **Status** `bwork-orders-status` (select) – Pflicht; Rückgabe: value; Werte: onboarding=Onboarding, planed=Geplant, start=Start, inprogress=In Progress, done=Erledigt
- *— Tab: Digital-Web-Workflow —*
- **Aktivierte Projektphasen** `bwork-orders-digital-phases` (select) – Rückgabe: value; mehrfach; Werte: onboarding=Onboarding, structure-design=Struktur und Design, development=Webentwicklung & Fertigstellung; Standard: ['onboarding', 'structure-design', 'development']
- **Design – Mockups  | Mobile Desktop** `bwork-orders-mod_mockup` (repeater)
  - **Description (Markdown)** `desc` (textarea)
  - **XD-Mockup- url** `url` (url)
  - **Typ** `type` (select) – Rückgabe: value; Werte: mobile=Mobil, desktop=Desktop; Standard: desktop
- *— Tab: Umsetzung —*
- **Zielsetzung** `bwork-orders-targets` (textarea)
- **Moods** `bwork-orders-moodboard` (textarea) – Standard: [uri=site title=title desc=desc]
- **Umsetzung** `bwork-orders-job` (textarea)
- **Seitenstuktur** `bwork-orders-site_structure` (textarea)
- **Tätigkeiten** `bwork-orders-tasks` (repeater)
  - **Aufgabe** `todo` (textarea)
  - **Job** `job` (textarea)
  - **Dauer (min)** `duration` (text)
  - **Datum** `date` (date_picker) – Rückgabe: d/m/Y
  - **Status** `status` (select) – Rückgabe: value; Werte: todo=ToDo, planed=Geplant, inprogress=In Progress, done=Erledigt
  - **Datei (falls Verfügbar)** `result-file` (file) – Rückgabe: id
- **Datei** `bwork-orders-files` (gallery) – Rückgabe: id
- *— Tab: Feedback/Support —*
- **bwork-orders-issues** `bwork-orders-issues` (repeater)
  - **Datum** `date` (date_picker) – Rückgabe: d/m/Y
  - **Inhalt** `desc` (wysiwyg)
  - **Datei** `attachment-id` (file) – Rückgabe: id

### Kunden (cpt) `bw-billing-clients`

**Querverbindung (tax):** keine registriert

**CF (acf)** – Gruppe „billing_client“ (`group_66b0c193c4a69`)

- **Import Textblock** `bw-billing-client-import_address` (textarea)
- **Persönliche Anrede** `bw-billing-client-salutation` (button_group) – Rückgabe: value; Werte: Du=Du, Sie=Sie; Standard: Du
- **Geschlecht** `bw-billing-client-gender` (button_group) – Rückgabe: value; Werte: woman=Frau, mr=Herr; Standard: woman : Frau
- **Kundennummer** `bw-billing-client-number` (text)
- **Kurzname** `bw-billing-client-shortname` (text)
- **Unternehmen** `bw-billing-client-company` (text)
- **Titel** `bw-billing-client-first_title` (text)
- **Vorname** `bw-billing-client-first_name` (text)
- **Nachname** `bw-billing-client-last_name` (text)
- **PLZ** `bw-billing-client-zip` (number)
- **Ort** `bw-billing-client-city` (text)
- **Strasse + Hausnummer** `bw-billing-client-address` (text)
- **Email** `bw-billing-client-email` (email)

### Dokumente (cpt) `bw-billing-documents`

**Querverbindung (tax):** keine registriert

**CF (acf)** – Gruppe „Billing Documents aside“ (`group_67a47fe830c50`)

- **Kunde** `bw-billing-relation-client_id` (post_object) – → CPT `bw-billing-clients`; Rückgabe: id; Hinweis: Neuen Kunden anlegen
- **Auftrag** `bw-billing-relation-order_id` (post_object) – → CPT `bw-billing-orders`; Rückgabe: id; Hinweis: Neuen Kunden anlegen

**CF (acf)** – Gruppe „Billing Documents normal“ (`group_67a481091f377`)

- **Seitenstruktur aktuell** `bw-billing-doc-treeold` (repeater) – Hinweis: Neuen Kunden anlegen
  - **Titel** `title` (text)
  - **Beschreibung** `content` (wysiwyg)

### Templates (cpt) `bw-billing-templates`

**Querverbindung (tax):** keine registriert

**CF (acf):** keine Feldgruppe

### Textvorlagen (cpt) `bw-billing-texts`

**Querverbindung (tax):** keine registriert
- über Feld `bw-billing-texts-type` → `bw-billing-doctype` (select)

**CF (acf)** – Gruppe „billing_texts“ (`group_66b35936b6977`)

- **Typ** `bw-billing-texts-type` (taxonomy) – → TAX `bw-billing-doctype`; Auswahl: select; Rückgabe: id
- **Einleitungstext** `bw-billing-texts-intro` (textarea)
- **Anmerkung** `bw-billing-texts-note` (textarea)

### Artikel (cpt) `bw-billing-articles`

**Querverbindung (tax):** keine registriert

**CF (acf):** keine Feldgruppe

### Support Tickets (cpt) `bw-billing-tickets`

**Querverbindung (tax):** keine registriert

**CF (acf)** – Gruppe „Billing Documents aside“ (`group_67a47fe830c50`)

- **Kunde** `bw-billing-relation-client_id` (post_object) – → CPT `bw-billing-clients`; Rückgabe: id; Hinweis: Neuen Kunden anlegen
- **Auftrag** `bw-billing-relation-order_id` (post_object) – → CPT `bw-billing-orders`; Rückgabe: id; Hinweis: Neuen Kunden anlegen

**CF (acf)** – Gruppe „Billing Support Ticket“ (`group_67a9b611e4475`)

- **Ticket** `bwork-support-tickets` (repeater)
  - **Beschreibung** `content` (wysiwyg)
  - **Datum** `date` (date_time_picker) – Rückgabe: d/m/Y g:i a
  - **Datei (falls Verfügbar)** `files` (gallery) – Rückgabe: id

### Projekt-Issues (cpt) `bw-billing-changes`

**Querverbindung (tax):** keine registriert

**CF (acf)** – Gruppe „Billing Changes“ (`group_677e9ae952a90`)

- **Kunde** `bw-billing-relation-client_id` (post_object) – → CPT `bw-billing-clients`; Rückgabe: id; Hinweis: Neuen Kunden anlegen
- **Auftrag** `bw-billing-relation-order_id` (post_object) – → CPT `bw-billing-orders`; Rückgabe: id; Hinweis: Neuen Auftrag anlegen
- **Beschreibung** `bw-billing-changes-desc` (textarea)
- **Status** `bw-billing-changes-status` (select) – Rückgabe: value; Werte: todo=ToDo, progress=In Arbeit, done=Erledigt, planed=Geplant
- **Dauer** `bw-billing-changes-duration` (number)

### Todos (cpt) `bw-todo`

**Querverbindung (tax):** keine registriert

**CF (acf):** keine Feldgruppe

### nicht registriert (cpt) `bw-billing-email`

**Querverbindung (tax):** keine registriert

**CF (acf)** – Gruppe „billing_email“ (`group_66b35f416628a`)

- **Typ** `bw-billing-email-type` (select) – Rückgabe: value; Werte: bw-billing-invoice=Rechnung, bw-billing-offer=Angebot, bw-billing-reminder=Mahnung, bw-billing-delivery=Lieferschein
- **Absender** `bw-billing-email-to` (email)
- **BBC-Empfänger** `bw-billing-email-bbc` (email)
- **Emailtext** `bw-billing-email-text` (textarea)
- **Anmerkung** `bw-billing-texts-note` (textarea)

## Gruppen ohne Post Type (Blöcke, Optionsseiten)

### Billing Artikel (`group_66bb63cb41af8`) – block == acf/billing-artikel

- **Inhalt** `bw-billing-article-content` (group)
  - **Titel** `article-_title` (text)
  - **Desc** `desc` (textarea)
- **Angaben** `bw-billing-article-meta` (group)
  - **Netto** `netto` (number)
  - **Ust** `ust` (select) – Rückgabe: value; Werte: 20=20%, 10=10%, 0=keine; Standard: 20
  - **Rabatt** `discount` (text)

### Billing Settings Allgemein (`group_66b2189ce2f85`) – options_page == plugin-einstellungen

- **Who the fuck is allice** `how_the_fuck_is_allice` (textarea)

### test (`group_68e79a1bad6d3`) – block == acf/test

- **foo** `foo` (text)

## Querverbindungen (Beziehungsfelder)

| Von (CPT) | Feld | Typ | Nach |
|---|---|---|---|
| `bw-billing-orders` | `bwork-relation-client_id` | post_object | CPT bw-billing-clients |
| `bw-billing-orders` | `bwork-orders-type` | taxonomy | TAX bw-billing-offerstype |
| `bw-billing-changes` | `bw-billing-relation-client_id` | post_object | CPT bw-billing-clients |
| `bw-billing-changes` | `bw-billing-relation-order_id` | post_object | CPT bw-billing-orders |
| `bw-billing-documents, bw-billing-tickets` | `bw-billing-relation-client_id` | post_object | CPT bw-billing-clients |
| `bw-billing-documents, bw-billing-tickets` | `bw-billing-relation-order_id` | post_object | CPT bw-billing-orders |
| `bw-billing-invoice` | `bw-billing-relation-client_id` | post_object | CPT bw-billing-clients |
| `bw-billing-invoice` | `bw-billing-relation-offer_id` | post_object | alle CPT (nicht eingeschränkt) |
| `bw-billing-offers` | `bw-billing-invoice-client_id` | post_object | CPT bw-billing-clients |
| `timetable` | `client` | post_object | CPT bw-billing-clients |
| `bw-billing-texts` | `bw-billing-texts-type` | taxonomy | TAX bw-billing-doctype |

## Auffälligkeiten für das Neu-Coden

1. **Taxonomie `bw-billing-doctype` fehlt:** `billing_texts` → `bw-billing-texts-type` verweist darauf, registriert ist sie nicht.
2. **CPT `bw-billing-email` fehlt:** Die Gruppe `billing_email` hängt an einem nicht registrierten Post Type, die Felder sind derzeit nirgends sichtbar.
3. **`bw-billing-offerstype` (Auftragstypen)** ist nur für Angebote (`bw-billing-offers`) registriert, wird aber im Auftrag (`bwork-orders-type`) als Taxonomiefeld benutzt. Eine Taxonomie für zwei CPTs, oder zwei getrennte?
4. **Verweis Rechnung → Angebot** (`bw-billing-relation-offer_id`) ist nicht auf einen CPT eingeschränkt und liefert ein Objekt statt einer ID wie alle anderen Verweise.
5. **Feldnamen uneinheitlich:** Präfixe `bwork-orders-*`, `bw-billing-*`, `bw-in_invoice-*`, `bwork-support-*`; im Timetable und in Wiederholungsfeldern ohne Präfix. Kundenverweis heißt mal `bwork-relation-client_id`, mal `bw-billing-relation-client_id`, im Angebot `bw-billing-invoice-client_id`.
6. **Doppelte Daten:** Auftrag hat `bwork-orders-timeline` (Text) und `_from`/`_to` (Datum) für denselben Zeitraum.
7. **Datumsformate gemischt** (`d/m/Y`, `y-m-d`, `Y-m-d`, `d.m.y`); Geldbeträge teils als Text (Rechnung, Eingangsrechnung), teils als Zahl (Artikel), USt als Auswahl oder als Text.
8. **Beziehungen nur einseitig** (`bidirectional` aus): Kunde kennt seine Aufträge, Rechnungen usw. nur über Abfragen.
9. **Zwei Gruppen für mehrere CPTs:** „Billing Documents aside“ gilt für Dokumente und Support-Tickets.
10. **Ohne Feldgruppe am CPT:** `bw-billing-templates`, `bw-billing-articles` (die Artikelfelder hängen am Block `acf/billing-artikel`), `bw-todo`.
11. **Reste:** Gruppe `test` (Block `acf/test`) und das Optionsfeld „Who the fuck is allice“ in „Billing Settings Allgemein“; kopierte Hinweistexte („Neuen Kunden anlegen“ bei Auftrag und Seitenstruktur).
