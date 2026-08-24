# Import

Daten kommen als Datei herein. Der Server ruft von sich aus nichts ab.

Jeder Import geht denselben Weg:

```text
Datei hochladen
      ↓
Format erkennen          am Inhalt, nicht an der Dateiendung
      ↓
Mannschaften zuordnen    nur beim ersten Mal je Name
      ↓
Vorschau                 neu / geändert / unverändert / geschützt
      ↓
Bestätigen
```

Bis zur Bestätigung wird nichts geschrieben. Der Vorgang liegt zwischen den
Schritten in der Datenbank, nicht in der Sitzung — ein abgebrochener Import
lässt sich fortsetzen.

## JSON

Kennung `varcheckdb-import/1`. Für vollständige Spielpläne.

```json
{
  "format": "varcheckdb-import/1",
  "competition_name": "Beispielliga",
  "season": "2026/27",
  "season_start_year": 2026,
  "timezone": "Europe/Berlin",
  "matches": [
    {
      "round": 1,
      "kickoff_date": "2026-08-20",
      "kickoff_time": "19:00",
      "kickoff_confirmed": 1,
      "home": "Verein A",
      "away": "Verein B",
      "home_goals": 3,
      "away_goals": 0,
      "home_goals_ht": 1,
      "away_goals_ht": 0,
      "status": "finished",
      "venue": "Stadion Rote Erde",
      "spectators": 1842,
      "source_match_id": "12345"
    }
  ]
}
```

Zeiten stehen in Ortszeit; die Zone steht einmal oben im Dokument.

`status`: `scheduled`, `live`, `finished`, `postponed`, `cancelled`.

`kickoff_confirmed` unterscheidet einen verbindlichen Termin von einer
vorläufigen Ansetzung.

Felder, die fehlen oder auf `null` stehen, bedeuten **keine Aussage**. Sie
löschen keinen bestehenden Wert. Wer ein Ergebnis wirklich entfernen will,
tut das von Hand.

`source_match_id` ist optional und dient nur der Rückverfolgbarkeit.

### Spielort und Zuschauer

`venue` ist der **Name** eines Spielorts, `spectators` die Zuschauerzahl.

Ein Import legt **keine** Spielorte an. Steht in der Datei ein Name, den es
noch nicht gibt, sagt die Vorschau das unter *Hinweise* und lässt das Feld
offen — der Rest der Zeile wird ganz normal übernommen. Angelegt wird der Ort
unter *Meine Ligen → Spielorte*, danach greift der nächste Import.

Das ist Absicht: eine Vorschau, die schon schreibt, wäre keine Vorschau. Und
ein Tippfehler in einer Spalte, die niemand prüft, hätte sonst dauerhaft einen
falschen Spielort in der Datenbank.

Der Spielort gehört zum **Spiel**, nicht zum Verein. Ausweichplatz, Heimspiel
beim Gegner, Endspiel auf neutralem Boden — die interessanten Fälle wären mit
einer festen Zuordnung nicht abzubilden. Mehr dazu in
[spielorte.md](spielorte.md).

### Mehrere Terminangaben

Liefert eine Datei für dieselbe Paarung zwei Termine, kann sie das im Feld
`conflicts` mitteilen:

```json
"conflicts": [
  {
    "round": 30,
    "home": "Verein A",
    "away": "Verein B",
    "alternatives": [
      { "kickoff_date": "2027-05-23", "kickoff_time": "15:00" },
      { "kickoff_date": "2027-05-16", "kickoff_time": "15:00" }
    ]
  }
]
```

Übernommen wird die **erste** Angabe; die Vorschau zeigt die übrigen zur
Auswahl. Wird dort eine andere angehakt, gilt sie als bestätigt und wird von
späteren Importen nicht mehr überschrieben.

Das trennt den Fall „Uhrzeit geändert" (gleiches Datum) sauber von
„verlegt" (anderes Datum). Ein verlegtes Spiel behält seinen Spieltag.

## CSV

Für Massenkorrekturen. Der Export erzeugt genau die Spalten, die der Import
wieder annimmt — exportieren, in der Tabellenkalkulation ändern, zurückspielen.

```text
match_id;round;kickoff_date;kickoff_time;kickoff_confirmed;home;away;
home_goals;away_goals;home_goals_ht;away_goals_ht;status;venue;spectators;note
```

Erforderlich sind nur `home` und `away`. Unbekannte Spalten werden übergangen
und gemeldet.

Auf die Eigenheiten der Tabellenprogramme ist Rücksicht genommen:

- **Trennzeichen** wird erkannt: Semikolon, Komma oder Tabulator.
- **Datum** geht als `2026-08-20` oder `20.08.2026`.
- **Uhrzeit** geht als `19:00` oder `19:00:00`.
- **Zeichensatz**: Windows-1252 wird erkannt und umgewandelt, eine BOM
  entfernt. Ohne das würde aus `Vorwärts` ein `Vorw?rts` — und damit ein
  neuer, falscher Verein samt dauerhaftem Alias.
- Der Export trägt selbst eine BOM, damit Excel ihn als UTF-8 öffnet.

`match_id` ordnet die Zeile eindeutig zu. Ohne sie wird über Spieltag und
Mannschaften gesucht.

Was über den CSV-Weg hereinkommt, gilt als Handarbeit: es darf frühere
Korrekturen überschreiben, und die geänderten Felder gelten danach als
bestätigt. Unberührte Felder bleiben unberührt.

## Mannschaften zuordnen

**Ein Mannschaftsname kommt genau einmal vor.** „Arminia Bielefeld" ist ein
Eintrag und steht im Frauen- wie im Männerwettbewerb; welcher gemeint ist,
sagt das Spiel. Geschlecht und Altersklasse hängen deshalb am Wettbewerb, nicht
an der Mannschaft — an einer geteilten Mannschaft wären sie schlicht falsch.

Wo eine Unterscheidung nötig ist, trägt der Name sie bereits: „Arminia
Bielefeld U19", „SGS Essen II". Das ist auch die Schreibweise der Quellen.

Beim ersten Import ist jeder Name unbekannt. Die Vorschläge sind bewusst
zurückhaltend: automatisch zugeordnet wird nur bei einem exakten Treffer.
Alles andere wird vorgeschlagen und einmal bestätigt; die Bestätigung wird als
Alias gespeichert und greift ab dem nächsten Import.

Reserve- und erste Mannschaften — etwa `SG Beispiel II` und `SG Beispiel` —
werden **nie** automatisch zusammengeführt. Ein falscher Vorschlag kostet
einen Klick; eine falsche Zusammenführung verdirbt den Datenbestand
unrettbar.

## Überschreibschutz

Jedes Feld merkt sich, woher sein Wert stammt und ob ein Mensch ihn bestätigt
hat. Eine bestätigte Angabe überlebt jeden weiteren Import einer nachrangigen
Quelle. Die Vorschau weist solche Felder aus, statt sie stillschweigend zu
übergehen.

## Rechte an den Daten

Woher eine Importdatei stammt, entscheidet der Betreiber — und er ist auch
dafür verantwortlich. Wer Daten aus fremden Beständen übernimmt, sollte deren
Bedingungen prüfen; das Recht des Datenbankherstellers nach § 87a UrhG bleibt
davon unberührt.
