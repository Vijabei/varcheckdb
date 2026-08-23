<?php
declare(strict_types=1);

/** Lesezugriffe und die gerechnete Tabelle. */

/** Legt einen kleinen, vollstaendig kontrollierten Bestand an. */
function build_league(array $results): int
{
    fresh_db();

    $csId = competition_season_id('frlw');
    $roundId = Db::insert('rounds', [
        'competition_season_id' => $csId, 'number' => 1, 'name' => '1. Spieltag',
    ]);

    $teams = [];
    foreach (['A', 'B', 'C', 'D'] as $name) {
        $teams[$name] = Db::insert('teams', [
            'name' => $name, 'name_normalized' => strtolower($name),
        ]);
    }

    foreach ($results as $index => [$home, $away, $hg, $ag]) {
        Db::insert('matches', [
            'competition_season_id' => $csId,
            'round_id'      => $roundId,
            'home_team_id'  => $teams[$home],
            'away_team_id'  => $teams[$away],
            'kickoff_utc'   => sprintf('2026-09-%02d 13:00:00', $index + 1),
            'home_goals'    => $hg,
            'away_goals'    => $ag,
            'status'        => $hg === null ? 'scheduled' : 'finished',
            'created_at'    => '2026-08-01 00:00:00',
            'updated_at'    => '2026-08-01 00:00:00',
        ]);
    }

    return $csId;
}

T::group('Repo - Tabelle rechnen');

// A gewinnt, B verliert, C und D trennen sich unentschieden.
$csId = build_league([
    ['A', 'B', 3, 1],
    ['C', 'D', 2, 2],
]);
$table = Repo::table($csId);

T::same(4, count($table), 'alle vier Mannschaften stehen in der Tabelle');
T::same('A', $table[0]['name'], 'der Sieger steht oben');
T::same(3, $table[0]['points'], 'ein Sieg gibt drei Punkte');
T::same(1, $table[0]['won'], 'als Sieg gezaehlt');
T::same(2, $table[0]['goalDiff'], 'die Tordifferenz stimmt');
T::same(1, $table[0]['position'], 'die Platzierung wird gesetzt');

$byName = array_column($table, null, 'name');
T::same(1, $byName['C']['points'], 'ein Unentschieden gibt einen Punkt');
T::same(1, $byName['C']['draw'], 'als Unentschieden gezaehlt');
T::same(0, $byName['B']['points'], 'eine Niederlage gibt nichts');
T::same(1, $byName['B']['lost'], 'als Niederlage gezaehlt');
T::same(4, $byName['B']['position'], 'der Verlierer steht unten');

T::group('Repo - Sortierung');

// Gleiche Punktzahl: die Tordifferenz entscheidet.
$csId = build_league([
    ['A', 'B', 5, 0],
    ['C', 'D', 1, 0],
]);
$table = Repo::table($csId);
T::same('A', $table[0]['name'], 'bei gleichen Punkten entscheidet die Tordifferenz');
T::same('C', $table[1]['name'], 'die schlechtere Differenz kommt danach');

// Gleiche Punkte und gleiche Differenz: die Zahl der Tore entscheidet.
$csId = build_league([
    ['A', 'B', 3, 1],
    ['C', 'D', 2, 0],
]);
$table = Repo::table($csId);
T::same('A', $table[0]['name'], 'bei gleicher Differenz entscheidet die Torzahl');

T::group('Repo - nur gespielte Spiele zaehlen');

$csId = build_league([
    ['A', 'B', 2, 0],
    ['C', 'D', null, null],
]);
$table = Repo::table($csId);
$byName = array_column($table, null, 'name');

T::same(1, $byName['A']['matches'], 'das gespielte Spiel zaehlt');
T::same(0, $byName['C']['matches'], 'das angesetzte Spiel zaehlt nicht');
T::same(0, $byName['C']['points'], 'und bringt keine Punkte');
T::same(4, count($table), 'die Mannschaften stehen trotzdem in der Tabelle');

T::group('Repo - Wettbewerb finden');

fresh_db();
T::ok(Repo::competitionSeason('frauen-regionalliga-west') !== null, 'ueber den Slug');
T::ok(Repo::competitionSeason('frlw') !== null, 'ueber das OpenLigaDB-Kuerzel');
T::same(2026, (int)Repo::competitionSeason('frlw')['start_year'], 'ohne Saisonangabe die neueste');
T::ok(Repo::competitionSeason('frlw', 2026) !== null, 'mit passender Saison');
T::same(null, Repo::competitionSeason('frlw', 1999), 'mit unpassender Saison nichts');
T::same(null, Repo::competitionSeason('gibtesnicht'), 'ein unbekannter Schluessel ergibt nichts');

T::group('Repo - Spiele filtern');

$csId = build_league([
    ['A', 'B', 2, 0],
    ['C', 'D', null, null],
]);

T::same(2, count(Repo::matches($csId)), 'ohne Filter alle');
T::same(1, count(Repo::matches($csId, ['status' => 'finished'])), 'nach Status');
T::same(2, count(Repo::matches($csId, ['round' => 1])), 'nach Spieltag');
T::same(0, count(Repo::matches($csId, ['round' => 2])), 'ein leerer Spieltag ergibt nichts');

$rows = Repo::matches($csId);
T::ok(isset($rows[0]['home_name'], $rows[0]['away_name']), 'die Mannschaftsnamen kommen mit');

T::group('Repo - Spiele ohne Termin stehen hinten');

$csId = build_league([['A', 'B', null, null]]);
Db::run('UPDATE matches SET kickoff_utc = NULL');
Db::insert('matches', [
    'competition_season_id' => $csId,
    'round_id'     => (int)Db::value('SELECT id FROM rounds LIMIT 1'),
    'home_team_id' => (int)Db::value('SELECT id FROM teams WHERE name = ?', ['C']),
    'away_team_id' => (int)Db::value('SELECT id FROM teams WHERE name = ?', ['D']),
    'kickoff_utc'  => '2026-09-05 13:00:00',
    'status'       => 'scheduled',
    'created_at'   => '2026-08-01 00:00:00',
    'updated_at'   => '2026-08-01 00:00:00',
]);

$rows = Repo::matches($csId);
T::same('C', $rows[0]['home_name'], 'das angesetzte Spiel kommt zuerst');
T::same(null, $rows[1]['kickoff_utc'], 'das unterminierte danach');

T::group('Repo - aktueller Spieltag');

$csId = build_league([['A', 'B', 2, 0], ['C', 'D', null, null]]);
T::same(1, (int)Repo::currentRound($csId)['number'], 'der Spieltag mit offenen Spielen');

Db::run('UPDATE matches SET status = ?, home_goals = 1, away_goals = 1', ['finished']);
T::same(1, (int)Repo::currentRound($csId)['number'], 'ist alles gespielt, der letzte Spieltag');

T::group('Repo - Kennzahlen');

$stats = Repo::stats();
T::same(2, $stats['competitions'], 'die Wettbewerbe werden gezaehlt');
T::same(4, $stats['teams'], 'die Mannschaften werden gezaehlt');
T::same(2, $stats['matches'], 'die Spiele werden gezaehlt');
T::same(2, $stats['finished'], 'die gespielten werden gezaehlt');
