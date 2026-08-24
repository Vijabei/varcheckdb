<?php
declare(strict_types=1);

require_once __DIR__ . '/Installer.php';

/**
 * Bringt eine bestehende Datenbank auf den aktuellen Stand.
 *
 * Fragt zuerst ab, was schon da ist, und fuehrt nur aus, was fehlt. Eine
 * Migration, die auf eine unpassende Datenbank trifft, richtet mehr Schaden
 * an als sie behebt.
 *
 * Steuerzeilen im Kopf einer Migrationsdatei:
 *
 *   -- @erledigt-wenn: <Abfrage>   liefert sie eine Zahl > 0, gilt die
 *                                  Migration als bereits erledigt
 *   -- @pruefe: <Abfrage>          liefert sie Zeilen, wird nicht ausgefuehrt
 *   -- @pruefe-hinweis: <Text>     was dann zu tun ist
 *
 * Wird von tools/migrate.php und von update.php benutzt - beide sollen
 * dasselbe tun, deshalb steht es an einer Stelle.
 */
final class Migrator
{
    public const OFFEN     = 'offen';
    public const ERLEDIGT  = 'erledigt';
    public const VORHANDEN = 'vorhanden';

    public function __construct(private readonly string $verzeichnis)
    {
    }

    /** @return string[] die Migrationsdateien in Reihenfolge */
    public function dateien(): array
    {
        $dateien = glob(rtrim($this->verzeichnis, '/') . '/*.sql') ?: [];
        sort($dateien);

        return $dateien;
    }

    /** Liest die Steuerzeilen aus dem Kopf einer Datei. */
    public static function steuerzeilen(string $inhalt): array
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
                // Zeilenumbruch erhalten: in den Hinweisen stehen
                // SQL-Beispiele, die aneinandergehaengt unlesbar werden.
                $marken[$aktuell] .= "\n" . $text;
            } else {
                $aktuell = null;
            }
        }

        return array_map('trim', $marken);
    }

    public static function tabelleVorhanden(PDO $pdo, string $name): bool
    {
        try {
            $pdo->query('SELECT 1 FROM ' . $name . ' LIMIT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** Steht in dieser Datenbank ueberhaupt ein Schema? */
    public static function eingerichtet(PDO $pdo): bool
    {
        return self::tabelleVorhanden($pdo, 'competitions');
    }

    public function tabelleAnlegen(PDO $pdo): void
    {
        if (self::tabelleVorhanden($pdo, 'schema_migrations')) {
            return;
        }

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
    }

    /**
     * Der Stand jeder Migration, ohne etwas zu aendern.
     *
     * @return array<int, array{name:string, zustand:string, blocker:array, hinweis:?string}>
     */
    public function stand(PDO $pdo): array
    {
        $vermerkt = self::tabelleVorhanden($pdo, 'schema_migrations')
            ? array_column($pdo->query('SELECT name FROM schema_migrations')->fetchAll(), 'name')
            : [];

        $ergebnis = [];

        foreach ($this->dateien() as $datei) {
            $name = basename($datei);
            $marken = self::steuerzeilen((string)file_get_contents($datei));

            if (in_array($name, $vermerkt, true)) {
                $ergebnis[] = ['name' => $name, 'zustand' => self::ERLEDIGT,
                               'blocker' => [], 'hinweis' => null];
                continue;
            }

            if ($this->schonHergestellt($pdo, $marken)) {
                $ergebnis[] = ['name' => $name, 'zustand' => self::VORHANDEN,
                               'blocker' => [], 'hinweis' => null];
                continue;
            }

            $ergebnis[] = [
                'name'    => $name,
                'zustand' => self::OFFEN,
                'blocker' => $this->blocker($pdo, $marken),
                'hinweis' => $marken['pruefe-hinweis'] ?? null,
            ];
        }

        return $ergebnis;
    }

    /** Ist der Zustand schon so, wie die Migration ihn herstellen wuerde? */
    private function schonHergestellt(PDO $pdo, array $marken): bool
    {
        if (($marken['erledigt-wenn'] ?? '') === '') {
            return false;
        }

        try {
            $wert = $pdo->query($marken['erledigt-wenn'])->fetchColumn();
        } catch (PDOException) {
            // Die Abfrage scheitert typischerweise, weil eine Spalte oder
            // Tabelle noch fehlt - also ist die Migration noetig.
            return false;
        }

        return $wert !== false && (int)$wert > 0;
    }

    /** Was der Migration im Weg steht. */
    private function blocker(PDO $pdo, array $marken): array
    {
        if (($marken['pruefe'] ?? '') === '') {
            return [];
        }

        try {
            return $pdo->query($marken['pruefe'])->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }

    /**
     * Fuehrt aus, was fehlt.
     *
     * @param callable|null $melden fn(string $art, string $text, array $zeilen)
     * @return array{ausgefuehrt:int, vermerkt:int, abgebrochen:?string}
     */
    public function ausfuehren(PDO $pdo, ?callable $melden = null): array
    {
        $melden ??= static function (): void {
        };

        $this->tabelleAnlegen($pdo);

        $ausgefuehrt = 0;
        $vermerkt = 0;

        foreach ($this->stand($pdo) as $eintrag) {
            $name = $eintrag['name'];

            if ($eintrag['zustand'] === self::ERLEDIGT) {
                $melden('erledigt', $name, []);
                continue;
            }

            if ($eintrag['zustand'] === self::VORHANDEN) {
                $this->vermerken($pdo, $name, true);
                $melden('vorhanden', $name, []);
                $vermerkt++;
                continue;
            }

            if ($eintrag['blocker'] !== []) {
                $melden('blockiert', $name, $eintrag['blocker']);

                return ['ausgefuehrt' => $ausgefuehrt, 'vermerkt' => $vermerkt,
                        'abgebrochen' => $name];
            }

            $datei = rtrim($this->verzeichnis, '/') . '/' . $name;
            $anweisungen = Installer::statements((string)file_get_contents($datei));
            $nummer = 0;

            foreach ($anweisungen as $anweisung) {
                $nummer++;

                try {
                    if (preg_match('/^\s*(SELECT|SHOW)\b/i', $anweisung) === 1) {
                        $zeilen = $pdo->query($anweisung)->fetchAll();
                        if ($zeilen !== []) {
                            $melden('bericht', $name, $zeilen);
                        }
                    } else {
                        $pdo->exec($anweisung);
                    }
                } catch (PDOException $e) {
                    $melden('fehler', sprintf(
                        "%s: Anweisung %d von %d gescheitert\n%s\n%s",
                        $name,
                        $nummer,
                        count($anweisungen),
                        $e->getMessage(),
                        mb_substr((string)preg_replace('/\s+/', ' ', $anweisung), 0, 160)
                    ), []);

                    return ['ausgefuehrt' => $ausgefuehrt, 'vermerkt' => $vermerkt,
                            'abgebrochen' => $name];
                }
            }

            $this->vermerken($pdo, $name, false);
            $melden('gelaufen', sprintf('%s (%d Anweisungen)', $name, count($anweisungen)), []);
            $ausgefuehrt++;
        }

        return ['ausgefuehrt' => $ausgefuehrt, 'vermerkt' => $vermerkt, 'abgebrochen' => null];
    }

    private function vermerken(PDO $pdo, string $name, bool $erkannt): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO schema_migrations (name, applied_at, detected) VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, gmdate('Y-m-d H:i:s'), $erkannt ? 1 : 0]);
    }
}
