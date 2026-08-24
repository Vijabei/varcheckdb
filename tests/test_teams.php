<?php
declare(strict_types=1);

/**
 * Ein Mannschaftsname kommt genau einmal vor.
 *
 * 'Arminia Bielefeld' ist ein Eintrag und steht im Frauen- wie im
 * Maennerwettbewerb; welcher gemeint ist, sagt das Spiel. Wo eine
 * Unterscheidung noetig ist, traegt der Name sie bereits.
 */

T::group('Mannschaften - ein Name, ein Eintrag');

fresh_db();
$matcher = new TeamMatcher();

$erste = $matcher->createTeam('Arminia Bielefeld');
$nochmal = $matcher->createTeam('Arminia Bielefeld');

T::same($erste, $nochmal, 'derselbe Name ergibt dieselbe Mannschaft');
T::same(1, (int)Db::value('SELECT COUNT(*) FROM teams'), 'und keinen zweiten Eintrag');

$u19 = $matcher->createTeam('Arminia Bielefeld U19');
T::ok($u19 !== $erste, 'die U19 ist eine eigene Mannschaft');
T::same(2, (int)Db::value('SELECT COUNT(*) FROM teams'), 'jetzt sind es zwei');

$zweite = $matcher->createTeam('SGS Essen II');
$ersteEssen = $matcher->createTeam('SGS Essen');
T::ok($zweite !== $ersteEssen, 'Reserve und erste Mannschaft bleiben getrennt');

T::group('Mannschaften - abweichende Schreibweisen');

// Beide fallen auf denselben normalisierten Schluessel. Ein zweiter Eintrag
// waere ein Duplikat, das die Datenbank ohnehin nicht zuliesse.
$a = $matcher->createTeam('FC Köln');
$b = $matcher->createTeam('FC Koeln');
T::same($a, $b, 'zwei Schreibweisen ergeben eine Mannschaft');
T::same('FC Köln', (string)Db::value('SELECT name FROM teams WHERE id = ?', [$a]),
    'der zuerst eingetragene Name bleibt stehen');

T::group('Mannschaften - dieselbe Mannschaft in mehreren Wettbewerben');

fresh_db();

// Ein Frauen- und ein Maennerwettbewerb.
$frauen = competition_season_id('frlw');
$maenner = Competitions::create([
    'slug' => 'maenner-regionalliga-west', 'shortcut' => 'mrlw',
    'name' => 'Maenner-Regionalliga West', 'start_year' => 2026,
]);

$matcher = new TeamMatcher();
$dortmund = $matcher->createTeam('Borussia Dortmund');
$gegner = $matcher->createTeam('MSV Duisburg');

$spiel = function (int $csId, int $heim, int $gast, string $datum) {
    $roundId = Db::value(
        'SELECT id FROM rounds WHERE competition_season_id = ? AND number = 1',
        [$csId]
    ) ?? Db::insert('rounds', [
        'competition_season_id' => $csId, 'number' => 1, 'name' => '1. Spieltag',
    ]);

    return Db::insert('matches', [
        'competition_season_id' => $csId, 'round_id' => (int)$roundId,
        'home_team_id' => $heim, 'away_team_id' => $gast,
        'kickoff_utc' => $datum, 'status' => 'scheduled',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
};

$spiel($frauen, $dortmund, $gegner, '2026-08-20 17:00:00');
$spiel($maenner, $dortmund, $gegner, '2026-08-21 17:00:00');

T::same(2, (int)Db::value('SELECT COUNT(*) FROM teams'), 'es gibt weiterhin nur zwei Mannschaften');
T::same(2, (int)Db::value('SELECT COUNT(*) FROM matches WHERE home_team_id = ?', [$dortmund]),
    'dieselbe Mannschaft spielt in beiden Wettbewerben');

T::same(1, count(Repo::matches($frauen)), 'der Frauenwettbewerb zeigt nur sein Spiel');
T::same(1, count(Repo::matches($maenner)), 'der Maennerwettbewerb nur seines');

// Und die Tabellen bleiben getrennt.
T::same(2, count(Repo::table($frauen)), 'die Frauentabelle hat zwei Mannschaften');
T::same(2, count(Repo::table($maenner)), 'die Maennertabelle ebenso');

T::group('Mannschaften - ein Alias zeigt auf genau eine Mannschaft');

$matcher->addAlias($dortmund, 'BVB', null);
T::same($dortmund, $matcher->resolve('BVB'), 'der Alias loest auf');

// Derselbe Alias fuer eine andere Mannschaft wuerde die Zuordnung mehrdeutig
// machen; er wird stillschweigend uebergangen.
$matcher->addAlias($gegner, 'BVB', null);
T::same($dortmund, $matcher->resolve('BVB'), 'ein vergebener Alias wandert nicht weiter');
T::same(1, (int)Db::value('SELECT COUNT(*) FROM team_aliases WHERE alias_normalized = ?', ['bvb']),
    'und wird nicht doppelt gespeichert');

T::group('Mannschaften - Entfernen eines Wettbewerbs laesst sie stehen');

Competitions::remove($maenner);
T::same(2, (int)Db::value('SELECT COUNT(*) FROM teams'),
    'die Mannschaften bleiben, sie gehoeren dem anderen Wettbewerb weiter');
T::same(1, count(Repo::matches($frauen)), 'dessen Spiel ist unberuehrt');
