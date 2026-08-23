<?php
declare(strict_types=1);

/**
 * Der CSV-Rundlauf: exportieren, aendern, zurueckimportieren.
 * Das ist der Weg fuer die En-Bloc-Korrektur.
 */

/** Legt einen Bestand an und gibt Wettbewerb und Zuordner zurueck. */
function csv_fixture(): array
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

    return [$csId, $matcher];
}

T::group('CSV - Export');

[$csId, $matcher] = csv_fixture();
$csv = CsvAdapter::export(Repo::matches($csId), 'Europe/Berlin');

T::ok(str_starts_with($csv, "\xEF\xBB\xBF"), 'die Datei beginnt mit einer BOM, damit Excel UTF-8 erkennt');

$lines = preg_split('/\R/', trim(substr($csv, 3)));
T::same(25, count($lines), '24 Spiele plus Kopfzeile');
T::same(implode(';', CsvAdapter::COLUMNS), $lines[0], 'die Kopfzeile nennt alle Spalten');
T::ok(str_contains($csv, 'DJK Südwest Köln'), 'Umlaute stehen unveraendert drin');

$first = str_getcsv($lines[1], ';', '"', '\\');
T::ok((int)$first[0] > 0, 'jede Zeile traegt ihre match_id');
T::same('2026-08-20', $first[2], 'das Datum in Ortszeit');
T::same('19:00', $first[3], 'die Uhrzeit in Ortszeit');

T::group('CSV - Ruecklauf ohne Aenderung');

$parsed = (new CsvAdapter())->parse($csv);
T::same(24, count($parsed['rows']), 'alle Zeilen kommen zurueck');

$csvSource = Db::one('SELECT id, priority FROM sources WHERE slug = ?', ['csv']);
$diff = (new Differ($csId, (int)$csvSource['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);

T::same(24, $diff['summary']['unchanged'], 'ein unveraenderter Ruecklauf aendert nichts');
T::same(0, $diff['summary']['create'], 'und legt nichts neu an');

T::group('CSV - En-Bloc-Korrektur');

// Drei Anstosszeiten aendern, wie es in einer Tabellenkalkulation passiert.
$lines = preg_split('/\R/', trim(substr($csv, 3)));
$changed = [$lines[0]];
foreach (array_slice($lines, 1) as $index => $line) {
    $cells = str_getcsv($line, ';', '"', '\\');
    if ($index < 3) {
        $cells[3] = '16:45';
    }
    $changed[] = implode(';', array_map(
        static fn(string $c): string => str_contains($c, ';') ? '"' . $c . '"' : $c,
        array_map('strval', $cells)
    ));
}

$parsed = (new CsvAdapter())->parse(implode("\n", $changed));
$diff = (new Differ($csId, (int)$csvSource['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);

T::same(3, $diff['summary']['update'], 'genau drei Zeilen gelten als geaendert');
T::same(21, $diff['summary']['unchanged'], 'alle uebrigen bleiben unberuehrt');

$geaendert = array_values(array_filter($diff['rows'], fn(array $r): bool => $r['action'] === 'update'));
foreach ($geaendert as $row) {
    T::same(['kickoff_utc'], array_keys($row['changes']), 'geaendert wurde nur der Anstoss');
}

(new Applier($csId, (int)$csvSource['id'], 'Europe/Berlin'))->apply($diff['rows'], $matcher);
T::same(3, (int)Db::value(
    'SELECT COUNT(*) FROM matches WHERE TIME(kickoff_utc) = ?',
    ['14:45:00']
), 'die drei neuen Zeiten stehen in der Datenbank (16:45 Ortszeit = 14:45 UTC)');

T::group('CSV - Zuordnung ueber die match_id');

// Auch wenn der Name in der Tabelle veraendert wurde, greift die id.
$lines = preg_split('/\R/', trim(substr($csv, 3)));
$cells = str_getcsv($lines[1], ';', '"', '\\');
$matchId = (int)$cells[0];
$cells[5] = 'Voellig anderer Name';
$cells[3] = '18:15';
$manipuliert = $lines[0] . "\n" . implode(';', $cells);

$parsed = (new CsvAdapter())->parse($manipuliert);
$diff = (new Differ($csId, (int)$csvSource['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);
T::same('skip', $diff['rows'][0]['action'], 'ein unbekannter Name wird nicht stillschweigend zugeordnet');
T::ok(str_contains((string)$diff['rows'][0]['message'], 'nicht zugeordnet'), 'sondern gemeldet');

T::group('CSV - Eigenheiten der Tabellenkalkulation');

$semikolon = "match_id;round;kickoff_date;kickoff_time;home;away\n1;1;20.08.2026;19:00:00;A;B";
$komma     = "match_id,round,kickoff_date,kickoff_time,home,away\n1,1,2026-08-20,19:00,A,B";

T::same(';', CsvAdapter::delimiter('match_id;round;home;away'), 'Semikolon wird erkannt');
T::same(',', CsvAdapter::delimiter('match_id,round,home,away'), 'Komma wird erkannt');

$row = (new CsvAdapter())->parse($semikolon)['rows'][0];
T::same('2026-08-20', $row->kickoffDate, 'das deutsche Datumsformat wird verstanden');
T::same('19:00', $row->kickoffTime, 'Sekunden aus Excel werden abgeschnitten');

$row = (new CsvAdapter())->parse($komma)['rows'][0];
T::same('2026-08-20', $row->kickoffDate, 'das ISO-Datum ebenso');

// Excel schreibt Windows-1252, wenn man nicht aufpasst.
$cp1252 = mb_convert_encoding(
    "match_id;round;home;away\n1;1;DJK Südwest Köln;Vorwärts Spoho",
    'Windows-1252',
    'UTF-8'
);
T::ok(!mb_check_encoding($cp1252, 'UTF-8'), 'die Testdatei ist tatsaechlich kein UTF-8');
$row = (new CsvAdapter())->parse($cp1252)['rows'][0];
T::same('DJK Südwest Köln', $row->home, 'die Umlaute ueberleben die Umwandlung');

$mitBom = "\xEF\xBB\xBFmatch_id;round;home;away\n1;1;A;B";
T::same(1, count((new CsvAdapter())->parse($mitBom)['rows']), 'eine BOM stoert nicht');

T::group('CSV - unbrauchbare Dateien');

T::throws(fn() => (new CsvAdapter())->parse('nur eine Zeile'), 'eine Datei ohne Datenzeilen wird abgewiesen');
T::throws(fn() => (new CsvAdapter())->parse("a;b;c\n1;2;3"), 'eine falsche Kopfzeile wird abgewiesen');

$luecken = (new CsvAdapter())->parse("match_id;home;away\n1;;\n2;A;B");
T::same(1, count($luecken['rows']), 'Zeilen ohne Mannschaften werden uebergangen');
T::ok((bool)array_filter($luecken['notices'], fn(string $n): bool => str_contains($n, 'uebergangen')),
    'und das wird gemeldet');

T::group('CSV - Erkennung durch den Import');

$erkannt = AdapterFactory::detect($csv, 'spielplan.csv');
T::ok($erkannt['adapter'] instanceof CsvAdapter, 'der Export wird als CSV erkannt');
T::same('csv', $erkannt['adapter']->sourceSlug(), 'und der Quelle csv zugeordnet');

$kicker = AdapterFactory::detect(file_get_contents(ROOT . '/tests/fixtures/kicker-sample.json'), 'k.json');
T::ok($kicker['adapter'] instanceof KickerJsonAdapter, 'die kicker-Datei bleibt kicker');

$wf = AdapterFactory::detect(file_get_contents(ROOT . '/tests/fixtures/worldfootball-sample.html'), 's.html');
T::ok($wf['adapter'] instanceof WorldfootballHtmlAdapter, 'die worldfootball-Seite bleibt worldfootball');

$nichts = AdapterFactory::detect('Hallo, Welt', 'egal.txt');
T::same(null, $nichts['adapter'], 'eine fremde Datei wird nicht angenommen');
T::ok(str_contains($nichts['reason'], 'egal.txt'), 'die Meldung nennt den Dateinamen');

T::group('CSV - der Ruecklauf darf eigene Korrekturen ueberschreiben');

// Der CSV-Weg ist Handpflege in grosser Zahl, kein fremder Import. Waere er
// schwaecher eingestuft als 'manual', koennte der Admin einen einmal
// bestaetigten Wert nie wieder per Tabelle aendern.
[$csId, $matcher] = csv_fixture();
$matchId = (int)Db::value('SELECT id FROM matches ORDER BY kickoff_utc LIMIT 1');

// Erst von Hand setzen und bestaetigen ...
Editor::setKickoff([$matchId], '2027-03-14', '15:00', 'Europe/Berlin');
T::same('2027-03-14 14:00:00', (string)Db::value('SELECT kickoff_utc FROM matches WHERE id = ?', [$matchId]),
    'der Termin ist von Hand gesetzt');
T::ok(FieldSource::isProtected($matchId, 'kickoff_utc', 50), 'und gegen kicker geschuetzt');

// ... dann ueber den CSV-Ruecklauf erneut aendern.
$csv = CsvAdapter::export(Repo::matches($csId), 'Europe/Berlin');
$lines = preg_split('/\R/', trim(substr($csv, 3)));
$geaendert = [$lines[0]];
foreach (array_slice($lines, 1) as $line) {
    $cells = str_getcsv($line, ';', '"', '\\');
    if ((int)$cells[0] === $matchId) {
        $cells[2] = '2027-03-21';
        $cells[3] = '17:30';
    }
    $geaendert[] = implode(';', array_map(
        static fn(string $c): string => str_contains($c, ';') ? '"' . $c . '"' : $c,
        array_map('strval', $cells)
    ));
}

$parsed = (new CsvAdapter())->parse(implode("\n", $geaendert));
$csvSource = Db::one('SELECT id, priority FROM sources WHERE slug = ?', ['csv']);
$diff = (new Differ($csId, (int)$csvSource['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);

$row = null;
foreach ($diff['rows'] as $candidate) {
    if (($candidate['match_id'] ?? null) === $matchId) { $row = $candidate; break; }
}

T::same('update', $row['action'], 'der Ruecklauf kommt durch');
T::ok(isset($row['changes']['kickoff_utc']), 'der Termin steht als Aenderung drin');
T::same([], $row['protected'], 'und wird nicht als geschuetzt abgewiesen');

(new Applier($csId, (int)$csvSource['id'], 'Europe/Berlin'))->apply($diff['rows'], $matcher);
T::same('2027-03-21 16:30:00', (string)Db::value('SELECT kickoff_utc FROM matches WHERE id = ?', [$matchId]),
    'der neue Termin steht in der Datenbank');

// Die Aenderung bleibt trotzdem gegen fremde Quellen geschuetzt.
T::ok(FieldSource::isProtected($matchId, 'kickoff_utc', 50), 'und ist weiterhin gegen kicker geschuetzt');
T::same('csv', (string)Db::value(
    'SELECT s.slug FROM match_field_sources mfs JOIN sources s ON s.id = mfs.source_id
      WHERE mfs.match_id = ? AND mfs.field = ?',
    [$matchId, 'kickoff_utc']
), 'die Herkunft bleibt als csv erkennbar');

T::group('CSV - unberuehrte Felder werden nicht mit geschuetzt');

// Nur weil ein Spiel durch die Tabelle gelaufen ist, darf nicht sein
// gesamter Datensatz als bestaetigt gelten.
$felder = array_column(Db::all(
    'SELECT field FROM match_field_sources WHERE match_id = ? AND confirmed = 1',
    [$matchId]
), 'field');

T::ok(in_array('kickoff_utc', $felder, true), 'der geaenderte Anstoss ist bestaetigt');
T::ok(!in_array('home_goals', $felder, true), 'das unberuehrte Ergebnis nicht');
T::ok(!in_array('status', $felder, true), 'der unberuehrte Status nicht');
