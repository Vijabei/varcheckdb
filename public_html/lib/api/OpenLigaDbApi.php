<?php
declare(strict_types=1);

/**
 * Ausgabe im Format von api.openligadb.de.
 *
 * Reine Uebersetzungsschicht ueber Repo - es gibt keine zweite Datenhaltung.
 * Zweck ist, dass bestehende Auswertungen, die gegen OpenLigaDB geschrieben
 * wurden, ohne Aenderung auch gegen diese Anwendung laufen.
 *
 * Die Feldnamen sind gegen die echte Schnittstelle geprueft und in
 * tests/fixtures/openligadb-referenz.json eingefroren. Zwei Eigenheiten des
 * Originals werden bewusst nachgebildet:
 *
 *  - leagueSeason ist in getavailableleagues eine Zeichenkette, in
 *    getmatchdata dagegen eine Zahl.
 *  - matchDateTime traegt keine Zeitzonenangabe; sie steckt in timeZoneID
 *    und in matchDateTimeUTC.
 *
 * Nicht gefuellt wird goals: Torschuetzen liefert keine unserer Quellen.
 * Das Feld bleibt als leere Liste bestehen, damit Auswertungen, die darueber
 * laufen, nicht auf einen fehlenden Schluessel stossen.
 */
final class OpenLigaDbApi
{
    private const TIME_ZONE_ID = 'W. Europe Standard Time';

    public function __construct(private readonly string $timezone = 'Europe/Berlin')
    {
    }

    /** GET /getavailableleagues */
    public function availableLeagues(): array
    {
        $out = [];

        foreach (Repo::competitions() as $row) {
            $out[] = [
                'leagueId'       => (int)$row['id'],
                'leagueName'     => $this->leagueName($row),
                'leagueShortcut' => $row['shortcut'],
                // Im Original hier eine Zeichenkette, in getmatchdata eine Zahl.
                'leagueSeason'   => (string)$row['start_year'],
                'sport'          => ['sportId' => 1, 'sportName' => 'Fußball'],
            ];
        }

        return $out;
    }

    /** GET /getmatchdata/{shortcut}/{season}[/{groupOrderID}] */
    public function matchData(array $competition, ?int $groupOrderId = null): array
    {
        $filter = $groupOrderId === null ? [] : ['round' => $groupOrderId];
        $out = [];

        foreach (Repo::matches((int)$competition['id'], $filter) as $row) {
            $out[] = $this->match($row, $competition);
        }

        return $out;
    }

    /** GET /getbltable/{shortcut}/{season} */
    public function table(array $competition): array
    {
        $out = [];

        foreach (Repo::table((int)$competition['id']) as $row) {
            $out[] = [
                'teamInfoId'    => $row['team_id'],
                'teamName'      => $row['name'],
                'shortName'     => $row['shortName'] ?? $row['name'],
                'teamIconUrl'   => $row['logo'],
                'points'        => $row['points'],
                'opponentGoals' => $row['opponentGoals'],
                'goals'         => $row['goals'],
                'matches'       => $row['matches'],
                'won'           => $row['won'],
                'lost'          => $row['lost'],
                'draw'          => $row['draw'],
                'goalDiff'      => $row['goalDiff'],
            ];
        }

        return $out;
    }

    /** GET /getcurrentgroup/{shortcut} */
    public function currentGroup(array $competition): ?array
    {
        $round = Repo::currentRound((int)$competition['id']);

        return $round === null ? null : $this->group($round, $competition);
    }

    // ------------------------------------------------------------ Umsetzung

    private function match(array $row, array $competition): array
    {
        [$local, $utc] = $this->times($row['kickoff_utc']);

        return [
            'matchID'            => (int)$row['id'],
            'matchDateTime'      => $local,
            'timeZoneID'         => self::TIME_ZONE_ID,
            'leagueId'           => (int)$competition['id'],
            'leagueName'         => $this->leagueName($competition),
            'leagueSeason'       => (int)$competition['start_year'],
            'leagueShortcut'     => $competition['shortcut'],
            'matchDateTimeUTC'   => $utc,
            'group'              => $this->group([
                'number' => $row['round_number'],
                'name'   => $row['round_name'],
                'id'     => $row['round_id'],
            ], $competition),
            'team1'              => $this->team($row, 'home'),
            'team2'              => $this->team($row, 'away'),
            'lastUpdateDateTime' => $this->stamp($row['updated_at']),
            'matchIsFinished'    => $row['status'] === 'finished',
            'matchResults'       => $this->results($row),
            'goals'              => [],
            'location'           => $this->location($row),
            'numberOfViewers'    => $row['spectators'] === null ? null : (int)$row['spectators'],
        ];
    }

    private function team(array $row, string $side): array
    {
        return [
            'teamId'        => (int)$row[$side . '_team_id'],
            'teamName'      => $row[$side . '_name'],
            'shortName'     => $row[$side . '_short'] ?? $row[$side . '_name'],
            'teamIconUrl'   => $row[$side . '_logo'],
            'teamGroupName' => null,
        ];
    }

    /**
     * Halbzeit und Endergebnis in der Form des Originals.
     *
     * Ein noch nicht gespieltes Spiel hat wie im Original eine leere Liste.
     *
     * Bei einem gespielten Spiel steht der HalfTime-Eintrag immer da, auch
     * wenn der Stand unbekannt ist - dann mit null als Punktzahl. Ihn
     * wegzulassen wuerde Auswertungen in die Irre fuehren, die
     * matchResults[0] als Halbzeit lesen und dort das Endergebnis vorfaenden.
     * Ein erfundenes 0:0 kam nicht in Frage: ein Spiel, das zur Pause 1:0
     * stand, stuende damit falsch in der oeffentlichen Ausgabe.
     *
     * resultID gibt es bei uns nicht als eigene Groesse - OpenLigaDB fuehrt
     * Ergebnisse als eigene Datensaetze. Abgeleitet aus der Spiel-ID bleibt
     * der Wert wenigstens eindeutig und ueber Abrufe hinweg stabil.
     */
    private function results(array $row): array
    {
        if ($row['home_goals'] === null || $row['away_goals'] === null) {
            return [];
        }

        $matchId = (int)$row['id'];
        $bekannt = $row['home_goals_ht'] !== null && $row['away_goals_ht'] !== null;

        $results = [[
            'resultID'          => $matchId * 10 + 1,
            'resultName'        => 'Halbzeitergebnis',
            'pointsTeam1'       => $bekannt ? (int)$row['home_goals_ht'] : null,
            'pointsTeam2'       => $bekannt ? (int)$row['away_goals_ht'] : null,
            'resultOrderID'     => 1,
            'resultTypeID'      => 1,
            'resultTypeKind'    => 'HalfTime',
            'resultDescription' => 'Ergebnis nach Ende der ersten Halbzeit',
        ]];

        $results[] = [
            'resultID'          => $matchId * 10 + 2,
            'resultName'        => 'Endergebnis',
            'pointsTeam1'       => (int)$row['home_goals'],
            'pointsTeam2'       => (int)$row['away_goals'],
            'resultOrderID'     => 2,
            'resultTypeID'      => 2,
            'resultTypeKind'    => 'After90Minutes',
            'resultDescription' => 'Ergebnis nach Ende der offiziellen Spielzeit',
        ];

        return $results;
    }

    private function location(array $row): ?array
    {
        if (($row['venue_name'] ?? null) === null) {
            return null;
        }

        return [
            'locationID'      => (int)($row['venue_id'] ?? 0),
            'locationStadium' => $row['venue_name'],
            'locationCity'    => $row['venue_city'],
        ];
    }

    private function group(array $round, array $competition): array
    {
        $number = (int)($round['number'] ?? 0);

        return [
            'groupName'    => $round['name'] ?? ($number . '. Spieltag'),
            'groupOrderID' => $number,
            'groupID'      => (int)($round['id'] ?? 0),
        ];
    }

    /** 'Frauen-Regionalliga West 2026/2027' - wie im Original mit Saison. */
    private function leagueName(array $competition): string
    {
        return (string)$competition['name'];
    }

    /** @return array{0: ?string, 1: ?string} Ortszeit ohne Zone, dann UTC mit Z. */
    private function times(?string $utc): array
    {
        if ($utc === null || $utc === '') {
            return [null, null];
        }

        $moment = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        return [
            $moment->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d\TH:i:s'),
            $moment->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function stamp(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s');
    }
}
