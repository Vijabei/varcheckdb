<?php
declare(strict_types=1);

/**
 * Durchstich mit echten kicker.de-Daten:
 * einlesen, Mannschaften zuordnen, vergleichen, uebernehmen, erneut vergleichen.
 */

$fixture = file_get_contents(ROOT . '/tests/fixtures/kicker-sample.json');

T::group('KickerJsonAdapter - Einlesen');

$adapter = new KickerJsonAdapter();
$parsed = $adapter->parse($fixture);

T::same(24, count($parsed['rows']), '24 Spiele eingelesen');
T::same('Frauen-Regionalliga West', $parsed['meta']['competition_name'], 'Wettbewerb erkannt');
T::same(2026, $parsed['meta']['season_start_year'], 'Saison-Startjahr erkannt');
T::same(2, count(array_filter($parsed['rows'], fn(ImportRow $r) => $r->hasConflict())), '2 Zeilen tragen eine zweite Terminangabe');

T::throws(fn() => $adapter->parse('kein json'), 'unlesbare Datei wird abgewiesen');
T::throws(fn() => $adapter->parse('{"format":"etwas-anderes"}'), 'fremdes Format wird abgewiesen');

T::group('ImportRow - Zeitzone');

$winter = new ImportRow(round: 1, home: 'A', away: 'B', kickoffDate: '2027-01-10', kickoffTime: '15:00');
$summer = new ImportRow(round: 1, home: 'A', away: 'B', kickoffDate: '2026-08-20', kickoffTime: '19:00');
T::same('2027-01-10 14:00:00', $winter->kickoffUtc('Europe/Berlin'), 'Winterzeit: MEZ ist UTC+1');
T::same('2026-08-20 17:00:00', $summer->kickoffUtc('Europe/Berlin'), 'Sommerzeit: MESZ ist UTC+2');
T::same(null, (new ImportRow(round: 1, home: 'A', away: 'B'))->kickoffUtc('Europe/Berlin'), 'ohne Datum kein Zeitstempel');

T::group('TeamMatcher - unbekannte Mannschaften');

fresh_db();
$matcher = new TeamMatcher();
$open = $matcher->unresolved($parsed['rows']);
T::same(16, count($open), 'alle 16 Mannschaften sind zunaechst unbekannt');
T::same(0, count($open[0]['suggestions']), 'ohne Bestand gibt es keine Vorschlaege');

foreach ($open as $entry) {
    $matcher->createTeam($entry['name'], 'women', 'senior');
}
T::same(0, count($matcher->unresolved($parsed['rows'])), 'nach dem Anlegen ist nichts mehr offen');

T::group('TeamMatcher - Alias und Vorschlag');

$dortmund = $matcher->resolve('Borussia Dortmund');
T::ok($dortmund !== null, 'exakter Name wird aufgeloest');
T::same(null, $matcher->resolve('BVB Dortmund Frauen'), 'abweichender Name zunaechst nicht');
$matcher->addAlias($dortmund, 'BVB Dortmund Frauen', source_id('worldfootball'));
T::same($dortmund, $matcher->resolve('BVB Dortmund Frauen'), 'nach dem Alias schon');

$suggestions = $matcher->suggest('DSC Arminia Bielefeld');
T::ok($suggestions !== [] && $suggestions[0]['name'] === 'Arminia Bielefeld', 'Langform schlaegt die Kurzform vor');
$essen = $matcher->suggest('SGS Essen');
T::ok(!in_array('SGS Essen II', array_column($essen, 'name'), true), 'die Reserve wird nicht vorgeschlagen');

T::group('Differ - Erstimport');

$csId = competition_season_id('frlw');
$kicker = Db::one('SELECT id, priority FROM sources WHERE slug = ?', ['kicker']);
$differ = new Differ($csId, (int)$kicker['priority'], 'Europe/Berlin');
$diff = $differ->compare($parsed['rows'], $matcher);

T::same(24, $diff['summary']['create'], 'alle 24 Spiele sind neu');
T::same(2, $diff['summary']['ambiguous'], '2 davon tragen eine abweichende Terminangabe');
T::same(0, $diff['summary']['skip'], 'nichts uebersprungen');

T::group('Applier - Uebernahme');

$applier = new Applier($csId, (int)$kicker['id'], 'Europe/Berlin');
$result = $applier->apply($diff['rows'], $matcher, [], 'kicker-sample.json');

T::same(24, $result['created'], 'alle 24 Spiele angelegt');
T::same(0, $result['skipped'], 'nichts bleibt liegen - ein Spieltag waere sonst unvollstaendig');
T::same(24, (int)Db::value('SELECT COUNT(*) FROM matches'), '24 Zeilen in matches');
T::same(3, (int)Db::value('SELECT COUNT(*) FROM rounds'), '3 Spieltage angelegt');
T::same(8, (int)Db::value('SELECT COUNT(*) FROM matches WHERE status = ?', ['finished']), 'der erste Spieltag ist komplett gespielt');
T::same('2026-08-20 17:00:00', (string)Db::value(
    'SELECT kickoff_utc FROM matches ORDER BY kickoff_utc LIMIT 1'
), 'erster Anstoss korrekt nach UTC gerechnet');
T::ok((int)Db::value('SELECT COUNT(*) FROM change_log') > 0, 'Aenderungen sind protokolliert');

T::group('Differ - zweiter Lauf ist folgenlos');

$diff2 = (new Differ($csId, (int)$kicker['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);
T::same(24, $diff2['summary']['unchanged'], 'derselbe Import aendert nichts');
T::same(0, $diff2['summary']['create'], 'es entstehen keine Duplikate');
T::same(0, $diff2['summary']['update'], 'es gibt nichts zu aktualisieren');

T::group('KickerJsonAdapter - alte Formatkennung');

// Das Projekt hiess frueher anders. Dateien, die damals erzeugt wurden,
// muessen weiter lesbar sein - sonst waeren sie beim Umbenennen wertlos.
$alt = str_replace('varcheckdb-import/1', 'vijabei-import/1', $fixture);
T::ok($alt !== $fixture, 'die Testdatei traegt die neue Kennung');
T::same(24, count((new KickerJsonAdapter())->parse($alt)['rows']), 'die alte Kennung wird noch gelesen');
T::ok(AdapterFactory::detect($alt, 'alt.json')['adapter'] instanceof KickerJsonAdapter,
    'und auch von der Erkennung angenommen');
