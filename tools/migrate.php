<?php
declare(strict_types=1);

/**
 * Bringt eine bestehende Datenbank auf den aktuellen Stand.
 *
 * Fragt zuerst ab, was schon da ist, und fuehrt nur aus, was fehlt. Eine
 * Migration, die auf eine unpassende Datenbank trifft, richtet mehr Schaden
 * an als sie behebt - deshalb erkennt das Werkzeug den Ist-Stand, statt sich
 * darauf zu verlassen, dass jemand die richtigen Dateien in der richtigen
 * Reihenfolge einspielt.
 *
 * Aufruf im Projektverzeichnis:
 *
 *   php tools/migrate.php --status     zeigt den Stand, aendert nichts
 *   php tools/migrate.php --probe      zeigt, was laufen wuerde
 *   php tools/migrate.php              fuehrt aus
 *
 * Die Zugangsdaten kommen aus public_html/config.php. Alternativ:
 *
 *   MYSQL_HOST=... MYSQL_DB=... MYSQL_USER=... MYSQL_PASSWORD=... php tools/migrate.php
 *
 * Steuerzeilen in einer Migrationsdatei:
 *
 *   -- @erledigt-wenn: <Abfrage>   liefert sie eine Zahl > 0, gilt die
 *                                  Migration als bereits erledigt
 *   -- @pruefe: <Abfrage>          liefert sie Zeilen, wird nicht ausgefuehrt
 *   -- @pruefe-hinweis: <Text>     was dann zu tun ist
 */

const WURZEL = __DIR__ . '/..';

require WURZEL . '/public_html/lib/setup/Installer.php';

$argumente = array_slice($argv, 1);
$nurStatus = in_array('--status', $argumente, true);
$nurProbe  = in_array('--probe', $argumente, true);

// ------------------------------------------------------------- Verbindung

function verbinden(): PDO
{
    $host = getenv('MYSQL_HOST');

    if ($host !== false) {
        $dsn = Installer::dsn(
            $host,
            (string)getenv('MYSQL_DB'),
            (int)(getenv('MYSQL_PORT') ?: 3306)
        );

        return new PDO($dsn, (string)getenv('MYSQL_USER'), (string)(getenv('MYSQL_PASSWORD') ?: ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    $datei = WURZEL . '/public_html/config.php';

    if (!is_file($datei)) {
        fwrite(STDERR, "Keine Zugangsdaten gefunden.\n\n"
            . "Entweder public_html/config.php anlegen oder die Umgebungsvariablen\n"
            . "MYSQL_HOST, MYSQL_DB, MYSQL_USER und MYSQL_PASSWORD setzen.\n");
        exit(2);
    }

    $config = require $datei;

    return new PDO(
        $config['db']['dsn'],
        $config['db']['user'] ?? null,
        $config['db']['password'] ?? null,
        ($config['db']['options'] ?? []) + [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

// ------------------------------------------------------------ Hilfsmittel

/** Liest die Steuerzeilen aus dem Kopf einer Migrationsdatei. */
function steuerzeilen(string $inhalt): array
{
    $marken = [];
    $aktuell = null;

    foreach (preg_split('/\R/', $inhalt) ?: [] as $zeile) {
        if (!str_starts_with(ltrim($zeile), '--')) {
            break;
        }

        $text = ltrim(ltrim($zeile), '- ');

        if (preg_match('/^@([a-z-]+):\s*(.*)$/', $text, $m) === 1) {
            $aktuell = $m[1];
            $marken[$aktuell] = $m[2];
        } elseif ($aktuell !== null && $text !== '') {
            // Zeilenumbruch erhalten: in den Hinweisen stehen SQL-Beispiele,
            // die aneinandergehaengt unlesbar werden.
            $marken[$aktuell] .= "\n" . $text;
        } else {
            $aktuell = null;
        }
    }

    return array_map('trim', $marken);
}

function tabelleVorhanden(PDO $pdo, string $name): bool
{
    try {
        $pdo->query('SELECT 1 FROM ' . $name . ' LIMIT 1');

        return true;
    } catch (PDOException) {
        return false;
    }
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
            mb_strlen($s),
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

// ----------------------------------------------------------------- Ablauf

try {
    $pdo = verbinden();
} catch (PDOException $e) {
    fwrite(STDERR, 'Verbindung fehlgeschlagen: ' . $e->getMessage() . "\n");
    exit(2);
}

$datenbank = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
printf("Datenbank: %s\n\n", $datenbank);

// Ist ueberhaupt schon ein Schema da?
if (!tabelleVorhanden($pdo, 'competitions')) {
    fwrite(STDERR, "In dieser Datenbank steht noch kein Schema.\n\n"
        . "Migrationen bringen eine bestehende Installation auf den neuen Stand;\n"
        . "eine leere Datenbank richtet der Installer ein: public_html/install.php\n");
    exit(2);
}

if (!tabelleVorhanden($pdo, 'schema_migrations')) {
    if ($nurStatus || $nurProbe) {
        echo "Die Tabelle schema_migrations fehlt noch; sie wird beim Ausfuehren angelegt.\n\n";
    } else {
        $pdo->exec(
            'CREATE TABLE schema_migrations (
               id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               name       VARCHAR(191) NOT NULL,
               applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
               detected   TINYINT(1)   NOT NULL DEFAULT 0,
               PRIMARY KEY (id),
               UNIQUE KEY uq_migration (name)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        echo "Tabelle schema_migrations angelegt.\n\n";
    }
}

$vermerkt = tabelleVorhanden($pdo, 'schema_migrations')
    ? array_column($pdo->query('SELECT name FROM schema_migrations')->fetchAll(), 'name')
    : [];

$dateien = glob(WURZEL . '/db/migrations/*.sql') ?: [];
sort($dateien);

if ($dateien === []) {
    echo "Keine Migrationsdateien gefunden.\n";
    exit(0);
}

$offen = 0;
$erkannt = 0;
$ausgefuehrt = 0;

foreach ($dateien as $datei) {
    $name = basename($datei);
    $inhalt = (string)file_get_contents($datei);
    $marken = steuerzeilen($inhalt);

    if (in_array($name, $vermerkt, true)) {
        printf("  [erledigt]  %s\n", $name);
        continue;
    }

    // Ist der Zustand schon so, wie die Migration ihn herstellen wuerde?
    $schonDa = false;
    if (($marken['erledigt-wenn'] ?? '') !== '') {
        try {
            $wert = $pdo->query($marken['erledigt-wenn'])->fetchColumn();
            $schonDa = $wert !== false && (int)$wert > 0;
        } catch (PDOException) {
            // Die Abfrage scheitert typischerweise, weil eine Spalte oder
            // Tabelle noch fehlt - also ist die Migration noetig.
            $schonDa = false;
        }
    }

    if ($schonDa) {
        printf("  [vorhanden] %s\n", $name);
        $erkannt++;

        if (!$nurStatus && !$nurProbe) {
            $stmt = $pdo->prepare(
                'INSERT INTO schema_migrations (name, applied_at, detected) VALUES (?, ?, 1)'
            );
            $stmt->execute([$name, gmdate('Y-m-d H:i:s')]);
        }

        continue;
    }

    printf("  [offen]     %s\n", $name);
    $offen++;

    if ($nurStatus) {
        continue;
    }

    // Steht dem etwas im Weg?
    if (($marken['pruefe'] ?? '') !== '') {
        try {
            $treffer = $pdo->query($marken['pruefe'])->fetchAll();
        } catch (PDOException $e) {
            $treffer = [];
        }

        if ($treffer !== []) {
            echo "\n  Diese Migration kann nicht laufen:\n\n";
            zeigeZeilen($treffer, '    ');
            if (($marken['pruefe-hinweis'] ?? '') !== '') {
                echo "\n";
                foreach (explode("\n", $marken['pruefe-hinweis']) as $zeile) {
                    echo '  ', $zeile, "\n";
                }
            }
            echo "\n  Abgebrochen. Es wurde nichts geaendert.\n";
            exit(1);
        }
    }

    if ($nurProbe) {
        continue;
    }

    // Ausfuehren, Anweisung fuer Anweisung.
    $anweisungen = Installer::statements($inhalt);
    $nummer = 0;

    foreach ($anweisungen as $anweisung) {
        $nummer++;

        try {
            if (preg_match('/^\s*(SELECT|SHOW)\b/i', $anweisung) === 1) {
                $zeilen = $pdo->query($anweisung)->fetchAll();
                if ($zeilen !== []) {
                    echo "\n";
                    zeigeZeilen($zeilen, '    ');
                    echo "\n";
                }
            } else {
                $pdo->exec($anweisung);
            }
        } catch (PDOException $e) {
            printf(
                "\n  Anweisung %d von %d gescheitert:\n    %s\n\n    %s\n\n",
                $nummer,
                count($anweisungen),
                $e->getMessage(),
                mb_substr((string)preg_replace('/\s+/', ' ', $anweisung), 0, 140)
            );
            echo "  Abgebrochen. Frueher gelaufene Anweisungen dieser Datei bleiben\n"
                . "  bestehen - bitte den Stand pruefen, bevor du erneut startest.\n";
            exit(1);
        }
    }

    $stmt = $pdo->prepare('INSERT INTO schema_migrations (name, applied_at, detected) VALUES (?, ?, 0)');
    $stmt->execute([$name, gmdate('Y-m-d H:i:s')]);

    printf("  [gelaufen]  %s (%d Anweisungen)\n", $name, count($anweisungen));
    $ausgefuehrt++;
}

echo "\n";

if ($nurStatus) {
    printf("%d offen, %d bereits vorhanden.\n", $offen, $erkannt);
    echo $offen > 0
        ? "Ausfuehren mit: php tools/migrate.php\n"
        : "Die Datenbank ist auf dem aktuellen Stand.\n";
    exit(0);
}

if ($nurProbe) {
    printf("%d Migrationen wuerden laufen. Nichts geaendert.\n", $offen);
    exit(0);
}

if ($erkannt > 0) {
    printf("%d Migrationen waren bereits vorhanden und wurden nur vermerkt.\n", $erkannt);
}

printf(
    "%s\n",
    $ausgefuehrt > 0
        ? sprintf('%d Migrationen ausgefuehrt. Die Datenbank ist auf dem aktuellen Stand.', $ausgefuehrt)
        : 'Nichts auszufuehren. Die Datenbank ist auf dem aktuellen Stand.'
);
