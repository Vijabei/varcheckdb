<?php
declare(strict_types=1);

/**
 * Testumgebung: laedt den Anwendungscode und legt eine frische
 * In-Memory-Datenbank an. Kein PHPUnit, keine Abhaengigkeiten.
 */

define('ROOT', dirname(__DIR__));

require_once ROOT . '/public_html/lib/db.php';
require_once ROOT . '/public_html/lib/repo.php';
require_once ROOT . '/public_html/lib/editor.php';
require_once ROOT . '/public_html/lib/competitions.php';
require_once ROOT . '/public_html/lib/users.php';
require_once ROOT . '/public_html/lib/access.php';
require_once ROOT . '/public_html/lib/venues.php';
require_once ROOT . '/public_html/lib/auth.php';
require_once ROOT . '/public_html/lib/mail.php';
require_once ROOT . '/public_html/lib/api/OpenLigaDbApi.php';
require_once ROOT . '/public_html/lib/normalize.php';
require_once ROOT . '/public_html/lib/encoding.php';
require_once ROOT . '/public_html/lib/import/Adapter.php';
require_once ROOT . '/public_html/lib/import/FieldSource.php';
require_once ROOT . '/public_html/lib/import/KickerJsonAdapter.php';
require_once ROOT . '/public_html/lib/import/CsvAdapter.php';
require_once ROOT . '/public_html/lib/import/AdapterFactory.php';
require_once ROOT . '/public_html/lib/import/Batch.php';
require_once ROOT . '/public_html/lib/import/WorldfootballHtmlAdapter.php';
require_once ROOT . '/public_html/lib/import/TeamMatcher.php';
require_once ROOT . '/public_html/lib/import/Differ.php';
require_once ROOT . '/public_html/lib/import/Applier.php';
require_once ROOT . '/public_html/lib/setup/Requirements.php';
require_once ROOT . '/public_html/lib/setup/Installer.php';
require_once ROOT . '/public_html/lib/setup/Migrator.php';

final class T
{
    public static int $passed = 0;
    public static array $failures = [];
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n  " . $name . "\n";
    }

    public static function ok(bool $condition, string $description): void
    {
        if ($condition) {
            self::$passed++;
            echo "    \033[32m+\033[0m " . $description . "\n";

            return;
        }

        self::$failures[] = self::$group . ' / ' . $description;
        echo "    \033[31mX " . $description . "\033[0m\n";
    }

    public static function same(mixed $expected, mixed $actual, string $description): void
    {
        $equal = $expected === $actual;
        self::ok($equal, $description . ($equal ? '' : sprintf(
            "\n        erwartet: %s\n        erhalten: %s",
            var_export($expected, true),
            var_export($actual, true)
        )));
    }

    public static function throws(callable $fn, string $description): void
    {
        try {
            $fn();
            self::ok(false, $description . ' (keine Ausnahme geworfen)');
        } catch (Throwable) {
            self::ok(true, $description);
        }
    }
}

/**
 * Frische Datenbank mit Schema und Grunddaten.
 *
 * $seed spielt zusaetzlich zu den Grunddaten die Beispielligen aus
 * tests/fixtures/beispielligen.sql ein. Die Grunddaten selbst enthalten nur
 * noch die Quellen; Ligen legt in der Anwendung jeder selbst an.
 */
function fresh_db(bool $seed = true): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(file_get_contents(ROOT . '/db/schema.sqlite.sql'));

    $pdo->exec(file_get_contents(ROOT . '/db/seed.sql'));

    if ($seed) {
        $pdo->exec(file_get_contents(ROOT . '/tests/fixtures/beispielligen.sql'));
    }

    Db::set($pdo);

    return $pdo;
}

/**
 * Legt einen Spielbestand an und gibt Wettbewerb und Zuordner zurueck.
 *
 * Steht hier und nicht in einer einzelnen Testdatei, weil mehrere Tests
 * einen gefuellten Spielplan brauchen - und weil ein Einzellauf
 * (php tests/run.php venues) sonst an der fehlenden Funktion scheitert.
 */
function csv_fixture(): array
{
    fresh_db();

    $parsed = (new KickerJsonAdapter())->parse(
        file_get_contents(ROOT . '/tests/fixtures/kicker-sample.json')
    );

    $matcher = new TeamMatcher();
    foreach ($matcher->unresolved($parsed['rows']) as $entry) {
        $matcher->createTeam($entry['name']);
    }

    $csId = competition_season_id('frlw');
    $kicker = Db::one('SELECT id, priority FROM sources WHERE slug = ?', ['kicker']);
    $diff = (new Differ($csId, (int)$kicker['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);
    (new Applier($csId, (int)$kicker['id'], 'Europe/Berlin'))->apply($diff['rows'], $matcher);

    return [$csId, $matcher];
}

function source_id(string $slug): int
{
    return (int)Db::value('SELECT id FROM sources WHERE slug = ?', [$slug]);
}

function competition_season_id(string $shortcut): int
{
    return (int)Db::value('SELECT id FROM competition_seasons WHERE shortcut = ?', [$shortcut]);
}
