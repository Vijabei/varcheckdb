<?php
declare(strict_types=1);

require_once __DIR__ . '/users.php';

/**
 * Anmeldung.
 *
 * Es gibt genau einen Weg herein: ein Benutzerkonto. Frueher galt ersatzweise
 * das Passwort aus config.php, solange noch kein Verwalter angelegt war -
 * gedacht als Starthilfe nach der Installation. Der Installer legt den ersten
 * Verwalter inzwischen selbst an, damit war es eine Tuer ohne Zweck.
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

    /** Meldet an. Ohne passendes Konto gibt es keinen Zugang. */
    public static function login(string $username, string $password): bool
    {
        self::start();

        $user = Users::authenticate($username, $password);

        if ($user === null) {
            return false;
        }

        return self::establish($user['username'], $user['role'], (int)$user['id']);
    }

    private static function establish(string $username, string $role, int $userId): bool
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
            header('Location: ../login.php');
            exit;
        }

        // Ein Konto, das waehrend der Sitzung abgeschaltet oder entfernt
        // wurde, soll nicht bis zum Abmelden weiterarbeiten koennen. Eine
        // Sitzung ohne Konto stammt noch vom fruehreren Notzugang und gilt
        // ebenfalls nicht mehr.
        $userId = self::userId();
        $user = $userId === null ? null : Users::find($userId);

        if ($user === null || (int)$user['active'] !== 1) {
            self::logout();
            header('Location: ../login.php?gesperrt=1');
            exit;
        }

        // Eine geaenderte Rolle greift sofort.
        $_SESSION[self::SESSION_KEY]['role'] = $user['role'];
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
                . 'Verwaltung.</p><div class="actions"><a href="../meine/">'
                . '<button type="button">Zu meinen Ligen</button></a></div></div></main>';
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
