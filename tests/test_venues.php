<?php
declare(strict_types=1);

/**
 * Spielorte und Zuschauerzahlen.
 *
 * Der Spielort haengt am Spiel, nicht am Verein - genau deshalb muss er sich
 * je Spiel setzen, wieder loesen und ueber den CSV-Rundlauf transportieren
 * lassen.
 */

T::group('Venues - Zahlen lesen');

fresh_db();

T::same(9500, Venues::capacity('9500'), 'eine schlichte Zahl');
T::same(9500, Venues::capacity('9.500'), 'mit Tausenderpunkt, wie aus Excel');
T::same(6000, Venues::capacity('6 000'), 'mit Leerzeichen');
T::same(6000, Venues::capacity("6\u{00a0}000"), 'auch mit geschuetztem Leerzeichen');
T::same(0, Venues::capacity('0'), 'die Null bleibt die Null und wird nicht zu "keine Angabe"');
T::same(null, Venues::capacity(''), 'leer heisst: keine Angabe');
T::same(null, Venues::capacity(null), 'null ebenso');
T::same(false, Venues::capacity('viele'), 'Text ist keine Zahl');
T::same(false, Venues::capacity('-5'), 'und eine negative Zahl auch nicht');

T::group('Venues - Anlegen und Aendern');

$id = Venues::create(['name' => 'Stadion Rote Erde', 'city' => 'Dortmund', 'capacity' => '9.500']);
T::ok($id > 0, 'der Spielort wird angelegt');
T::same(9500, (int)Venues::find($id)['capacity'], 'der Tausenderpunkt ist verschwunden');

T::same([], Venues::validate(['name' => 'Tönnies-Arena', 'capacity' => '3562']),
    'ein neuer Name geht durch');
T::ok(Venues::validate(['name' => 'Stadion Rote Erde']) !== [],
    'derselbe Name ein zweites Mal nicht');
T::ok(Venues::validate(['name' => 'stadion  rote   erde']) !== [],
    'Gross- und Kleinschreibung sowie Leerzeichen zaehlen nicht mit');
T::same([], Venues::validate(['name' => 'Stadion Rote Erde'], $id),
    'beim Aendern zaehlt der eigene Eintrag nicht als Dublette');
T::ok(Venues::validate(['name' => '']) !== [], 'ohne Namen geht es nicht');
T::ok(Venues::validate(['name' => 'X', 'capacity' => 'viele']) !== [],
    'ein unlesbares Fassungsvermoegen wird abgewiesen');

T::same(['capacity'], Venues::update($id, ['capacity' => '9000']), 'das Fassungsvermoegen aendert sich');
T::same([], Venues::update($id, ['capacity' => '9000']), 'derselbe Wert noch einmal aendert nichts');

// Ein Formular, das die Adresse gar nicht anbietet, darf sie nicht leeren.
Db::update('venues', $id, ['address' => 'Strobelallee 50']);
Venues::update($id, ['capacity' => '9500']);
T::same('Strobelallee 50', Venues::find($id)['address'],
    'ein nicht uebergebenes Feld bleibt stehen');

T::group('Venues - Aufloesen ueber den Namen');

T::same($id, (int)Venues::byName('stadion rote erde')['id'], 'unscharf gefunden');
T::same(null, Venues::byName('Gibt es nicht'), 'ein unbekannter Name ergibt nichts');
T::same($id, Venues::resolve('Stadion Rote Erde'), 'resolve findet den vorhandenen Ort');

$vorher = count(Venues::all());
$neu = Venues::resolve('Bezirkssportanlage Westender Straße');
T::same($vorher + 1, count(Venues::all()), 'einen unbekannten legt resolve an');
T::same('Bezirkssportanlage Westender Straße', Venues::find($neu)['name'], 'mit dem Namen von der Quelle');

T::group('Venues - Entfernen nur, solange nichts daran haengt');

$frei = Venues::create(['name' => 'Nie bespielt']);
T::ok(Venues::remove($frei), 'ein unbenutzter Ort laesst sich entfernen');
T::same(null, Venues::find($frei), 'und ist danach weg');

// csv_fixture() legt die Datenbank neu an, der Ort muss also danach kommen.
[$csId, $matcher] = csv_fixture();
$id = Venues::create(['name' => 'Stadion Rote Erde', 'city' => 'Dortmund', 'capacity' => '9500']);
$match = Repo::matches($csId)[0];
Editor::update((int)$match['id'], ['venue_id' => $id, 'spectators' => 412], 'test');

T::same(1, Venues::inUse($id), 'der Ort haengt jetzt an einem Spiel');
T::same(false, Venues::remove($id), 'und laesst sich deshalb nicht entfernen');
T::ok(Venues::find($id) !== null, 'er steht noch da');

T::group('Venues - am Spiel');

$aktualisiert = Db::one('SELECT * FROM matches WHERE id = ?', [$match['id']]);
T::same($id, (int)$aktualisiert['venue_id'], 'der Spielort steht am Spiel');
T::same(412, (int)$aktualisiert['spectators'], 'die Zuschauerzahl ebenso');

// Beides ist von Hand gesetzt und muss deshalb gegen Importe geschuetzt sein.
$kickerPrio = (int)Db::value('SELECT priority FROM sources WHERE slug = ?', ['kicker']);
T::ok(FieldSource::isProtected((int)$match['id'], 'venue_id', $kickerPrio),
    'der Spielort ist als bestaetigt vermerkt');
T::ok(FieldSource::isProtected((int)$match['id'], 'spectators', $kickerPrio),
    'die Zuschauerzahl auch');

// Der Spielort laesst sich wieder loesen - null heisst hier "keine Angabe".
T::same(['venue_id'], Editor::update((int)$match['id'], ['venue_id' => null], 'test'),
    'der Spielort laesst sich wieder entfernen');
T::same(0, Venues::inUse($id), 'danach haengt nichts mehr daran');

T::group('Venues - durch den CSV-Rundlauf');

[$csId, $matcher] = csv_fixture();
$ortId = Venues::create(['name' => 'Sportpark Nord', 'city' => 'Bonn', 'capacity' => '2000']);
$erstes = Repo::matches($csId)[0];
Editor::update((int)$erstes['id'], ['venue_id' => $ortId, 'spectators' => 1337], 'test');

$csv = CsvAdapter::export(Repo::matches($csId), 'Europe/Berlin');
$kopf = explode(';', preg_split('/\R/', trim(substr($csv, 3)))[0]);

T::ok(in_array('venue', $kopf, true), 'die Ausgabe hat eine Spalte fuer den Spielort');
T::ok(in_array('spectators', $kopf, true), 'und eine fuer die Zuschauer');
T::ok(str_contains($csv, 'Sportpark Nord'), 'der Spielort steht in der Datei');
T::ok(str_contains($csv, '1337'), 'die Zuschauerzahl ebenso');

$zurueck = (new CsvAdapter())->parse($csv);
$zeile = null;
foreach ($zurueck['rows'] as $row) {
    if ($row->matchId === (int)$erstes['id']) {
        $zeile = $row;
        break;
    }
}

T::ok($zeile !== null, 'die Zeile kommt wieder herein');
T::same('Sportpark Nord', $zeile->venue, 'mit dem Spielort');
T::same(1337, $zeile->spectators, 'und der Zuschauerzahl');

T::group('Venues - der Differ legt nichts an');

// Eine Vorschau darf nicht schreiben. Ein unbekannter Spielort wird deshalb
// gemeldet und nicht angelegt.
$csvPrio = (int)Db::value('SELECT priority FROM sources WHERE slug = ?', ['csv']);
$vorher = count(Venues::all());

$fremd = new ImportRow(
    round: (int)$erstes['round_number'],
    home: $erstes['home_name'],
    away: $erstes['away_name'],
    venue: 'Noch nie gehoert',
    spectators: 250,
);

$diff = (new Differ($csId, $csvPrio, 'Europe/Berlin'))->compare([$fremd], $matcher);

T::same($vorher, count(Venues::all()), 'der Differ hat keinen Spielort angelegt');
T::ok(str_contains((string)$diff['rows'][0]['message'], 'Noch nie gehoert'),
    'die Vorschau nennt den unbekannten Ort');
T::ok(!array_key_exists('venue_id', $diff['rows'][0]['changes']),
    'und laesst das Feld offen');
T::same(250, $diff['rows'][0]['changes']['spectators']['to'] ?? null,
    'die Zuschauerzahl kommt trotzdem an');

T::group('Venues - ein bekannter Ort wird uebernommen');

$bekannt = new ImportRow(
    round: (int)$erstes['round_number'],
    home: $erstes['home_name'],
    away: $erstes['away_name'],
    venue: 'sportpark nord',
);

$diff = (new Differ($csId, $csvPrio, 'Europe/Berlin'))->compare([$bekannt], $matcher);

T::same('unchanged', $diff['rows'][0]['action'],
    'der Ort steht schon so am Spiel und aendert nichts');

$anderer = Venues::create(['name' => 'Ausweichplatz']);
$diff = (new Differ($csId, $csvPrio, 'Europe/Berlin'))->compare([new ImportRow(
    round: (int)$erstes['round_number'],
    home: $erstes['home_name'],
    away: $erstes['away_name'],
    venue: 'Ausweichplatz',
)], $matcher);

T::same($anderer, $diff['rows'][0]['changes']['venue_id']['to'] ?? null,
    'ein anderer bekannter Ort wird als Aenderung gezeigt');
