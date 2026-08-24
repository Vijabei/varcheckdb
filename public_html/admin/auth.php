<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/users.php';

/**
 * Anmeldung am Adminbereich.
 *
 * Solange kein aktiver Verwalter angelegt ist, gilt das Passwort aus
 * config.php - damit kommt man nach der Installation oder nach der Migration
 * herein und legt den ersten Benutzer an. Sobald ein aktiver Verwalter
 * besteht, verliert es seine Gueltigkeit. Es ist ein Weg fuer den Anfang,
 * keine dauerhafte Hintertuer.
 *
 * Ausgesperrt? Dann hilft ein neuer Passwort-Hash direkt in der Datenbank;
 * der Weg steht in docs/benutzer.md.
 */
final class Auth
{
    private const SESSION_KEY = 'varcheckdb_admin';

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

    /**
     * Meldet an. Der Benutzername darf leer bleiben, solange der Notzugang
     * aus config.php gilt.
     */
    public static function login(string $username, string $password, array $config): bool
    {
        self::start();

        if (Users::hasActiveAdmin()) {
            $user = Users::authenticate($username, $password);

            if ($user === null) {
                return false;
            }

            return self::establish($user['username'], $user['role'], (int)$user['id']);
        }

        // Erstzugang: das Passwort aus der Konfiguration.
        $hash = (string)($config['admin_password_hash'] ?? '');

        if ($hash === '' || !password_verify($password, $hash)) {
            // Gleiche Laufzeit, egal ob ein Hash hinterlegt ist.
            password_verify($password, '$2y$10$ungueltigungueltigungueltigungueltigungueltigungueltigun');

            return false;
        }

        return self::establish('erstzugang', Users::ROLE_ADMIN, null);
    }

    private static function establish(string $username, string $role, ?int $userId): bool
    {
        session_regenerate_id(true);

        $_SESSION[self::SESSION_KEY] = [
            'username' => $username,
            'role'     => $role,
            'user_id'  => $userId,
            'since'    => time(),
        ];

        return true;
    }

    public static function isLoggedIn(): bool
    {
        self::start();

        return isset($_SESSION[self::SESSION_KEY]);
    }

    public static function username(): string
    {
        self::start();

        return (string)($_SESSION[self::SESSION_KEY]['username'] ?? 'unbekannt');
    }

    public static function role(): ?string
    {
        self::start();

        return $_SESSION[self::SESSION_KEY]['role'] ?? null;
    }

    public static function userId(): ?int
    {
        self::start();

        return $_SESSION[self::SESSION_KEY]['user_id'] ?? null;
    }

    /** Meldet sich der Erstzugang aus der Konfiguration an? */
    public static function isBootstrap(): bool
    {
        return self::isLoggedIn() && self::userId() === null;
    }

    public static function can(string $capability): bool
    {
        return self::isLoggedIn() && Users::can(self::role(), $capability);
    }

    public static function logout(): void
    {
        self::start();
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    /** Ohne Anmeldung geht es nicht weiter. */
    public static function require(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: index.php?login=1');
            exit;
        }

        // Ein Konto, das waehrend der Sitzung abgeschaltet oder entfernt
        // wurde, soll nicht bis zum Abmelden weiterarbeiten koennen.
        $userId = self::userId();
        if ($userId !== null) {
            $user = Users::find($userId);
            if ($user === null || (int)$user['active'] !== 1) {
                self::logout();
                header('Location: index.php?gesperrt=1');
                exit;
            }

            // Eine geaenderte Rolle greift sofort.
            $_SESSION[self::SESSION_KEY]['role'] = $user['role'];
        }
    }

    /** Verlangt eine bestimmte Berechtigung. */
    public static function requireCapability(string $capability): void
    {
        self::require();

        if (!self::can($capability)) {
            http_response_code(403);
            require __DIR__ . '/layout.php';
            admin_head('Nicht erlaubt', ['site_name' => 'Meine Ligen']);
            echo '<main style="max-width:32rem;margin:4rem auto;padding:0 1rem">'
                . '<h1>Nicht erlaubt</h1><div class="card"><p>Dafür fehlt deiner Rolle '
                . '<strong>' . htmlspecialchars(Users::ROLES[self::role()] ?? '—', ENT_QUOTES) . '</strong> '
                . 'die Berechtigung.</p><p class="note">Wende dich an jemanden mit der Rolle '
                . 'Verwaltung.</p><div class="actions"><a href="index.php">'
                . '<button type="button">Zur Übersicht</button></a></div></div></main>';
            admin_foot();
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
