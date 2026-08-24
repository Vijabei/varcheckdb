<?php
declare(strict_types=1);

/**
 * Spielorte.
 *
 * Ein Spielort haengt bewusst nicht am Verein, sondern am Spiel. Eine
 * Mannschaft weicht aus, traegt ein Heimspiel beim Gegner aus oder spielt ein
 * Endspiel auf neutralem Boden - eine feste Zuordnung waere in genau diesen
 * Faellen falsch, und das sind die interessanten. Deshalb ist der Ort am
 * Spiel optional anzugeben und sonst schlicht unbekannt.
 *
 * capacity ist das Fassungsvermoegen. Es dient nicht der Statistik, sondern
 * dem Eintragen: wer die Zuschauerzahl erfasst, sieht daneben, was der Platz
 * ueberhaupt hergibt - eine Null zu viel faellt so sofort auf.
 */
final class Venues
{
    /** Alle Spielorte, alphabetisch. */
    public static function all(): array
    {
        return Db::all(
            'SELECT v.*,
                    (SELECT COUNT(*) FROM matches m WHERE m.venue_id = v.id) AS match_count
               FROM venues v
              ORDER BY v.name'
        );
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM venues WHERE id = ?', [$id]);
    }

    /**
     * Sucht einen Spielort ueber seinen Namen.
     *
     * Der Vergleich laeuft ueber Normalize::strict(), damit der CSV-Ruecklauf
     * einen Ort wiederfindet, auch wenn unterwegs ein Bindestrich oder eine
     * Grossschreibung verrutscht ist.
     */
    public static function byName(string $name, ?string $city = null): ?array
    {
        $gesucht = Normalize::strict($name);

        if ($gesucht === '') {
            return null;
        }

        foreach (self::all() as $venue) {
            if (Normalize::strict((string)$venue['name']) !== $gesucht) {
                continue;
            }

            // Gleicher Name in zwei Orten: nur mit passender Stadt zaehlt es.
            if ($city !== null && $city !== '' && (string)($venue['city'] ?? '') !== ''
                && Normalize::strict((string)$venue['city']) !== Normalize::strict($city)) {
                continue;
            }

            return $venue;
        }

        return null;
    }

    /**
     * Loest einen Namen in eine id auf und legt den Ort bei Bedarf an.
     *
     * Der Weg fuer den CSV-Ruecklauf: dort steht der Spielort als Text, und
     * ein Rundlauf soll nicht daran scheitern, dass ein Ort noch fehlt.
     */
    public static function resolve(string $name, ?string $city = null, string $actor = 'import'): ?int
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $vorhanden = self::byName($name, $city);

        if ($vorhanden !== null) {
            return (int)$vorhanden['id'];
        }

        return self::create(['name' => $name, 'city' => $city], $actor);
    }

    /** @return string[] leer heisst: in Ordnung */
    public static function validate(array $input, ?int $ignoreId = null): array
    {
        $errors = [];
        $name = trim((string)($input['name'] ?? ''));
        $city = trim((string)($input['city'] ?? ''));

        if ($name === '') {
            $errors[] = 'Ein Name ist Pflicht.';
        } elseif (mb_strlen($name) > 191) {
            $errors[] = 'Der Name ist zu lang (höchstens 191 Zeichen).';
        }

        if (mb_strlen($city) > 128) {
            $errors[] = 'Der Ort ist zu lang (höchstens 128 Zeichen).';
        }

        if (mb_strlen(trim((string)($input['address'] ?? ''))) > 255) {
            $errors[] = 'Die Adresse ist zu lang (höchstens 255 Zeichen).';
        }

        $capacity = self::capacity($input['capacity'] ?? null);

        if ($capacity === false) {
            $errors[] = 'Das Fassungsvermögen muss eine Zahl ab 0 sein oder leer bleiben.';
        }

        if ($name !== '') {
            $vorhanden = self::byName($name, $city === '' ? null : $city);

            if ($vorhanden !== null && (int)$vorhanden['id'] !== $ignoreId) {
                $errors[] = sprintf('"%s" gibt es schon.', $name);
            }
        }

        return $errors;
    }

    public static function create(array $input, string $actor = 'admin'): int
    {
        $id = Db::insert('venues', [
            'name'       => trim((string)$input['name']),
            'city'       => self::orNull($input['city'] ?? null),
            'address'    => self::orNull($input['address'] ?? null),
            'capacity'   => self::zahlOderNull($input['capacity'] ?? null),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        self::log($id, 'created', null, trim((string)$input['name']), $actor);

        return $id;
    }

    /** @return string[] die tatsaechlich geaenderten Felder */
    public static function update(int $id, array $input, string $actor = 'admin'): array
    {
        $current = self::find($id);

        if ($current === null) {
            return [];
        }

        // Nur uebergebene Felder anfassen: ein Formular, das die Adresse gar
        // nicht anbietet, soll sie nicht stillschweigend leeren.
        $neu = [];

        foreach (['name', 'city', 'address'] as $feld) {
            if (array_key_exists($feld, $input)) {
                $neu[$feld] = $feld === 'name'
                    ? trim((string)$input[$feld])
                    : self::orNull($input[$feld]);
            }
        }

        if (array_key_exists('capacity', $input)) {
            $neu['capacity'] = self::zahlOderNull($input['capacity']);
        }

        $changed = [];

        foreach ($neu as $feld => $wert) {
            $vorher = $current[$feld];

            if ((string)$vorher === (string)$wert && ($vorher === null) === ($wert === null)) {
                continue;
            }

            $changed[] = $feld;
            self::log($id, $feld, $vorher === null ? null : (string)$vorher,
                $wert === null ? null : (string)$wert, $actor);
        }

        if ($changed === []) {
            return [];
        }

        Db::update('venues', $id, $neu);

        return $changed;
    }

    /**
     * Entfernt einen Spielort.
     *
     * Wird er noch an einem Spiel verwendet, passiert nichts - sonst
     * verschwaende der Ort und liesse ein Spiel mit einem Verweis ins Leere
     * zurueck. Was zu tun ist, sagt die Oberflaeche.
     */
    public static function remove(int $id, string $actor = 'admin'): bool
    {
        $venue = self::find($id);

        if ($venue === null || self::inUse($id) > 0) {
            return false;
        }

        Db::run('DELETE FROM venues WHERE id = ?', [$id]);
        self::log($id, 'removed', (string)$venue['name'], null, $actor);

        return true;
    }

    public static function inUse(int $id): int
    {
        return (int)Db::value('SELECT COUNT(*) FROM matches WHERE venue_id = ?', [$id]);
    }

    /**
     * Liest eine Zuschauerzahl oder ein Fassungsvermoegen.
     *
     * @return int|false|null false = keine gueltige Zahl, null = keine Angabe
     */
    public static function capacity(mixed $value): int|false|null
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }

        // Punkte und geschuetzte Leerzeichen kommen aus jeder Tabellen-
        // kalkulation: "9.500" und "9 500" sind gemeint wie 9500.
        $roh = str_replace(['.', ' ', "\u{00a0}", "'"], '', trim((string)$value));

        if (!preg_match('/^\d+$/', $roh)) {
            return false;
        }

        return (int)$roh;
    }

    /** Wie capacity(), aber eine ungueltige Angabe wird zu "keine Angabe". */
    private static function zahlOderNull(mixed $value): ?int
    {
        $zahl = self::capacity($value);

        return $zahl === false ? null : $zahl;
    }

    private static function orNull(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));

        return $value === '' ? null : $value;
    }

    private static function log(int $id, string $field, ?string $old, ?string $new, string $actor): void
    {
        Db::insert('change_log', [
            'entity_type' => 'venue',
            'entity_id'   => $id,
            'field'       => $field,
            'old_value'   => $old,
            'new_value'   => $new,
            'actor'       => $actor,
            'source_id'   => Db::value('SELECT id FROM sources WHERE slug = ?', ['manual']),
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
