# varcheckdb

Eigene Spieldatenbank für Fußballwettbewerbe, die von den großen Portalen
schlecht bedient werden — vor allem Frauen- und Amateurligen. Sie speist sich
aus externen Quellen, wird von Hand gepflegt und gibt die Daten als JSON aus,
wahlweise im eigenen Format oder in dem von OpenLigaDB.

Läuft auf gewöhnlichem PHP-Webhosting: PHP 8.1, MariaDB, kein Composer, kein
Node, kein Build-Schritt.

## Warum

Wer Spieldaten einer Liga führen will, stößt fast überall auf dieselbe Wand:
**es gibt keinen brauchbaren Weg hinein und keinen wieder hinaus.** Kaum ein
Portal bietet eine benutzbare Schnittstelle, viele bieten gar keinen Export,
und ein Import ist meist überhaupt nicht vorgesehen. Wer Daten hat, kann sie
nicht einspielen; wer sie eingepflegt hat, kommt nicht mehr an sie heran.

OpenLigaDB ist die rühmliche Ausnahme mit einer offenen, gut dokumentierten
Schnittstelle — nur ist die Pflege dort mühsam.

Daraus ergibt sich der Zweck dieses Projekts:

- **Rein kommt man über Dateien.** JSON oder CSV, beides offen und
  nachvollziehbar. Woher eine Datei stammt, ist Sache des Betreibers.
- **Raus kommt man jederzeit.** Jede Ansicht ist als JSON abrufbar, der
  gesamte Spielplan als CSV. Keine Sackgasse.
- **Massenänderungen gehören dazu**, nicht als Notbehelf. Spielpläne ändern
  sich über die Saison; das muss in Minuten gehen, nicht in Stunden.

Auch diese Datenbank lebt davon, dass jemand sie pflegt. Der Unterschied ist,
wie viel Arbeit das ist: ein Importlauf legt eine komplette Saison an — 240
Spiele, 16 Mannschaften, 30 Spieltage, aus einer Datei, in unter einer
Sekunde. Was bleibt, ist die laufende Pflege.

Der Server ruft von sich aus nirgends etwas ab. Dateien werden hochgeladen.

## Grundsätze

**Die Datenbank ist die Wahrheit.** Ein Import setzt nie unmittelbar Fakten,
sondern erzeugt eine Vorschau, die bestätigt wird.

**Was du bestätigst, bleibt.** Jedes Feld merkt sich seine Herkunft. Eine
Korrektur von Hand überlebt jeden weiteren Import derselben Quelle.

**`null` heißt „keine Aussage", nicht „leer".** Sagt eine Quelle zu einem Feld
nichts, bleibt der bestehende Wert stehen, statt gelöscht zu werden.

**Ein Spiel gehört zu seinem Spieltag, unabhängig vom Termin.** Ein vom
Ostersamstag in den Mai verlegtes Spiel behält seinen Spieltag.

Mehrere Personen können pflegen: es gibt Benutzerkonten mit zwei Rollen, und
jede Änderung trägt den Namen dessen, der sie gemacht hat. Siehe
[docs/benutzer.md](docs/benutzer.md). Was noch offen ist, steht in
[docs/naechste-schritte.md](docs/naechste-schritte.md).

## Importformate

| Format | wofür |
|---|---|
| JSON (`varcheckdb-import/1`) | vollständige Spielpläne, maschinell erzeugt |
| CSV | Massenkorrekturen: exportieren, in der Tabellenkalkulation ändern, zurückspielen |

Beide gehen denselben Weg: einlesen, Mannschaften zuordnen, Vorschau,
Bestätigung. Beschrieben in [docs/import.md](docs/import.md).

Eine grafische Maske zum **Anlegen** einzelner Partien gibt es bewusst nicht.
Ein Spielplan entsteht als Ganzes, nicht Zeile für Zeile im Browser. Bearbeiten
lassen sich vorhandene Partien natürlich — einzeln und en bloc.

## Installation

`public_html/` auf den Webspace laden, `db/` möglichst eine Ebene darüber,
dann `install.php` aufrufen. Der Installer prüft die Umgebung, testet die
Datenbankrechte praktisch aus, spielt das Schema ein und löscht sich am Ende
selbst. Siehe [docs/installation.md](docs/installation.md).

## Schnittstelle

```text
api/v1/competitions/{slug}/seasons/{jahr}/matches
api/openligadb/getmatchdata/{kuerzel}/{saison}
```

Beide Formate über denselben Datenbestand. Die OpenLigaDB-Ausgabe ist gegen
die echte Schnittstelle geprüft und in einem Test gegen eingefrorene echte
Antworten abgesichert. Siehe [docs/api.md](docs/api.md).

## Entwicklung

```bash
sudo apt install -y php-cli php-sqlite3 php-mysql php-xml php-mbstring
php tests/run.php
```

Die Testsuite läuft gegen SQLite im Speicher, ohne Netzwerk und ohne
Webserver. Für einen Durchlauf gegen echtes MariaDB:

```bash
MYSQL_HOST=127.0.0.1 MYSQL_DB=varcheckdb_test MYSQL_USER=BENUTZER MYSQL_PASSWORD=PASSWORT \
  php tests/integration_mysql.php
```

## Aufbau

```text
public_html/          Dokumentenverzeichnis
  index.php           Startseite und API
  install.php         Installer, wird nach der Einrichtung gelöscht
  admin/              Anmeldung, Spielplanpflege, Import
  lib/                Anwendungscode
    import/           Adapter, Namensabgleich, Differ, Übernahme
    api/              OpenLigaDB-Übersetzung
    setup/            Umgebungsprüfung und Installation
db/                   Schema und Grunddaten, gehört nicht ins Dokumentenverzeichnis
tools/                Werkzeuge für den eigenen Rechner
tests/                Testsuite
docs/                 Dokumentation
```

## Lizenz und Daten

Der Code steht unter der Apache-Lizenz 2.0.

Die Spieldaten stammen aus fremden Quellen und stehen nicht darunter. Sie
werden mit niedriger Frequenz, manuell ausgelöst und mit Quellenangabe
verwendet. Vor einer Weiterveröffentlichung in größerem Umfang ist das
Datenbankherstellerrecht nach § 87a UrhG zu beachten.
