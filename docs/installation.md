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

## Bestehende Installation aktualisieren

Nach einem Update der Dateien muss die Datenbank nachziehen. Das geht genau
wie die Installation: hochladen, im Browser aufrufen, danach wieder entfernen.

1. Neue Dateien hochladen, **einschließlich `db/migrations/`** und
   `public_html/update.php`.
2. `https://deine-domain/update.php` aufrufen.
3. Mit dem Passwort aus der Installation anmelden.
4. Den angezeigten Stand prüfen, dann *Jetzt aktualisieren*.
5. `update.php` wieder entfernen — die Seite bietet es an.

### Warum das Passwort und nicht das Benutzerkonto

Gerade wenn eine Migration nötig ist, kann die Benutzertabelle noch fehlen
oder anders aussehen. Ein Zugang, der von dem abhängt, was er reparieren
soll, taugt nicht. `update.php` prüft deshalb gegen den
`admin_password_hash` aus `config.php`.

### Was die Seite zeigt

| Zustand | |
|---|---|
| erledigt | schon gelaufen, steht in `schema_migrations` |
| bereits vorhanden | der Zustand ist schon hergestellt — wird nur vermerkt, nicht ausgeführt |
| offen | wird beim Aktualisieren ausgeführt |
| blockiert | etwas steht im Weg; die Seite zeigt was, und was zu tun ist |

**Blockiert** heißt nicht kaputt. Beispiel: doppelte Mannschaftsnamen müssen
zusammengeführt werden, ehe der Name eindeutig werden kann. Die Seite zeigt
die betroffenen Zeilen und die nötigen SQL-Anweisungen zum Kopieren. Nach dem
Aufräumen in phpMyAdmin genügt ein Neuladen.

Es wird bis zur gesperrten Migration ausgeführt und dort angehalten — was
davor lief, bleibt bestehen.

### Auf der Kommandozeile

Wer SSH hat, kann dasselbe ohne Upload erledigen. Beide Wege benutzen
dieselbe Logik:

```bash
php tools/migrate.php --status    zeigt den Stand, ändert nichts
php tools/migrate.php --probe     zeigt, was laufen würde
php tools/migrate.php             führt aus
```

Die Zugangsdaten kommen aus `public_html/config.php`. Ohne Datei geht es auch
über Umgebungsvariablen:

```bash
MYSQL_HOST=localhost MYSQL_DB=... MYSQL_USER=... MYSQL_PASSWORD=... php tools/migrate.php
```

### Vorher sichern

Migrationen ändern die Struktur; ein Rückweg ist nicht eingebaut. Eine
Sicherung über die Hetzner-Konsole oder phpMyAdmin dauert eine Minute.

## Mailversand

Für Bestätigung und Passwort-Rücksetzung. Welche DNS-Einträge die Zustellung
entscheiden und wie man sie setzt, steht in
[mail-zustellung.md](mail-zustellung.md).

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
