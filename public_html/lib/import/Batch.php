<?php
declare(strict_types=1);

/**
 * Ein Importvorgang, zwischengespeichert in der Datenbank.
 *
 * Der Ablauf hat drei Stufen - hochladen, Mannschaften zuordnen, Vorschau
 * bestaetigen - und soll eine unterbrochene Sitzung ueberstehen. Deshalb
 * liegen die eingelesenen Zeilen in import_rows und nicht in der Session:
 * ein Spielplan mit 240 Zeilen gehoert nicht in ein Cookie, und ein
 * abgebrochener Vorgang laesst sich so spaeter fortsetzen.
 */
final class Batch
{
    /** Legt einen Vorgang mit den eingelesenen Zeilen an. */
    public static function create(
        int $sourceId,
        int $competitionSeasonId,
        string $adapter,
        string $filename,
        array $rows
    ): int {
        $batchId = Db::insert('import_batches', [
            'source_id'             => $sourceId,
            'competition_season_id' => $competitionSeasonId,
            'adapter'               => $adapter,
            'filename'              => mb_substr($filename, 0, 255),
            'row_count'             => count($rows),
            'status'                => 'pending',
            'created_at'            => gmdate('Y-m-d H:i:s'),
        ]);

        foreach ($rows as $row) {
            Db::insert('import_rows', [
                'batch_id'    => $batchId,
                'line_no'     => $row->lineNo,
                'raw_json'    => json_encode($row->toArray(), JSON_UNESCAPED_UNICODE),
                'action'      => 'pending',
                'status'      => 'pending',
            ]);
        }

        return $batchId;
    }

    public static function find(int $batchId): ?array
    {
        return Db::one('SELECT * FROM import_batches WHERE id = ?', [$batchId]);
    }

    /** @return ImportRow[] */
    public static function rows(int $batchId): array
    {
        $rows = [];

        foreach (Db::all('SELECT raw_json FROM import_rows WHERE batch_id = ? ORDER BY line_no', [$batchId]) as $row) {
            $data = json_decode((string)$row['raw_json'], true);
            if (is_array($data)) {
                $rows[] = ImportRow::fromArray($data);
            }
        }

        return $rows;
    }

    /** Offene Vorgaenge, damit ein abgebrochener Import nicht verlorengeht. */
    public static function pending(): array
    {
        return Db::all(
            'SELECT b.*, s.name AS source_name, cs.name AS competition_name
               FROM import_batches b
               JOIN sources s ON s.id = b.source_id
          LEFT JOIN competition_seasons cs ON cs.id = b.competition_season_id
              WHERE b.status = ?
              ORDER BY b.created_at DESC',
            ['pending']
        );
    }

    public static function markApplied(int $batchId): void
    {
        Db::update('import_batches', $batchId, [
            'status'     => 'applied',
            'applied_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function discard(int $batchId): void
    {
        Db::update('import_batches', $batchId, ['status' => 'discarded']);
    }

    /** Aeltere abgeschlossene Vorgaenge samt Zeilen entfernen. */
    public static function cleanup(int $keep = 20): void
    {
        $old = Db::all(
            'SELECT id FROM import_batches WHERE status <> ? ORDER BY created_at DESC',
            ['pending']
        );

        foreach (array_slice($old, $keep) as $batch) {
            Db::run('DELETE FROM import_rows WHERE batch_id = ?', [$batch['id']]);
        }
    }
}
