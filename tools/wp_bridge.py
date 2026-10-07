#!/usr/bin/env python3
"""
wp_bridge.py – Kommandozeilen-Client für die WordPress-REST-API + BW WP Bridge.

Umgebungsvariablen:
  WP_URL            z. B. https://dev.appa.at
  WP_USER           WordPress-Benutzername
  WP_APP_PASSWORD   Anwendungspasswort (Profil › Anwendungspasswörter)
  WP_BASIC_AUTH     optional, "user:passwort" falls der Server per .htpasswd geschützt ist
  WP_TIMEOUT        optional, Sekunden pro Anfrage (Standard 120; auch --timeout)

Beispiele:
  wp_bridge.py status
  wp_bridge.py auth-check                # Diagnose, falls 401 trotz Anwendungspasswort
  wp_bridge.py get wp/v2/pages --query per_page=100 --query _fields=id,slug,title
  wp_bridge.py post wp/v2/pages --json '{"title":"Kontakt","slug":"kontakt","status":"publish"}'
  wp_bridge.py elementor-get 42 -o seite.json
  wp_bridge.py elementor-put 42 templates/page-kontakt.json
  wp_bridge.py page-from-template templates/page-home.json --title Home --slug home
  wp_bridge.py kit-put kit-site-settings.json
  wp_bridge.py template-import templates/header-hauptnavigation.json
  wp_bridge.py cpt-set event --json '{"label":"Veranstaltungen","menu_icon":"dashicons-calendar"}'
  wp_bridge.py tax-set event_category --json '{"object_types":["event"],"args":{"label":"Kategorien"}}'

Texte, Suche, Meta, Stapel (ab Bridge 1.2):
  wp_bridge.py search "Impressum" --lang de             # Seiten, Produkte, Meta und Elementor-Texte durchsuchen
  wp_bridge.py elementor-texts 12 -q Kontakt          # alle Texte einer Seite mit Widget-ID und Pfad
  wp_bridge.py elementor-set 12 8db1a15 settings.title "Neuer Titel" --dry-run
  wp_bridge.py elementor-set 12 --file aenderungen.json   # [{"widget_id","path","value","expect"?}, …]
  wp_bridge.py elementor-put 42 seite.json --dry-run   # Textvergleich alt/neu, ohne zu speichern
  wp_bridge.py elementor-backups 12                  # automatische Sicherungen vor jedem Speichern
  wp_bridge.py elementor-restore 12 [--time 1700000000]
  wp_bridge.py translations 12                         # WPML: { de: 34, en: 12 }
  wp_bridge.py translation-link 34 --of 12 [--lang de]  # WPML: 34 als Übersetzung von 12 verbinden
  wp_bridge.py render 12 -q Kontakt                 # sichtbarer Text im Frontend
  wp_bridge.py meta-get 56 --prefix _shop_
  wp_bridge.py meta-set 56 --set _shop_hinweis=Text --dry-run
  wp_bridge.py batch operationen.json                  # {"operations":[{"method","path","query"?,"body"?}, …]}

Theme-Dateien (nur wenn im Backend unter Einstellungen › BW WP Bridge freigeschaltet):
  wp_bridge.py theme-ls woocommerce/emails -r          # Ordner auflisten (-r rekursiv)
  wp_bridge.py theme-get woocommerce/emails/customer-new-account.php -o alt.php
  wp_bridge.py theme-put woocommerce/emails/customer-new-account.php neu.php   # legt Sicherung an
  wp_bridge.py theme-backups woocommerce/emails/customer-new-account.php
  wp_bridge.py theme-restore woocommerce/emails/customer-new-account.php       # neueste Sicherung
  wp_bridge.py theme-rm woocommerce/emails/alt.php
  (--parent bei allen: Parent-Theme statt aktivem Theme, falls freigegeben)
"""

import argparse
import base64
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request


class Client:
    def __init__(self):
        try:
            self.base = os.environ["WP_URL"].rstrip("/")
            user, pw = os.environ["WP_USER"], os.environ["WP_APP_PASSWORD"]
        except KeyError as e:
            sys.exit("Umgebungsvariable fehlt: %s" % e.args[0])
        self.auth = "Basic " + base64.b64encode(("%s:%s" % (user, pw)).encode()).decode()
        # Bei .htpasswd-Schutz belegt die Server-Anmeldung den Authorization-Header;
        # das Anwendungspasswort geht dann als X-WP-Authorization (wertet das Bridge-Plugin aus).
        self.server_auth = os.environ.get("WP_BASIC_AUTH")
        self.timeout = int(os.environ.get("WP_TIMEOUT") or 120)

    def url(self, path, query=None):
        path = path.lstrip("/")
        u = "%s/?rest_route=/%s" % (self.base, path)
        if query:
            u += "&" + urllib.parse.urlencode(query, doseq=True)
        return u

    def request(self, method, path, body=None, query=None):
        data = None if body is None else json.dumps(body).encode()
        req = urllib.request.Request(self.url(path, query), data=data, method=method)
        req.add_header("Accept", "application/json")
        if data is not None:
            req.add_header("Content-Type", "application/json")
        if self.server_auth:
            req.add_header("Authorization", "Basic " + base64.b64encode(self.server_auth.encode()).decode())
            req.add_header("X-WP-Authorization", self.auth)
        else:
            req.add_header("Authorization", self.auth)
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as r:
                raw = r.read().decode()
        except urllib.error.HTTPError as e:
            raw = e.read().decode(errors="replace")
            try:
                err = json.loads(raw)
                msg = "%s: %s" % (err.get("code"), err.get("message"))
            except ValueError:
                msg = raw[:500]
            sys.exit("HTTP %s bei %s %s\n%s" % (e.code, method, path, msg))
        except urllib.error.URLError as e:
            sys.exit("Keine Verbindung zu %s: %s" % (self.base, e.reason))
        except TimeoutError:
            sys.exit("Zeitüberschreitung nach %d s bei %s %s (länger warten mit --timeout oder WP_TIMEOUT)" % (self.timeout, method, path))
        return json.loads(raw) if raw else None


def load_json(arg_json, arg_file):
    if arg_json is not None:
        return json.loads(arg_json)
    if arg_file == "-":
        return json.load(sys.stdin)
    with open(arg_file, encoding="utf-8") as fh:
        return json.load(fh)


def elementor_payload(doc):
    """Akzeptiert Elementor-Vorlagen-JSON ({content, page_settings}) oder {elements, settings}."""
    if "content" in doc:
        ps = doc.get("page_settings") or {}
        return {"elements": doc["content"], "settings": ps if isinstance(ps, dict) else {}}
    return doc


def out(data, path=None):
    text = json.dumps(data, ensure_ascii=False, indent=1)
    if path:
        with open(path, "w", encoding="utf-8") as fh:
            fh.write(text + "\n")
        print("gespeichert:", path)
    else:
        print(text)


def theme_command(c, a):
    q = [("theme", "parent" if a.parent else "child"), ("path", a.path)]
    base = "bw-bridge/v1/theme/files"
    if a.cmd == "theme-ls":
        if a.recursive:
            q.append(("recursive", "1"))
        r = c.request("GET", base, None, q)
        if r.get("type") != "dir":
            sys.exit("%s ist eine Datei, nicht ein Ordner (theme-get verwenden)." % a.path)
        for e in r["entries"]:
            print("%-5s %8s  %s" % (e["type"], e["size"] if e["size"] is not None else "-", e["path"]))
        if r.get("truncated"):
            print("… Liste gekürzt")
    elif a.cmd == "theme-get":
        r = c.request("GET", base, None, q)
        if r.get("type") != "file":
            sys.exit("%s ist ein Ordner (theme-ls verwenden)." % a.path)
        data = base64.b64decode(r["content"]) if r["encoding"] == "base64" else r["content"].encode()
        if a.out:
            with open(a.out, "wb") as fh:
                fh.write(data)
            print("gespeichert: %s (%d Bytes, sha1 %s)" % (a.out, len(data), r["sha1"]))
        else:
            sys.stdout.write(r["content"] if r["encoding"] == "utf8" else "[Binärdatei, %d Bytes – mit -o speichern]\n" % r["size"])
    elif a.cmd == "theme-put":
        with open(a.file, "rb") as fh:
            raw = fh.read()
        try:
            body = {"content": raw.decode("utf-8"), "encoding": "utf8"}
        except UnicodeDecodeError:
            body = {"content": base64.b64encode(raw).decode(), "encoding": "base64"}
        if a.sha1:
            body["expected_sha1"] = a.sha1
        if a.no_lint:
            body["skip_lint"] = True
        r = c.request("POST", base, body, q)
        print("%s: %s (%d Bytes)%s" % ("angelegt" if r["created"] else "überschrieben", r["path"], r["size"], ", Sicherung " + r["backup"] if r["backup"] else ""))
    elif a.cmd == "theme-rm":
        r = c.request("DELETE", base, None, q)
        print("gelöscht: %s, Sicherung %s" % (r["path"], r["backup"]))
    elif a.cmd == "theme-backups":
        out(c.request("GET", "bw-bridge/v1/theme/backups", None, q))
    else:
        r = c.request("POST", "bw-bridge/v1/theme/backups", {"backup": a.backup} if a.backup else {}, q)
        print("wiederhergestellt: %s aus %s" % (r["path"], r["restored"]))


def texts_command(c, a):
    base = "bw-bridge/v1/elementor/%d" % a.id
    if a.cmd == "elementor-texts":
        r = c.request("GET", base + "/texts", None, [("q", a.q)] if a.q else None)
        if a.out:
            out(r, a.out)
            return
        for t in r["texts"]:
            text = " ".join(t["value"].split())
            print("%-9s %-18s %-34s %s" % (t["widget_id"], t["widget_type"], t["path"], text[:a.width]))
        print("%d Texte" % r["count"])
    elif a.cmd == "elementor-set":
        if a.file:
            changes = load_json(None, a.file)
            changes = changes.get("changes", changes) if isinstance(changes, dict) else changes
        else:
            if not (a.widget_id and a.path and a.value is not None):
                sys.exit("elementor-set: WIDGET_ID PATH WERT oder --file angeben.")
            changes = [{"widget_id": a.widget_id, "path": a.path, "value": a.value}]
            if a.expect is not None:
                changes[0]["expect"] = a.expect
        r = c.request("POST", base + "/texts", {"changes": changes, "dry_run": a.dry_run})
        for x in r["results"]:
            if x["status"] == "ok":
                print("ok     %s %s\n         alt: %s\n         neu: %s" % (x["widget_id"], x["path"], " ".join(x["old"].split())[:160], " ".join(x["new"].split())[:160]))
            else:
                print("FEHLER %s %s: %s" % (x["widget_id"], x["path"], x.get("message")))
        print("gespeichert" if r["saved"] else ("Probelauf, nichts gespeichert" if r["dry_run"] else "nichts gespeichert (Fehler in den Änderungen)"))
        if not r["saved"] and not r["dry_run"]:
            sys.exit(1)
    elif a.cmd == "elementor-backups":
        for b in c.request("GET", base + "/backups")["backups"]:
            print("%d  %s  %8d Bytes  %s" % (b["time"], b["date"], b["bytes"], b["label"]))
    else:
        r = c.request("POST", base + "/restore", {"time": a.time} if a.time else {})
        print("wiederhergestellt: Stand %d" % r["restored"])


def search_command(c, a):
    q = [("q", a.q), ("limit", a.limit)]
    if a.types:
        q.append(("types", a.types))
    if a.lang:
        q.append(("lang", a.lang))
    if a.no_meta:
        q.append(("meta", "0"))
    r = c.request("GET", "bw-bridge/v1/search", None, q)
    if a.json_out:
        out(r)
        return
    for x in r["results"]:
        print("#%d  %s  %s  [%s]%s  %s" % (x["id"], x["type"], x["status"], x["slug"], "  " + x["lang"] if x.get("lang") else "", x["title"]))
        for h in x["hits"]:
            where = h["where"] + (" %s %s" % (h["widget_id"], h["path"]) if h["where"] == "elementor" else (" " + h["key"] if h["where"] == "meta" else ""))
            print("      %-52s %s" % (where, h["snippet"]))
    print("%d Treffer-Beiträge" % r["count"])


def meta_command(c, a):
    base = "bw-bridge/v1/meta/%d" % a.id
    if a.cmd == "meta-get":
        q = []
        if a.prefix:
            q.append(("prefix", a.prefix))
        if a.keys:
            q.append(("keys", a.keys))
        out(c.request("GET", base, None, q)["meta"])
    else:
        body = {"set": {}, "delete": a.delete or [], "dry_run": a.dry_run}
        for kv in a.set or []:
            k, _, v = kv.partition("=")
            body["set"][k] = v
        r = c.request("POST", base, body)
        for k, ch in r["changes"].items():
            print("%s: %r -> %r%s" % (k, ch["old"], ch["new"], " (gelöscht)" if ch.get("deleted") else ""))
        print("Probelauf, nichts gespeichert" if r["dry_run"] else "gespeichert")


def batch_command(c, a):
    doc = load_json(None, a.file)
    if isinstance(doc, list):
        doc = {"operations": doc}
    doc.setdefault("stop_on_error", not a.keep_going)
    r = c.request("POST", "bw-bridge/v1/batch", doc)
    for i, x in enumerate(r["results"]):
        op = doc["operations"][i]
        print("%3d  %s %s %s" % (x["status"], op.get("method", "GET").upper(), op["path"], "" if x["status"] < 400 else json.dumps(x["data"], ensure_ascii=False)[:200]))
    if r["stopped"]:
        print("Abgebrochen nach dem ersten Fehler.")
    if a.out:
        out(r, a.out)
    if any(x["status"] >= 400 for x in r["results"]):
        sys.exit(1)


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)

    sub.add_parser("status")
    sub.add_parser("auth-check", help="Diagnose: kommt die Anmeldung bei WordPress an?")
    for m in ("get", "post", "put", "delete"):
        s = sub.add_parser(m, help="beliebiger REST-Aufruf, z. B. wp/v2/pages")
        s.add_argument("path")
        s.add_argument("--query", action="append", default=[], help="key=value")
        s.add_argument("--json")
        s.add_argument("--file")
        s.add_argument("-o", "--out")

    s = sub.add_parser("elementor-get"); s.add_argument("id", type=int); s.add_argument("-o", "--out")
    s = sub.add_parser("elementor-put"); s.add_argument("id", type=int); s.add_argument("file")
    s.add_argument("--dry-run", action="store_true", help="nur Textvergleich alt/neu, nichts speichern")
    s.add_argument("--template", help="z. B. elementor_canvas oder elementor_header_footer")
    s = sub.add_parser("page-from-template"); s.add_argument("file"); s.add_argument("--title", required=True)
    s.add_argument("--slug"); s.add_argument("--status", default="draft"); s.add_argument("--type", default="pages")
    s = sub.add_parser("kit-get"); s.add_argument("-o", "--out")
    s = sub.add_parser("kit-put"); s.add_argument("file"); s.add_argument("--replace", action="store_true")
    s = sub.add_parser("template-import"); s.add_argument("file")
    sub.add_parser("clear-cache")
    for name in ("cpt", "tax"):
        sub.add_parser(name + "-list")
        s = sub.add_parser(name + "-set"); s.add_argument("slug"); s.add_argument("--json"); s.add_argument("--file")
        s = sub.add_parser(name + "-delete"); s.add_argument("slug")

    s = sub.add_parser("elementor-texts", help="Alle Texte einer Seite mit Widget-ID und Pfad")
    s.add_argument("id", type=int); s.add_argument("-q", help="nur Texte, die das enthalten"); s.add_argument("-o", "--out"); s.add_argument("--width", type=int, default=110)
    s = sub.add_parser("elementor-set", help="Einzelne Texte gezielt setzen (mit Sicherung)")
    s.add_argument("id", type=int); s.add_argument("widget_id", nargs="?"); s.add_argument("path", nargs="?"); s.add_argument("value", nargs="?")
    s.add_argument("--expect", help="nur ändern, wenn der aktuelle Text genau so lautet")
    s.add_argument("--file", help="JSON mit Liste von Änderungen"); s.add_argument("--dry-run", action="store_true")
    s = sub.add_parser("elementor-backups", help="Automatische Layout-Sicherungen auflisten"); s.add_argument("id", type=int)
    s = sub.add_parser("elementor-restore", help="Layout aus einer Sicherung wiederherstellen"); s.add_argument("id", type=int); s.add_argument("--time", type=int)
    s = sub.add_parser("search", help="Seiten, Produkte, Meta und Elementor-Texte durchsuchen")
    s.add_argument("q"); s.add_argument("--types", help="z. B. page,product"); s.add_argument("--lang", help="WPML-Sprache, z. B. de")
    s.add_argument("--no-meta", action="store_true"); s.add_argument("--limit", type=int, default=50); s.add_argument("--json", dest="json_out", action="store_true")
    s = sub.add_parser("translations", help="WPML-Übersetzungen eines Beitrags"); s.add_argument("id", type=int)
    s = sub.add_parser("translation-link", help="Beitrag als WPML-Übersetzung eines anderen verbinden"); s.add_argument("id", type=int)
    s.add_argument("--of", dest="of", type=int, required=True, help="ID des Beitrags in der Ausgangssprache")
    s.add_argument("--lang", help="Sprache des Beitrags setzen (z. B. de); ohne Angabe bleibt sie")
    s = sub.add_parser("render", help="Sichtbarer Text einer Seite im Frontend"); s.add_argument("id", type=int)
    s.add_argument("-q", help="nur Zeilen mit diesem Text"); s.add_argument("--limit", type=int, default=400)
    s = sub.add_parser("meta-get", help="Post-Meta eines Beitrags lesen"); s.add_argument("id", type=int)
    s.add_argument("--prefix"); s.add_argument("--keys", help="kommagetrennt")
    s = sub.add_parser("meta-set", help="Post-Meta setzen/löschen"); s.add_argument("id", type=int)
    s.add_argument("--set", action="append", metavar="KEY=WERT"); s.add_argument("--delete", action="append", metavar="KEY"); s.add_argument("--dry-run", action="store_true")
    s = sub.add_parser("batch", help="Mehrere REST-Aufrufe in einer Anfrage"); s.add_argument("file")
    s.add_argument("--keep-going", action="store_true", help="bei Fehlern weitermachen"); s.add_argument("-o", "--out")

    s = sub.add_parser("theme-ls", help="Ordner im Theme auflisten")
    s.add_argument("path", nargs="?", default=""); s.add_argument("-r", "--recursive", action="store_true")
    s = sub.add_parser("theme-get", help="Datei aus dem Theme lesen")
    s.add_argument("path"); s.add_argument("-o", "--out")
    s = sub.add_parser("theme-put", help="Lokale Datei ins Theme schreiben (Sicherung + PHP-Syntaxprüfung)")
    s.add_argument("path"); s.add_argument("file"); s.add_argument("--sha1", help="nur schreiben, wenn die Datei noch diesen Stand hat")
    s.add_argument("--no-lint", action="store_true")
    s = sub.add_parser("theme-rm", help="Datei im Theme löschen (mit Sicherung)"); s.add_argument("path")
    s = sub.add_parser("theme-backups", help="Sicherungen einer Datei auflisten"); s.add_argument("path")
    s = sub.add_parser("theme-restore", help="Sicherung zurückspielen"); s.add_argument("path"); s.add_argument("--backup")
    for name in ("theme-ls", "theme-get", "theme-put", "theme-rm", "theme-backups", "theme-restore"):
        sub.choices[name].add_argument("--parent", action="store_true", help="Parent-Theme statt aktivem Theme")

    p.add_argument("--timeout", type=int, help="Sekunden pro Anfrage (Standard 120 oder WP_TIMEOUT)")
    a = p.parse_args()
    c = Client()
    if a.timeout:
        c.timeout = a.timeout

    if a.cmd == "status":
        out(c.request("GET", "bw-bridge/v1/status"))
    elif a.cmd == "auth-check":
        out(c.request("GET", "bw-bridge/v1/auth-check"))
    elif a.cmd in ("get", "post", "put", "delete"):
        q = [tuple(x.split("=", 1)) for x in a.query]
        body = load_json(a.json, a.file) if (a.json or a.file) else None
        out(c.request(a.cmd.upper(), a.path, body, q), a.out)
    elif a.cmd == "elementor-get":
        out(c.request("GET", "bw-bridge/v1/elementor/%d" % a.id), a.out)
    elif a.cmd == "elementor-put":
        payload = elementor_payload(load_json(None, a.file))
        if a.template:
            payload.setdefault("settings", {})["template"] = a.template
        if a.dry_run:
            payload["dry_run"] = True
        r = c.request("POST", "bw-bridge/v1/elementor/%d" % a.id, payload)
        if a.dry_run:
            d = r["texts"]
            print("Probelauf, nichts gespeichert. Elemente: %d -> %d" % (r["elements"]["before"], r["elements"]["after"]))
            for x in d["changed"]:
                print("geändert    %s %s\n   alt: %s\n   neu: %s" % (x["widget_id"], x["path"], " ".join(x["old"].split())[:160], " ".join(x["new"].split())[:160]))
            for x in d["added"]:
                print("hinzugefügt %s %s: %s" % (x["widget_id"], x["path"], " ".join(x["new"].split())[:160]))
            for x in d["removed"]:
                print("entfernt    %s %s: %s" % (x["widget_id"], x["path"], " ".join(x["old"].split())[:160]))
            return
        print("gespeichert: #%d %s (%d Elemente oberste Ebene)" % (r["id"], r["title"], len(r["elements"])))
    elif a.cmd == "page-from-template":
        doc = load_json(None, a.file)
        page = c.request("POST", "wp/v2/" + a.type, {"title": a.title, "slug": a.slug or "", "status": a.status})
        payload = elementor_payload(doc)
        c.request("POST", "bw-bridge/v1/elementor/%d" % page["id"], payload)
        print("angelegt: #%d %s" % (page["id"], page["link"]))
    elif a.cmd == "kit-get":
        out(c.request("GET", "bw-bridge/v1/elementor/kit"), a.out)
    elif a.cmd == "kit-put":
        doc = load_json(None, a.file)
        settings = doc.get("settings", doc)
        r = c.request("POST", "bw-bridge/v1/elementor/kit", {"settings": settings, "replace": a.replace})
        print("Kit #%d aktualisiert (%d Einstellungen)" % (r["id"], len(r["settings"])))
    elif a.cmd == "template-import":
        out(c.request("POST", "bw-bridge/v1/elementor/templates", load_json(None, a.file)))
    elif a.cmd == "clear-cache":
        out(c.request("POST", "bw-bridge/v1/elementor/clear-cache"))
    elif a.cmd in ("elementor-texts", "elementor-set", "elementor-backups", "elementor-restore"):
        texts_command(c, a)
    elif a.cmd == "search":
        search_command(c, a)
    elif a.cmd == "translations":
        out(c.request("GET", "bw-bridge/v1/translations/%d" % a.id))
    elif a.cmd == "translation-link":
        body = {"translation_of": a.of}
        if a.lang:
            body["language"] = a.lang
        out(c.request("POST", "bw-bridge/v1/translations/%d" % a.id, body))
    elif a.cmd == "render":
        q = [("limit", a.limit)] + ([("q", a.q)] if a.q else [])
        r = c.request("GET", "bw-bridge/v1/render/%d" % a.id, None, q)
        print("\n".join(r["lines"]))
        print("— %s (%d Zeilen)" % (r["url"], r["count"]), file=sys.stderr)
    elif a.cmd in ("meta-get", "meta-set"):
        meta_command(c, a)
    elif a.cmd == "batch":
        batch_command(c, a)
    elif a.cmd.startswith("theme-"):
        theme_command(c, a)
    else:
        route = "post-types" if a.cmd.startswith("cpt") else "taxonomies"
        if a.cmd.endswith("-list"):
            out(c.request("GET", "bw-bridge/v1/" + route))
        elif a.cmd.endswith("-set"):
            out(c.request("POST", "bw-bridge/v1/%s/%s" % (route, a.slug), load_json(a.json, a.file or "-")))
        else:
            out(c.request("DELETE", "bw-bridge/v1/%s/%s" % (route, a.slug)))


if __name__ == "__main__":
    main()
