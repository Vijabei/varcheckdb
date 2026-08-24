<?php
declare(strict_types=1);

/**
 * Bringt eine bestehende Datenbank auf den aktuellen Stand.
 *
 * Dasselbe wie public_html/update.php, nur fuer die Kommandozeile. Die Logik
 * steht in Migrator; beide Wege sollen sich nicht auseinanderentwickeln.
 *
 *   php tools/migrate.php --status     zeigt den Stand, aendert nichts
 *   php tools/migrate.php --probe      zeigt, was laufen wuerde
 *   php tools/migrate.php              fuehrt aus
 *
 * Die Zugangsdaten kommen aus public_html/config.php. Alternativ:
 *
 *   MYSQL_HOST=... MYSQL_DB=... MYSQL_USER=... MYSQL_PASSWORD=... php tools/migrate.php
 */

const WURZEL = __DIR__ . '/..';

require WURZEL . '/public_html/lib/setup/Migrator.php';

$argumente = array_slice($argv, 1);
$nurStatus = in_array('--status', $argumente, true);
$nurProbe  = in_array('--probe', $argumente, true);

function verbinden(): PDO
{
    $host = getenv('MYSQL_HOST');

    if ($host !== false) {
        return new PDO(
            Installer::dsn($host, (string)getenv('MYSQL_DB'), (int)(getenv('MYSQL_PORT') ?: 3306)),
            (string)getenv('MYSQL_USER'),
            (string)(getenv('MYSQL_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    $datei = WURZEL . '/public_html/config.php';

    if (!is_file($datei)) {
        fwrite(STDERR, "Keine Zugangsdaten gefunden.\n\n"
            . "Entweder public_html/config.php anlegen oder MYSQL_HOST, MYSQL_DB,\n"
            . "MYSQL_USER und MYSQL_PASSWORD setzen.\n");
        exit(2);
    }

    $config = require $datei;

    return new PDO(
        $config['db']['dsn'],
        $config['db']['user'] ?? null,
        $config['db']['password'] ?? null,
        ($config['db']['options'] ?? []) + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

/** Gibt Abfrageergebnisse als Tabelle aus. */
function zeigeZeilen(array $zeilen, string $einzug = '    '): void
{
    if ($zeilen === []) {
        return;
    }

    $spalten = array_keys($zeilen[0]);
    $breite = [];

    foreach ($spalten as $s) {
        $breite[$s] = max(
            mb_strlen((string)$s),
            max(array_map(static fn(array $z): int => mb_strlen((string)($z[$s] ?? '')), $zeilen))
        );
    }

    $zeile = static fn(array $werte): string => $einzug . implode('  ', array_map(
        static fn(string $s): string => str_pad((string)($werte[$s] ?? ''), $breite[$s]),
        $spalten
    ));

    echo $zeile(array_combine($spalten, $spalten)), "\n";
    echo $einzug, str_repeat('-', array_sum($breite) + 2 * (count($spalten) - 1)), "\n";
    foreach ($zeilen as $z) {
        echo $zeile($z), "\n";
    }
}

try {
    $pdo = verbinden();
} catch (PDOException $e) {
    fwrite(STDERR, 'Verbindung fehlgeschlagen: ' . $e->getMessage() . "\n");
    exit(2);
}

printf("Datenbank: %s\n\n", (string)$pdo->query('SELECT DATABASE()')->fetchColumn());

if (!Migrator::eingerichtet($pdo)) {
    fwrite(STDERR, "In dieser Datenbank steht noch kein Schema.\n\n"
        . "Migrationen bringen eine bestehende Installation auf den neuen Stand;\n"
        . "eine leere Datenbank richtet der Installer ein: public_html/install.php\n");
    exit(2);
}

$migrator = new Migrator(WURZEL . '/db/migrations');

if ($migrator->dateien() === []) {
    echo "Keine Migrationsdateien gefunden.\n";
    exit(0);
}

// --- Nur ansehen
if ($nurStatus || $nurProbe) {
    $offen = 0;
    $vorhanden = 0;

    foreach ($migrator->stand($pdo) as $eintrag) {
        printf("  [%-10s] %s\n", match ($eintrag['zustand']) {
            Migrator::ERLEDIGT  => 'erledigt',
            Migrator::VORHANDEN => 'vorhanden',
            default             => $eintrag['blocker'] !== [] ? 'blockiert' : 'offen',
        }, $eintrag['name']);

        if ($eintrag['zustand'] === Migrator::VORHANDEN) {
            $vorhanden++;
        } elseif ($eintrag['zustand'] === Migrator::OFFEN) {
            $offen++;

            if ($eintrag['blocker'] !== []) {
                echo "\n";
                zeigeZeilen($eintrag['blocker']);
                if ($eintrag['hinweis'] !== null) {
                    echo "\n";
                    foreach (explode("\n", $eintrag['hinweis']) as $z) {
                        echo '    ', $z, "\n";
                    }
                }
                echo "\n";
            }
        }
    }

    printf("\n%d offen, %d bereits vorhanden.\n", $offen, $vorhanden);
    echo $offen > 0
        ? "Ausfuehren mit: php tools/migrate.php\n"
        : "Die Datenbank ist auf dem aktuellen Stand.\n";
    exit(0);
}

// --- Ausfuehren
$ergebnis = $migrator->ausfuehren($pdo, static function (string $art, string $text, array $zeilen): void {
    if ($art === 'bericht') {
        echo "\n";
        zeigeZeilen($zeilen);
        echo "\n";

        return;
    }

    if ($art === 'blockiert') {
        printf("\n  %s kann nicht laufen:\n\n", $text);
        zeigeZeilen($zeilen);

        return;
    }

    if ($art === 'fehler') {
        echo "\n";
        foreach (explode("\n", $text) as $z) {
            echo '  ', $z, "\n";
        }

        return;
    }

    printf("  [%-10s] %s\n", $art, $text);
});

if ($ergebnis['abgebrochen'] !== null) {
    // Den Hinweis der blockierten Migration nachreichen.
    foreach ($migrator->stand($pdo) as $eintrag) {
        if ($eintrag['name'] === $ergebnis['abgebrochen'] && $eintrag['hinweis'] !== null) {
            echo "\n";
            foreach (explode("\n", $eintrag['hinweis']) as $z) {
                echo '  ', $z, "\n";
            }
        }
    }

    printf("\n  Abgebrochen bei %s. Was davor lief, bleibt bestehen.\n", $ergebnis['abgebrochen']);
    exit(1);
}

echo "\n";

if ($ergebnis['vermerkt'] > 0) {
    printf("%d Migrationen waren bereits vorhanden und wurden nur vermerkt.\n", $ergebnis['vermerkt']);
}

printf("%s\n", $ergebnis['ausgefuehrt'] > 0
    ? sprintf('%d Migrationen ausgefuehrt. Die Datenbank ist auf dem aktuellen Stand.', $ergebnis['ausgefuehrt'])
    : 'Nichts auszufuehren. Die Datenbank ist auf dem aktuellen Stand.');
