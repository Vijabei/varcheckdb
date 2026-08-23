# Testdaten

Diese Dateien sind **echte Ausschnitte fremder Quellen**. Sie liegen hier,
weil die Adapter sonst nur gegen erfundene Daten geprueft waeren - und
erfundene Daten haben genau die Eigenheiten nicht, an denen ein Parser
scheitert: falsch deklarierte Zeichensaetze, doppelte Datensaetze,
uneinheitliche Vereinsnamen.

Erzeugt und aktualisiert werden sie mit:

```bash
python3 tools/make_fixtures.py           # neu erzeugen
python3 tools/make_fixtures.py --check   # nur pruefen
```

Die vollstaendigen Quelldateien liegen unter `samples/` und sind bewusst
nicht im Repository.

## Umfang und Herkunft

| Datei | Herkunft | Umfang |
|---|---|---|
| `kicker-sample.json` | kicker.de, Frauen-Regionalliga West 2026/27 | 24 von 240 Spielen (Spieltage 1, 11, 27) |
| `worldfootball-sample.html` | worldfootball.net, dieselbe Liga | dieselben 24 Spiele, nur die Spiel-Container |
| `worldfootball-cp1252.html` | wie oben, als Windows-1252 kodiert | bildet den Praxisfall nach, dass ein Browser die Seite falsch speichert |
| `openligadb-referenz.json` | api.openligadb.de, Liga `rlw-frauen/2026` | 9 Datensaetze, nur zur Pruefung der Feldnamen |

Aus den HTML-Ausschnitten sind Skripte, Stile, Navigation und Werbung
entfernt; es bleiben die Spiel-Container und die Spieltag-Ueberschriften.

## Rechtliches

Die Ausschnitte sind bewusst klein gehalten - jeweils rund ein Zehntel einer
Saison - und dienen ausschliesslich der Pruefung der Parser. Sie werden nicht
ausgeliefert und nicht als Datenbestand verwendet.

Die Rechte an den Daten liegen bei den jeweiligen Quellen. OpenLigaDB-Daten
stehen unter der Open Database License. Wer diesen Ausschnitt weiterverwendet,
sollte die Bedingungen der jeweiligen Quelle pruefen; das Recht des
Datenbankherstellers nach § 87a UrhG bleibt unberuehrt.
