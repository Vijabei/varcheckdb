<?php
declare(strict_types=1);

/**
 * Der Migrator, den sich Kommandozeile und Weboberflaeche teilen.
 *
 * Geprueft wird die Mechanik - Erkennen, Sperren, Vermerken - mit eigenen
 * Migrationsdateien. Die echten benutzen information_schema, das es in SQLite
 * nicht gibt; sie laufen in tests/integration_mysql.php gegen MariaDB.
 */

T::group('Migrator - Steuerzeilen lesen');

$marken = Migrator::steuerzeilen(<<<'SQL'
-- @erledigt-wenn: SELECT COUNT(*) FROM t
--                  WHERE x = 1
-- @pruefe: SELECT * FROM t WHERE kaputt = 1
-- @pruefe-hinweis: Erst aufraeumen:
--                    DELETE FROM t WHERE kaputt = 1;
--                    UPDATE t SET y = 2;

CREATE TABLE beispiel (id INT);
SQL);

T::ok(str_contains($marken['erledigt-wenn'], 'SELECT COUNT(*)'), 'die Erkennung wird gelesen');
T::ok(str_contains($marken['erledigt-wenn'], 'WHERE x = 1'), 'auch ueber mehrere Zeilen');
T::same('SELECT * FROM t WHERE kaputt = 1', $marken['pruefe'], 'die Sperrpruefung wird gelesen');

// Im Hinweis stehen SQL-Beispiele. Aneinandergehaengt waeren sie unlesbar.
T::ok(str_contains($marken['pruefe-hinweis'], "\n"), 'der Hinweis behaelt seine Zeilen');
T::same(3, count(explode("\n", $marken['pruefe-hinweis'])), 'alle drei Zeilen');

T::same([], Migrator::steuerzeilen('CREATE TABLE x (id INT);'), 'ohne Steuerzeilen kommt nichts');
T::same([], Migrator::steuerzeilen("-- nur ein Kommentar\nCREATE TABLE x (id INT);"),
    'ein gewoehnlicher Kommentar ist keine Steuerzeile');

/** Legt ein Verzeichnis mit Migrationsdateien an. */
function migrationen(array $dateien): string
{
    $ordner = sys_get_temp_dir() . '/varcheckdb-mig-' . bin2hex(random_bytes(4));
    mkdir($ordner, 0770, true);

    foreach ($dateien as $name => $inhalt) {
        file_put_contents($ordner . '/' . $name, $inhalt);
    }

    return $ordner;
}

function aufraeumen(string $ordner): void
{
    foreach (glob($ordner . '/*') ?: [] as $datei) {
        unlink($datei);
    }
    rmdir($ordner);
}

T::group('Migrator - Erkennen und Ausfuehren');

$pdo = fresh_db();
$ordner = migrationen([
    '2026-01-01-erste.sql' => "-- @erledigt-wenn: SELECT COUNT(*) FROM sqlite_master WHERE name = 'neu_a'\n\n"
        . "CREATE TABLE neu_a (id INTEGER PRIMARY KEY);\n",
    '2026-01-02-zweite.sql' => "-- @erledigt-wenn: SELECT COUNT(*) FROM sqlite_master WHERE name = 'neu_b'\n\n"
        . "CREATE TABLE neu_b (id INTEGER PRIMARY KEY);\n",
]);
$migrator = new Migrator($ordner);

T::same(2, count($migrator->dateien()), 'beide Dateien werden gefunden');
T::same('2026-01-01-erste.sql', basename($migrator->dateien()[0]), 'in Reihenfolge des Namens');

$stand = $migrator->stand($pdo);
T::same([Migrator::OFFEN, Migrator::OFFEN], array_column($stand, 'zustand'), 'beide sind offen');

$ergebnis = $migrator->ausfuehren($pdo);
T::same(2, $ergebnis['ausgefuehrt'], 'beide wurden ausgefuehrt');
T::same(0, $ergebnis['vermerkt'], 'keine nur vermerkt');
T::same(null, $ergebnis['abgebrochen'], 'nichts abgebrochen');
T::ok(Migrator::tabelleVorhanden($pdo, 'neu_a'), 'die erste Tabelle steht');
T::ok(Migrator::tabelleVorhanden($pdo, 'neu_b'), 'die zweite auch');

T::group('Migrator - ein zweiter Lauf ist folgenlos');

T::same([Migrator::ERLEDIGT, Migrator::ERLEDIGT], array_column($migrator->stand($pdo), 'zustand'),
    'beide gelten als erledigt');

$zweiter = $migrator->ausfuehren($pdo);
T::same(0, $zweiter['ausgefuehrt'], 'nichts erneut ausgefuehrt');
T::same(2, (int)Db::value('SELECT COUNT(*) FROM schema_migrations'), 'keine doppelten Eintraege');

aufraeumen($ordner);

T::group('Migrator - schon hergestellter Zustand wird nur vermerkt');

// Das ist der Fall einer frischen Installation: das Schema bringt alles mit.
$pdo = fresh_db();
$pdo->exec('CREATE TABLE neu_c (id INTEGER PRIMARY KEY)');

$ordner = migrationen([
    '2026-01-01-erste.sql' => "-- @erledigt-wenn: SELECT COUNT(*) FROM sqlite_master WHERE name = 'neu_c'\n\n"
        . "CREATE TABLE neu_c (id INTEGER PRIMARY KEY);\n",
]);
$migrator = new Migrator($ordner);

T::same(Migrator::VORHANDEN, $migrator->stand($pdo)[0]['zustand'], 'sie gilt als bereits vorhanden');

$ergebnis = $migrator->ausfuehren($pdo);
T::same(0, $ergebnis['ausgefuehrt'], 'sie wurde nicht ausgefuehrt');
T::same(1, $ergebnis['vermerkt'], 'nur vermerkt');
T::same(1, (int)Db::value('SELECT COUNT(*) FROM schema_migrations WHERE detected = 1'),
    'und als erkannt gekennzeichnet');

// Waere sie gelaufen, haette CREATE TABLE auf einer vorhandenen Tabelle gescheitert.
T::same(null, $ergebnis['abgebrochen'], 'nichts ist dabei gescheitert');

aufraeumen($ordner);

T::group('Migrator - Sperre haelt vor der Aenderung an');

$pdo = fresh_db();
$pdo->exec('CREATE TABLE kaputt (id INTEGER PRIMARY KEY, wert TEXT)');
$pdo->exec("INSERT INTO kaputt (wert) VALUES ('doppelt'), ('doppelt')");

$ordner = migrationen([
    '2026-01-01-erste.sql' => "-- @erledigt-wenn: SELECT COUNT(*) FROM sqlite_master WHERE name = 'neu_d'\n\n"
        . "CREATE TABLE neu_d (id INTEGER PRIMARY KEY);\n",
    '2026-01-02-blockiert.sql' =>
        "-- @erledigt-wenn: SELECT COUNT(*) FROM sqlite_master WHERE name = 'neu_e'\n"
        . "-- @pruefe: SELECT wert, COUNT(*) AS n FROM kaputt GROUP BY wert HAVING COUNT(*) > 1\n"
        . "-- @pruefe-hinweis: Erst die Doppel entfernen:\n"
        . "--                    DELETE FROM kaputt WHERE id = 2;\n\n"
        . "CREATE TABLE neu_e (id INTEGER PRIMARY KEY);\n",
    '2026-01-03-danach.sql' => "-- @erledigt-wenn: SELECT COUNT(*) FROM sqlite_master WHERE name = 'neu_f'\n\n"
        . "CREATE TABLE neu_f (id INTEGER PRIMARY KEY);\n",
]);
$migrator = new Migrator($ordner);

$stand = $migrator->stand($pdo);
T::same(1, count($stand[1]['blocker']), 'die Sperre wird erkannt');
T::ok(str_contains((string)$stand[1]['hinweis'], 'DELETE FROM kaputt'), 'mit dem Hinweis, was zu tun ist');
T::same([], $stand[0]['blocker'], 'die uebrigen sind frei');

$ergebnis = $migrator->ausfuehren($pdo);
T::same('2026-01-02-blockiert.sql', $ergebnis['abgebrochen'], 'es haelt bei der gesperrten an');
T::same(1, $ergebnis['ausgefuehrt'], 'die davor lief noch');
T::ok(Migrator::tabelleVorhanden($pdo, 'neu_d'), 'ihre Tabelle steht');
T::ok(!Migrator::tabelleVorhanden($pdo, 'neu_e'), 'die gesperrte wurde nicht angelegt');
T::ok(!Migrator::tabelleVorhanden($pdo, 'neu_f'), 'und die dahinter auch nicht');

// Nach dem Aufraeumen laeuft der Rest.
$pdo->exec('DELETE FROM kaputt WHERE id = 2');
$ergebnis = $migrator->ausfuehren($pdo);
T::same(null, $ergebnis['abgebrochen'], 'jetzt geht es durch');
T::same(2, $ergebnis['ausgefuehrt'], 'die beiden verbliebenen laufen');
T::ok(Migrator::tabelleVorhanden($pdo, 'neu_f'), 'auch die letzte');

aufraeumen($ordner);

T::group('Migrator - Fehler beim Ausfuehren');

$pdo = fresh_db();
$ordner = migrationen([
    '2026-01-01-kaputt.sql' => "-- @erledigt-wenn: SELECT COUNT(*) FROM sqlite_master WHERE name = 'nie'\n\n"
        . "CREATE TABLE zwischenschritt (id INTEGER PRIMARY KEY);\n"
        . "DIES IST KEIN SQL;\n",
]);
$migrator = new Migrator($ordner);

$meldungen = [];
$ergebnis = $migrator->ausfuehren($pdo, static function (string $art, string $text) use (&$meldungen): void {
    $meldungen[] = [$art, $text];
});

T::same('2026-01-01-kaputt.sql', $ergebnis['abgebrochen'], 'der Lauf bricht ab');
T::same('fehler', $meldungen[0][0], 'und meldet einen Fehler');
T::ok(str_contains($meldungen[0][1], 'Anweisung 2 von 2'), 'mit der Nummer der Anweisung');
T::ok(Migrator::tabelleVorhanden($pdo, 'zwischenschritt'),
    'was davor lief, bleibt bestehen - das muss die Meldung sagen');
T::same(0, (int)Db::value('SELECT COUNT(*) FROM schema_migrations'),
    'die gescheiterte Migration wird nicht als erledigt vermerkt');

aufraeumen($ordner);

T::group('Migrator - leeres Verzeichnis');

$leer = new Migrator(sys_get_temp_dir() . '/gibtesnicht-' . bin2hex(random_bytes(4)));
T::same([], $leer->dateien(), 'ohne Dateien kommt nichts');
T::same(0, $leer->ausfuehren(fresh_db())['ausgefuehrt'], 'und es wird nichts ausgefuehrt');
