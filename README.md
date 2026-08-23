# varcheckdb

Eigene Spieldatenbank für Fußballwettbewerbe, die von den großen Portalen
schlecht bedient werden — vor allem Frauen- und Amateurligen. Sie speist sich
aus externen Quellen, wird von Hand gepflegt und gibt die Daten als JSON aus,
wahlweise im eigenen Format oder in dem von OpenLigaDB.

Läuft auf gewöhnlichem PHP-Webhosting: PHP 8.1, MariaDB, kein Composer, kein
Node, kein Build-Schritt.

## Warum

Die naheliegenden Wege tragen nicht:

- **FUSSBALL.DE** liefert im Widget zwar stabile IDs, aber verschleierte
  Schrift statt Namen und leere Felder statt Datum und Uhrzeit.
- **OpenLigaDB** führt weder die Frauen-Regionalliga West noch die
  Frauen-Westfalenliga.
- **worldfootball.net** beantwortet jeden nicht-browserartigen Zugriff mit
  einer Cloudflare-Prüfung.

Also eine eigene Datenbank, befüllt aus Dateien, die der Admin auf seinem
eigenen Rechner erzeugt. Der Server ruft nirgends von sich aus etwas ab.

## Grundsätze

**Die Datenbank ist die Wahrheit.** Ein Import setzt nie unmittelbar Fakten,
sondern erzeugt eine Vorschau, die bestätigt wird.

**Was du bestätigst, bleibt.** Jedes Feld merkt sich seine Herkunft. Eine
Korrektur von Hand überlebt jeden weiteren Import derselben Quelle.

**`null` heißt „keine Aussage", nicht „leer".** Sagt eine Quelle zu einem Feld
nichts, bleibt der bestehende Wert stehen, statt gelöscht zu werden.

**Ein Spiel gehört zu seinem Spieltag, unabhängig vom Termin.** Ein vom
Ostersamstag in den Mai verlegtes Spiel behält seinen Spieltag.

## Datenquellen

| Quelle | Rolle | Abruf |
|---|---|---|
| kicker.de | Primärquelle | `tools/fetch_kicker.py`, lokal |
| worldfootball.net | Gegenprüfung | Seite im Browser speichern, hochladen |
| CSV | Massenkorrektur | Export → Tabellenkalkulation → Import |

Einzelheiten, geprüfte Endpunkte und die Eigenheiten jeder Quelle stehen in
[docs/datenquellen.md](docs/datenquellen.md).

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
