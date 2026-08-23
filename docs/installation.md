# Installation auf vijabei.net

## Voraussetzungen

- PHP 8.1 oder neuer mit `pdo_mysql`, `dom`, `mbstring`, `json`
- Eine leere MariaDB/MySQL-Datenbank, in der Hetzner-Konsole angelegt
- `upload_max_filesize` mindestens 2 MB

Der Installer prueft das alles selbst und benennt, was fehlt.

## Ablauf

1. Den Inhalt von `public_html/` auf den Webspace laden, dazu den Ordner `db/`.
   Bevorzugt eine Ebene ueber dem Dokumentenverzeichnis:

   ```text
   /
   ├── db/
   │   ├── schema.mysql.sql
   │   └── seed.sql
   └── public_html/          <- das Dokumentenverzeichnis
       ├── install.php
       ├── lib/
       └── admin/
   ```

   `db/` gehoert nach Moeglichkeit **ausserhalb** des Dokumentenverzeichnisses:
   die Schemadateien gehen niemanden etwas an.

   Weil die Verzeichnisstruktur je nach Hoster anders aussieht, sucht der
   Installer an mehreren Stellen — eine Ebene darueber, im selben Verzeichnis,
   zwei Ebenen darueber, in `setup/db`. Findet er nichts, meldet er das in
   Schritt 1 mit allen durchsuchten Pfaden und bietet ein Eingabefeld an, in
   dem der Pfad von Hand eingetragen und sofort geprueft werden kann.

2. In der Hetzner-Konsole eine leere Datenbank anlegen und die Zugangsdaten
   notieren.

3. `https://vijabei.net/install.php` aufrufen und den fuenf Schritten folgen.

4. Am Ende auf **Installer loeschen** klicken. Falls das nicht klappt,
   `install.php` per SFTP entfernen.

## Was der Installer prueft

**Schritt 1 - Voraussetzungen.** PHP-Version, alle benoetigten Erweiterungen,
`upload_max_filesize` und `post_max_size`, ob das Verzeichnis fuer `config.php`
beschreibbar ist und ob die Schemadateien auffindbar sind. Fehlt ein
Pflichtpunkt, geht es nicht weiter — ausser beim Pfad zu den Schemadateien,
der sich an Ort und Stelle nachtragen laesst.

Zu `post_max_size`: ist der Wert kleiner als `upload_max_filesize`, bricht ein
Upload ohne Fehlermeldung ab. Deshalb wird das Verhaeltnis geprueft, nicht nur
der einzelne Wert.

**Schritt 2 - Datenbank.** Der Installer verbindet sich tatsaechlich und
probiert die Rechte praktisch aus, statt GRANT-Zeilen zu lesen: Tabelle
anlegen, hineinschreiben, lesen, aendern, loeschen. Dabei wird ein Wort mit
Umlaut geschrieben und wieder gelesen — kommt es veraendert zurueck, steht die
Datenbank nicht auf `utf8mb4`, und das wuerde spaeter jeden Vereinsnamen
beschaedigen.

Liegen in der Datenbank schon Tabellen der Anwendung, bricht der Installer ab,
statt vorhandene Daten zu ueberschreiben.

**Verschluesselung.** TLS zur Datenbank ist eine Moeglichkeit, keine Vorgabe:
viele Hoster bieten es nicht an, weil die Datenbank ohnehin ueber den lokalen
Socket erreicht wird. Ohne Haken wird unverschluesselt verbunden.

Mit Haken laesst sich zusaetzlich ein CA-Zertifikat angeben. Ohne CA-Datei ist
die Verbindung zwar verschluesselt, aber nicht authentifiziert — der Installer
schaltet die Zertifikatspruefung dann selbst ab, weil sie ohne CA nicht
moeglich ist.

Ob die Verbindung tatsaechlich verschluesselt ist, liest der Installer
anschliessend per `SHOW STATUS LIKE 'Ssl_cipher'` beim Server nach und meldet
es. Ist der Haken gesetzt, die Verbindung aber unverschluesselt, wird das
ausdruecklich gesagt statt stillschweigend hingenommen. Die Installation laeuft
in dem Fall weiter — unverschluesselt ist auf einem lokalen Socket kein
Problem.

Die gewaehlten Optionen landen in `config.php` unter `db.options` und werden im
Betrieb von `Db::connect()` verwendet.

**Schritt 3 - Webseite.** Name, Adresse, Zeitzone, Quellenhinweis und das
Adminpasswort (mindestens 10 Zeichen, wird nur als Hash gespeichert).

**Schritt 4 - Installation.** Schema und Grunddaten werden Anweisung fuer
Anweisung eingespielt, damit im Fehlerfall benannt werden kann, welche
gescheitert ist. Danach wird `config.php` geschrieben und sofort gegengelesen.

**Schritt 5 - Fertig.** Der Installer bietet an, sich selbst zu loeschen.

## Sicherheit

- Eine vorhandene `config.php` wird nie ueberschrieben. Der Installer
  verweigert dann den Dienst, per GET wie per POST.
- Jedes Formular traegt ein Sitzungstoken.
- Datenbank- und Adminpasswort erscheinen nie in der ausgegebenen Seite,
  auch nicht in Fehlermeldungen.
- Die Zugangsdaten werden aus der Sitzung entfernt, sobald `config.php` steht.
- `config.php` wird mit Rechten 0640 angelegt.
- Nach der Installation muss `install.php` verschwinden.

## Von Hand statt mit dem Installer

```bash
mysql -u BENUTZER -p DATENBANK < db/schema.mysql.sql
mysql -u BENUTZER -p DATENBANK < db/seed.sql
cp public_html/config.example.php public_html/config.php
php -r 'echo password_hash("dein-passwort", PASSWORD_DEFAULT), "\n";'
```

Den Hash in `config.php` bei `admin_password_hash` eintragen, Datenbankzugang
ergaenzen, `install.php` loeschen.

## Lokale Entwicklung

```bash
sudo apt install -y php-cli php-sqlite3 php-mysql php-xml php-mbstring
php tests/run.php
```

Die Tests laufen gegen eine SQLite-Datenbank im Speicher und brauchen weder
MariaDB noch einen Webserver.
