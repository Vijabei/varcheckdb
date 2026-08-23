<?php
declare(strict_types=1);

/**
 * Herkunft und Verbindlichkeit einzelner Spielfelder.
 *
 * Wird sowohl vom Import als auch von der manuellen Pflege im Adminbereich
 * benutzt - beide Wege muessen denselben Eintrag schreiben, sonst greift der
 * Ueberschreibschutz nur auf einem von beiden.
 */
final class FieldSource
{
    /** Legt den Eintrag an oder aktualisiert ihn. */
    public static function set(int $matchId, string $field, int $sourceId, bool $confirmed): void
    {
        $values = [
            'source_id'  => $sourceId,
            'confidence' => $confirmed ? 'confirmed' : 'imported',
            'confirmed'  => $confirmed ? 1 : 0,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        $existing = Db::value(
            'SELECT id FROM match_field_sources WHERE match_id = ? AND field = ?',
            [$matchId, $field]
        );

        if ($existing === null) {
            Db::insert('match_field_sources', $values + ['match_id' => $matchId, 'field' => $field]);

            return;
        }

        // Eine bereits bestaetigte Angabe darf ein Import nicht stillschweigend
        // zurueckstufen. Nur eine gleich- oder hoeherwertige Quelle darf das.
        $current = Db::one(
            'SELECT mfs.confirmed, s.priority FROM match_field_sources mfs
               JOIN sources s ON s.id = mfs.source_id
              WHERE mfs.id = ?',
            [$existing]
        );

        $incoming = (int)Db::value('SELECT priority FROM sources WHERE id = ?', [$sourceId]);

        if (!$confirmed && (int)$current['confirmed'] === 1 && (int)$current['priority'] < $incoming) {
            return;
        }

        Db::update('match_field_sources', (int)$existing, $values);
    }

    /** Ist das Feld gegen eine Quelle dieser Wertigkeit geschuetzt? */
    public static function isProtected(int $matchId, string $field, int $againstPriority): bool
    {
        $row = Db::one(
            'SELECT s.priority FROM match_field_sources mfs
               JOIN sources s ON s.id = mfs.source_id
              WHERE mfs.match_id = ? AND mfs.field = ? AND mfs.confirmed = 1',
            [$matchId, $field]
        );

        return $row !== null && (int)$row['priority'] < $againstPriority;
    }
}
