# Spielorte und Zuschauer

## Der Gedanke

**Ein Spielort gehört zum Spiel, nicht zum Verein.**

Die naheliegende Lösung wäre, jedem Verein sein Stadion zuzuordnen und den
Spielort daraus abzuleiten. Das geht bei den langweiligen Fällen gut und bei
allen interessanten schief:

- die Mannschaft weicht aus, weil der Platz unbespielbar ist
- ein Heimspiel findet auf der Anlage des Gegners statt
- ein Verein hat zwei Plätze und wechselt zwischen ihnen
- ein Endspiel wird auf neutralem Boden ausgetragen

Genau diese Fälle sind es, die man festhalten will. Deshalb ist der Spielort
am einzelnen Spiel **optional** anzugeben; ist dort nichts eingetragen, ist er
schlicht unbekannt — und nicht falsch geraten.

## Fassungsvermögen

Das Fassungsvermögen ist optional und dient nicht der Statistik, sondern dem
Eintragen. Wer eine Zuschauerzahl erfasst, sieht daneben, was der Platz
hergibt; liegt die eingetragene Zahl darüber, wird der Hinweis rot. Eine Null
zu viel fällt so beim Eintragen auf und nicht erst in der Auswertung.

Übernommen wird die Zahl trotzdem — es ist ein Hinweis, keine Sperre. Es gibt
Spiele mit mehr Zuschauern als Sitzplätzen, und die Datenbank soll nicht
klüger sein wollen als der, der dabei war.

## Anlegen

Unter *Meine Ligen → Spielorte*. Einzeln über das Formular, oder als Liste
direkt aus einer Tabelle kopiert:

```text
Stadion Rote Erde	9500
Tönnies-Arena	3562
Bezirkssportanlage Westender Straße	4000
```

Zwei Spalten sind `Name` und `Fassungsvermögen`, drei schieben die `Stadt`
dazwischen. Trennzeichen ist Tabulator oder Semikolon. Punkte und Leerzeichen
in der Zahl stören nicht — `9.500` und `9 500` sind beide 9500.

**Was es schon gibt, bleibt unverändert.** Dieselbe Liste lässt sich also
gefahrlos zweimal einfügen.

## Entfernen

Ein Spielort lässt sich nur entfernen, solange kein Spiel auf ihn verweist.
Sonst bliebe ein Spiel mit einem Verweis ins Leere zurück. Die Liste zeigt je
Ort, an wie vielen Spielen er hängt.

Soll ein Ort trotzdem weg: erst an den betroffenen Spielen einen anderen Ort
setzen oder das Feld leeren, dann entfernen.

## Im Import

Ein Import **legt keine Spielorte an**. Ein unbekannter Name erscheint in der
Vorschau unter *Hinweise*, das Feld bleibt offen, der Rest der Zeile wird
übernommen. Siehe [import.md](import.md).

## In der Schnittstelle

Modern (`/api/v1/…/matches`):

```json
{ "venue": "Stadion Rote Erde", "venueCapacity": 9500, "spectators": 1842 }
```

OpenLigaDB-kompatibel:

```json
{ "location": { "locationID": 1, "locationStadium": "Stadion Rote Erde",
                "locationCity": "Dortmund" },
  "numberOfViewers": 1842 }
```

Unbekannt ist überall `null` — nie 0 und nie ein leerer Text.
