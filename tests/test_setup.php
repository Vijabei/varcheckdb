<?php
declare(strict_types=1);

T::group('Requirements - Groessenangaben');

T::same(2 * 1024 * 1024, Requirements::bytes('2M'), 'M wird umgerechnet');
T::same(512 * 1024, Requirements::bytes('512K'), 'K wird umgerechnet');
T::same(1024 * 1024 * 1024, Requirements::bytes('1G'), 'G wird umgerechnet');
T::same(-1, Requirements::bytes('-1'), 'unbegrenzt bleibt unbegrenzt');
T::same(0, Requirements::bytes(''), 'leere Angabe ist null');
T::same(8388608, Requirements::bytes('8M'), 'ein realistischer Wert');

T::group('Requirements - Pruefliste');

$checks = Requirements::all();
T::ok(count($checks) > 10, 'es wird eine brauchbare Zahl an Punkten geprueft');

$levels = array_unique(array_column($checks, 'level'));
sort($levels);
T::same(['info', 'recommended', 'required'], $levels, 'alle drei Stufen kommen vor');

foreach ($checks as $check) {
    if (!array_key_exists('name', $check) || !array_key_exists('ok', $check)) {
        T::ok(false, 'ein Pruefpunkt ist unvollstaendig');
        break;
    }
}
T::ok(true, 'jeder Pruefpunkt hat Name und Ergebnis');

$names = array_column($checks, 'name');
foreach (['PHP-Version', 'Erweiterung pdo_mysql', 'Erweiterung dom', 'upload_max_filesize'] as $expected) {
    T::ok(in_array($expected, $names, true), sprintf('"%s" wird geprueft', $expected));
}

// Diese Testumgebung erfuellt die Voraussetzungen - sonst liefen die Tests nicht.
$blockers = array_filter(
    Requirements::blockers(),
    fn(array $c): bool => !str_contains($c['name'], 'Konfiguration')
);
T::same([], array_column($blockers, 'name'), 'die Testumgebung selbst hat keine harten Maengel');

T::group('Installer - SQL zerlegen');

$statements = Installer::statements((string)file_get_contents(ROOT . '/db/schema.mysql.sql'));
$creates = array_filter($statements, fn(string $s): bool => str_starts_with($s, 'CREATE TABLE'));

T::same(18, count($creates), 'alle 18 CREATE TABLE werden einzeln erkannt');
T::same(0, count(array_filter($statements, fn(string $s): bool => str_contains($s, '--'))),
    'Kommentarzeilen sind entfernt');
T::same(0, count(array_filter($statements, fn(string $s): bool => trim($s) === '')),
    'keine leeren Anweisungen');
T::ok(str_contains(implode("\n", $statements), 'FOREIGN KEY'), 'Fremdschluessel bleiben erhalten');

$seed = Installer::statements((string)file_get_contents(ROOT . '/db/seed.sql'));
T::same(5, count($seed), 'die Grunddaten bestehen aus 5 Anweisungen');
T::ok(str_contains($seed[0], 'INSERT INTO sources'), 'die erste legt die Quellen an');

T::group('Installer - Semikolon an heiklen Stellen');

// Genau die Faelle, wegen derer der Zerleger zeichenweise liest.
T::same(
    ["INSERT INTO t (a) VALUES ('x; y')"],
    Installer::statements("INSERT INTO t (a) VALUES ('x; y');"),
    'ein Semikolon in einer Zeichenkette beendet die Anweisung nicht'
);
T::same(
    ['SELECT 1'],
    Installer::statements("SELECT 1; -- und hier; noch eins\n"),
    'ein Semikolon in einem Zeilenkommentar ebenfalls nicht'
);
T::same(
    ['SELECT 1', 'SELECT 2'],
    Installer::statements("SELECT 1;\n/* dazwischen; kommentiert */\nSELECT 2;"),
    'Blockkommentare werden entfernt'
);
T::same(
    ["INSERT INTO t VALUES ('O''Brien')"],
    Installer::statements("INSERT INTO t VALUES ('O''Brien');"),
    'ein maskiertes Anfuehrungszeichen beendet die Zeichenkette nicht'
);
T::same(
    ['SELECT 1', 'SELECT 2'],
    Installer::statements('SELECT 1;;;SELECT 2;'),
    'leere Anweisungen fallen weg'
);
T::same([], Installer::statements("-- nur ein Kommentar\n"), 'eine reine Kommentardatei ergibt nichts');

T::group('Installer - Schema tatsaechlich einspielen');

// Gegen SQLite laeuft das MySQL-Schema nicht; geprueft wird die generierte
// Fassung, die im Testlauf ohnehin die Wahrheit ist.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$result = Installer::runSql($pdo, ROOT . '/db/schema.sqlite.sql');

T::ok($result['ok'], 'das Schema laeuft ohne Fehler durch' . ($result['ok'] ? '' : ': ' . $result['message']));
T::ok($result['executed'] >= 15, 'mindestens 15 Anweisungen ausgefuehrt');

$tables = array_column(
    $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC),
    'name'
);
$missing = array_diff(Installer::TABLES, $tables);
T::same([], array_values($missing), 'alle erwarteten Tabellen sind angelegt');

$seedResult = Installer::runSql($pdo, ROOT . '/db/seed.sql');
T::ok($seedResult['ok'], 'die Grunddaten laufen durch');
T::same(4, (int)$pdo->query('SELECT COUNT(*) FROM sources')->fetchColumn(), '4 Quellen eingetragen');

T::group('Installer - fehlerhafte Datei');

$broken = Installer::runSql($pdo, ROOT . '/db/gibtesnicht.sql');
T::ok(!$broken['ok'], 'eine fehlende Datei wird gemeldet');
T::ok(str_contains((string)$broken['message'], 'nicht gefunden'), 'mit brauchbarem Hinweis');

T::group('Installer - Konfiguration schreiben');

$values = [
    'created_at'          => '2026-08-23 20:00:00',
    'dsn'                 => Installer::dsn('localhost', 'meine_db'),
    'db_user'             => 'benutzer',
    // Genau die Zeichen, an denen eine naiv zusammengebaute Datei zerbricht.
    'db_password'         => 'a\'b"c\\d$e{f}',
    'site_name'           => 'vijabei.net Spieldaten',
    'base_url'            => 'https://vijabei.net',
    'timezone'            => 'Europe/Berlin',
    'attribution'         => 'Daten gepflegt von vijabei.net',
    'admin_password_hash' => password_hash('geheim', PASSWORD_DEFAULT),
];

$path = sys_get_temp_dir() . '/varcheckdb-config-test-' . bin2hex(random_bytes(4)) . '.php';
$written = Installer::writeConfig($path, $values);

T::ok($written['ok'], 'die Konfiguration wird geschrieben');

$loaded = include $path;
T::same($values['db_password'], $loaded['db']['password'], 'Sonderzeichen im Passwort ueberleben');
T::same($values['dsn'], $loaded['db']['dsn'], 'der DSN steht drin');
T::same('Europe/Berlin', $loaded['timezone'], 'die Zeitzone steht drin');
T::same(false, $loaded['debug'], 'die Fehlerausgabe ist ausgeschaltet');
T::ok(password_verify('geheim', $loaded['admin_password_hash']), 'das Adminpasswort laesst sich pruefen');
T::ok(!str_contains((string)file_get_contents($path), 'geheim'), 'das Klartextpasswort steht nicht in der Datei');

unlink($path);

$failed = Installer::writeConfig('/gibtesnicht/verzeichnis/config.php', $values);
T::ok(!$failed['ok'], 'ein nicht schreibbarer Pfad wird gemeldet');

T::group('Installer - DSN');

T::same('mysql:host=localhost;port=3306;dbname=meine_db;charset=utf8mb4',
    Installer::dsn('localhost', 'meine_db'), 'Standardport');
T::same('mysql:host=db.example;port=3307;dbname=x;charset=utf8mb4',
    Installer::dsn('db.example', 'x', 3307), 'abweichender Port');

T::group('Installer - Verbindungsfehler verstaendlich melden');

$result = Installer::connect('127.0.0.1', 'gibtesnicht', 'niemand', 'falsch', 1);
T::ok(!$result['ok'], 'eine unmoegliche Verbindung scheitert');
T::same(null, $result['pdo'], 'ohne Verbindung kein PDO');
T::ok($result['message'] !== '', 'es gibt eine Meldung');
T::ok(!str_contains($result['message'], 'falsch'), 'das Passwort steht nicht in der Meldung');

T::group('Schema - Reihenfolge der Tabellen');

$schema = (string)file_get_contents(ROOT . '/db/schema.mysql.sql');

// Jede per REFERENCES angesprochene Tabelle muss vorher angelegt sein.
// Sonst laeuft das Einspielen nur mit abgeschalteter Fremdschluesselpruefung,
// und das verdeckt echte Fehler im Schema.
preg_match_all('/CREATE TABLE (\w+) \((.*?)\n\) ENGINE/s', $schema, $blocks, PREG_SET_ORDER);

$created = [];
$forward = [];
foreach ($blocks as [, $table, $body]) {
    preg_match_all('/REFERENCES (\w+)/', $body, $refs);
    foreach ($refs[1] as $ref) {
        if ($ref !== $table && !in_array($ref, $created, true)) {
            $forward[] = $table . ' -> ' . $ref;
        }
    }
    $created[] = $table;
}

T::same(18, count($blocks), 'das Schema enthaelt 18 Tabellen');
T::same([], $forward, 'keine Tabelle verweist auf eine spaeter angelegte');
T::ok(!str_contains($schema, 'FOREIGN_KEY_CHECKS'),
    'die Fremdschluesselpruefung muss nicht abgeschaltet werden');

// Beide Schemafassungen muessen dieselben Tabellen kennen.
preg_match_all('/CREATE TABLE (\w+)/', (string)file_get_contents(ROOT . '/db/schema.sqlite.sql'), $sqlite);
sort($created);
$sqliteTables = $sqlite[1];
sort($sqliteTables);
T::same($created, $sqliteTables, 'MySQL- und SQLite-Fassung enthalten dieselben Tabellen');

$expectedTables = Installer::TABLES;
sort($expectedTables);
T::same($expectedTables, $created, 'Installer::TABLES deckt sich mit dem Schema');

T::group('Installer - Schemadateien finden');

$found = Installer::findSchemaDir(ROOT . '/public_html');
T::same(realpath(ROOT . '/db'), $found['dir'], 'db/ neben dem Dokumentenverzeichnis wird gefunden');

T::ok(Installer::hasSchema(ROOT . '/db'), 'beide Dateien liegen dort');
T::ok(!Installer::hasSchema(ROOT . '/tools'), 'ein Verzeichnis ohne die Dateien wird abgelehnt');
T::ok(!Installer::hasSchema('/gibtesnicht'), 'ein nicht vorhandenes Verzeichnis wird abgelehnt');

// Flacher Upload: install.php und db/ im selben Verzeichnis.
$flat = sys_get_temp_dir() . '/varcheckdb-flach-' . bin2hex(random_bytes(4));
mkdir($flat . '/db', 0770, true);
copy(ROOT . '/db/schema.mysql.sql', $flat . '/db/schema.mysql.sql');
copy(ROOT . '/db/seed.sql', $flat . '/db/seed.sql');
T::same(realpath($flat . '/db'), Installer::findSchemaDir($flat)['dir'], 'db/ im selben Verzeichnis wird auch gefunden');
unlink($flat . '/db/schema.mysql.sql');
unlink($flat . '/db/seed.sql');
rmdir($flat . '/db');
rmdir($flat);

$nothing = Installer::findSchemaDir('/gibtesnicht/irgendwo');
T::same(null, $nothing['dir'], 'ohne Dateien wird nichts gefunden');
T::ok(count($nothing['searched']) >= 3, 'die durchsuchten Pfade werden mitgeteilt');
T::same(0, count(array_filter($nothing['searched'], fn(string $p): bool => str_contains($p, '//'))),
    'in den Pfaden stehen keine doppelten Schraegstriche');

// Auch die Umgebungspruefung muss den Fall melden.
$check = null;
foreach (Requirements::all('/gibtesnicht/irgendwo') as $entry) {
    if (str_contains($entry['name'], 'Schemadateien')) { $check = $entry; break; }
}
T::ok($check !== null && !$check['ok'], 'Schritt 1 meldet fehlende Schemadateien');
// Auf den Inhalt pruefen, nicht auf den Wortlaut: der Hinweis muss die
// tatsaechlich durchsuchten Pfade enthalten, damit man weiss, wohin die
// Dateien gehoeren.
$searched = Installer::findSchemaDir('/gibtesnicht/irgendwo')['searched'];
$named = array_filter($searched, fn(string $p): bool => str_contains((string)$check['hint'], $p));
T::same(count($searched), count($named), 'der Hinweis nennt jeden durchsuchten Pfad');

T::group('Installer - Verschluesselung als Wahlmoeglichkeit');

T::same([], Installer::sslOptions(false), 'ohne Verschluesselung keine Optionen');
T::same([], Installer::sslOptions(false, '/pfad/ca.pem', true), 'abgewaehlt schlaegt alles andere');

$plain = Installer::sslOptions(true);
T::ok(array_key_exists(PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT, $plain), 'mit Verschluesselung wird die Pruefung gesetzt');
T::same(false, $plain[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT], 'ohne CA-Datei kann nicht geprueft werden');
T::ok(!array_key_exists(PDO::MYSQL_ATTR_SSL_CA, $plain), 'ohne Angabe keine CA-Datei');

$withCa = Installer::sslOptions(true, '/pfad/ca.pem', true);
T::same('/pfad/ca.pem', $withCa[PDO::MYSQL_ATTR_SSL_CA], 'die CA-Datei wird uebernommen');
T::same(true, $withCa[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT], 'mit CA-Datei wird geprueft');

$noVerify = Installer::sslOptions(true, '/pfad/ca.pem', false);
T::same(false, $noVerify[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT], 'die Pruefung laesst sich abwaehlen');

T::group('Installer - Optionen in der Konfiguration');

$rendered = Installer::renderConfig([
    'created_at' => 'jetzt',
    'dsn' => 'mysql:host=localhost', 'db_user' => 'u', 'db_password' => 'p',
    'db_options' => Installer::sslOptions(true, '/pfad/ca.pem', true),
    'site_name' => 's', 'base_url' => 'https://x', 'timezone' => 'UTC',
    'attribution' => 'a', 'admin_password_hash' => 'h',
]);
T::ok(str_contains($rendered, 'PDO::MYSQL_ATTR_SSL_CA'), 'die Optionen stehen als Konstantennamen in der Datei');
T::ok(str_contains($rendered, "'/pfad/ca.pem'"), 'mit dem Pfad zur CA-Datei');

$without = Installer::renderConfig([
    'created_at' => 'jetzt',
    'dsn' => 'mysql:host=localhost', 'db_user' => 'u', 'db_password' => 'p',
    'db_options' => [],
    'site_name' => 's', 'base_url' => 'https://x', 'timezone' => 'UTC',
    'attribution' => 'a', 'admin_password_hash' => 'h',
]);
T::ok(str_contains($without, "'options'  => [],"), 'ohne Verschluesselung bleibt die Liste leer');

// Die erzeugte Datei muss gueltiges PHP sein und die Optionen zurueckliefern.
$path = sys_get_temp_dir() . '/varcheckdb-opt-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($path, $rendered);
$loaded = include $path;
T::same('/pfad/ca.pem', $loaded['db']['options'][PDO::MYSQL_ATTR_SSL_CA] ?? null,
    'die geschriebene Datei liefert die Optionen zurueck');
unlink($path);


T::group('Installer - Pfadangaben des Admins aufloesen');

// Struktur eines typischen Webhosting-Kontos nachbauen:
//   Dateisystem: /.../usr/www/users/kunde/public_html/seite.de
//   im FTP:      /public_html/seite.de
$root = sys_get_temp_dir() . '/varcheckdb-pfade-' . bin2hex(random_bytes(4));
$install = $root . '/usr/www/users/kunde/public_html/seite.de';
$schema  = $root . '/usr/www/users/kunde/public_html/db';
mkdir($install, 0770, true);
mkdir($schema, 0770, true);
copy(ROOT . '/db/schema.mysql.sql', $schema . '/schema.mysql.sql');
copy(ROOT . '/db/seed.sql', $schema . '/seed.sql');

$expected = realpath($schema);

// Der volle Pfad muss selbstverstaendlich gehen.
T::same($expected, Installer::resolveSchemaDir($schema, $install)['dir'], 'der volle Pfad wird angenommen');

// Das ist der Fall, an dem es in der Praxis gescheitert ist: FTP-Programme
// zeigen einen gekuerzten Pfad, der im Dateisystem nicht existiert.
T::same($expected, Installer::resolveSchemaDir('/public_html/db', $install)['dir'],
    'der im FTP sichtbare Pfad wird als Endstueck erkannt');
T::same($expected, Installer::resolveSchemaDir('/public_html/db/', $install)['dir'],
    'auch mit Schraegstrich am Ende');
T::same($expected, Installer::resolveSchemaDir('/db', $install)['dir'],
    'auch ein kurzes Endstueck');

// Relative Angaben beziehen sich auf die Installation.
T::same($expected, Installer::resolveSchemaDir('../db', $install)['dir'], 'relativ nach oben');
T::same($expected, Installer::resolveSchemaDir('db', $install . '/../')['dir'], 'relativ im selben Verzeichnis');

// Eine relative Angabe darf niemals gegen das Arbeitsverzeichnis des
// Prozesses aufgeloest werden. Geprueft mit einer Installation, in deren
// Naehe es kein db/ gibt, waehrend im Arbeitsverzeichnis eines liegt:
// das Ergebnis muss leer bleiben statt das fremde Verzeichnis zu treffen.
$fremd = $root . '/woanders';
mkdir($fremd, 0770, true);
$oldCwd = getcwd();
chdir(ROOT);
$strayed = Installer::resolveSchemaDir('db', $fremd);
chdir((string)$oldCwd);
T::same(null, $strayed['dir'], 'eine relative Angabe trifft nicht das Arbeitsverzeichnis des Prozesses');
T::same(0, count(array_filter($strayed['tried'], fn(string $p): bool => $p === realpath(ROOT . '/db'))),
    'das Verzeichnis des Prozesses wird gar nicht erst geprueft');
rmdir($fremd);

T::same(null, Installer::resolveSchemaDir('/gibtesnicht/db', $install)['dir'], 'ein falscher Pfad bleibt falsch');
T::same(null, Installer::resolveSchemaDir('', $install)['dir'], 'eine leere Angabe ergibt nichts');
T::same([], Installer::resolveSchemaDir('', $install)['tried'], 'und probiert gar nichts erst aus');

$failed = Installer::resolveSchemaDir('/gibtesnicht/db', $install);
T::ok(count($failed['tried']) >= 3, 'im Fehlerfall werden die geprueften Stellen genannt');

// Die automatische Suche findet dieselbe Struktur ohne jede Eingabe.
T::same($expected, Installer::findSchemaDir($install)['dir'], 'die automatische Suche findet es auch');

// Und die Umgebungspruefung nimmt die Aufloesung ebenfalls vor.
$check = null;
foreach (Requirements::all($install, '/public_html/db') as $entry) {
    if (str_contains($entry['name'], 'Schemadateien')) { $check = $entry; break; }
}
T::ok($check !== null && $check['ok'], 'Schritt 1 akzeptiert den FTP-Pfad');
T::same($expected, $check['actual'], 'und zeigt den aufgeloesten Pfad an');

foreach ([$schema . '/schema.mysql.sql', $schema . '/seed.sql'] as $file) {
    unlink($file);
}
rmdir($schema);
rmdir($install);
foreach (['/usr/www/users/kunde/public_html', '/usr/www/users/kunde', '/usr/www/users', '/usr/www', '/usr'] as $dir) {
    @rmdir($root . $dir);
}
@rmdir($root);

T::group('Requirements - eigener Pfad wird angezeigt');

$own = null;
foreach (Requirements::all() as $entry) {
    if (str_contains($entry['name'], 'liegt in')) { $own = $entry; break; }
}
T::ok($own !== null, 'der eigene Pfad steht in der Liste');
T::same(realpath(ROOT . '/public_html'), $own['actual'], 'und ist der echte Pfad im Dateisystem');
T::ok(str_contains((string)$own['hint'], 'FTP'), 'mit dem Hinweis auf abweichende FTP-Pfade');
