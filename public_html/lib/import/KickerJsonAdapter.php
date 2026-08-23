<?php
declare(strict_types=1);

/**
 * Liest die Datei, die tools/fetch_kicker.py lokal erzeugt.
 *
 * Der Abruf bei kicker.de findet bewusst nicht auf dem Webserver statt,
 * sondern auf dem Rechner des Admins. Hier kommt nur noch die fertige
 * Datei an.
 */
final class KickerJsonAdapter implements Adapter
{
    public function sourceSlug(): string
    {
        return 'kicker';
    }

    public function parse(string $content): array
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ImportException('Datei ist kein gueltiges JSON: ' . $e->getMessage());
        }

        // 'vijabei-import/1' war die Kennung vor der Umbenennung des Projekts.
        // Dateien, die damit erzeugt wurden, bleiben lesbar.
        $bekannt = ['varcheckdb-import/1', 'vijabei-import/1'];

        if (!is_array($data) || !in_array($data['format'] ?? null, $bekannt, true)) {
            throw new ImportException(
                'Unerwartetes Format. Erwartet wird eine Datei aus tools/fetch_kicker.py '
                . '(Feld "format": "varcheckdb-import/1").'
            );
        }

        $matches = $data['matches'] ?? null;
        if (!is_array($matches) || $matches === []) {
            throw new ImportException('Die Datei enthaelt keine Spiele.');
        }

        // Konflikte sind nach (Spieltag, Heim, Gast) geschluesselt, damit die
        // Alternativen an der passenden Zeile haengen.
        $conflicts = [];
        foreach ($data['conflicts'] ?? [] as $conflict) {
            $key = $this->key($conflict['round'] ?? null, $conflict['home'] ?? null, $conflict['away'] ?? null);
            $conflicts[$key] = $conflict['alternatives'] ?? [];
        }

        $rows = [];
        foreach ($matches as $index => $match) {
            $key = $this->key($match['round'] ?? null, $match['home'] ?? null, $match['away'] ?? null);

            $rows[] = new ImportRow(
                round: $this->int($match['round'] ?? null),
                home: $match['home'] ?? null,
                away: $match['away'] ?? null,
                kickoffDate: $match['kickoff_date'] ?? null,
                kickoffTime: $match['kickoff_time'] ?? null,
                kickoffConfirmed: isset($match['kickoff_confirmed'])
                    ? (bool)$match['kickoff_confirmed']
                    : null,
                homeGoals: $this->int($match['home_goals'] ?? null),
                awayGoals: $this->int($match['away_goals'] ?? null),
                homeGoalsHt: $this->int($match['home_goals_ht'] ?? null),
                awayGoalsHt: $this->int($match['away_goals_ht'] ?? null),
                status: $match['status'] ?? null,
                venue: $match['venue'] ?? null,
                sourceMatchId: isset($match['source_match_id']) ? (string)$match['source_match_id'] : null,
                homeSourceId: isset($match['home_source_id']) ? (string)$match['home_source_id'] : null,
                awaySourceId: isset($match['away_source_id']) ? (string)$match['away_source_id'] : null,
                alternatives: $conflicts[$key] ?? [],
                lineNo: $index + 1,
            );
        }

        $notices = [];
        if ($conflicts !== []) {
            $notices[] = sprintf(
                '%d Paarungen haben in der Quelle zwei abweichende Termine. '
                . 'Sie sind unten als Konflikt markiert und warten auf deine Auswahl.',
                count($conflicts)
            );
        }

        return [
            'meta' => [
                'competition_name'  => $data['competition_name'] ?? null,
                'season'            => $data['season'] ?? null,
                'season_start_year' => $this->int($data['season_start_year'] ?? null),
                'timezone'          => $data['timezone'] ?? 'Europe/Berlin',
                'fetched_at'        => $data['fetched_at'] ?? null,
            ],
            'rows'    => $rows,
            'notices' => $notices,
        ];
    }

    private function key(mixed $round, mixed $home, mixed $away): string
    {
        return sprintf('%s|%s|%s', (string)$round, Normalize::strict((string)$home), Normalize::strict((string)$away));
    }

    private function int(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int)$value;
    }
}
