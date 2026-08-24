<?php
declare(strict_types=1);

/**
 * worldfootball.net als Gegenquelle.
 *
 * Die Seite wird vom Admin im Browser gespeichert und hochgeladen; ein
 * serverseitiger Abruf scheitert an der Cloudflare-Pruefung.
 */

$html = file_get_contents(ROOT . '/tests/fixtures/worldfootball-sample.html');

T::group('WorldfootballHtmlAdapter - Einlesen');

$adapter = new WorldfootballHtmlAdapter();
$parsed = $adapter->parse($html);
$rows = $parsed['rows'];

T::same(24, count($rows), '24 Spiele erkannt');
T::same(0, count(array_filter($rows, fn(ImportRow $r): bool => $r->round === null)), 'jedes Spiel hat einen Spieltag');
T::same([1, 11, 27], array_values(array_unique(array_map(fn(ImportRow $r): ?int => $r->round, $rows))),
    'die Spieltage stammen aus den Zwischenueberschriften');
T::same(0, count(array_filter($rows, fn(ImportRow $r): bool => $r->kickoffDate === null)), 'jedes Spiel hat ein Datum');

T::throws(fn() => $adapter->parse('<html><body>nichts</body></html>'), 'fremde Seite wird abgewiesen');

T::group('WorldfootballHtmlAdapter - Feldinhalte');

$first = $rows[0];
T::same('Borussia Dortmund', $first->home, 'Heimmannschaft aus dem Langnamen');
T::same('Arminia Bielefeld', $first->away, 'Gastmannschaft aus dem Langnamen');
T::same(3, $first->homeGoals, 'Ergebnis aufgeteilt');
T::same(0, $first->awayGoals, 'Ergebnis aufgeteilt');
T::same('finished', $first->status, 'Status aus der CSS-Klasse');
T::same('12327043', $first->sourceMatchId, 'Quell-ID des Spiels');
T::same('285266', $first->homeSourceId, 'Quell-ID der Mannschaft aus dem Link');

// data-datetime ist bereits UTC; der Umweg ueber die Ortszeit muss verlustfrei sein.
T::same('2026-08-20 17:00:00', $first->kickoffUtc('Europe/Berlin'), 'UTC-Zeitstempel bleibt erhalten');
T::same('19:00', $first->kickoffTime, 'Ortszeit korrekt zurueckgerechnet');

T::group('WorldfootballHtmlAdapter - bewusste Luecken');

T::same(null, $first->homeGoalsHt, 'kein Halbzeitstand: die Quelle liefert keinen');
T::same(null, $first->kickoffConfirmed, 'kein Terminstatus: die Quelle kennt keinen');
T::ok(
    (bool)array_filter($parsed['notices'], fn(string $n): bool => str_contains($n, 'Halbzeit')),
    'die Vorschau weist auf die Luecken hin'
);

T::group('Gegenquelle - Namensabgleich ueber Vorschlaege');

// Bestand aus kicker aufbauen ...
fresh_db();
$kicker = (new KickerJsonAdapter())->parse(file_get_contents(ROOT . '/tests/fixtures/kicker-sample.json'));
$matcher = new TeamMatcher();
foreach ($matcher->unresolved($kicker['rows']) as $entry) {
    $matcher->createTeam($entry['name']);
}

// ... dann die abweichenden worldfootball-Namen zuordnen.
$open = $matcher->unresolved($rows);
T::ok($open !== [], 'worldfootball benutzt abweichende Schreibweisen');
T::same(0, count(array_filter($open, fn(array $e): bool => $e['suggestions'] === [])),
    'fuer jeden abweichenden Namen gibt es mindestens einen Vorschlag');

$expected = [
    'Bayer Leverkusen II'    => 'Bayer 04 Leverkusen II',
    'Rhenania Bottrop'       => 'SV Rhenania Bottrop',
    'SV Fortuna Freudenberg' => 'Fortuna Freudenberg',
    'VFR SW Warbeyen'        => 'VfR Warbeyen',
    'Vorwärts Spoho 98'      => 'Vorwärts Spoho Köln',
    'Wacker Mecklenbeck'     => 'DJK Wacker Mecklenbeck',
];

$byName = [];
foreach ($open as $entry) {
    $byName[$entry['name']] = $entry;
}

// Ueber die Erwartungsliste laufen, nicht ueber das Ergebnis: sonst faellt
// ein Name, den der Adapter gar nicht liefert, stillschweigend unter den Tisch.
foreach ($expected as $sourceName => $ownName) {
    if (!isset($byName[$sourceName])) {
        T::ok(false, sprintf('"%s" fehlt in der Liste der offenen Namen', $sourceName));
        continue;
    }
    T::same($ownName, $byName[$sourceName]['suggestions'][0]['name'],
        sprintf('"%s" wird richtig vorgeschlagen', $sourceName));
}

// Die Zuordnung wird einmal bestaetigt und gilt danach dauerhaft.
foreach ($open as $entry) {
    $matcher->addAlias($entry['suggestions'][0]['team_id'], $entry['name'], source_id('worldfootball'));
}
T::same(0, count($matcher->unresolved($rows)), 'nach der Bestaetigung ist nichts mehr offen');

T::group('Gegenquelle - Abgleich mit dem Bestand');

$csId = competition_season_id('frlw');
$src = Db::one('SELECT id, priority FROM sources WHERE slug = ?', ['kicker']);

// Bestand aus kicker anlegen, Konflikte offen lassen.
$diff = (new Differ($csId, (int)$src['priority'], 'Europe/Berlin'))->compare($kicker['rows'], $matcher);
(new Applier($csId, (int)$src['id'], 'Europe/Berlin'))->apply($diff['rows'], $matcher);

// Jetzt worldfootball dagegenhalten.
$wfSource = Db::one('SELECT id, priority FROM sources WHERE slug = ?', ['worldfootball']);
$check = (new Differ($csId, (int)$wfSource['priority'], 'Europe/Berlin'))->compare($rows, $matcher);

T::same(0, $check['summary']['skip'], 'alle Spiele lassen sich zuordnen');
T::ok($check['summary']['unchanged'] > 0, 'der Grossteil deckt sich');

// Kennt der Bestand ein Ergebnis nicht, muss die Gegenquelle es nachtragen
// koennen. Geprueft wird das Verhalten, nicht der Tagesstand der Quellen:
// dass kicker gerade hinterherhinkt oder nicht, darf den Test nicht steuern.
$luecke = (int)Db::value(
    "SELECT m.id FROM matches m JOIN rounds r ON r.id = m.round_id
      WHERE r.number = 1 AND m.home_goals IS NOT NULL LIMIT 1"
);
Db::run('UPDATE matches SET home_goals = NULL, away_goals = NULL, status = ? WHERE id = ?',
    ['scheduled', $luecke]);

$mitLuecke = (new Differ($csId, (int)$wfSource['priority'], 'Europe/Berlin'))->compare($rows, $matcher);
$nachtrag = array_filter(
    $mitLuecke['rows'],
    fn(array $r): bool => ($r['match_id'] ?? null) === $luecke && isset($r['changes']['home_goals'])
);
T::ok($nachtrag !== [], 'worldfootball traegt ein fehlendes Ergebnis nach');

// Halbzeitstaende duerfen dabei nicht angetastet werden.
$touchesHalfTime = array_filter($check['rows'], fn(array $r): bool => isset($r['changes']['home_goals_ht']));
T::same(0, count($touchesHalfTime), 'die Halbzeitstaende aus kicker bleiben unberuehrt');


T::group('Zeichensatz - falsch deklarierte Dateien');

// Die vom Browser gespeicherte Seite behauptet utf-8, ist aber Windows-1252.
// Unbehandelt wird aus 'Vorwärts' ein 'Vorw?rts' - und damit eine neue,
// falsche Mannschaft samt Alias.
$cp1252 = file_get_contents(ROOT . '/tests/fixtures/worldfootball-cp1252.html');

T::ok(!mb_check_encoding($cp1252, 'UTF-8'), 'die Testdatei ist tatsaechlich kein UTF-8');
T::same('Windows-1252 (umgewandelt)', Encoding::describe($cp1252), 'der Zeichensatz wird erkannt');

$fromCp = (new WorldfootballHtmlAdapter())->parse($cp1252)['rows'];
$fromUtf = (new WorldfootballHtmlAdapter())->parse($html)['rows'];

$names = static function (array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $out[$row->home] = true;
        $out[$row->away] = true;
    }
    ksort($out);

    return array_keys($out);
};

T::same($names($fromUtf), $names($fromCp), 'beide Kodierungen ergeben dieselben Namen');
T::ok(in_array('Vorwärts Spoho 98', $names($fromCp), true), 'der Umlaut ueberlebt die Umwandlung');
T::same(0, count(array_filter($names($fromCp), fn(string $n): bool => str_contains($n, "\u{FFFD}"))),
    'kein Name enthaelt ein Ersetzungszeichen');

T::same('UTF-8', Encoding::describe($html), 'eine echte UTF-8-Datei wird als solche erkannt');
T::same('abc', Encoding::toUtf8("\xEF\xBB\xBFabc"), 'eine BOM wird entfernt');
