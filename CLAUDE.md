# Hinweise für Claude

## Versionsnummer

Mit jedem Merge in `main` die Plugin-Version erhöhen, in `bw-wp-bridge.php` an beiden Stellen (Kopfzeile `Version:` und Konstante `BW_WP_BRIDGE_VERSION`). Die Stufe wählt Claude selbst nach Wichtigkeit (Semantic Versioning):

- Patch (x.y.Z): Fehlerbehebung, Text, kleine Korrektur ohne neues Verhalten.
- Minor (x.Y.0): neue Funktion oder spürbar geändertes Verhalten, abwärtskompatibel.
- Major (X.0.0): Änderung, die bestehende Einrichtungen oder Clients bricht.

Die Erhöhung gehört in denselben PR wie die Änderung.

## Erkenntnisse aus Websites

Daten und Erkenntnisse aus einer angebundenen Website (Datenmodell, Feldlisten, Analysen) gehören nicht in dieses Repo, sondern in das jeweilige Projekt-Repo (z. B. `blickwert/bw-bework`). Hier landen nur Änderungen am Bridge-Plugin selbst.
