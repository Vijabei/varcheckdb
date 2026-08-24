<?php
declare(strict_types=1);

/**
 * Aenderungen von Hand.
 *
 * Geht bewusst denselben Weg wie der Import: jede Aenderung landet im
 * change_log, und jedes geaenderte Feld wird in match_field_sources als
 * manuell bestaetigt vermerkt. Nur dadurch ueberlebt eine Korrektur den
 * naechsten Import - sonst wuerde die Quelle sie beim naechsten Lauf
 * stillschweigend zurueckdrehen.
 */
final class Editor
{
    /** Felder, die von Hand geaendert werden duerfen. */
    public const FIELDS = [
        'kickoff_utc', 'kickoff_is_confirmed',
        'home_goals', 'away_goals', 'home_goals_ht', 'away_goals_ht',
        'status', 'venue_id', 'spectators', 'note',
    ];

    /**
     * Aendert ein Spiel.
     *
     * @param array<string, mixed> $values nur Felder aus FIELDS
     * @return string[] die tatsaechlich geaenderten Felder
     */
    public static function update(int $matchId, array $values, string $actor = 'admin'): array
    {
        $current = Db::one('SELECT * FROM matches WHERE id = ?', [$matchId]);
        if ($current === null) {
            return [];
        }

        $manualId = self::manualSourceId();
        $changed = [];
        $write = [];

        foreach ($values as $field => $value) {
            if (!in_array($field, self::FIELDS, true)) {
                continue;
            }

            $before = $current[$field] ?? null;
            if (self::same($before, $value)) {
                continue;
            }

            $write[$field] = $value;
            $changed[] = $field;

            Db::insert('change_log', [
                'entity_type' => 'match',
                'entity_id'   => $matchId,
                'field'       => $field,
                'old_value'   => $before === null ? null : (string)$before,
                'new_value'   => $value === null ? null : (string)$value,
                'actor'       => $actor,
                'source_id'   => $manualId,
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ]);
        }

        if ($write === []) {
            return [];
        }

        $write['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::update('matches', $matchId, $write);

        foreach ($changed as $field) {
            FieldSource::set($matchId, $field, $manualId, true);
        }

        return $changed;
    }

    /**
     * Setzt Datum und Uhrzeit fuer eine Auswahl von Spielen.
     *
     * @param int[] $matchIds
     * @return int Zahl der tatsaechlich geaenderten Spiele
     */
    public static function setKickoff(array $matchIds, ?string $date, ?string $time, string $timezone, string $actor = 'admin'): int
    {
        $changed = 0;

        foreach ($matchIds as $matchId) {
            $match = Db::one('SELECT kickoff_utc FROM matches WHERE id = ?', [$matchId]);
            if ($match === null) {
                continue;
            }

            // Fehlt eine Haelfte, bleibt sie wie sie war.
            $existing = $match['kickoff_utc'] === null
                ? null
                : (new DateTimeImmutable((string)$match['kickoff_utc'], new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone($timezone));

            $newDate = $date ?: ($existing?->format('Y-m-d'));
            $newTime = $time ?: ($existing?->format('H:i'));

            if ($newDate === null || $newTime === null) {
                continue;
            }

            $utc = (new DateTimeImmutable($newDate . ' ' . $newTime, new DateTimeZone($timezone)))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');

            if (self::update($matchId, ['kickoff_utc' => $utc, 'kickoff_is_confirmed' => 1], $actor) !== []) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Verschiebt Spiele um eine Zahl von Tagen.
     *
     * Gerechnet wird in Ortszeit, damit ein Spiel um 15:00 auch nach einem
     * Wechsel der Sommerzeit um 15:00 stattfindet.
     *
     * @param int[] $matchIds
     */
    public static function shift(array $matchIds, int $days, string $timezone, string $actor = 'admin'): int
    {
        if ($days === 0) {
            return 0;
        }

        $changed = 0;

        foreach ($matchIds as $matchId) {
            $match = Db::one('SELECT kickoff_utc FROM matches WHERE id = ?', [$matchId]);
            if ($match === null || $match['kickoff_utc'] === null) {
                continue;
            }

            $local = (new DateTimeImmutable((string)$match['kickoff_utc'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($timezone))
                ->modify(sprintf('%+d days', $days));

            $utc = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            if (self::update($matchId, ['kickoff_utc' => $utc], $actor) !== []) {
                $changed++;
            }
        }

        return $changed;
    }

    /** Markiert Termine als verbindlich oder wieder als vorlaeufig. */
    public static function setConfirmed(array $matchIds, bool $confirmed, string $actor = 'admin'): int
    {
        $changed = 0;

        foreach ($matchIds as $matchId) {
            if (self::update($matchId, ['kickoff_is_confirmed' => $confirmed ? 1 : 0], $actor) !== []) {
                $changed++;
            }
        }

        return $changed;
    }

    private static function manualSourceId(): int
    {
        return (int)Db::value('SELECT id FROM sources WHERE slug = ?', ['manual']);
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return (string)$a === (string)$b;
    }
}
