<?php
declare(strict_types=1);

/**
 * Benutzerkonten und Rollen.
 *
 * Zwei Rollen, kein Rechtesystem:
 *
 *   admin   Verwaltung - Benutzer, Wettbewerbe, alle Importe
 *   editor  Pflege     - Spiele aendern, CSV herunterladen und zurueckspielen
 *
 * Der CSV-Ruecklauf gehoert bewusst zur Pflege: er ist der Weg, auf dem viele
 * Aenderungen auf einmal gemacht werden. Wer Spiele aendern darf, aber seine
 * eigene Tabelle nicht wieder hochladen kann, kann die Arbeit nicht tun.
 * Vollstaendige Importe aus fremden Dateien bleiben der Verwaltung.
 */
final class Users
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER  = 'user';

    public const ROLES = [
        self::ROLE_ADMIN => 'Webadmin',
        self::ROLE_USER  => 'Mitmachen',
    ];

    /**
     * Was eine globale Rolle darf, unabhaengig von einer Liga.
     *
     * Alles Weitere - Spiele aendern, importieren, Rechte vergeben - haengt
     * an der Mitgliedschaft am Wettbewerb und steht in Access.
     */
    private const CAPABILITIES = [
        self::ROLE_ADMIN => ['users.manage', 'competitions.create', 'system.manage'],
        self::ROLE_USER  => ['competitions.create'],
    ];

    public const USERNAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,31}$/';
    public const MIN_PASSWORD_LENGTH = 10;

    /** Wie lange eine Bestaetigungs- oder Ruecksetzmarke gilt. */
    public const TOKEN_MINUTES = 60;

    public static function can(?string $role, string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES[$role] ?? [], true);
    }

    /** @return array<int, string> */
    public static function capabilities(string $role): array
    {
        return self::CAPABILITIES[$role] ?? [];
    }

    public static function all(): array
    {
        return Db::all('SELECT * FROM users ORDER BY username');
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function byName(string $username): ?array
    {
        return Db::one(
            'SELECT * FROM users WHERE username_normalized = ?',
            [self::normalize($username)]
        );
    }

    /** Gibt es ueberhaupt jemanden, der verwalten darf? */
    public static function hasActiveAdmin(): bool
    {
        return (int)Db::value(
            'SELECT COUNT(*) FROM users WHERE role = ? AND active = 1',
            [self::ROLE_ADMIN]
        ) > 0;
    }

    public static function count(): int
    {
        return (int)Db::value('SELECT COUNT(*) FROM users');
    }

    /**
     * Prueft Name und Passwort.
     *
     * @return array|null der Benutzer, oder null
     */
    public static function authenticate(string $username, string $password): ?array
    {
        $user = self::byName($username);

        // Auch ohne Treffer wird geprueft: sonst laesst sich am Zeitverhalten
        // ablesen, welche Benutzernamen es gibt.
        $hash = $user['password_hash']
            ?? '$2y$10$ungueltigungueltigungueltigungueltigungueltigungueltigun';

        if (!password_verify($password, $hash) || $user === null) {
            return null;
        }

        if ((int)$user['active'] !== 1) {
            return null;
        }

        Db::update('users', (int)$user['id'], ['last_login_at' => gmdate('Y-m-d H:i:s')]);

        return $user;
    }

    /** @return string[] */
    public static function validate(array $input, ?int $ignoreId = null): array
    {
        $errors = [];
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $role = (string)($input['role'] ?? '');

        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            $errors[] = 'Der Benutzername darf Buchstaben, Ziffern, Punkt, Bindestrich und '
                . 'Unterstrich enthalten, muss mit einem Buchstaben oder einer Ziffer beginnen '
                . 'und 2 bis 32 Zeichen lang sein.';
        }

        if (!array_key_exists($role, self::ROLES)) {
            $errors[] = 'Unbekannte Rolle.';
        }

        // Beim Anlegen ist eine Adresse Pflicht. Beim Aendern nur dann, wenn
        // das Feld ueberhaupt uebergeben wurde - sonst koennte man einen
        // Benutzer nicht mehr aendern, ohne seine Adresse mitzuschicken.
        $emailGefragt = $ignoreId === null || array_key_exists('email', $input);

        if ($emailGefragt) {
            $email = self::normalizeEmail((string)($input['email'] ?? ''));

            if ($email === '') {
                $errors[] = 'Eine Mailadresse wird gebraucht - ohne sie laesst sich ein '
                    . 'vergessenes Passwort nicht zuruecksetzen.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Die Mailadresse sieht nicht wie eine Adresse aus.';
            } else {
                $vorhandenMail = Db::one('SELECT id FROM users WHERE email_normalized = ?', [$email]);
                if ($vorhandenMail !== null && (int)$vorhandenMail['id'] !== $ignoreId) {
                    // Keine Auskunft darueber, wem sie gehoert.
                    $errors[] = 'Diese Mailadresse wird bereits verwendet.';
                }
            }
        }

        // Beim Anlegen ist ein Passwort noetig, beim Aendern nur, wenn eines
        // eingegeben wurde.
        if ($ignoreId === null || $password !== '') {
            if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $errors[] = sprintf(
                    'Das Passwort muss mindestens %d Zeichen haben.',
                    self::MIN_PASSWORD_LENGTH
                );
            }
            if ($password !== (string)($input['password_repeat'] ?? $password)) {
                $errors[] = 'Die beiden Passwoerter stimmen nicht ueberein.';
            }
        }

        if ($errors === []) {
            $vorhanden = self::byName($username);
            if ($vorhanden !== null && (int)$vorhanden['id'] !== $ignoreId) {
                $errors[] = sprintf('Den Benutzer "%s" gibt es schon.', $username);
            }
        }

        return $errors;
    }

    public static function create(array $input, string $actor = 'admin'): int
    {
        $username = trim((string)$input['username']);

        $id = Db::insert('users', [
            'username'            => $username,
            'username_normalized' => self::normalize($username),
            'email'               => trim((string)($input['email'] ?? '')) ?: null,
            'email_normalized'    => self::normalizeEmail((string)($input['email'] ?? '')) ?: null,
            'password_hash'       => password_hash((string)$input['password'], PASSWORD_DEFAULT),
            'role'                => (string)($input['role'] ?? self::ROLE_USER),
            'active'              => empty($input['active']) ? 0 : 1,
            'created_at'          => gmdate('Y-m-d H:i:s'),
        ]);

        self::log($id, 'created', null, $username . ' (' . $input['role'] . ')', $actor);

        return $id;
    }

    /** @return string[] die geaenderten Felder */
    public static function update(int $id, array $input, string $actor = 'admin'): array
    {
        $user = self::find($id);
        if ($user === null) {
            return [];
        }

        $changed = [];
        $write = [];

        $role = (string)($input['role'] ?? $user['role']);
        if ($role !== $user['role']) {
            $write['role'] = $role;
            $changed[] = 'role';
            self::log($id, 'role', $user['role'], $role, $actor);
        }

        // Nur aendern, wenn das Feld ueberhaupt uebergeben wurde. Sonst
        // wuerde eine Teilaenderung - etwa nur das Passwort - das Konto
        // stillschweigend abschalten und den Benutzer aussperren.
        $active = array_key_exists('active', $input)
            ? (empty($input['active']) ? 0 : 1)
            : (int)$user['active'];

        if ($active !== (int)$user['active']) {
            $write['active'] = $active;
            $changed[] = 'active';
            self::log($id, 'active', (string)$user['active'], (string)$active, $actor);
        }

        if (array_key_exists('email', $input)) {
            $neu = self::normalizeEmail((string)$input['email']);
            if ($neu !== '' && $neu !== (string)$user['email_normalized']) {
                $write['email'] = trim((string)$input['email']);
                $write['email_normalized'] = $neu;
                // Eine neue Adresse ist zunaechst unbestaetigt.
                $write['email_verified_at'] = null;
                $changed[] = 'email';
                self::log($id, 'email', $user['email'], $write['email'], $actor);
            }
        }

        if (($input['password'] ?? '') !== '') {
            $write['password_hash'] = password_hash((string)$input['password'], PASSWORD_DEFAULT);
            $changed[] = 'password';
            // Das Passwort selbst kommt nicht ins Protokoll, nur die Tatsache.
            self::log($id, 'password', null, 'geaendert', $actor);
        }

        if ($write !== []) {
            Db::update('users', $id, $write);
        }

        return $changed;
    }

    /**
     * Entfernt einen Benutzer.
     *
     * Der letzte aktive Verwalter kann nicht entfernt oder herabgestuft
     * werden - sonst kaeme niemand mehr an die Verwaltung.
     */
    public static function remove(int $id, string $actor = 'admin'): bool
    {
        $user = self::find($id);
        if ($user === null || self::isLastAdmin($id)) {
            return false;
        }

        Db::run('DELETE FROM users WHERE id = ?', [$id]);
        self::log($id, 'removed', $user['username'], null, $actor);

        return true;
    }

    /** Ist das der einzige aktive Verwalter? */
    public static function isLastAdmin(int $id): bool
    {
        $user = self::find($id);

        if ($user === null || $user['role'] !== self::ROLE_ADMIN || (int)$user['active'] !== 1) {
            return false;
        }

        return (int)Db::value(
            'SELECT COUNT(*) FROM users WHERE role = ? AND active = 1 AND id <> ?',
            [self::ROLE_ADMIN, $id]
        ) === 0;
    }

    /**
     * Bremst automatisierte Massenanmeldungen.
     *
     * Ohne Mailadresse gibt es keine Bestaetigung per Post; ein Zaehler je
     * Herkunft ist das mildeste Mittel, das ueberhaupt wirkt. Gespeichert
     * wird nur ein Hash der Adresse - fuer das Zaehlen genuegt er, und eine
     * IP ist ein personenbezogenes Datum.
     *
     * @return bool true, wenn noch eine Anmeldung erlaubt ist
     */
    public static function mayRegister(string $ip, int $maxProStunde = 3): bool
    {
        $hash = hash('sha256', $ip . '|varcheckdb');

        // Alte Eintraege verfallen; sie werden nicht gebraucht und sollen
        // nicht unbegrenzt liegen bleiben.
        Db::run('DELETE FROM signup_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);

        $zuletzt = (int)Db::value(
            'SELECT COUNT(*) FROM signup_attempts WHERE ip_hash = ? AND created_at > ?',
            [$hash, gmdate('Y-m-d H:i:s', time() - 3600)]
        );

        return $zuletzt < $maxProStunde;
    }

    public static function noteRegistration(string $ip): void
    {
        Db::insert('signup_attempts', [
            'ip_hash'    => hash('sha256', $ip . '|varcheckdb'),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function byEmail(string $email): ?array
    {
        $email = self::normalizeEmail($email);

        return $email === ''
            ? null
            : Db::one('SELECT * FROM users WHERE email_normalized = ?', [$email]);
    }

    public static function isVerified(array $user): bool
    {
        return ($user['email_verified_at'] ?? null) !== null;
    }

    /**
     * Erzeugt eine einmalige Marke.
     *
     * Zurueck kommt der Klartext - er steht nur in der Mail. In der Datenbank
     * liegt allein sein Hash.
     */
    public static function createToken(int $userId, string $kind): string
    {
        // Aeltere Marken derselben Art verfallen; sonst gaebe es mehrere
        // gueltige Wege gleichzeitig.
        Db::run('DELETE FROM user_tokens WHERE user_id = ? AND kind = ?', [$userId, $kind]);

        $token = bin2hex(random_bytes(32));

        Db::insert('user_tokens', [
            'user_id'    => $userId,
            'kind'       => $kind,
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TOKEN_MINUTES * 60),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /**
     * Prueft eine Marke, ohne sie zu verbrauchen.
     *
     * Wird gebraucht, um das Formular zu zeigen, bevor abgeschickt wird -
     * sonst waere die Marke schon beim Anzeigen der Seite aufgebraucht und
     * ein Tippfehler im Passwort haette sie wertlos gemacht.
     */
    public static function peekToken(string $token, string $kind): ?array
    {
        $row = self::findToken($token, $kind);

        return $row === null ? null : self::find((int)$row['user_id']);
    }

    /** Loest eine Marke ein. Danach ist sie verbraucht. */
    public static function useToken(string $token, string $kind): ?array
    {
        $row = self::findToken($token, $kind);

        if ($row === null) {
            return null;
        }

        Db::update('user_tokens', (int)$row['id'], ['used_at' => gmdate('Y-m-d H:i:s')]);

        return self::find((int)$row['user_id']);
    }

    /** Die gueltige, unverbrauchte Marke, oder null. */
    private static function findToken(string $token, string $kind): ?array
    {
        $row = Db::one(
            'SELECT * FROM user_tokens WHERE token_hash = ? AND kind = ?',
            [hash('sha256', $token), $kind]
        );

        if ($row === null || $row['used_at'] !== null) {
            return null;
        }

        return (string)$row['expires_at'] < gmdate('Y-m-d H:i:s') ? null : $row;
    }

    public static function markVerified(int $userId): void
    {
        Db::update('users', $userId, ['email_verified_at' => gmdate('Y-m-d H:i:s')]);
        self::log($userId, 'email_verified', null, 'bestaetigt', 'system');
    }

    public static function setPassword(int $userId, string $password, string $actor): void
    {
        Db::update('users', $userId, ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        self::log($userId, 'password', null, 'zurueckgesetzt', $actor);
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public static function normalize(string $username): string
    {
        return mb_strtolower(trim($username), 'UTF-8');
    }

    private static function log(
        int $id,
        string $field,
        ?string $from,
        ?string $to,
        string $actor = 'admin'
    ): void {
        Db::insert('change_log', [
            'entity_type' => 'user',
            'entity_id'   => $id,
            'field'       => $field,
            'old_value'   => $from,
            'new_value'   => $to,
            'actor'       => $actor,
            'source_id'   => Db::value('SELECT id FROM sources WHERE slug = ?', ['manual']),
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
