<?php
declare(strict_types=1);

/** Anmeldung fuer den Adminbereich. Ein Passwort, kein Benutzersystem. */
final class Auth
{
    private const SESSION_KEY = 'vijabei_admin';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'cookie_secure'   => ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off',
            ]);
        }
    }

    public static function check(string $password, array $config): bool
    {
        $hash = (string)($config['admin_password_hash'] ?? '');

        // password_verify braucht immer gleich lange, auch bei leerem Hash -
        // sonst laesst sich am Zeitverhalten ablesen, ob ueberhaupt eines gesetzt ist.
        if ($hash === '') {
            password_verify($password, '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin');

            return false;
        }

        if (!password_verify($password, $hash)) {
            return false;
        }

        self::start();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = ['since' => time()];

        return true;
    }

    public static function isLoggedIn(): bool
    {
        self::start();

        return isset($_SESSION[self::SESSION_KEY]);
    }

    public static function logout(): void
    {
        self::start();
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    /** Ohne Anmeldung geht es hier nicht weiter. */
    public static function require(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: index.php?login=1');
            exit;
        }
    }

    public static function token(): string
    {
        self::start();

        return $_SESSION['admin_token'] ??= bin2hex(random_bytes(16));
    }

    public static function tokenValid(): bool
    {
        return isset($_POST['token']) && hash_equals(self::token(), (string)$_POST['token']);
    }
}
