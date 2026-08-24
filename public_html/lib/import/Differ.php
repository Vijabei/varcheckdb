<?php
declare(strict_types=1);

/**
 * Vergleicht normalisierte Importzeilen mit dem Datenbestand.
 *
 * Der Differ schreibt nichts. Er erzeugt nur die Vorschau, die im
 * Adminbereich bestaetigt wird - das ist der Kern des Prinzips, dass ein
 * Import nie unmittelbar Fakten setzt.
 *
 * Liefert die Quelle fuer eine Paarung mehrere Termine, wird der vom Adapter
 * gewaehlte uebernommen und die verworfene Angabe in 'alternatives'
 * mitgefuehrt. Frueher blieb eine solche Zeile liegen, bis jemand entschied -
 * das liess Spieltage unvollstaendig, obwohl der Adapter die Regel kennt.
 * Die Vorschau zeigt die Abweichung, damit sie ueberstimmt werden kann.
 *
 * Ein Feld gilt als geschuetzt, wenn es in match_field_sources als
 * confirmed = 1 mit einer hoeherwertigen Quelle (kleinere priority)
 * eingetragen ist. Solche Felder erscheinen in der Vorschau, werden aber
 * nicht uebernommen.
 */
final class Differ
{
    /** Felder, die ein Import ueberhaupt setzen darf. */
    private const FIELDS = [
        'kickoff_utc', 'kickoff_is_confirmed',
        'home_goals', 'away_goals', 'home_goals_ht', 'away_goals_ht',
        'status', 'note',
    ];

    // venue fehlt hier bewusst: die Spielstaette braucht eine Aufloesung von
    // Name auf venues.id, und der Differ schreibt nichts. Solange sie in den
    // Importdateien nur vereinzelt auftaucht, waere das Aufwand ohne Ertrag.

    public function __construct(
        private readonly int $competitionSeasonId,
        private readonly int $sourcePriority,
        private readonly string $timezone,
    ) {
    }

    /**
     * @param ImportRow[] $rows
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function compare(array $rows, TeamMatcher $matcher): array
    {
        $result = [];
        $summary = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'skip' => 0, 'ambiguous' => 0];

        foreach ($rows as $row) {
            $entry = $this->compareRow($row, $matcher);
            $summary[$entry['action']] = ($summary[$entry['action']] ?? 0) + 1;

            if ($entry['alternatives'] !== []) {
                $summary['ambiguous']++;
            }

            $result[] = $entry;
        }

        return ['rows' => $result, 'summary' => $summary];
    }

    private function compareRow(ImportRow $row, TeamMatcher $matcher): array
    {
        $homeId = $matcher->resolve($row->home);
        $awayId = $matcher->resolve($row->away);

        $base = [
            'line_no'      => $row->lineNo,
            'round'        => $row->round,
            'home'         => $row->home,
            'away'         => $row->away,
            'alternatives' => $row->alternatives,
            'changes'      => [],
            'protected'    => [],
            'match_id'     => null,
        ];

        if ($homeId === null || $awayId === null) {
            $missing = [];
            if ($homeId === null) { $missing[] = (string)$row->home; }
            if ($awayId === null) { $missing[] = (string)$row->away; }

            return array_merge($base, [
                'action'  => 'skip',
                'message' => 'Mannschaft noch nicht zugeordnet: ' . implode(', ', $missing),
            ]);
        }

        $existing = $this->findMatch($row, $homeId, $awayId);
        $incoming = $this->incomingValues($row);

        if ($existing === null) {
            return array_merge($base, [
                'action'  => 'create',
                'changes' => $incoming,
                'message' => null,
            ]);
        }

        $changes = [];
        $protected = [];

        foreach ($incoming as $field => $value) {
            if (!$this->differs($existing[$field] ?? null, $value)) {
                continue;
            }

            if ($this->isProtected((int)$existing['id'], $field)) {
                $protected[$field] = ['from' => $existing[$field] ?? null, 'to' => $value];
                continue;
            }

            $changes[$field] = ['from' => $existing[$field] ?? null, 'to' => $value];
        }

        return array_merge($base, [
            'action'    => $changes === [] ? 'unchanged' : 'update',
            'match_id'  => (int)$existing['id'],
            'changes'   => $changes,
            'protected' => $protected,
            'message'   => $protected === []
                ? null
                : sprintf('%d Feld(er) bleiben unveraendert, weil sie manuell bestaetigt sind.', count($protected)),
        ]);
    }

    /**
     * Werte, zu denen die Quelle tatsaechlich etwas sagt.
     * null bedeutet 'keine Aussage' und wird nicht als Aenderung gewertet.
     */
    private function incomingValues(ImportRow $row): array
    {
        $values = [];

        $kickoff = $row->kickoffUtc($this->timezone);
        if ($kickoff !== null) {
            $values['kickoff_utc'] = $kickoff;
        }
        if ($row->kickoffConfirmed !== null) {
            $values['kickoff_is_confirmed'] = $row->kickoffConfirmed ? 1 : 0;
        }
        foreach (['homeGoals' => 'home_goals', 'awayGoals' => 'away_goals',
                  'homeGoalsHt' => 'home_goals_ht', 'awayGoalsHt' => 'away_goals_ht'] as $property => $column) {
            if ($row->{$property} !== null) {
                $values[$column] = $row->{$property};
            }
        }
        if ($row->status !== null && $row->status !== '') {
            $values['status'] = $row->status;
        }
        if ($row->note !== null) {
            $values['note'] = $row->note;
        }

        return array_intersect_key($values, array_flip(self::FIELDS));
    }

    private function findMatch(ImportRow $row, int $homeId, int $awayId): ?array
    {
        if ($row->matchId !== null) {
            return Db::one('SELECT * FROM matches WHERE id = ?', [$row->matchId]);
        }

        return Db::one(
            'SELECT m.* FROM matches m
               JOIN rounds r ON r.id = m.round_id
              WHERE m.competition_season_id = ?
                AND r.number = ?
                AND m.home_team_id = ?
                AND m.away_team_id = ?',
            [$this->competitionSeasonId, $row->round ?? 0, $homeId, $awayId]
        );
    }

    private function isProtected(int $matchId, string $field): bool
    {
        return FieldSource::isProtected($matchId, $field, $this->sourcePriority);
    }

    private function differs(mixed $current, mixed $incoming): bool
    {
        if ($current === null || $incoming === null) {
            return $current !== $incoming;
        }

        return (string)$current !== (string)$incoming;
    }
}
