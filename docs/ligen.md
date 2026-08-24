# Ligen anlegen

Eine frisch installierte Datenbank ist leer. Es gibt keine Beispielligen, die
man erst wegräumen müsste — die erste Liga legst du selbst an, unter
**Meine Ligen → Ligen**.

## Was eine Liga ausmacht

| Feld | Beispiel | Bedeutung |
|---|---|---|
| Kurzform (`slug`) | `frauen-regionalliga-west` | die sprechende Adresse in der modernen Schnittstelle, bis 64 Zeichen |
| Kürzel (`shortcut`) | `frlw` | der `leagueShortcut` der OpenLigaDB-kompatiblen Ausgabe, bis 16 Zeichen |
| Name | `Frauen-Regionalliga West` | der Anzeigename; die Saison wird automatisch angehängt |
| Saison | `2026` | Startjahr, entspricht `leagueSeason` |
| Geschlecht, Altersklasse | `Frauen`, `senior` | beschreibt den Wettbewerb, nicht die Mannschaft |
| Mannschaften | `16` | rein informativ |

Das **Kürzel steht in jeder Adresse der öffentlichen Schnittstelle**. Wird es
nachträglich geändert, brechen fremde Abfragen. Deshalb vor dem ersten Import
festlegen und dann stehen lassen.

## Benennungsregel für das Kürzel

Geschlechtspräfix, dann die Liga, ohne Trennzeichen — `f` für Frauen, `m` für
Männer:

| Kürzel | Liga |
|---|---|
| `frlw` | Frauen-Regionalliga West |
| `mrlw` | Männer-Regionalliga West |
| `fwfl` | Frauen-Westfalenliga |

Das Präfix wird auch bei Männerligen gesetzt, obwohl `rlw` kürzer wäre: eine
Regel mit Ausnahme ist keine Regel, und die Abkürzung bliebe sonst Raten,
sobald beide Ligen nebeneinanderstehen.

## Was bewusst nicht erfasst wird

Region, Ebene und Veranstalter gab es früher als Felder. Sie waren beim
Anlegen auszufüllen, ohne je gelesen zu werden. Diese Datenbank bildet keine
Gesamtstruktur des Spielbetriebs ab, sondern Spiele — drei Pflichtfelder ohne
Zweck halten eher jemanden vom Anlegen ab, als dass sie nützen.

Wer die Ebene im Namen braucht, schreibt sie hinein: `Frauen-Regionalliga
West` sagt bereits alles, was die drei Felder gesagt hätten.
