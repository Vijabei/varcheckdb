<?php
declare(strict_types=1);

/**
 * PDO-Zugriff. Haelt genau eine Verbindung pro Request.
 *
 * Der gesamte Anwendungscode benutzt portables SQL, damit dieselben Queries
 * produktiv auf MariaDB und in den Tests auf SQLite laufen.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function connect(array $config): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        // Die Optionen aus der Konfiguration kommen zuerst, koennen die
        // Vorgaben aber nicht ueberschreiben: Fehlerbehandlung und
        // Ergebnisform sind nicht verhandelbar.
        self::$pdo = new PDO(
            $config['dsn'],
            $config['user'] ?? null,
            $config['password'] ?? null,
            ($config['options'] ?? []) + [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );

        if (self::$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        }

        return self::$pdo;
    }

    /** Setzt eine bestehende Verbindung, z.B. die In-Memory-DB der Tests. */
    public static function set(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo instanceof PDO) {
            throw new RuntimeException('Keine Datenbankverbindung. Db::connect() zuerst aufrufen.');
        }

        return self::$pdo;
    }

    public static function all(string $sql, array $params = []): array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** Erste Spalte der ersten Zeile, oder null. */
    public static function value(string $sql, array $params = []): mixed
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    public static function run(string $sql, array $params = []): int
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);

        self::run(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $table,
                implode(', ', $columns),
                implode(', ', $placeholders)
            ),
            $data
        );

        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $assignments = array_map(static fn(string $c): string => $c . ' = :' . $c, array_keys($data));
        $data['__id'] = $id;

        self::run(
            sprintf('UPDATE %s SET %s WHERE id = :__id', $table, implode(', ', $assignments)),
            $data
        );
    }
}
