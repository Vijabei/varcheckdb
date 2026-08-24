<?php
declare(strict_types=1);

/** Wettbewerbe anlegen und entfernen. */

fresh_db();

T::group('Competitions - Kuerzel pruefen');

$gueltig = ['start_year' => 2027, 'name' => 'Testliga'];

foreach (['frlw', 'mrlw', 'fwfl', 'f-rlw', 'bl1', 'ab'] as $k) {
    T::same([], Competitions::validate($gueltig + ['slug' => $k, 'shortcut' => $k]),
        sprintf('"%s" wird angenommen', $k));
}

foreach ([
    'A'      => 'zu kurz und gross geschrieben',
    'FRLW'   => 'Grossbuchstaben',
    '1rlw'   => 'beginnt mit einer Ziffer',
    'fr lw'  => 'enthaelt ein Leerzeichen',
    'fr_lw'  => 'enthaelt einen Unterstrich',
    'frauen-regionalliga-west-2026' => 'zu lang',
    ''       => 'leer',
] as $k => $warum) {
    $fehler = Competitions::validate($gueltig + ['slug' => $k, 'shortcut' => $k]);
    T::ok($fehler !== [], sprintf('"%s" wird abgelehnt (%s)', $k, $warum));
}

T::ok(Competitions::validate(['slug' => 'x1', 'shortcut' => 'x1', 'name' => '', 'start_year' => 2027]) !== [],
    'ein leerer Name wird abgelehnt');
T::ok(Competitions::validate(['slug' => 'x1', 'shortcut' => 'x1', 'name' => 'X', 'start_year' => 1500]) !== [],
    'ein unplausibles Jahr wird abgelehnt');

T::group('Competitions - Kuerzel bleibt eindeutig');

// Das Kuerzel ist der leagueShortcut der oeffentlichen API. Zweimal dasselbe
// in einer Saison waere von aussen nicht unterscheidbar.
$doppelt = Competitions::validate([
    'slug' => 'neu', 'shortcut' => 'frlw', 'name' => 'Andere Liga', 'start_year' => 2026,
]);
T::ok($doppelt !== [], 'ein schon vergebenes Kuerzel wird abgelehnt');
T::ok(str_contains($doppelt[0], 'frlw'), 'die Meldung nennt das Kuerzel');

T::same([], Competitions::validate([
    'slug' => 'neu', 'shortcut' => 'frlw', 'name' => 'Andere Liga', 'start_year' => 2027,
]), 'in einer anderen Saison ist dasselbe Kuerzel frei');

T::group('Competitions - anlegen');

fresh_db();
$vorher = count(Repo::competitions());

$id = Competitions::create([
    'slug' => 'mrlw', 'shortcut' => 'mrlw', 'name' => 'Maenner-Regionalliga West',
    'gender' => 'men', 'age_group' => 'senior', 'region' => 'West',
    'level' => 'Regionalliga', 'organizer' => 'WDFV', 'start_year' => 2026, 'team_count' => 18,
]);

T::ok($id > 0, 'der Wettbewerb wird angelegt');
T::same($vorher + 1, count(Repo::competitions()), 'er taucht in der Liste auf');

$neu = Repo::competitionSeason('mrlw', 2026);
T::ok($neu !== null, 'er ist ueber sein Kuerzel zu finden');
T::same('Maenner-Regionalliga West 2026/2027', $neu['name'], 'der Anzeigename traegt die Saison');
T::same(18, (int)$neu['team_count'], 'die Mannschaftszahl steht drin');
T::same('men', $neu['gender'], 'das Geschlecht steht drin');

T::same('2026/27', Competitions::seasonName(2026), 'der Saisonname wird gebildet');
T::same('1999/00', Competitions::seasonName(1999), 'auch ueber den Jahrhundertwechsel');

T::group('Competitions - vorhandene Saison und Wettbewerb weiterverwenden');

$seasons = (int)Db::value('SELECT COUNT(*) FROM seasons');
Competitions::create([
    'slug' => 'mrlw', 'shortcut' => 'mrlw', 'name' => 'Maenner-Regionalliga West',
    'start_year' => 2027,
]);
T::same($seasons + 1, (int)Db::value('SELECT COUNT(*) FROM seasons'), 'eine neue Saison entsteht');
T::same(1, (int)Db::value('SELECT COUNT(*) FROM competitions WHERE slug = ?', ['mrlw']),
    'der Wettbewerb wird nicht doppelt angelegt');

T::group('Competitions - was am Wettbewerb haengt');

fresh_db();
$parsed = (new KickerJsonAdapter())->parse(file_get_contents(ROOT . '/tests/fixtures/kicker-sample.json'));
$matcher = new TeamMatcher();
foreach ($matcher->unresolved($parsed['rows']) as $entry) {
    $matcher->createTeam($entry['name']);
}
$csId = competition_season_id('frlw');
$kicker = Db::one('SELECT id, priority FROM sources WHERE slug = ?', ['kicker']);
$diff = (new Differ($csId, (int)$kicker['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);
(new Applier($csId, (int)$kicker['id'], 'Europe/Berlin'))->apply($diff['rows'], $matcher, [], 'test.json');

$haengt = Competitions::dependents($csId);
T::same(24, $haengt['matches'], '24 Spiele haengen daran');
T::same(3, $haengt['rounds'], '3 Spieltage');
T::ok($haengt['field_sources'] > 0, 'Herkunftsvermerke');
T::same(1, $haengt['import_batches'], 'ein Importvorgang');
T::same(0, $haengt['confirmed'], 'noch nichts von Hand bestaetigt');

// Nach einer Handkorrektur muss die Vorwarnung sie mitzaehlen.
$matchId = (int)Db::value('SELECT id FROM matches LIMIT 1');
Editor::update($matchId, ['home_goals' => 9]);
T::ok(Competitions::dependents($csId)['confirmed'] > 0,
    'bestaetigte Angaben werden gesondert gezaehlt - sie gingen unwiederbringlich verloren');

T::group('Competitions - entfernen');

$teamsVorher = (int)Db::value('SELECT COUNT(*) FROM teams');
$logVorher = (int)Db::value('SELECT COUNT(*) FROM change_log');

$entfernt = Competitions::remove($csId);

T::same(24, $entfernt['matches'], '24 Spiele entfernt');
T::same(3, $entfernt['rounds'], '3 Spieltage entfernt');
T::same(1, $entfernt['competition'], 'der Wettbewerb selbst ebenfalls, weil keine Saison mehr daran haengt');

T::same(0, (int)Db::value('SELECT COUNT(*) FROM matches'), 'keine Spiele mehr');
T::same(0, (int)Db::value('SELECT COUNT(*) FROM rounds'), 'keine Spieltage mehr');
T::same(0, (int)Db::value('SELECT COUNT(*) FROM match_field_sources'), 'keine Herkunftsvermerke mehr');
T::same(0, (int)Db::value('SELECT COUNT(*) FROM import_batches'), 'keine Importvorgaenge mehr');
T::same(0, (int)Db::value('SELECT COUNT(*) FROM import_rows'), 'auch deren Zeilen nicht');
T::same(null, Repo::competitionSeason('frlw', 2026), 'der Wettbewerb ist weg');

T::group('Competitions - was das Entfernen ueberlebt');

T::same($teamsVorher, (int)Db::value('SELECT COUNT(*) FROM teams'),
    'Mannschaften bleiben - sie gehoeren keinem Wettbewerb');
T::ok((int)Db::value('SELECT COUNT(*) FROM change_log') > $logVorher,
    'das Protokoll waechst, statt geleert zu werden');
T::same(1, (int)Db::value('SELECT COUNT(*) FROM change_log WHERE field = ?', ['removed']),
    'das Entfernen selbst ist protokolliert');
T::ok(str_contains(
    (string)Db::value('SELECT old_value FROM change_log WHERE field = ? LIMIT 1', ['removed']),
    '24 Spiele'
), 'mit dem Umfang dessen, was verschwunden ist');

// Die zweite Liga aus den Grunddaten darf nicht mitgerissen worden sein.
T::ok(Repo::competitionSeason('fwfl') !== null, 'der andere Wettbewerb steht unberuehrt da');

T::group('Competitions - verwaiste Mannschaften');

$verwaist = Competitions::orphanedTeams();
T::same(16, count($verwaist), 'nach dem Entfernen stehen alle 16 Mannschaften ohne Spiel da');

$einTeam = (int)$verwaist[0]['id'];
T::ok(Competitions::removeTeam($einTeam), 'eine verwaiste Mannschaft laesst sich entfernen');
T::same(15, count(Competitions::orphanedTeams()), 'danach ist eine weniger uebrig');

T::group('Competitions - benutzte Mannschaften bleiben geschuetzt');

fresh_db();
$matcher = new TeamMatcher();
$a = $matcher->createTeam('Verein A');
$b = $matcher->createTeam('Verein B');
$csId = competition_season_id('frlw');
$roundId = Db::insert('rounds', ['competition_season_id' => $csId, 'number' => 1, 'name' => '1. Spieltag']);
Db::insert('matches', [
    'competition_season_id' => $csId, 'round_id' => $roundId,
    'home_team_id' => $a, 'away_team_id' => $b,
    'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
]);

T::same([], Competitions::orphanedTeams(), 'beide Mannschaften sind in Gebrauch');
T::same(false, Competitions::removeTeam($a), 'eine benutzte Mannschaft wird nicht entfernt');
T::same(2, (int)Db::value('SELECT COUNT(*) FROM teams'), 'sie steht noch da');

T::group('Competitions - Entfernen bricht sauber ab');

T::same([], Competitions::remove(999999), 'ein unbekannter Wettbewerb ergibt nichts');
T::same(2, (int)Db::value('SELECT COUNT(*) FROM competition_seasons'), 'und aendert nichts');

T::group('Competitions - die Grunddaten bestehen die eigene Pruefung');

// Klingt selbstverstaendlich, war es nicht: die Kurzform darf laenger sein
// als das Kuerzel. 'frauen-regionalliga-west' hat 24 Zeichen und ist genau
// richtig so, waehrend ein Kuerzel kurz bleiben soll - es steht in jeder
// Adresse der oeffentlichen Schnittstelle.
fresh_db();

foreach (Db::all(
    'SELECT c.slug, cs.shortcut, c.name, s.start_year
       FROM competition_seasons cs
       JOIN competitions c ON c.id = cs.competition_id
       JOIN seasons s      ON s.id = cs.season_id'
) as $vorhanden) {
    $fehler = Competitions::validate([
        'slug'       => $vorhanden['slug'],
        'shortcut'   => $vorhanden['shortcut'],
        'name'       => $vorhanden['name'],
        'start_year' => (int)$vorhanden['start_year'],
    ], (int)Db::value('SELECT id FROM competition_seasons WHERE shortcut = ?', [$vorhanden['shortcut']]));

    T::same([], $fehler, sprintf('"%s" / "%s" aus seed.sql wird angenommen',
        $vorhanden['slug'], $vorhanden['shortcut']));
}

T::group('Competitions - Kurzform darf laenger sein als das Kuerzel');

$lang = ['name' => 'Test', 'start_year' => 2030, 'shortcut' => 'mrlw'];
T::same([], Competitions::validate($lang + ['slug' => 'maenner-regionalliga-west']),
    'eine 25 Zeichen lange Kurzform wird angenommen');
T::ok(Competitions::validate($lang + ['slug' => str_repeat('a', 65)]) !== [],
    'ab 65 Zeichen ist Schluss - die Spalte fasst 64');
T::ok(Competitions::validate(['name' => 'Test', 'start_year' => 2030,
    'slug' => 'gueltig', 'shortcut' => 'viel-zu-langes-kuerzel']) !== [],
    'ein zu langes Kuerzel wird weiter abgelehnt');
