# Nächste Schritte

Was gebaut ist, steht im [README](../README.md). Hier steht, was fehlt und
warum es fehlt.

## Benutzerkonten

**Der nächste größere Baustein.** Zurzeit gibt es ein einziges Passwort und
keine Benutzer. Das trägt, solange eine Person pflegt.

Sobald zwei Leute pflegen, fehlt die Antwort auf „wer war das?". Die Datenbank
ist darauf vorbereitet: `change_log.actor` ist eine Textspalte und steht
heute auf `admin` oder `import`. Ein echter Benutzername passt dort ohne
Schemaänderung hinein. Auch `match_field_sources` merkt sich bereits, welche
Quelle ein Feld zuletzt gesetzt hat — nur eben nicht, welcher Mensch.

Minimalistisch heißt: kein Rechtesystem, keine Gruppen, keine
Selbstregistrierung.

- Tabelle `users`: Name, Passwort-Hash, aktiv ja/nein, angelegt am
- Anmeldung über Name statt nur Passwort
- `change_log.actor` bekommt den Benutzernamen
- Zwei Rollen genügen fürs Erste:
  - **Verwaltung** — darf importieren, Wettbewerbe anlegen, Benutzer pflegen
  - **Pflege** — darf Spiele ändern, aber nicht importieren und keine
    Benutzer anlegen
- Angelegt werden Benutzer nur von der Verwaltung

Was bewusst wegbleibt, solange es niemand braucht: Passwort-vergessen per
Mail, Zwei-Faktor, Rechte je Wettbewerb, Sitzungsverwaltung über mehrere
Geräte.

Zu klären, bevor gebaut wird: Soll jemand nur *seinen* Wettbewerb pflegen
dürfen? Das wäre der Punkt, an dem aus zwei Rollen ein Rechtesystem wird —
und der Aufwand deutlich steigt.

## Was ein Importlauf leistet

Zur Einordnung, was die Konten später verwalten. Gemessen an der
Frauen-Regionalliga West 2026/27, vollständige Saison aus einer Datei:

| | |
|---|---|
| Einlesen | 2 ms |
| Mannschaften zuordnen | 1 ms (16 Namen, nur beim ersten Mal) |
| Vergleichen | 8 ms |
| Übernehmen | 32 ms |
| **gesamt** | **43 ms** |

Angelegt werden dabei 240 Spiele, 16 Mannschaften, 30 Spieltage und 738
Feldeinträge mit Herkunftsvermerk. Ein zweiter Lauf derselben Datei meldet
240 mal `unchanged` und ändert nichts.

Von Hand wären das 240 Datensätze mit je Datum, Uhrzeit, Paarung, Ergebnis
und Halbzeitstand. Die Grundlast entfällt also; was bleibt, ist die laufende
Pflege: nachgereichte Ansetzungen, Verlegungen, Ergebnisse.

Genau diese Pflege ist der Grund für die Konten. Sie verteilt sich auf
mehrere Personen, und dann muss nachvollziehbar sein, wer wann was geändert
hat.

## Kleinere offene Punkte

**Spielstätten.** `venues` steht im Schema, wird aber vom Import nicht
gefüllt: die Spielstätte bräuchte eine Auflösung von Name auf `venues.id`,
und der Differ schreibt grundsätzlich nichts. Solange sie in den
Importdateien nur vereinzelt auftaucht, wäre das Aufwand ohne Ertrag.

**Torschützen.** `goals` bleibt in der OpenLigaDB-Ausgabe eine leere Liste;
das Feld ist vorhanden, damit Auswertungen nicht auf einen fehlenden
Schlüssel stoßen. Es zu füllen wäre Handarbeit und damit eine Frage der
Pflegekapazität, nicht der Technik.

**Zweite Liga.** Die Frauen-Westfalenliga ist als Wettbewerb angelegt, aber
leer. Für sie liegt bisher keine maschinell lesbare Aufstellung vor. Bis das
anders ist, führt der Weg über CSV: Grundgerüst einmal aufsetzen, danach
laufend pflegen.

Das ist die ehrliche Einordnung des Projekts: der Import nimmt Arbeit ab, wo
eine verwertbare Aufstellung existiert. Wo keine existiert, bleibt es
Handarbeit — nur eben mit einem Werkzeug, das Massenänderungen kann.

**Subdomain statt Pfad.** Die OpenLigaDB-Ausgabe liegt unter
`/api/openligadb/`. Eine eigene Subdomain wäre denkbar, bringt aber nur
Kosmetik und hängt an der DNS-Konfiguration.

## Festlegungen, die keine Lücken sind

**Kein automatischer Abruf.** Der Server holt sich nirgends von selbst Daten.
Dateien werden hochgeladen. Was in einer Datei steht und woher sie stammt,
verantwortet der Betreiber.

**Keine Maske zum Anlegen von Partien.** Ein Spielplan entsteht als Ganzes —
per JSON, sonst per CSV. Eine Eingabemaske, in der man Partie für Partie
zusammenklickt, wird es nicht geben: sie wäre für 240 Spiele unbenutzbar und
würde einen zweiten Weg in die Daten schaffen, der an Vorschau und
Herkunftsvermerk vorbeiführt.

Bearbeiten ist etwas anderes und bleibt: vorhandene Partien lassen sich
einzeln ändern und en bloc korrigieren. Nur entstehen tun sie aus einer Datei.

**CSV als kleinster gemeinsamer Nenner.** Wo JSON nicht geht, geht CSV. Jede
Tabellenkalkulation kann es, es lässt sich lesen, versionieren und von Hand
reparieren.
