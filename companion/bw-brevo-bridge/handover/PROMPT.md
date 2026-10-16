# Prompt für die neue Session (im neuen Repo einfügen)

> Vorher: Neues leeres GitHub-Repo anlegen (z. B. `blickwert/bw-brevo-bridge`) und der Session zugänglich machen.
> Quelle für den Code: Repo `blickwert/bw-wp-bridge`, Ordner `companion/bw-brevo-bridge/` (Branch `claude/wordpress-prevo-bridge-3bf8el` bzw. `main`, sobald PR #14 gemergt ist).

---

Du richtest dieses leere Repo als eigenständiges WordPress-Plugin **BW Brevo Bridge** ein.

1. Hole mit `add_repo` das Repo `blickwert/bw-wp-bridge` dazu (nur lesen) und klone es in ein Unterverzeichnis außerhalb dieses Repos.
2. Kopiere den Inhalt von `companion/bw-brevo-bridge/` in die **Wurzel** dieses Repos (`bw-brevo-bridge.php`, `includes/`, `admin/`, `tests/`, `uninstall.php`, `README.md`). Kopiere außerdem `tools/wp_bridge.py` aus dem Quell-Repo nach `tools/` (Client für die REST-API mit Mehrwebsite-Unterstützung).
3. Lege den Ordner `handover/` nicht ins neue Repo, sondern lege `handover/SKILL.md` als `.claude/skills/brevo-bridge/SKILL.md` ab. Ergänze eine kurze `CLAUDE.md`: Zweck, `php tests/test-brevo.php` vor jedem Commit, Regeln aus dem Skill gelten immer.
4. Ergänze `.gitattributes` (`/tools /tests /.claude /CLAUDE.md export-ignore`, damit das Plugin-ZIP schlank bleibt) und `.gitignore` (`__pycache__/`, `*.zip`). Passe in der README die Pfade an (Plugin liegt jetzt im Repo-Wurzelverzeichnis; der Hinweis auf „Begleit-Plugin der BW WP Bridge“ bleibt: Es nutzt deren Namespace und Verbindungsschlüssel, läuft aber auch allein – in dem Fall funktioniert der Client mit dem Pfad `bw-bridge/v1/brevo/…` nicht über `check_site`; prüfe, ob `wp_bridge.py` ohne installierte BW WP Bridge funktioniert, und passe ihn sonst minimal an).
5. Führe `php -l` über alle PHP-Dateien und `php tests/test-brevo.php` aus; beides muss grün sein. Committe, pushe auf den vorgegebenen Branch und lege einen Draft-PR an.
6. Gehe danach die Abschnitt „Beim ersten Einsatz prüfen“ im Skill durch: Prüfe gegen die offizielle Brevo-Dokumentation (https://developers.brevo.com), ob Endpunkte, Felder (`previewText`, `scheduledAt`, `sendTest`, `sendNow`), Webhook-Ereignisnamen und der Abmeldelink-Platzhalter stimmen, und korrigiere Abweichungen samt Tests. Nenne mir am Ende jede Abweichung einzeln.

Wichtig: Versende nichts und löse keine Ereignisse aus. Für Live-Tests brauche ich eine ausdrückliche Freigabe, einen Test-API-Schlüssel und eine Testliste von mir.
