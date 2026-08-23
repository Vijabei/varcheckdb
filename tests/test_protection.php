<?php
declare(strict_types=1);

/**
 * Die beiden Zusagen des Vertrauensmodells:
 * manuell bestaetigte Werte ueberleben jeden weiteren Import, und ein
 * Konflikt der Quelle wird nur durch eine ausdrueckliche Auswahl aufgeloest.
 */

/** Baut einen frischen Bestand aus der Fixture auf. */
function seeded_import(): array
{
    fresh_db();

    $parsed = (new KickerJsonAdapter())->parse(
        file_get_contents(ROOT . '/tests/fixtures/kicker-sample.json')
    );

    $matcher = new TeamMatcher();
    foreach ($matcher->unresolved($parsed['rows']) as $entry) {
        $matcher->createTeam($entry['name'], 'women', 'senior');
    }

    $csId = competition_season_id('frlw');
    $kicker = Db::one('SELECT id, priority FROM sources WHERE slug = ?', ['kicker']);

    $diff = (new Differ($csId, (int)$kicker['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);
    (new Applier($csId, (int)$kicker['id'], 'Europe/Berlin'))->apply($diff['rows'], $matcher);

    return [$parsed, $matcher, $csId, $kicker];
}

T::group('Ueberschreibschutz - manuelle Korrektur');

[$parsed, $matcher, $csId, $kicker] = seeded_import();

// Der Admin verlegt ein Spiel und bestaetigt den Termin.
$match = Db::one('SELECT * FROM matches WHERE kickoff_utc IS NOT NULL ORDER BY kickoff_utc LIMIT 1');
$matchId = (int)$match['id'];
$original = (string)$match['kickoff_utc'];
$corrected = '2026-08-21 12:30:00';

Db::update('matches', $matchId, ['kickoff_utc' => $corrected, 'kickoff_is_confirmed' => 1]);
FieldSource::set($matchId, 'kickoff_utc', source_id('manual'), true);

T::ok($corrected !== $original, 'der korrigierte Termin weicht vom Import ab');

// Derselbe Import laeuft erneut.
$diff = (new Differ($csId, (int)$kicker['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);
$row = null;
foreach ($diff['rows'] as $candidate) {
    if (($candidate['match_id'] ?? null) === $matchId) {
        $row = $candidate;
        break;
    }
}

T::ok($row !== null, 'das korrigierte Spiel taucht in der Vorschau auf');
T::ok(isset($row['protected']['kickoff_utc']), 'der Anstoss ist als geschuetzt markiert');
T::ok(!isset($row['changes']['kickoff_utc']), 'der Anstoss steht nicht unter den Aenderungen');
T::same('unchanged', $row['action'], 'die Zeile gilt als unveraendert');

(new Applier($csId, (int)$kicker['id'], 'Europe/Berlin'))->apply($diff['rows'], $matcher);
T::same($corrected, (string)Db::value('SELECT kickoff_utc FROM matches WHERE id = ?', [$matchId]),
    'nach dem zweiten Import steht der korrigierte Termin unveraendert da');

T::group('Ueberschreibschutz - unbestaetigte Werte');

[$parsed, $matcher, $csId, $kicker] = seeded_import();
$other = Db::one('SELECT * FROM matches WHERE kickoff_utc IS NOT NULL ORDER BY kickoff_utc LIMIT 1');
Db::update('matches', (int)$other['id'], ['kickoff_utc' => '2026-08-21 12:30:00']);

$diff = (new Differ($csId, (int)$kicker['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);
$row = null;
foreach ($diff['rows'] as $candidate) {
    if (($candidate['match_id'] ?? null) === (int)$other['id']) {
        $row = $candidate;
        break;
    }
}
T::same('update', $row['action'], 'ohne Bestaetigung korrigiert die Quelle den Wert zurueck');
T::ok(isset($row['changes']['kickoff_utc']), 'die Rueckkorrektur steht als Aenderung drin');

T::group('Mehrere Terminangaben - die neuere wird uebernommen');

// kicker.de fuehrt fuer manche Paarungen zwei Datensaetze: die urspruengliche
// und die geaenderte Ansetzung. Massgeblich ist der mit der hoeheren Quell-ID.
// Geprueft gegen die von Hand gepflegte OpenLigaDB-Liga rlw-frauen/2026
// stimmt das in 15 von 15 Faellen.
//
// Frueher blieben solche Zeilen liegen, bis jemand entschied - dadurch hatte
// ein Spieltag 7 statt 8 Spielen. Ein Spiel gehoert aber unabhaengig von
// seinem Termin zu seinem Spieltag, auch ein verlegtes.
[$parsed, $matcher, $csId, $kicker] = seeded_import();

T::same(24, (int)Db::value('SELECT COUNT(*) FROM matches'),
    'alle 24 Spiele der Fixture sind uebernommen, keines blieb liegen');

$proRunde = Db::all(
    'SELECT r.number, COUNT(*) AS n FROM matches m JOIN rounds r ON r.id = m.round_id
      GROUP BY r.number ORDER BY r.number'
);
T::same([8, 8, 8], array_map(static fn(array $r): int => (int)$r['n'], $proRunde),
    'jeder Spieltag hat seine acht Spiele');

// Uebernommen wurde die spaetere Uhrzeit, nicht die urspruengliche.
$essen = Db::one(
    "SELECT m.kickoff_utc FROM matches m
       JOIN rounds r ON r.id = m.round_id
       JOIN teams h ON h.id = m.home_team_id
      WHERE r.number = 11 AND h.name = 'SGS Essen II'"
);
T::same('2026-11-08 12:00:00', (string)$essen['kickoff_utc'],
    'die neuere Angabe 13:00 Ortszeit wurde uebernommen, nicht 11:00');

T::group('Mehrere Terminangaben - die verworfene bleibt sichtbar');

$diff = (new Differ($csId, (int)$kicker['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);
T::same(2, $diff['summary']['ambiguous'], 'zwei Zeilen tragen eine abweichende Angabe');
T::same(0, $diff['summary']['skip'], 'keine davon wird uebersprungen');

$mitAlternativen = array_values(array_filter(
    $diff['rows'],
    static fn(array $r): bool => $r['alternatives'] !== []
));
T::same(2, count($mitAlternativen), 'beide sind in der Vorschau zu finden');
T::same(2, count($mitAlternativen[0]['alternatives']), 'mit beiden Angaben der Quelle');
T::same('unchanged', $mitAlternativen[0]['action'], 'und gelten als erledigt, nicht als offen');

T::group('Mehrere Terminangaben - der Admin kann ueberstimmen');

// Die verworfene Angabe waehlen: sie ersetzt den Wert und gilt als bestaetigt.
$verworfen = $mitAlternativen[0]['alternatives'][1];
$matchId = (int)$mitAlternativen[0]['match_id'];

(new Applier($csId, (int)$kicker['id'], 'Europe/Berlin'))->apply(
    $diff['rows'],
    $matcher,
    [$mitAlternativen[0]['line_no'] => ['alternative' => $verworfen, 'confirm' => true]]
);

$erwartet = (new DateTimeImmutable(
    $verworfen['kickoff_date'] . ' ' . $verworfen['kickoff_time'],
    new DateTimeZone('Europe/Berlin')
))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

T::same($erwartet, (string)Db::value('SELECT kickoff_utc FROM matches WHERE id = ?', [$matchId]),
    'die angehakte Angabe steht in der Datenbank');
T::same(1, (int)Db::value('SELECT kickoff_is_confirmed FROM matches WHERE id = ?', [$matchId]),
    'und gilt als verbindlich');
T::same('manual', (string)Db::value(
    'SELECT s.slug FROM match_field_sources mfs JOIN sources s ON s.id = mfs.source_id
      WHERE mfs.match_id = ? AND mfs.field = ?',
    [$matchId, 'kickoff_utc']
), 'die Entscheidung gehoert der manuellen Pflege');

// Und sie haelt gegen den naechsten Lauf derselben Quelle.
$diff = (new Differ($csId, (int)$kicker['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);
$row = null;
foreach ($diff['rows'] as $candidate) {
    if (($candidate['match_id'] ?? null) === $matchId) { $row = $candidate; break; }
}
T::ok(isset($row['protected']['kickoff_utc']), 'der naechste Import darf sie nicht zurueckdrehen');

T::group('Verlegte Spiele behalten ihren Spieltag');

// Ein Spiel gehoert zu seinem Spieltag, unabhaengig davon, wann es
// stattfindet - auch wenn es von April in den Mai verlegt wird.
[$parsed, $matcher, $csId, $kicker] = seeded_import();

$matchId = (int)Db::value(
    'SELECT m.id FROM matches m JOIN rounds r ON r.id = m.round_id WHERE r.number = 1 LIMIT 1'
);
$spieltagVorher = (int)Db::value(
    'SELECT r.number FROM matches m JOIN rounds r ON r.id = m.round_id WHERE m.id = ?',
    [$matchId]
);

Editor::setKickoff([$matchId], '2027-05-30', '15:00', 'Europe/Berlin');

T::same($spieltagVorher, (int)Db::value(
    'SELECT r.number FROM matches m JOIN rounds r ON r.id = m.round_id WHERE m.id = ?',
    [$matchId]
), 'die Verlegung um neun Monate aendert den Spieltag nicht');

T::same(8, (int)Db::value(
    'SELECT COUNT(*) FROM matches m JOIN rounds r ON r.id = m.round_id
      WHERE m.competition_season_id = ? AND r.number = 1',
    [$csId]
), 'der Spieltag hat weiterhin acht Spiele');

// Und der Import ordnet es beim naechsten Lauf wieder derselben Zeile zu,
// obwohl das Datum voellig anders ist.
$diff = (new Differ($csId, (int)$kicker['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);
T::same(0, $diff['summary']['create'], 'es entsteht kein Duplikat fuer das verlegte Spiel');
