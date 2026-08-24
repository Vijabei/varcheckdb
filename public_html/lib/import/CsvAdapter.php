<?php
declare(strict_types=1);

/**
 * Der Ruecklauf aus der Tabellenkalkulation.
 *
 * Dies ist zugleich das normalisierte Kernformat: der Export erzeugt genau
 * diese Spalten, und was hier wieder hereinkommt, geht denselben Weg ueber
 * Differ und Vorschau wie jeder andere Import. Damit ist die En-Bloc-Korrektur
 * kein Sonderweg, sondern derselbe Mechanismus.
 *
 * Auf die Eigenheiten von Excel und LibreOffice ist Ruecksicht genommen:
 * Trennzeichen wird erkannt, Windows-1252 wird umgewandelt, eine BOM entfernt.
 */
final class CsvAdapter implements Adapter
{
    public const COLUMNS = [
        'match_id', 'round', 'kickoff_date', 'kickoff_time', 'kickoff_confirmed',
        'home', 'away', 'home_goals', 'away_goals', 'home_goals_ht', 'away_goals_ht',
        'status', 'venue', 'spectators', 'note',
    ];

    /** Spalten, ohne die eine Zeile nicht zuzuordnen ist. */
    private const REQUIRED = ['home', 'away'];

    public function sourceSlug(): string
    {
        return 'csv';
    }

    public function parse(string $content): array
    {
        $content = Encoding::toUtf8($content);

        $lines = preg_split('/\R/', trim($content)) ?: [];
        if (count($lines) < 2) {
            throw new ImportException('Die Datei enthaelt keine Datenzeilen.');
        }

        $delimiter = self::delimiter($lines[0]);
        $header = array_map(
            static fn(string $h): string => strtolower(trim($h)),
            str_getcsv($lines[0], $delimiter, '"', '\\')
        );

        $missing = array_diff(self::REQUIRED, $header);
        if ($missing !== []) {
            throw new ImportException(sprintf(
                'In der Kopfzeile fehlen die Spalten: %s. Erwartet werden: %s.',
                implode(', ', $missing),
                implode(', ', self::COLUMNS)
            ));
        }

        $unknown = array_diff($header, self::COLUMNS);

        $rows = [];
        $skipped = 0;

        foreach (array_slice($lines, 1) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, $delimiter, '"', '\\');
            $data = [];
            foreach ($header as $position => $name) {
                $data[$name] = isset($values[$position]) ? trim((string)$values[$position]) : '';
            }

            if ($data['home'] === '' || $data['away'] === '') {
                $skipped++;
                continue;
            }

            $rows[] = new ImportRow(
                round: self::int($data['round'] ?? ''),
                home: $data['home'],
                away: $data['away'],
                kickoffDate: self::date($data['kickoff_date'] ?? ''),
                kickoffTime: self::time($data['kickoff_time'] ?? ''),
                kickoffConfirmed: self::bool($data['kickoff_confirmed'] ?? ''),
                homeGoals: self::int($data['home_goals'] ?? ''),
                awayGoals: self::int($data['away_goals'] ?? ''),
                homeGoalsHt: self::int($data['home_goals_ht'] ?? ''),
                awayGoalsHt: self::int($data['away_goals_ht'] ?? ''),
                status: ($data['status'] ?? '') !== '' ? $data['status'] : null,
                venue: ($data['venue'] ?? '') !== '' ? $data['venue'] : null,
                spectators: self::int($data['spectators'] ?? ''),
                note: ($data['note'] ?? '') !== '' ? $data['note'] : null,
                matchId: self::int($data['match_id'] ?? ''),
                lineNo: $index + 2,
            );
        }

        if ($rows === []) {
            throw new ImportException('Es liess sich keine einzige Zeile lesen.');
        }

        $notices = [];
        if ($skipped > 0) {
            $notices[] = sprintf('%d Zeilen ohne Mannschaftsnamen wurden uebergangen.', $skipped);
        }
        if ($unknown !== []) {
            $notices[] = 'Unbekannte Spalten wurden ignoriert: ' . implode(', ', $unknown) . '.';
        }
        $notices[] = sprintf('Trennzeichen erkannt: "%s".', $delimiter);

        return [
            'meta'    => ['competition_name' => null, 'season' => null, 'timezone' => 'Europe/Berlin'],
            'rows'    => $rows,
            'notices' => $notices,
        ];
    }

    /**
     * Trennzeichen bestimmen.
     *
     * Excel schreibt in deutscher Einstellung Semikolon, LibreOffice und
     * die englische Einstellung das Komma.
     */
    public static function delimiter(string $header): string
    {
        $counts = [
            ';'    => substr_count($header, ';'),
            ','    => substr_count($header, ','),
            "\t"   => substr_count($header, "\t"),
        ];

        arsort($counts);
        $best = (string)array_key_first($counts);

        return $counts[$best] > 0 ? $best : ';';
    }

    private static function int(string $value): ?int
    {
        $value = trim($value);

        return ($value === '' || !preg_match('/^-?\d+$/', $value)) ? null : (int)$value;
    }

    private static function bool(string $value): ?bool
    {
        $value = strtolower(trim($value));

        return match ($value) {
            '' => null,
            '1', 'ja', 'j', 'wahr', 'true', 'x' => true,
            default => false,
        };
    }

    /** Nimmt sowohl 2026-08-20 als auch 20.08.2026 entgegen. */
    private static function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        return null;
    }

    /** Nimmt 15:00 und 15:00:00 entgegen; Excel macht daraus gern Letzteres. */
    private static function time(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', $value, $m) === 1
            ? sprintf('%02d:%02d', (int)$m[1], (int)$m[2])
            : null;
    }

    /**
     * Erzeugt die Datei, die spaeter wieder hereinkommt.
     *
     * Mit BOM, damit Excel sie als UTF-8 oeffnet statt Umlaute zu zerlegen.
     */
    public static function export(array $matches, string $timezone, string $delimiter = ';'): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Konnte keinen Zwischenspeicher anlegen.');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::COLUMNS, $delimiter, '"', '\\');

        foreach ($matches as $match) {
            $date = null;
            $time = null;

            if (($match['kickoff_utc'] ?? null) !== null) {
                $local = (new DateTimeImmutable((string)$match['kickoff_utc'], new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone($timezone));
                $date = $local->format('Y-m-d');
                $time = $local->format('H:i');
            }

            fputcsv($handle, [
                $match['id'],
                $match['round_number'] ?? '',
                $date ?? '',
                $time ?? '',
                (int)($match['kickoff_is_confirmed'] ?? 0),
                $match['home_name'] ?? '',
                $match['away_name'] ?? '',
                $match['home_goals'] ?? '',
                $match['away_goals'] ?? '',
                $match['home_goals_ht'] ?? '',
                $match['away_goals_ht'] ?? '',
                $match['status'] ?? '',
                $match['venue_name'] ?? '',
                $match['spectators'] ?? '',
                $match['note'] ?? '',
            ], $delimiter, '"', '\\');
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
