<?php
declare(strict_types=1);

/**
 * Vollstaendiger Durchlauf gegen ein echtes MariaDB/MySQL.
 *
 * Laeuft nicht mit tests/run.php mit: die uebrigen Tests brauchen keine
 * Datenbank, und dieser hier legt Tabellen an und wieder ab.
 *
 *   MYSQL_HOST=127.0.0.1 MYSQL_DB=varcheckdb_test MYSQL_USER=BENUTZER MYSQL_PASSWORD=PASSWORT \
 *     php tests/integration_mysql.php
 *
 * Geprueft wird genau das, was der Installer auf dem Webspace tun wird, und
 * anschliessend ein echter Import derselben Daten - damit auch die Abweichungen
 * zwischen SQLite und MariaDB auffallen (Datumsformate, Fremdschluessel,
 * utf8mb4, AUTO_INCREMENT).
 *
 * Am Ende werden die angelegten Tabellen wieder entfernt, ausser bei --keep.
 */

require __DIR__ . '/bootstrap.php';

$host     = getenv('MYSQL_HOST') ?: '127.0.0.1';
$port     = (int)(getenv('MYSQL_PORT') ?: 3306);
$database = getenv('MYSQL_DB') ?: 'varcheckdb_test';
$user     = getenv('MYSQL_USER') ?: 'root';
$password = (string)(getenv('MYSQL_PASSWORD') ?: '');
$keep     = in_array('--keep', $argv, true);

echo "\nDurchlauf gegen {$user}@{$host}:{$port}/{$database}\n";

// ------------------------------------------------------- 1 Voraussetzungen

T::group('1 Voraussetzungen');

$blockers = array_filter(
    Requirements::blockers(),
    fn(array $c): bool => !str_contains($c['name'], 'Konfiguration')
);
T::same([], array_column($blockers, 'name'), 'die Umgebung erfuellt die Pflichtpunkte');

// ------------------------------------------------------------ 2 Datenbank

T::group('2 Datenbank');

$connection = Installer::connect($host, $database, $user, $password, $port);
T::ok($connection['ok'], 'Verbindung steht' . ($connection['ok'] ? ' (Server ' . $connection['server'] . ')' : ': ' . $connection['message']));

if (!$connection['ok']) {
    echo "\nOhne Verbindung geht es nicht weiter.\n";
    echo "Datenbank anlegen mit:\n";
    echo "  sudo mariadb -e \"CREATE DATABASE {$database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"\n\n";
    exit(1);
}

$pdo = $connection['pdo'];
Db::set($pdo);

$privileges = Installer::checkPrivileges($pdo);
T::ok($privileges['ok'], 'Rechte reichen aus' . ($privileges['ok'] ? '' : ': ' . $privileges['message']));

// Aufraeumen, falls ein frueherer Lauf abgebrochen ist.
$leftovers = Installer::existingTables($pdo);
if ($leftovers !== []) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($leftovers as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "    (Reste eines frueheren Laufs entfernt: " . count($leftovers) . " Tabellen)\n";
}

T::same([], Installer::existingTables($pdo), 'die Datenbank ist leer');

// --------------------------------------------------------- 3 Schema einspielen

T::group('3 Schema einspielen');

$schema = Installer::runSql($pdo, ROOT . '/db/schema.mysql.sql');
T::ok($schema['ok'], 'schema.mysql.sql laeuft durch' . ($schema['ok'] ? " ({$schema['executed']} Anweisungen)" : ': ' . $schema['message']));

if (!$schema['ok']) {
    echo "\nAbbruch.\n";
    exit(1);
}

T::same(Installer::TABLES, Installer::existingTables($pdo), 'alle 15 Tabellen sind da');

// Die Fremdschluessel muessen wirklich greifen, nicht nur deklariert sein.
$constraints = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
)->fetchColumn();
T::ok($constraints >= 14, sprintf('%d Fremdschluessel sind aktiv', $constraints));

$charset = (string)$pdo->query(
    "SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()"
)->fetchColumn();
T::ok(str_starts_with($charset, 'utf8mb4'), "die Datenbank steht auf utf8mb4 (ist: {$charset})");

$seed = Installer::runSql($pdo, ROOT . '/db/seed.sql');
T::ok($seed['ok'], 'seed.sql laeuft durch' . ($seed['ok'] ? '' : ': ' . $seed['message']));
T::same(4, (int)Db::value('SELECT COUNT(*) FROM sources'), '4 Quellen eingetragen');
T::same(2, (int)Db::value('SELECT COUNT(*) FROM competition_seasons'), '2 Wettbewerbe eingetragen');

// Ein Fremdschluessel muss auch tatsaechlich verweigern.
$rejected = false;
try {
    Db::insert('rounds', ['competition_season_id' => 999999, 'number' => 1, 'name' => 'Test']);
} catch (PDOException) {
    $rejected = true;
}
T::ok($rejected, 'ein ungueltiger Fremdschluessel wird abgewiesen');

// ------------------------------------------------------------- 4 Echter Import

T::group('4 Import derselben Daten wie im Testlauf');

$kicker = (new KickerJsonAdapter())->parse(file_get_contents(ROOT . '/tests/fixtures/kicker-sample.json'));
$matcher = new TeamMatcher();
foreach ($matcher->unresolved($kicker['rows']) as $entry) {
    $matcher->createTeam($entry['name']);
}
T::same(16, (int)Db::value('SELECT COUNT(*) FROM teams'), '16 Mannschaften angelegt');

// Umlaute muessen die Runde durch MariaDB unveraendert ueberstehen.
$umlaut = (string)Db::value('SELECT name FROM teams WHERE name LIKE ?', ['%dwest%']);
T::same('DJK Südwest Köln', $umlaut, 'Umlaute kommen unveraendert zurueck');

$csId = competition_season_id('frlw');
$src  = Db::one('SELECT id, priority FROM sources WHERE slug = ?', ['kicker']);

$diff = (new Differ($csId, (int)$src['priority'], 'Europe/Berlin'))->compare($kicker['rows'], $matcher);
T::same(24, $diff['summary']['create'], '24 Spiele neu');
T::same(2, $diff['summary']['ambiguous'], '2 mit abweichender Terminangabe');

$applied = (new Applier($csId, (int)$src['id'], 'Europe/Berlin'))->apply($diff['rows'], $matcher, [], 'integration.json');
T::same(24, $applied['created'], '24 Spiele angelegt');
T::same(24, (int)Db::value('SELECT COUNT(*) FROM matches'), '24 Zeilen in matches');

// DATETIME verhaelt sich in MariaDB anders als TEXT in SQLite.
T::same('2026-08-20 17:00:00', (string)Db::value('SELECT kickoff_utc FROM matches ORDER BY kickoff_utc LIMIT 1'),
    'der Zeitstempel kommt unveraendert zurueck');
T::same(8, (int)Db::value('SELECT COUNT(*) FROM matches WHERE status = ?', ['finished']), '8 Spiele beendet');

T::group('5 Zweiter Lauf und Ueberschreibschutz');

$diff2 = (new Differ($csId, (int)$src['priority'], 'Europe/Berlin'))->compare($kicker['rows'], $matcher);
T::same(24, $diff2['summary']['unchanged'], 'derselbe Import aendert nichts');
T::same(0, $diff2['summary']['create'], 'keine Duplikate - der Unique-Key greift');

$matchId = (int)Db::value('SELECT id FROM matches ORDER BY kickoff_utc LIMIT 1');
Db::update('matches', $matchId, ['kickoff_utc' => '2026-08-21 12:30:00', 'kickoff_is_confirmed' => 1]);
FieldSource::set($matchId, 'kickoff_utc', source_id('manual'), true);

$diff3 = (new Differ($csId, (int)$src['priority'], 'Europe/Berlin'))->compare($kicker['rows'], $matcher);
$row = null;
foreach ($diff3['rows'] as $candidate) {
    if (($candidate['match_id'] ?? null) === $matchId) { $row = $candidate; break; }
}
T::ok($row !== null && isset($row['protected']['kickoff_utc']), 'die manuelle Korrektur ist geschuetzt');

(new Applier($csId, (int)$src['id'], 'Europe/Berlin'))->apply($diff3['rows'], $matcher);
T::same('2026-08-21 12:30:00', (string)Db::value('SELECT kickoff_utc FROM matches WHERE id = ?', [$matchId]),
    'sie ueberlebt den zweiten Import');

T::group('6 Gegenquelle worldfootball');

$wf = (new WorldfootballHtmlAdapter())->parse(file_get_contents(ROOT . '/tests/fixtures/worldfootball-cp1252.html'));
foreach ($matcher->unresolved($wf['rows']) as $entry) {
    $matcher->addAlias($entry['suggestions'][0]['team_id'], $entry['name'], source_id('worldfootball'));
}
T::same(0, count($matcher->unresolved($wf['rows'])), 'alle worldfootball-Namen sind zugeordnet');
T::same(6, (int)Db::value('SELECT COUNT(*) FROM team_aliases'), '6 Aliase gespeichert');

$alias = (string)Db::value('SELECT alias FROM team_aliases WHERE alias LIKE ?', ['%Spoho%']);
T::same('Vorwärts Spoho 98', $alias, 'auch der Alias haelt den Umlaut');

$wfSrc = Db::one('SELECT id, priority FROM sources WHERE slug = ?', ['worldfootball']);
$check = (new Differ($csId, (int)$wfSrc['priority'], 'Europe/Berlin'))->compare($wf['rows'], $matcher);
T::same(0, $check['summary']['skip'], 'alle Spiele lassen sich zuordnen');

T::group('7 Konfiguration schreiben');

$configPath = sys_get_temp_dir() . '/varcheckdb-integration-config.php';
$written = Installer::writeConfig($configPath, [
    'created_at'          => date('d.m.Y H:i'),
    'dsn'                 => Installer::dsn($host, $database, $port),
    'db_user'             => $user,
    'db_password'         => $password,
    'site_name'           => 'vijabei.net Spieldaten',
    'base_url'            => 'https://vijabei.net',
    'timezone'            => 'Europe/Berlin',
    'attribution'         => 'Daten gepflegt von vijabei.net',
    'admin_password_hash' => password_hash('integrationstest', PASSWORD_DEFAULT),
]);
T::ok($written['ok'], 'config.php wird geschrieben');

// Die geschriebene Konfiguration muss auch wirklich zur Datenbank fuehren.
$config = include $configPath;
$fresh = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
T::same(24, (int)$fresh->query('SELECT COUNT(*) FROM matches')->fetchColumn(),
    'mit der geschriebenen Konfiguration laesst sich verbinden und lesen');
unlink($configPath);

// ------------------------------------------------------------------ Aufraeumen

if (!$keep) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (Installer::TABLES as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    T::same([], Installer::existingTables($pdo), 'die Testtabellen sind wieder entfernt');
} else {
    echo "\n    (--keep: die Tabellen bleiben stehen)\n";
}

echo "\n" . str_repeat('-', 60) . "\n";
if (T::$failures === []) {
    printf("\033[32m%d Pruefungen bestanden - der Weg funktioniert auf MariaDB.\033[0m\n\n", T::$passed);
    exit(0);
}
printf("\033[31m%d von %d fehlgeschlagen:\033[0m\n", count(T::$failures), T::$passed + count(T::$failures));
foreach (T::$failures as $failure) {
    echo '  - ' . $failure . "\n";
}
echo "\n";
exit(1);
