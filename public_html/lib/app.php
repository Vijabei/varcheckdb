<?php
declare(strict_types=1);

/**
 * Gemeinsamer Einstieg fuer alle Seiten.
 *
 * Laedt die Konfiguration, stellt die Datenbankverbindung her und bringt
 * die Klassen mit, die beide Seiten brauchen.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/encoding.php';

final class App
{
    private static ?array $config = null;

    /** Startet die Anwendung. Ohne Konfiguration wird zur Installation geleitet. */
    public static function boot(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $file = dirname(__DIR__) . '/config.php';

        if (!is_file($file)) {
            self::sendToInstaller();
        }

        $config = require $file;

        if (!is_array($config) || !isset($config['db']['dsn'])) {
            http_response_code(500);
            exit('Die Datei config.php ist unbrauchbar. Bitte neu installieren.');
        }

        date_default_timezone_set($config['timezone'] ?? 'Europe/Berlin');

        if ($config['debug'] ?? false) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            ini_set('display_errors', '0');
        }

        try {
            Db::connect($config['db']);
        } catch (PDOException $e) {
            http_response_code(503);
            exit(($config['debug'] ?? false)
                ? 'Datenbankverbindung fehlgeschlagen: ' . $e->getMessage()
                : 'Die Datenbank ist gerade nicht erreichbar.');
        }

        return self::$config = $config;
    }

    /** Ist ueberhaupt schon installiert? */
    public static function isInstalled(): bool
    {
        return is_file(dirname(__DIR__) . '/config.php');
    }

    private static function sendToInstaller(): never
    {
        if (is_file(dirname(__DIR__) . '/install.php')) {
            header('Location: install.php');
            exit;
        }

        http_response_code(503);
        exit(
            'Noch nicht eingerichtet: es gibt weder config.php noch install.php. '
            . 'Bitte install.php hochladen und aufrufen.'
        );
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    /** Der Pfad unterhalb des Installationsverzeichnisses, ohne Parameter. */
    public static function path(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $uri = (string)parse_url($uri, PHP_URL_PATH);

        // Die Anwendung kann in einem Unterverzeichnis liegen.
        $base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

        return '/' . trim($uri, '/');
    }
}
