<?php
declare(strict_types=1);

/** Aenderungen von Hand: einzeln und en bloc. */

function editor_fixture(): array
{
    fresh_db();

    $parsed = (new KickerJsonAdapter())->parse(
        file_get_contents(ROOT . '/tests/fixtures/kicker-sample.json')
    );
    $matcher = new TeamMatcher();
    foreach ($matcher->unresolved($parsed['rows']) as $entry) {
        $matcher->createTeam($entry['name']);
    }

    $csId = competition_season_id('frlw');
    $kicker = Db::one('SELECT id, priority FROM sources WHERE slug = ?', ['kicker']);
    $diff = (new Differ($csId, (int)$kicker['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);
    (new Applier($csId, (int)$kicker['id'], 'Europe/Berlin'))->apply($diff['rows'], $matcher);

    return [$csId, $matcher, $kicker];
}

T::group('Editor - einzelnes Spiel');

[$csId, $matcher, $kicker] = editor_fixture();
$matchId = (int)Db::value('SELECT id FROM matches ORDER BY kickoff_utc LIMIT 1');

$changed = Editor::update($matchId, ['home_goals' => 5, 'away_goals' => 1, 'status' => 'finished']);
sort($changed);
T::same(['away_goals', 'home_goals'], $changed, 'nur die tatsaechlich anderen Felder gelten als geaendert');

T::same(5, (int)Db::value('SELECT home_goals FROM matches WHERE id = ?', [$matchId]), 'der Wert steht drin');
T::same(0, count(Editor::update($matchId, ['home_goals' => 5])), 'derselbe Wert erneut aendert nichts');
T::same(0, count(Editor::update(999999, ['home_goals' => 1])), 'ein unbekanntes Spiel ergibt nichts');

T::group('Editor - Aenderungen sind protokolliert und geschuetzt');

$log = Db::all(
    'SELECT field, old_value, new_value, actor FROM change_log
      WHERE entity_id = ? AND actor = ? ORDER BY field',
    [$matchId, 'admin']
);
T::same(2, count($log), 'beide Aenderungen stehen im Protokoll');
T::same('away_goals', $log[0]['field'], 'mit Feldnamen');
T::same('5', $log[1]['new_value'], 'und neuem Wert');

$owner = Db::value(
    'SELECT s.slug FROM match_field_sources mfs JOIN sources s ON s.id = mfs.source_id
      WHERE mfs.match_id = ? AND mfs.field = ?',
    [$matchId, 'home_goals']
);
T::same('manual', $owner, 'das Feld gehoert jetzt der manuellen Pflege');

// Und es haelt gegen einen erneuten Import derselben Quelle.
$parsed = (new KickerJsonAdapter())->parse(file_get_contents(ROOT . '/tests/fixtures/kicker-sample.json'));
$diff = (new Differ($csId, (int)$kicker['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);
$row = null;
foreach ($diff['rows'] as $candidate) {
    if (($candidate['match_id'] ?? null) === $matchId) { $row = $candidate; break; }
}
T::ok(isset($row['protected']['home_goals']), 'der Import darf das Ergebnis nicht zurueckdrehen');

T::group('Editor - Termin fuer eine Auswahl setzen');

[$csId, $matcher] = editor_fixture();
$ids = array_map('intval', array_column(
    Db::all('SELECT m.id FROM matches m JOIN rounds r ON r.id = m.round_id
              WHERE m.competition_season_id = ? AND r.number = ?', [$csId, 11]),
    'id'
));
T::same(8, count($ids), 'der Spieltag hat acht Spiele');

$n = Editor::setKickoff($ids, '2027-03-14', '15:00', 'Europe/Berlin');
T::same(count($ids), $n, 'alle wurden umgesetzt');

// 14.03.2027 ist Winterzeit: 15:00 Ortszeit sind 14:00 UTC.
T::same(count($ids), (int)Db::value(
    'SELECT COUNT(*) FROM matches WHERE kickoff_utc = ?',
    ['2027-03-14 14:00:00']
), 'alle stehen auf demselben Zeitpunkt');
T::same(count($ids), (int)Db::value('SELECT COUNT(*) FROM matches WHERE id IN (' . implode(',', $ids) . ') AND kickoff_is_confirmed = 1'),
    'ein gesetzter Termin gilt als verbindlich');

T::group('Editor - nur eine Haelfte setzen');

[$csId, $matcher] = editor_fixture();
$matchId = (int)Db::value('SELECT id FROM matches ORDER BY kickoff_utc LIMIT 1');
$vorher = (string)Db::value('SELECT kickoff_utc FROM matches WHERE id = ?', [$matchId]);

Editor::setKickoff([$matchId], null, '20:30', 'Europe/Berlin');
$nachher = (string)Db::value('SELECT kickoff_utc FROM matches WHERE id = ?', [$matchId]);

T::same(substr($vorher, 0, 10), substr($nachher, 0, 10), 'ohne Datum bleibt der Tag stehen');
T::same('18:30:00', substr($nachher, 11), 'nur die Uhrzeit wird gesetzt (20:30 MESZ = 18:30 UTC)');

T::group('Editor - verschieben ueber die Zeitumstellung');

[$csId, $matcher] = editor_fixture();

// Ein Spiel auf den 21.03.2027 um 15:00 setzen - das ist Winterzeit.
$matchId = (int)Db::value('SELECT id FROM matches ORDER BY kickoff_utc LIMIT 1');
Editor::setKickoff([$matchId], '2027-03-21', '15:00', 'Europe/Berlin');
T::same('2027-03-21 14:00:00', (string)Db::value('SELECT kickoff_utc FROM matches WHERE id = ?', [$matchId]),
    'Winterzeit: 15:00 Ortszeit = 14:00 UTC');

// Sieben Tage weiter ist Sommerzeit. Das Spiel muss weiterhin um 15:00
// Ortszeit stattfinden, der UTC-Zeitpunkt verschiebt sich also um eine Stunde.
Editor::shift([$matchId], 7, 'Europe/Berlin');
T::same('2027-03-28 13:00:00', (string)Db::value('SELECT kickoff_utc FROM matches WHERE id = ?', [$matchId]),
    'Sommerzeit: dasselbe Spiel um 15:00 Ortszeit = 13:00 UTC');

$lokal = (new DateTimeImmutable(
    (string)Db::value('SELECT kickoff_utc FROM matches WHERE id = ?', [$matchId]),
    new DateTimeZone('UTC')
))->setTimezone(new DateTimeZone('Europe/Berlin'));
T::same('15:00', $lokal->format('H:i'), 'in Ortszeit gesehen bleibt die Anstosszeit gleich');

T::same(0, Editor::shift([$matchId], 0, 'Europe/Berlin'), 'null Tage aendern nichts');

T::group('Editor - Termine als verbindlich markieren');

[$csId, $matcher] = editor_fixture();
Db::run('UPDATE matches SET kickoff_is_confirmed = 0');

$ids = array_map('intval', array_column(Db::all('SELECT id FROM matches LIMIT 5'), 'id'));
T::same(5, Editor::setConfirmed($ids, true), 'fuenf markiert');
T::same(5, (int)Db::value('SELECT COUNT(*) FROM matches WHERE kickoff_is_confirmed = 1'), 'und in der Datenbank gesetzt');
T::same(0, Editor::setConfirmed($ids, true), 'noch einmal aendert nichts');
T::same(5, Editor::setConfirmed($ids, false), 'wieder auf vorlaeufig');

T::group('Editor - fremde Felder werden nicht geschrieben');

[$csId, $matcher] = editor_fixture();
$matchId = (int)Db::value('SELECT id FROM matches LIMIT 1');
$vorher = Db::one('SELECT * FROM matches WHERE id = ?', [$matchId]);

$changed = Editor::update($matchId, [
    'home_team_id'          => 999,
    'competition_season_id' => 999,
    'id'                    => 999,
    'home_goals'            => 7,
]);

T::same(['home_goals'], $changed, 'nur erlaubte Felder werden angefasst');
T::same($vorher['home_team_id'], Db::value('SELECT home_team_id FROM matches WHERE id = ?', [$matchId]),
    'die Mannschaft bleibt unveraendert');
