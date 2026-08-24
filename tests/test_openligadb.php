<?php
declare(strict_types=1);

/**
 * Die OpenLigaDB-kompatible Ausgabe.
 *
 * Geprueft wird gegen tests/fixtures/openligadb-referenz.json - echte
 * Antworten von api.openligadb.de, eingefroren. Ein Tippfehler in einem
 * Feldnamen faellt sonst erst dem auf, dessen Auswertung nicht mehr laeuft.
 */

$referenz = json_decode(
    (string)file_get_contents(ROOT . '/tests/fixtures/openligadb-referenz.json'),
    true
);

function olb_fixture(): array
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

    return [Repo::competitionSeason('frlw', 2026), new OpenLigaDbApi('Europe/Berlin')];
}

/** Vergleicht die Schluessel zweier Strukturen, ohne auf Werte zu sehen. */
function keys_of(array $data): array
{
    $keys = array_keys($data);
    sort($keys);

    return $keys;
}

[$competition, $api] = olb_fixture();

T::group('OpenLigaDB - getmatchdata');

$matches = $api->matchData($competition);
T::same(24, count($matches), 'alle Spiele werden ausgegeben');

$vorbild = $referenz['getmatchdata'][0];
T::same(keys_of($vorbild), keys_of($matches[0]), 'die Felder eines Spiels stimmen mit dem Original ueberein');
T::same(keys_of($vorbild['group']), keys_of($matches[0]['group']), 'die Felder von group stimmen');
T::same(keys_of($vorbild['team1']), keys_of($matches[0]['team1']), 'die Felder von team1 stimmen');
T::same(keys_of($vorbild['team2']), keys_of($matches[0]['team2']), 'die Felder von team2 stimmen');

$gespielt = null;
foreach ($matches as $match) {
    if ($match['matchIsFinished']) { $gespielt = $match; break; }
}
T::ok($gespielt !== null, 'es gibt ein gespieltes Spiel');
T::same(keys_of($vorbild['matchResults'][0]), keys_of($gespielt['matchResults'][0]),
    'die Felder eines Ergebnisses stimmen');

T::group('OpenLigaDB - Werte eines Spiels');

$erstes = $matches[0];
T::same('2026-08-20T19:00:00', $erstes['matchDateTime'], 'matchDateTime ist Ortszeit ohne Zonenangabe');
T::same('2026-08-20T17:00:00Z', $erstes['matchDateTimeUTC'], 'matchDateTimeUTC traegt das Z');
T::same('W. Europe Standard Time', $erstes['timeZoneID'], 'timeZoneID wie im Original');
T::same(2026, $erstes['leagueSeason'], 'leagueSeason ist hier eine Zahl');
T::same('frlw', $erstes['leagueShortcut'], 'leagueShortcut ist unser Kuerzel');
T::same(1, $erstes['group']['groupOrderID'], 'groupOrderID ist die Spieltagsnummer');
T::same('1. Spieltag', $erstes['group']['groupName'], 'groupName wie im Original');
T::same('Borussia Dortmund', $erstes['team1']['teamName'], 'team1 ist die Heimmannschaft');
T::same([], $erstes['goals'], 'goals bleibt leer, aber vorhanden');
T::same(true, $erstes['matchIsFinished'], 'ein gespieltes Spiel ist als solches markiert');

T::group('OpenLigaDB - matchResults');

$ergebnisse = $erstes['matchResults'];
T::same(2, count($ergebnisse), 'Halbzeit und Endergebnis');
T::same('HalfTime', $ergebnisse[0]['resultTypeKind'], 'die Halbzeit steht zuerst');
T::same(1, $ergebnisse[0]['pointsTeam1'], 'mit dem Halbzeitstand');
T::same('After90Minutes', $ergebnisse[1]['resultTypeKind'], 'danach das Endergebnis');
T::same(3, $ergebnisse[1]['pointsTeam1'], 'mit dem Endstand');
T::same(0, $ergebnisse[1]['pointsTeam2'], 'auch fuer die Gastmannschaft');
T::ok($ergebnisse[0]['resultID'] !== $ergebnisse[1]['resultID'], 'die beiden resultID sind verschieden');

// Ist der Halbzeitstand unbekannt, steht der Eintrag trotzdem da - mit null.
// Ihn wegzulassen wuerde Auswertungen taeuschen, die matchResults[0] als
// Halbzeit lesen; ein erfundenes 0:0 waere schlicht falsch.
$ohneHalbzeit = null;
foreach ($matches as $match) {
    if ($match['matchIsFinished'] && $match['matchResults'][0]['pointsTeam1'] === null) {
        $ohneHalbzeit = $match;
        break;
    }
}
T::ok($ohneHalbzeit !== null, 'es gibt ein gespieltes Spiel ohne bekannten Halbzeitstand');
T::same(2, count($ohneHalbzeit['matchResults']), 'auch dort stehen beide Eintraege');
T::same('HalfTime', $ohneHalbzeit['matchResults'][0]['resultTypeKind'], 'die Halbzeit steht an erster Stelle');
T::same(null, $ohneHalbzeit['matchResults'][0]['pointsTeam2'], 'mit null statt einer erfundenen Zahl');
T::same('After90Minutes', $ohneHalbzeit['matchResults'][1]['resultTypeKind'], 'das Endergebnis dahinter');
T::ok($ohneHalbzeit['matchResults'][1]['pointsTeam1'] !== null, 'und das ist bekannt');

// Die Reihenfolge muss verlaesslich sein, sonst nuetzt der Eintrag nichts.
foreach ($matches as $match) {
    if ($match['matchResults'] === []) {
        continue;
    }
    if ($match['matchResults'][0]['resultTypeKind'] !== 'HalfTime'
        || $match['matchResults'][1]['resultTypeKind'] !== 'After90Minutes') {
        T::ok(false, 'ein Spiel hat die Ergebnisse in falscher Reihenfolge');
        break;
    }
}
T::ok(true, 'bei jedem gespielten Spiel steht die Halbzeit an erster Stelle');

// Ein noch nicht gespieltes Spiel hat gar kein Ergebnis.
$offen = null;
foreach ($matches as $match) {
    if (!$match['matchIsFinished']) { $offen = $match; break; }
}
T::same([], $offen['matchResults'], 'ein angesetztes Spiel hat keine Ergebnisse');

T::group('OpenLigaDB - ein einzelner Spieltag');

$spieltag = $api->matchData($competition, 1);
T::same(8, count($spieltag), 'der erste Spieltag hat acht Spiele');
T::same([1], array_values(array_unique(array_map(
    static fn(array $m): int => $m['group']['groupOrderID'],
    $spieltag
))), 'und enthaelt nur diesen Spieltag');
T::same(0, count($api->matchData($competition, 99)), 'ein leerer Spieltag ergibt eine leere Liste');

T::group('OpenLigaDB - getbltable');

$tabelle = $api->table($competition);
T::same(keys_of($referenz['getbltable'][0]), keys_of($tabelle[0]), 'die Felder der Tabelle stimmen');
T::same(16, count($tabelle), 'alle 16 Mannschaften stehen drin');
T::same(3, $tabelle[0]['points'], 'der Erste hat nach einem Spieltag drei Punkte');
T::ok($tabelle[0]['goalDiff'] >= $tabelle[1]['goalDiff'] || $tabelle[0]['points'] > $tabelle[1]['points'],
    'die Sortierung folgt Punkten und Tordifferenz');

T::group('OpenLigaDB - getcurrentgroup');

$gruppe = $api->currentGroup($competition);
T::same(keys_of($referenz['getcurrentgroup']), keys_of($gruppe), 'die Felder stimmen');
T::ok($gruppe['groupOrderID'] > 0, 'es wird ein Spieltag genannt');
T::ok(str_contains((string)$gruppe['groupName'], 'Spieltag'), 'mit sprechendem Namen');

T::group('OpenLigaDB - getavailableleagues');

$ligen = $api->availableLeagues();
T::same(keys_of($referenz['getavailableleagues'][0]), keys_of($ligen[0]), 'die Felder stimmen');
T::same(keys_of($referenz['getavailableleagues'][0]['sport']), keys_of($ligen[0]['sport']), 'auch die von sport');
T::same(2, count($ligen), 'beide Wettbewerbe werden gelistet');

// Der Typwechsel des Originals wird nachgebildet.
T::same('string', gettype($ligen[0]['leagueSeason']), 'leagueSeason ist hier eine Zeichenkette');
T::same('integer', gettype($matches[0]['leagueSeason']), 'in getmatchdata dagegen eine Zahl');
T::same(gettype($referenz['getavailableleagues'][0]['leagueSeason']), gettype($ligen[0]['leagueSeason']),
    'genau wie beim Original');
T::same(gettype($referenz['getmatchdata'][0]['leagueSeason']), gettype($matches[0]['leagueSeason']),
    'und dort ebenso');

T::group('OpenLigaDB - Spiele ohne Termin');

// Ein Spiel ohne Ansetzung darf die Ausgabe nicht zerlegen.
Db::run('UPDATE matches SET kickoff_utc = NULL WHERE id = (SELECT MIN(id) FROM (SELECT id FROM matches) x)');
$mitLuecke = (new OpenLigaDbApi('Europe/Berlin'))->matchData($competition);
$ohneTermin = array_values(array_filter($mitLuecke, static fn(array $m): bool => $m['matchDateTime'] === null));

T::same(1, count($ohneTermin), 'das unterminierte Spiel ist dabei');
T::same(null, $ohneTermin[0]['matchDateTimeUTC'], 'ohne Termin auch ohne UTC-Angabe');
T::same(keys_of($referenz['getmatchdata'][0]), keys_of($ohneTermin[0]), 'die Felder bleiben vollstaendig');
