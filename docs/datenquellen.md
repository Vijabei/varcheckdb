# Datenquellen

Die Datenbank auf vijabei.net wird nicht automatisch von fremden Webseiten
befuellt. Der Admin erzeugt eine Importdatei, prueft die Vorschau und
bestaetigt sie. Dieses Dokument haelt fest, woher die Dateien kommen.

Die vollstaendigen Quelldateien liegen unter `samples/` und sind **nicht**
im Repository: sie sind gross, jederzeit neu erzeugbar und stehen unter
fremdem Urheberrecht. Im Repository liegen nur die zurechtgeschnittenen
Testfixtures unter `tests/fixtures/`.

## kicker.de — Primaerquelle

Vollstaendige Spielplaene mit Ergebnis, Halbzeitstand und einem eigenen
Kennzeichen dafuer, ob eine Ansetzung final ist.

```bash
python3 tools/fetch_kicker.py 4530 2026-27 -o samples/kicker-4530-2026-27.json
```

- `4530` ist die kicker-Liga-ID der Frauen-Regionalliga West,
  `8140` die der 2. Frauen-Bundesliga. Die Liste aller IDs liefert der
  Endpunkt `LeagueListHome/3`.
- Das Werkzeug braucht nur die Python-Standardbibliothek, kein venv.
- Es haelt eine Sekunde Pause zwischen den Spieltagen und schickt einen
  sprechenden User-Agent. Eine Kontaktadresse darin ist der Quelle gegenueber
  fair - sie kann sich melden, statt stumm zu sperren. Sie steht nicht im
  Quelltext, sondern kommt aus der Umgebung:

  ```bash
  export VARCHECKDB_CONTACT='deine@adresse.example'
  ```

Benutzte Endpunkte:

```text
https://ovsyndication.kicker.de/API/universal/3.0/LeagueSeasonInfo/3/ligid/{liga}/saison/{saison}
https://ovsyndication.kicker.de/API/universal/3.0/Gameday/3/ligid/{liga}/saison/{saison}/spieltag/{n}
```

Die Saison wird als `2026-27` uebergeben. Ein Schraegstrich im Pfad
(`2026/27`) fuehrt zu HTTP 404 — daran scheitert auch die Bibliothek
`kickerde-api-client`, deren `league_season()` die ID ungeprueft in den
Pfad schreibt.

`robots.txt` auf `ovsyndication.kicker.de` antwortet mit HTTP 204, es sind
also keine Regeln hinterlegt.

### Doppeleintraege

kicker.de fuehrt fuer einen Teil der Paarungen **zwei** Datensaetze mit
abweichenden Terminen. In der Saison 2026/27 der Frauen-Regionalliga West
betrifft das 15 von 240 Spielen. `modifiedAt` und `timeConfirmed` sind bei
beiden identisch, eine automatische Regel gibt es also nicht.

`tools/fetch_kicker.py` fasst sie zusammen, schlaegt den Datensatz mit der
niedrigeren Quell-ID vor und meldet die Abweichung im Feld `conflicts`. Der
Import zeigt sie als Konflikt zur Auswahl. Ohne Deduplizierung entstuenden
316 statt 240 Spiele.

## worldfootball.net — Gegenquelle

Dient dem Abgleich, nicht der Erstbefuellung: keine Halbzeitstaende, kein
Terminstatus.

Die Seite steht hinter einer Cloudflare-Pruefung und beantwortet **jeden**
nicht-browserartigen Zugriff mit HTTP 403 — auch den ersten. Das ist keine
Ratenbegrenzung; ein serverseitiger Abruf ist deshalb ausgeschlossen.

So kommt die Datei zustande:

1. <https://www.worldfootball.net/competition/co16640/germany-women-regionalliga-west/all-matches/>
   im eigenen Browser oeffnen.
2. Mit Strg+S als „Webseite, nur HTML" speichern.
3. Nach `samples/worldfootball-co16640-all-matches.html` legen.

### Zwei Eigenheiten

**Der Zeichensatz ist falsch deklariert.** Die Seite gibt im meta-Tag
`charset="utf-8"` an, wird vom Browser unter Windows aber als Windows-1252
gespeichert. Unbehandelt wird aus `Vorwärts Spoho 98` ein `Vorw?rts Spoho 98`
— und damit ein neuer, falscher Verein samt dauerhaftem Alias.
`public_html/lib/encoding.php` erkennt und korrigiert das. Dasselbe gilt fuer
CSV-Dateien aus Excel.

**`data-round_id` ist unbrauchbar.** Alle 240 Spiele tragen denselben Wert.
Der Spieltag steht nur in den `round-head`-Zwischenueberschriften
(„Matchday N") und wird ueber die Reihenfolge im Dokument zugeordnet. Das ist
die einzige Stelle im Parser, die von der Dokumentstruktur abhaengt.

Erfreulich ist dagegen `data-datetime="2026-08-20T17:00:00Z"`: der Anstoss
steht bereits in UTC am Spiel-Container, es muss nichts aus Textspalten
geraten werden.

## Was der Abgleich beider Quellen ergibt

Stand 23.08.2026, Frauen-Regionalliga West 2026/27:

| | |
|---|---|
| Spiele je Quelle | 240 |
| ueber Aliase verknuepft | 240 von 240 |
| noetige Alias-Bestaetigungen | 6 |
| kicker-Konflikte, die worldfootball entscheidet | 15 von 15 |
| verbleibende Terminabweichungen | 0 |
| abweichende Ergebnisse | 1 |

Die sechs Namen, die einmal bestaetigt werden muessen — der Matcher schlaegt
jeweils den richtigen als ersten vor:

```text
Bayer Leverkusen II     -> Bayer 04 Leverkusen II
Rhenania Bottrop        -> SV Rhenania Bottrop
SV Fortuna Freudenberg  -> Fortuna Freudenberg
VFR SW Warbeyen         -> VfR Warbeyen
Vorwärts Spoho 98       -> Vorwärts Spoho Köln
Wacker Mecklenbeck      -> DJK Wacker Mecklenbeck
```

Die eine Ergebnisabweichung: worldfootball fuehrt am 1. Spieltag
`MSV Duisburg 2:1 SSV Rhade`, kicker hat das Spiel noch ohne Ergebnis.

## Nicht verwendete Quellen

**FUSSBALL.DE** liefert im Widget zwar stabile Mannschafts- und Vereins-IDs,
aber `obfuscatedFont` und leere Felder statt Namen, Datum und Uhrzeit
(`"name": "  "`, `"date": ""`). Ohne Umgehung der Schriftverschleierung
unbrauchbar — und die bauen wir nicht.

**OpenLigaDB** fuehrt weder die Frauen-Regionalliga West noch die
Frauen-Westfalenliga (geprueft ueber `getavailableleagues`, 822 Ligen). Als
Quelle wertlos. Das Ausgabeformat wird trotzdem nachgebildet, damit
bestehende Auswertungen weiterverwendet werden koennen.

**FuPa** haette technisch die beste Schnittstelle (sauberes JSON per
einfachem Request, exakte Vereinsnamen, stabile IDs, deckt beide Ligen ab).
Dagegen steht `https://api.fupa.net/robots.txt` mit `User-agent: *` und
`Disallow: /`. Deshalb nicht eingebunden.

## Testfixtures erneuern

```bash
python3 tools/make_fixtures.py           # neu erzeugen
python3 tools/make_fixtures.py --check   # nur pruefen
```

Erzeugt aus den Quelldateien die Spieltage 1, 11 und 27 — gespielte Partien
mit Ergebnis und Halbzeitstand sowie zwei kicker-Doppeleintraege. Vor dem
Schreiben wird geprueft: Spielanzahl, Anzahl der Spieltag-Ueberschriften und
ob die Umlaute die Umwandlung ueberstanden haben.

`--check` meldet einen Unterschied zwischen den abgelegten Fixtures und dem,
was aus den aktuellen Quelldateien entstuende. Genau daran erkennt man, dass
eine Quelle ihr Format geaendert hat.
