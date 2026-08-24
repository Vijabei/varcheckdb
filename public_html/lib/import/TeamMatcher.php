<?php
declare(strict_types=1);

/**
 * Ordnet Mannschaftsnamen aus einer Quelle den Teams der eigenen Datenbank zu.
 *
 * Bewusst zurueckhaltend: automatisch zugeordnet wird nur bei einem exakten
 * Treffer auf teams.name_normalized oder team_aliases.alias_normalized.
 * Alles andere wird als Vorschlag angeboten und muss einmal bestaetigt werden.
 * Die Bestaetigung wird als Alias gespeichert und greift ab dem naechsten
 * Import automatisch.
 */
final class TeamMatcher
{
    /** Ab diesem Wert taucht ein Team ueberhaupt als Vorschlag auf. */
    private const SUGGESTION_THRESHOLD = 0.55;

    /** @var array<string, int> normalisierter Name => team_id */
    private array $index = [];

    /** @var array<int, array{id:int, name:string}> */
    private array $teams = [];

    public function __construct()
    {
        foreach (Db::all('SELECT id, name, name_normalized FROM teams') as $team) {
            $this->teams[(int)$team['id']] = ['id' => (int)$team['id'], 'name' => $team['name']];
            $this->index[$team['name_normalized']] = (int)$team['id'];
        }

        foreach (Db::all('SELECT team_id, alias_normalized FROM team_aliases') as $alias) {
            $this->index[$alias['alias_normalized']] = (int)$alias['team_id'];
        }
    }

    /** Exakter Treffer oder null. */
    public function resolve(?string $name): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return $this->index[Normalize::strict($name)] ?? null;
    }

    /**
     * Vorschlaege fuer einen unbekannten Namen, bester zuerst.
     *
     * @return array<int, array{team_id:int, name:string, score:float}>
     */
    public function suggest(string $name, int $limit = 5): array
    {
        $scored = [];
        foreach ($this->teams as $team) {
            $score = Normalize::similarity($name, $team['name']);
            if ($score >= self::SUGGESTION_THRESHOLD) {
                $scored[] = ['team_id' => $team['id'], 'name' => $team['name'], 'score' => $score];
            }
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Alle Namen aus den Zeilen, die noch keinem Team zugeordnet sind.
     *
     * @param ImportRow[] $rows
     * @return array<int, array{name:string, count:int, suggestions:array}>
     */
    public function unresolved(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            foreach ([$row->home, $row->away] as $name) {
                if ($name === null || trim($name) === '' || $this->resolve($name) !== null) {
                    continue;
                }
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        $open = [];
        foreach ($counts as $name => $count) {
            $open[] = [
                'name'        => (string)$name,
                'count'       => $count,
                'suggestions' => $this->suggest((string)$name),
            ];
        }

        usort($open, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $open;
    }

    /** Verknuepft einen Quellnamen dauerhaft mit einem Team. */
    public function addAlias(int $teamId, string $name, ?int $sourceId = null): void
    {
        $normalized = Normalize::strict($name);
        if ($normalized === '' || isset($this->index[$normalized])) {
            return;
        }

        Db::insert('team_aliases', [
            'alias'            => $name,
            'alias_normalized' => $normalized,
            'team_id'          => $teamId,
            'source_id'        => $sourceId,
        ]);

        $this->index[$normalized] = $teamId;
    }

    /**
     * Legt eine neue Mannschaft an.
     *
     * Ein Name kommt genau einmal vor und ist nicht an Geschlecht oder
     * Altersklasse gebunden: dieselbe 'Arminia Bielefeld' steht im Frauen-
     * wie im Maennerwettbewerb. Wo eine Unterscheidung noetig ist, traegt der
     * Name sie bereits - 'Arminia Bielefeld U19', 'SGS Essen II'.
     */
    public function createTeam(string $name): int
    {
        $normalisiert = Normalize::strict($name);

        // Zwei Schreibweisen koennen auf denselben Schluessel fallen -
        // 'FC Köln' und 'FC Koeln' etwa. Dann ist es dieselbe Mannschaft,
        // und der Name, der zuerst da war, bleibt stehen.
        $vorhanden = Db::value('SELECT id FROM teams WHERE name_normalized = ?', [$normalisiert]);

        if ($vorhanden !== null) {
            $this->index[$normalisiert] = (int)$vorhanden;

            return (int)$vorhanden;
        }

        $teamId = Db::insert('teams', [
            'name'            => $name,
            'name_normalized' => $normalisiert,
        ]);

        $this->teams[$teamId] = ['id' => $teamId, 'name' => $name];
        $this->index[Normalize::strict($name)] = $teamId;

        return $teamId;
    }
}
