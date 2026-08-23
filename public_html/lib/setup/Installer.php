<?php
declare(strict_types=1);

/**
 * Die Schritte, die der Installer ausfuehrt.
 *
 * Getrennt vom Formular, damit sie testbar sind: Verbindungsaufbau,
 * Rechtepruefung, Einspielen des Schemas und Schreiben der Konfiguration.
 */
final class Installer
{
    /** Diese Tabellen muessen nach dem Einspielen vorhanden sein. */
    public const TABLES = [
        'competitions', 'seasons', 'competition_seasons', 'clubs', 'teams',
        'team_aliases', 'venues', 'rounds', 'matches', 'match_field_sources',
        'sources', 'source_mappings', 'import_batches', 'import_rows', 'change_log',
    ];


    /**
     * Sucht das Verzeichnis mit schema.mysql.sql und seed.sql.
     *
     * Die Verzeichnisstruktur unterscheidet sich je nach Hoster: mal liegt
     * install.php im Dokumentenverzeichnis und db/ eine Ebene darueber, mal
     * wurde alles flach hochgeladen. Statt einen Pfad festzuschreiben, werden
     * die naheliegenden Orte durchprobiert.
     *
     * @return array{dir: ?string, searched: string[]}
     */
    public static function findSchemaDir(string $base): array
    {
        $candidates = [
            dirname($base) . '/db',      // db/ liegt ausserhalb des Dokumentenverzeichnisses
            $base . '/db',               // alles flach hochgeladen
            dirname($base, 2) . '/db',   // Dokumentenverzeichnis zwei Ebenen tief
            $base . '/setup/db',
        ];

        $searched = [];
        foreach ($candidates as $candidate) {
            $normalized = self::normalize($candidate);
            if (in_array($normalized, $searched, true)) {
                continue;
            }
            $searched[] = $normalized;

            if (self::hasSchema($normalized)) {
                return ['dir' => $normalized, 'searched' => $searched];
            }
        }

        return ['dir' => null, 'searched' => $searched];
    }

    /**
     * Loest eine Pfadangabe des Admins auf.
     *
     * FTP-Programme zeigen selten den echten Pfad im Dateisystem: was dort
     * als /public_html/db erscheint, heisst auf dem Server oft
     * /usr/www/users/kunde/public_html/db. Eine wortwoertlich uebernommene
     * Eingabe geht deshalb regelmaessig ins Leere.
     *
     * Darum wird die Angabe nicht nur direkt geprueft, sondern auch relativ
     * zur Installation und als Endstueck eines der uebergeordneten
     * Verzeichnisse. Damit funktionieren "db", "../db" und "/public_html/db"
     * gleichermassen.
     *
     * @return array{dir: ?string, tried: string[]}
     */
    public static function resolveSchemaDir(string $input, string $base): array
    {
        $input = trim($input);
        if ($input === '') {
            return ['dir' => null, 'tried' => []];
        }

        $input = rtrim($input, '/');

        // Eine relative Angabe wird bewusst NICHT wortwoertlich geprueft:
        // realpath() wuerde sie gegen das Arbeitsverzeichnis des Prozesses
        // aufloesen und koennte ein voellig fremdes Verzeichnis treffen.
        $candidates = str_starts_with($input, '/') ? [$input] : [];

        if (!str_starts_with($input, '/')) {
            $candidates[] = $base . '/' . $input;
            $candidates[] = dirname($base) . '/' . $input;
        } else {
            // Als Endstueck deuten: von der Installation aus nach oben gehen
            // und schauen, wo die Angabe passt.
            $ancestor = $base;
            while ($ancestor !== '/' && $ancestor !== '' && $ancestor !== '.') {
                $candidates[] = $ancestor . $input;
                $parent = dirname($ancestor);
                if ($parent === $ancestor) {
                    break;
                }
                $ancestor = $parent;
            }
        }

        $tried = [];
        foreach ($candidates as $candidate) {
            $normalized = self::normalize($candidate);
            if (in_array($normalized, $tried, true)) {
                continue;
            }
            $tried[] = $normalized;

            if (self::hasSchema($normalized)) {
                return ['dir' => $normalized, 'tried' => $tried];
            }
        }

        return ['dir' => null, 'tried' => $tried];
    }

    /** Liegen beide benoetigten SQL-Dateien in diesem Verzeichnis? */
    public static function hasSchema(string $dir): bool
    {
        return is_file(rtrim($dir, '/') . '/schema.mysql.sql')
            && is_file(rtrim($dir, '/') . '/seed.sql');
    }

    /** Pfad vereinheitlichen, ohne dass er existieren muss. */
    private static function normalize(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }

        // Doppelte Schraegstriche entstehen, wenn dirname() bei der Wurzel
        // angekommen ist. In einer Fehlermeldung sieht das nach einem Fehler aus.
        return rtrim((string)preg_replace('#/+#', '/', $path), '/') ?: '/';
    }

    /**
     * PDO-Optionen fuer eine verschluesselte Verbindung.
     *
     * Viele Hoster - auch Hetzner-Webhosting - bieten keine TLS-Verbindung
     * zur Datenbank an, weil sie ohnehin ueber den lokalen Socket laeuft.
     * Deshalb ist Verschluesselung eine Moeglichkeit, keine Vorgabe.
     *
     * @return array<int, mixed>
     */
    public static function sslOptions(bool $enabled, string $caFile = '', bool $verify = true): array
    {
        if (!$enabled) {
            return [];
        }

        $options = [];

        if ($caFile !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $caFile;
        }

        // Ohne CA-Datei laesst sich das Zertifikat nicht pruefen; die
        // Verbindung ist dann verschluesselt, aber nicht authentifiziert.
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $verify && $caFile !== '';

        return $options;
    }

    /**
     * Baut die Verbindung auf und meldet, was dabei herauskam.
     *
     * @return array{ok: bool, message: string, server: ?string, pdo: ?PDO}
     */
    public static function connect(
        string $host,
        string $database,
        string $user,
        string $password,
        int $port = 3306,
        array $options = []
    ): array {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

        try {
            $pdo = new PDO($dsn, $user, $password, $options + [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (PDOException $e) {
            return [
                'ok'      => false,
                'message' => self::explain($e),
                'server'  => null,
                'pdo'     => null,
            ];
        }

        return [
            'ok'      => true,
            'message' => 'Verbindung steht.',
            'server'  => (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'pdo'     => $pdo,
        ];
    }

    /** Uebersetzt die haeufigsten Verbindungsfehler in eine brauchbare Auskunft. */
    private static function explain(PDOException $e): string
    {
        $text = $e->getMessage();

        return match (true) {
            str_contains($text, 'Access denied') =>
                'Benutzername oder Passwort stimmt nicht, oder der Benutzer hat auf diese '
                . 'Datenbank keinen Zugriff.',
            str_contains($text, 'Unknown database') =>
                'Die Datenbank gibt es nicht. Sie muss in der Hetzner-Konsole zuerst angelegt werden; '
                . 'der Installer legt keine Datenbank an.',
            str_contains($text, 'getaddrinfo') || str_contains($text, 'Name or service not known') =>
                'Der Servername ist nicht aufloesbar. Bei Hetzner steht dort meist localhost.',
            str_contains($text, 'Connection refused') =>
                'Der Datenbankserver nimmt auf diesem Port keine Verbindung an.',
            str_contains($text, 'timed out') =>
                'Zeitueberschreitung. Servername und Port pruefen.',
            str_contains($text, 'SSL') || str_contains($text, 'TLS') =>
                'Die verschluesselte Verbindung kam nicht zustande. Viele Hoster bieten '
                . 'keine TLS-Verbindung zur Datenbank an - dann die Verschluesselung abwaehlen.',
            default => 'Verbindung fehlgeschlagen: ' . $text,
        };
    }

    /**
     * Prueft, ob der Benutzer die noetigen Rechte hat.
     *
     * Statt GRANT-Zeilen zu lesen - die je nach Hoster verborgen sind -
     * wird es praktisch versucht: Tabelle anlegen, schreiben, lesen, loeschen.
     *
     * @return array{ok: bool, message: string}
     */
    public static function checkPrivileges(PDO $pdo): array
    {
        $table = 'varcheckdb_rechtetest_' . bin2hex(random_bytes(4));

        try {
            $pdo->exec(sprintf(
                'CREATE TABLE %s (id INT NOT NULL AUTO_INCREMENT, wert VARCHAR(16), PRIMARY KEY (id))
                 ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                $table
            ));
        } catch (PDOException) {
            return ['ok' => false, 'message' => 'Dem Benutzer fehlt das Recht CREATE.'];
        }

        try {
            $pdo->exec(sprintf("INSERT INTO %s (wert) VALUES ('Grün')", $table));
            $value = $pdo->query(sprintf('SELECT wert FROM %s LIMIT 1', $table))?->fetchColumn();

            if ($value !== 'Grün') {
                return [
                    'ok'      => false,
                    'message' => 'Umlaute kommen nicht unveraendert zurueck. '
                        . 'Die Datenbank sollte auf utf8mb4 stehen.',
                ];
            }

            $pdo->exec(sprintf("UPDATE %s SET wert = 'x'", $table));
            $pdo->exec(sprintf('DELETE FROM %s', $table));
        } catch (PDOException) {
            return ['ok' => false, 'message' => 'Dem Benutzer fehlen Rechte zum Lesen oder Schreiben.'];
        } finally {
            try {
                $pdo->exec('DROP TABLE ' . $table);
            } catch (PDOException) {
                return ['ok' => false, 'message' => 'Dem Benutzer fehlt das Recht DROP.'];
            }
        }

        return ['ok' => true, 'message' => 'CREATE, INSERT, SELECT, UPDATE, DELETE und DROP sind moeglich.'];
    }

    /** Welche unserer Tabellen liegen bereits in der Datenbank? */
    public static function existingTables(PDO $pdo): array
    {
        $found = [];

        foreach (self::TABLES as $table) {
            try {
                $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
                $found[] = $table;
            } catch (PDOException) {
                // Tabelle gibt es nicht - genau das wollten wir wissen.
            }
        }

        return $found;
    }

    /**
     * Zerlegt eine SQL-Datei in Einzelanweisungen.
     *
     * PDO::exec kann zwar mehrere Anweisungen auf einmal, meldet dann aber
     * nur den ersten Fehler. Einzeln ausgefuehrt laesst sich sagen, welche
     * Anweisung gescheitert ist.
     *
     * Gelesen wird zeichenweise statt mit einem regulaeren Ausdruck, weil
     * beides vorkommt: Semikolons duerfen in Kommentaren und in
     * Zeichenketten stehen, ohne die Anweisung zu beenden.
     *
     * @return string[]
     */
    public static function statements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($inString) {
                $current .= $char;

                if ($char === '\\' && $next !== '') {
                    // Maskiertes Zeichen unveraendert uebernehmen.
                    $current .= $next;
                    $i++;
                } elseif ($char === "'") {
                    // Zwei Anfuehrungszeichen hintereinander sind ein
                    // maskiertes Anfuehrungszeichen, kein Ende.
                    if ($next === "'") {
                        $current .= $next;
                        $i++;
                    } else {
                        $inString = false;
                    }
                }

                continue;
            }

            if ($char === "'") {
                $inString = true;
                $current .= $char;
                continue;
            }

            // Zeilenkommentar: -- gefolgt von Leerzeichen oder Zeilenende.
            if ($char === '-' && $next === '-') {
                $after = $sql[$i + 2] ?? "\n";
                if ($after === ' ' || $after === "\t" || $after === "\n" || $after === "\r") {
                    while ($i < $length && $sql[$i] !== "\n") {
                        $i++;
                    }
                    $current .= "\n";
                    continue;
                }
            }

            // Blockkommentar.
            if ($char === '/' && $next === '*') {
                $close = strpos($sql, '*/', $i + 2);
                $i = $close === false ? $length : $close + 1;
                continue;
            }

            if ($char === ';') {
                $statements[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $statements[] = $current;

        $cleaned = [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $cleaned[] = $statement;
            }
        }

        return $cleaned;
    }

    /**
     * Spielt eine SQL-Datei ein.
     *
     * @return array{ok: bool, executed: int, message: ?string}
     */
    public static function runSql(PDO $pdo, string $file): array
    {
        if (!is_file($file)) {
            return ['ok' => false, 'executed' => 0, 'message' => 'Datei nicht gefunden: ' . $file];
        }

        $statements = self::statements((string)file_get_contents($file));
        $executed = 0;

        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
                $executed++;
            } catch (PDOException $e) {
                return [
                    'ok'       => false,
                    'executed' => $executed,
                    'message'  => sprintf(
                        'Anweisung %d von %d gescheitert: %s | %s',
                        $executed + 1,
                        count($statements),
                        $e->getMessage(),
                        mb_substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 120)
                    ),
                ];
            }
        }

        return ['ok' => true, 'executed' => $executed, 'message' => null];
    }

    /**
     * Schreibt die config.php.
     *
     * Werte werden mit var_export eingesetzt, damit Anfuehrungszeichen und
     * Backslashes im Datenbankpasswort die Datei nicht zerlegen.
     */
    public static function renderConfig(array $values): string
    {
        $quote = static fn(mixed $v): string => var_export($v, true);
        $options = self::renderOptions($values['db_options'] ?? []);

        return <<<PHP
        <?php
        /**
         * Erzeugt vom Installer am {$values['created_at']}.
         * Diese Datei enthaelt Zugangsdaten und gehoert nicht ins Repository.
         */

        return [
            'db' => [
                'dsn'      => {$quote($values['dsn'])},
                'user'     => {$quote($values['db_user'])},
                'password' => {$quote($values['db_password'])},
                'options'  => {$options},
            ],

            'site_name'   => {$quote($values['site_name'])},
            'base_url'    => {$quote($values['base_url'])},
            'timezone'    => {$quote($values['timezone'])},
            'attribution' => {$quote($values['attribution'])},

            'admin_password_hash' => {$quote($values['admin_password_hash'])},

            'debug' => false,
        ];

        PHP;
    }

    /**
     * Gibt die PDO-Optionen mit sprechenden Konstantennamen aus.
     *
     * var_export wuerde nur die Zahlenwerte schreiben; in einer Datei, die
     * spaeter von Hand angefasst wird, ist PDO::MYSQL_ATTR_SSL_CA lesbarer
     * als 1009.
     */
    private static function renderOptions(array $options): string
    {
        if ($options === []) {
            return '[]';
        }

        $names = [
            PDO::MYSQL_ATTR_SSL_CA                 => 'PDO::MYSQL_ATTR_SSL_CA',
            PDO::MYSQL_ATTR_SSL_CAPATH             => 'PDO::MYSQL_ATTR_SSL_CAPATH',
            PDO::MYSQL_ATTR_SSL_CERT               => 'PDO::MYSQL_ATTR_SSL_CERT',
            PDO::MYSQL_ATTR_SSL_KEY                => 'PDO::MYSQL_ATTR_SSL_KEY',
            PDO::MYSQL_ATTR_SSL_CIPHER             => 'PDO::MYSQL_ATTR_SSL_CIPHER',
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => 'PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT',
        ];

        $lines = [];
        foreach ($options as $key => $value) {
            $lines[] = sprintf(
                '        %s => %s,',
                $names[$key] ?? (string)$key,
                var_export($value, true)
            );
        }

        return "[\n" . implode("\n", $lines) . "\n    ]";
    }

    /** @return array{ok: bool, message: ?string} */
    public static function writeConfig(string $path, array $values): array
    {
        $content = self::renderConfig($values);

        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            return [
                'ok'      => false,
                'message' => 'Die Datei ' . $path . ' konnte nicht geschrieben werden. '
                    . 'Rechte des Verzeichnisses pruefen.',
            ];
        }

        @chmod($path, 0640);

        // Sofort gegenlesen: eine unvollstaendig geschriebene Konfiguration
        // wuerde sonst erst beim naechsten Aufruf auffallen.
        $check = @include $path;
        if (!is_array($check) || !isset($check['db']['dsn'])) {
            return ['ok' => false, 'message' => 'Die geschriebene Konfiguration ist unbrauchbar.'];
        }

        return ['ok' => true, 'message' => null];
    }

    public static function dsn(string $host, string $database, int $port = 3306): string
    {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
    }
}
