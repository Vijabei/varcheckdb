<?php
declare(strict_types=1);

/**
 * Prueft, ob die Umgebung fuer den Betrieb taugt.
 *
 * Bewusst als eigene Klasse und nicht im Installer-Formular: so laesst sich
 * die Pruefung testen und spaeter auch im Adminbereich anzeigen, wenn nach
 * einem PHP-Update etwas fehlt.
 *
 * Drei Stufen:
 *   required     - ohne das laeuft die Anwendung nicht
 *   recommended  - laeuft, aber mit Einschraenkungen
 *   info         - nur zur Kenntnis
 */
require_once __DIR__ . '/Installer.php';

final class Requirements
{
    public const MIN_PHP = '8.1.0';

    /** Die groesste zu erwartende Importdatei ist ein gespeicherter
     *  HTML-Spielplan mit rund 0,5 MB. 2 MB gibt Luft. */
    public const MIN_UPLOAD_BYTES = 2 * 1024 * 1024;

    /**
     * @return array<int, array{
     *   name: string, level: string, ok: bool,
     *   actual: string, expected: string, hint: ?string
     * }>
     */
    public static function all(?string $configDir = null, ?string $schemaDir = null): array
    {
        $configDir ??= dirname(__DIR__, 2);

        return array_merge(
            self::php(),
            self::extensions(),
            self::limits(),
            self::filesystem($configDir),
            self::schemaFiles($configDir, $schemaDir),
            self::server()
        );
    }

    /** Nur die Punkte, die den Betrieb verhindern. */
    public static function blockers(?string $configDir = null, ?string $schemaDir = null): array
    {
        return array_values(array_filter(
            self::all($configDir, $schemaDir),
            static fn(array $c): bool => $c['level'] === 'required' && !$c['ok']
        ));
    }

    private static function php(): array
    {
        return [self::check(
            'PHP-Version',
            'required',
            version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            PHP_VERSION,
            self::MIN_PHP . ' oder neuer',
            'Die Anwendung nutzt typisierte Eigenschaften und Enums aus PHP 8.1. '
            . 'Die PHP-Version laesst sich bei Hetzner in der Konsole umstellen.'
        )];
    }

    private static function extensions(): array
    {
        $required = [
            'pdo'       => 'Datenbankzugriff',
            'pdo_mysql' => 'Verbindung zu MariaDB/MySQL',
            'mbstring'  => 'Umlaute in Vereinsnamen korrekt behandeln',
            'json'      => 'Import- und API-Format',
            'dom'       => 'Auswertung gespeicherter HTML-Spielplaene',
            'libxml'    => 'Grundlage von dom',
        ];

        $optional = [
            'fileinfo' => 'Pruefung hochgeladener Dateien',
            'intl'     => 'sprachrichtige Sortierung von Mannschaftsnamen',
            'zlib'     => 'komprimierte Auslieferung der API',
        ];

        $checks = [];

        foreach ($required as $name => $purpose) {
            $checks[] = self::check(
                'Erweiterung ' . $name,
                'required',
                extension_loaded($name),
                extension_loaded($name) ? 'vorhanden' : 'fehlt',
                'vorhanden',
                extension_loaded($name) ? null : $purpose
            );
        }

        foreach ($optional as $name => $purpose) {
            $checks[] = self::check(
                'Erweiterung ' . $name,
                'recommended',
                extension_loaded($name),
                extension_loaded($name) ? 'vorhanden' : 'fehlt',
                'vorhanden',
                extension_loaded($name) ? null : $purpose . ' - nicht zwingend'
            );
        }

        return $checks;
    }

    private static function limits(): array
    {
        $upload = self::bytes((string)ini_get('upload_max_filesize'));
        $post   = self::bytes((string)ini_get('post_max_size'));
        $memory = self::bytes((string)ini_get('memory_limit'));

        return [
            self::check(
                'upload_max_filesize',
                'required',
                $upload >= self::MIN_UPLOAD_BYTES,
                (string)ini_get('upload_max_filesize'),
                '2M oder mehr',
                'Ein gespeicherter HTML-Spielplan ist schnell 0,5 MB gross.'
            ),
            self::check(
                'post_max_size',
                'required',
                $post >= $upload,
                (string)ini_get('post_max_size'),
                'mindestens so gross wie upload_max_filesize',
                'Ist post_max_size kleiner, bricht der Upload ohne Fehlermeldung ab.'
            ),
            self::check(
                'memory_limit',
                'recommended',
                $memory === -1 || $memory >= 64 * 1024 * 1024,
                (string)ini_get('memory_limit'),
                '64M oder mehr',
                'Der Vergleich einer ganzen Saison haelt rund 240 Spiele gleichzeitig im Speicher.'
            ),
            self::check(
                'max_execution_time',
                'info',
                true,
                ((string)ini_get('max_execution_time')) . ' s',
                '30 s genuegen',
                null
            ),
        ];
    }

    private static function filesystem(string $configDir): array
    {
        $configFile = $configDir . '/config.php';
        $exists = is_file($configFile);

        // Existiert die Konfiguration schon, muss das Verzeichnis nicht
        // beschreibbar sein - dann wird ohnehin nicht installiert.
        $writable = $exists ? is_writable($configFile) : is_writable($configDir);

        return [
            self::check(
                'Konfiguration schreibbar',
                'required',
                $writable,
                $writable ? 'ja' : 'nein',
                'ja',
                $writable ? null : sprintf(
                    'Das Verzeichnis %s muss fuer den Webserver beschreibbar sein. '
                    . 'Alternativ config.php aus config.example.php selbst anlegen.',
                    $configDir
                )
            ),
            self::check(
                'Konfiguration vorhanden',
                'info',
                true,
                $exists ? 'ja - Installation bereits erfolgt' : 'nein',
                '-',
                null
            ),
        ];
    }

    /**
     * Liegen die SQL-Dateien dort, wo der Installer sie findet?
     *
     * Wird in Schritt 1 geprueft und nicht erst beim Einspielen: sonst faellt
     * der Fehler erst auf, nachdem die Zugangsdaten schon eingetragen sind.
     */
    private static function schemaFiles(string $base, ?string $override = null): array
    {
        // Ein von Hand eingetragener Pfad hat Vorrang vor der Suche.
        if ($override !== null && $override !== '') {
            $resolved = Installer::resolveSchemaDir($override, $base);

            return [self::check(
                'Schemadateien (db/)',
                'required',
                $resolved['dir'] !== null,
                $resolved['dir'] ?? 'nicht gefunden',
                'schema.mysql.sql und seed.sql',
                $resolved['dir'] !== null
                    ? 'Aus der Eingabe "' . $override . '" aufgeloest.'
                    : sprintf(
                        'Geprueft wurde: %s. Beachte: FTP-Programme zeigen oft einen anderen '
                        . 'Pfad als das Dateisystem des Servers.',
                        implode(', ', $resolved['tried'])
                    )
            )];
        }

        $found = Installer::findSchemaDir($base);

        return [self::check(
            'Schemadateien (db/)',
            'required',
            $found['dir'] !== null,
            $found['dir'] ?? 'nicht gefunden',
            'schema.mysql.sql und seed.sql',
            $found['dir'] !== null ? null : sprintf(
                'Den Ordner db/ mit schema.mysql.sql und seed.sql an eine dieser Stellen '
                . 'laden - am besten ausserhalb des Dokumentenverzeichnisses: %s. '
                . 'Oder den Pfad unten eintragen.',
                implode(', ', $found['searched'])
            )
        )];
    }

    private static function server(): array
    {
        $own = self::check(
            'Diese Installation liegt in',
            'info',
            true,
            dirname(__DIR__, 2),
            '-',
            'Das ist der Pfad im Dateisystem des Servers. Im FTP-Programm sieht er '
            . 'meist kuerzer aus - danach richten sich alle Pfadangaben hier.'
        );

        $rewrite = null;
        if (function_exists('apache_get_modules')) {
            $rewrite = in_array('mod_rewrite', apache_get_modules(), true);
        }

        return [
            $own,
            self::check(
                'URL-Umschreibung (mod_rewrite)',
                'recommended',
                $rewrite !== false,
                match ($rewrite) {
                    true  => 'aktiv',
                    false => 'nicht aktiv',
                    null  => 'nicht feststellbar',
                },
                'aktiv',
                $rewrite === false
                    ? 'Ohne mod_rewrite sind die API-Adressen nur in der Form /index.php?route=... erreichbar.'
                    : ($rewrite === null
                        ? 'Bei FastCGI laesst sich das nicht aus PHP heraus feststellen. Der Installer prueft es im letzten Schritt direkt.'
                        : null)
            ),
            self::check(
                'Serversoftware',
                'info',
                true,
                (string)($_SERVER['SERVER_SOFTWARE'] ?? 'unbekannt'),
                '-',
                null
            ),
        ];
    }

    private static function check(
        string $name,
        string $level,
        bool $ok,
        string $actual,
        string $expected,
        ?string $hint
    ): array {
        return compact('name', 'level', 'ok', 'actual', 'expected', 'hint');
    }

    /** Wandelt Angaben wie '2M', '512K', '1G' oder '-1' in Bytes um. */
    public static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return $value === '-1' ? -1 : 0;
        }

        $number = (int)$value;
        $unit = strtolower(substr($value, -1));

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
