# Schnittstelle

Alle Antworten sind JSON, UTF-8. Mit `?download=1` wird die Antwort als Datei
angeboten statt im Browser angezeigt.

Der Spielplan gibt es zusaetzlich als CSV — `?format=csv` liefert genau die
Spalten, die der Import wieder annimmt. Damit ist der Rundlauf
*herunterladen, in der Tabellenkalkulation aendern, zurueckspielen* auch ohne
Anmeldung moeglich:

```text
GET api/v1/competitions/{slug}/seasons/{jahr}/matches?format=csv&download=1
```

Ohne `mod_rewrite` ist jede Adresse auch als `index.php?route=…` erreichbar.

## Eigenes Format

```text
GET api/v1/competitions
GET api/v1/competitions/{slug}/seasons/{jahr}/matches
GET api/v1/competitions/{slug}/seasons/{jahr}/table
```

Statt einer Jahreszahl geht `current` für die neueste Saison. Beim Spielplan
wirken die Filter `?round=`, `?status=`, `?from=` und `?to=`.

Anstosszeiten stehen doppelt: `kickoff` in der eingestellten Ortszeit,
`kickoffUtc` in UTC. `kickoffConfirmed` sagt, ob die Ansetzung verbindlich ist.

Je Spiel kommen `venue`, `venueCapacity` und `spectators` dazu; unbekannt ist
`null`. Siehe [spielorte.md](spielorte.md).

Am Wettbewerb gab es frueher `region` und `level`. Beide sind entfallen: sie
waren beim Anlegen auszufuellen, ohne je gelesen zu werden. Diese Datenbank
bildet keine Gesamtstruktur des Spielbetriebs ab, sondern Spiele.

## OpenLigaDB-kompatibel

```text
GET api/openligadb/getavailableleagues
GET api/openligadb/getmatchdata/{kuerzel}/{saison}
GET api/openligadb/getmatchdata/{kuerzel}/{saison}/{spieltag}
GET api/openligadb/getmatchdata/{kuerzel}            (neueste Saison)
GET api/openligadb/getbltable/{kuerzel}/{saison}
GET api/openligadb/getcurrentgroup/{kuerzel}
```

Reine Uebersetzungsschicht ueber denselben Datenbestand - es gibt keine
zweite Datenhaltung. Zweck ist, dass Auswertungen, die gegen
`api.openligadb.de` geschrieben wurden, ohne Aenderung auch hier laufen.

Die Feldnamen sind gegen die echte Schnittstelle geprueft und in
`tests/fixtures/openligadb-referenz.json` eingefroren; `tests/test_openligadb.php`
vergleicht bei jedem Lauf dagegen.

### Nachgebildete Eigenheiten

- `leagueSeason` ist in `getavailableleagues` eine **Zeichenkette**, in
  `getmatchdata` dagegen eine **Zahl**. Das ist im Original so und wird
  uebernommen, damit streng typisierte Auswertungen nicht stolpern.
- `matchDateTime` traegt keine Zonenangabe; die Zone steht in `timeZoneID`,
  der eindeutige Zeitpunkt in `matchDateTimeUTC`.
- `groupOrderID` ist die Spieltagsnummer. Ein Spiel gehoert zu seinem
  Spieltag, unabhaengig davon, wann es stattfindet - ein vom Ostersamstag in
  den Mai verlegtes Spiel behaelt seinen Spieltag.

### Was leer bleibt

`goals` ist immer eine leere Liste: Torschuetzen liefert keine unserer
Quellen. Das Feld bleibt vorhanden, damit Auswertungen nicht auf einen
fehlenden Schluessel stossen.

`location` ist `null`, solange am Spiel kein Spielort eingetragen ist.
`numberOfViewers` ist `null`, solange keine Zuschauerzahl erfasst ist — nie 0,
denn 0 waere eine Aussage.

`matchResults` ist bei einem noch nicht gespielten Spiel leer, wie im
Original. Bei einem gespielten Spiel stehen immer beide Eintraege in fester
Reihenfolge: erst `HalfTime`, dann `After90Minutes`.

Ist der Halbzeitstand unbekannt, traegt der `HalfTime`-Eintrag `null` als
Punktzahl. Quellen liefern ihn oft unvollstaendig; ein Endergebnis ohne
Halbzeitstand ist der Normalfall, nicht die Ausnahme.

Die beiden verworfenen Alternativen und warum:

- **Eintrag weglassen**: Auswertungen, die `matchResults[0]` als Halbzeit
  lesen, faenden dort das Endergebnis vor und rechneten falsch.
- **0:0 einsetzen**: waeren erfundene Daten. Ein Spiel, das zur Pause 1:0
  stand, stuende damit falsch in der oeffentlichen Ausgabe.

Fehlende Halbzeitstaende lassen sich unter *Meine Ligen -> Spielplan* oder
ueber den CSV-Ruecklauf nachtragen; danach stehen dort Zahlen statt `null`.

### Abgleich mit dem Original

Geprueft gegen die von Hand gepflegte Liga `rlw-frauen/2026` auf
api.openligadb.de, Stand 24.08.2026:

| | |
|---|---|
| Spiele zugeordnet | 240 von 240 |
| `matchDateTime` und `matchDateTimeUTC` identisch | 240 |
| `matchIsFinished` identisch | 240 |
| `matchResults` identisch | 233 |
| davon abweichend nur durch unbekannte Halbzeitstaende | 7 |
| Feldnamen zu viel oder zu wenig | keine |
| Typabweichungen | keine |

Die sieben Abweichungen bei `matchResults` sind ausschliesslich Halbzeit-
staende, die die Quelle nicht kennt: dort steht `null`, wo die von Hand
gepflegte Liga `0` fuehrt. Struktur und Reihenfolge sind identisch.

## Ligakuerzel

`shortcut` ist der `leagueShortcut` der OpenLigaDB-Ausgabe und damit Teil der
oeffentlichen Schnittstelle. Nachtraeglich geaendert brechen fremde Abfragen.

Benennungsregel: Geschlechtspraefix, dann die Liga, ohne Trennzeichen.

```text
frlw   Frauen-Regionalliga West
mrlw   Maenner-Regionalliga West
fwfl   Frauen-Westfalenliga
```

Das Praefix wird auch bei Maennerligen gesetzt, obwohl `rlw` kuerzer waere:
eine Regel mit Ausnahme ist keine Regel.
