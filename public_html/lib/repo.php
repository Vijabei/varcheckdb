<?php
declare(strict_types=1);

/**
 * Lesezugriffe auf den Spielbestand.
 *
 * Beide API-Fassungen und die angemeldeten Seiten greifen hierauf zu; es gibt nur
 * eine Datenhaltung und nur eine Stelle, an der gelesen wird.
 */
final class Repo
{
    /** Alle Wettbewerbe je Saison, wie sie oeffentlich angeboten werden. */
    public static function competitions(): array
    {
        return Db::all(
            'SELECT cs.id, cs.shortcut, cs.name, cs.team_count,
                    c.slug, c.name AS competition_name, c.gender, c.age_group,
                    s.name AS season_name, s.start_year
               FROM competition_seasons cs
               JOIN competitions c ON c.id = cs.competition_id
               JOIN seasons s      ON s.id = cs.season_id
              ORDER BY s.start_year DESC, c.name'
        );
    }

    /** Ein Wettbewerb, angesprochen ueber Slug oder OpenLigaDB-Kuerzel. */
    public static function competitionSeason(string $key, ?int $startYear = null): ?array
    {
        $sql = 'SELECT cs.id, cs.shortcut, cs.name, cs.team_count,
                       c.slug, c.name AS competition_name, c.gender,
                       s.name AS season_name, s.start_year
                  FROM competition_seasons cs
                  JOIN competitions c ON c.id = cs.competition_id
                  JOIN seasons s      ON s.id = cs.season_id
                 WHERE (c.slug = ? OR cs.shortcut = ?)';

        $params = [$key, $key];

        if ($startYear !== null) {
            $sql .= ' AND s.start_year = ?';
            $params[] = $startYear;
        }

        // Ohne Saisonangabe die neueste liefern.
        $sql .= ' ORDER BY s.start_year DESC LIMIT 1';

        return Db::one($sql, $params);
    }

    /**
     * Spiele eines Wettbewerbs.
     *
     * @param array{round?: int, from?: string, to?: string, status?: string} $filter
     */
    public static function matches(int $competitionSeasonId, array $filter = []): array
    {
        $sql = 'SELECT m.*, r.number AS round_number, r.name AS round_name,
                       h.name AS home_name, h.short_name AS home_short, h.logo_url AS home_logo,
                       a.name AS away_name, a.short_name AS away_short, a.logo_url AS away_logo,
                       v.name AS venue_name, v.city AS venue_city, v.capacity AS venue_capacity
                  FROM matches m
                  JOIN rounds r ON r.id = m.round_id
                  JOIN teams h  ON h.id = m.home_team_id
                  JOIN teams a  ON a.id = m.away_team_id
             LEFT JOIN venues v ON v.id = m.venue_id
                 WHERE m.competition_season_id = ?';

        $params = [$competitionSeasonId];

        if (isset($filter['round'])) {
            $sql .= ' AND r.number = ?';
            $params[] = (int)$filter['round'];
        }
        if (isset($filter['status'])) {
            $sql .= ' AND m.status = ?';
            $params[] = $filter['status'];
        }
        if (isset($filter['from'])) {
            $sql .= ' AND m.kickoff_utc >= ?';
            $params[] = $filter['from'];
        }
        if (isset($filter['to'])) {
            $sql .= ' AND m.kickoff_utc <= ?';
            $params[] = $filter['to'];
        }

        // Spiele ohne Termin ans Ende, sonst stehen sie vor allem anderen.
        $sql .= ' ORDER BY r.number, CASE WHEN m.kickoff_utc IS NULL THEN 1 ELSE 0 END,'
              . ' m.kickoff_utc, h.name';

        return Db::all($sql, $params);
    }

    /** Der aktuelle Spieltag: der naechste mit einem noch offenen Spiel. */
    public static function currentRound(int $competitionSeasonId): ?array
    {
        $row = Db::one(
            'SELECT r.number, r.name, r.id
               FROM matches m JOIN rounds r ON r.id = m.round_id
              WHERE m.competition_season_id = ? AND m.status <> ?
              ORDER BY r.number LIMIT 1',
            [$competitionSeasonId, 'finished']
        );

        // Alles gespielt? Dann ist der letzte Spieltag der aktuelle.
        return $row ?? Db::one(
            'SELECT r.number, r.name, r.id
               FROM rounds r
              WHERE r.competition_season_id = ?
              ORDER BY r.number DESC LIMIT 1',
            [$competitionSeasonId]
        );
    }

    /**
     * Tabelle aus den beendeten Spielen.
     *
     * Wird gerechnet und nicht gespeichert: so kann sie nie vom Spielbestand
     * abweichen, und eine Korrektur wirkt sich sofort aus.
     */
    public static function table(int $competitionSeasonId): array
    {
        $teams = Db::all(
            'SELECT DISTINCT t.id, t.name, t.short_name, t.logo_url
               FROM matches m
               JOIN teams t ON t.id IN (m.home_team_id, m.away_team_id)
              WHERE m.competition_season_id = ?',
            [$competitionSeasonId]
        );

        $rows = [];
        foreach ($teams as $team) {
            $rows[(int)$team['id']] = [
                'team_id'   => (int)$team['id'],
                'name'      => $team['name'],
                'shortName' => $team['short_name'],
                'logo'      => $team['logo_url'],
                'matches'   => 0, 'won' => 0, 'draw' => 0, 'lost' => 0,
                'goals'     => 0, 'opponentGoals' => 0, 'goalDiff' => 0, 'points' => 0,
            ];
        }

        $played = Db::all(
            'SELECT home_team_id, away_team_id, home_goals, away_goals
               FROM matches
              WHERE competition_season_id = ? AND status = ?
                AND home_goals IS NOT NULL AND away_goals IS NOT NULL',
            [$competitionSeasonId, 'finished']
        );

        foreach ($played as $match) {
            $home = (int)$match['home_team_id'];
            $away = (int)$match['away_team_id'];
            $hg = (int)$match['home_goals'];
            $ag = (int)$match['away_goals'];

            foreach ([[$home, $hg, $ag], [$away, $ag, $hg]] as [$team, $for, $against]) {
                if (!isset($rows[$team])) {
                    continue;
                }
                $rows[$team]['matches']++;
                $rows[$team]['goals'] += $for;
                $rows[$team]['opponentGoals'] += $against;
                $rows[$team]['goalDiff'] = $rows[$team]['goals'] - $rows[$team]['opponentGoals'];

                if ($for > $against) {
                    $rows[$team]['won']++;
                    $rows[$team]['points'] += 3;
                } elseif ($for === $against) {
                    $rows[$team]['draw']++;
                    $rows[$team]['points'] += 1;
                } else {
                    $rows[$team]['lost']++;
                }
            }
        }

        $rows = array_values($rows);
        usort($rows, static function (array $a, array $b): int {
            return [$b['points'], $b['goalDiff'], $b['goals'], $a['name']]
               <=> [$a['points'], $a['goalDiff'], $a['goals'], $b['name']];
        });

        foreach ($rows as $index => $row) {
            $rows[$index]['position'] = $index + 1;
        }

        return $rows;
    }

    /** Ein paar Zahlen fuer die Startseite und die Uebersichten. */
    public static function stats(): array
    {
        return [
            'competitions' => (int)Db::value('SELECT COUNT(*) FROM competition_seasons'),
            'teams'        => (int)Db::value('SELECT COUNT(*) FROM teams'),
            'matches'      => (int)Db::value('SELECT COUNT(*) FROM matches'),
            'finished'     => (int)Db::value('SELECT COUNT(*) FROM matches WHERE status = ?', ['finished']),
            'aliases'      => (int)Db::value('SELECT COUNT(*) FROM team_aliases'),
            'last_change'  => Db::value('SELECT MAX(created_at) FROM change_log'),
        ];
    }
}
