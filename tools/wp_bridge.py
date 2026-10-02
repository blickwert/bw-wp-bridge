#!/usr/bin/env python3
"""
wp_bridge.py – Kommandozeilen-Client für die WordPress-REST-API + BW WP Bridge.

Umgebungsvariablen:
  WP_URL            z. B. https://dev.appa.at
  WP_USER           WordPress-Benutzername
  WP_APP_PASSWORD   Anwendungspasswort (Profil › Anwendungspasswörter)
  WP_BASIC_AUTH     optional, "user:passwort" falls der Server per .htpasswd geschützt ist

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
            with urllib.request.urlopen(req, timeout=120) as r:
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

    a = p.parse_args()
    c = Client()

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
        r = c.request("POST", "bw-bridge/v1/elementor/%d" % a.id, payload)
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
