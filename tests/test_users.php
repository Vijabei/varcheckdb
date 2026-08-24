<?php
declare(strict_types=1);

/** Benutzerkonten, Rollen und was sie duerfen. */

T::group('Users - Rollen und Berechtigungen');

T::ok(Users::can('admin', 'users.manage'), 'die Verwaltung darf Benutzer pflegen');
T::ok(Users::can('admin', 'competitions.manage'), 'und Wettbewerbe');
T::ok(Users::can('admin', 'import.full'), 'und vollstaendig importieren');
T::ok(Users::can('admin', 'matches.edit'), 'und Spiele aendern');

T::ok(Users::can('editor', 'matches.edit'), 'die Pflege darf Spiele aendern');
T::ok(Users::can('editor', 'import.csv'),
    'und ihre CSV wieder hochladen - sonst koennte sie en bloc gar nicht arbeiten');
T::ok(!Users::can('editor', 'import.full'), 'aber keine fremden Dateien importieren');
T::ok(!Users::can('editor', 'competitions.manage'), 'keine Wettbewerbe verwalten');
T::ok(!Users::can('editor', 'users.manage'), 'und keine Benutzer');

T::ok(!Users::can(null, 'matches.edit'), 'ohne Rolle ist nichts erlaubt');
T::ok(!Users::can('gibtesnicht', 'matches.edit'), 'eine unbekannte Rolle darf nichts');

T::group('Users - Benutzernamen pruefen');

fresh_db();
$basis = ['password' => 'einLangesPasswort', 'role' => 'editor'];

foreach (['anna', 'max.mustermann', 'staffel-west', 'user_1', 'a1'] as $name) {
    T::same([], Users::validate($basis + ['username' => $name]), sprintf('"%s" wird angenommen', $name));
}

foreach ([
    'a'      => 'zu kurz',
    '.anna'  => 'beginnt mit einem Punkt',
    'an na'  => 'enthaelt ein Leerzeichen',
    'anna@x' => 'enthaelt ein At-Zeichen',
    ''       => 'leer',
    str_repeat('a', 33) => 'zu lang',
] as $name => $warum) {
    T::ok(Users::validate($basis + ['username' => $name]) !== [],
        sprintf('"%s" wird abgelehnt (%s)', mb_substr($name, 0, 12), $warum));
}

T::ok(Users::validate(['username' => 'anna', 'password' => 'kurz', 'role' => 'editor']) !== [],
    'ein zu kurzes Passwort wird abgelehnt');
T::ok(Users::validate(['username' => 'anna', 'password' => 'einLangesPasswort',
    'password_repeat' => 'etwasAnderes', 'role' => 'editor']) !== [],
    'zwei verschiedene Passwoerter werden abgelehnt');
T::ok(Users::validate(['username' => 'anna', 'password' => 'einLangesPasswort', 'role' => 'chef']) !== [],
    'eine unbekannte Rolle wird abgelehnt');

T::group('Users - anlegen und anmelden');

T::same(0, Users::count(), 'zu Beginn gibt es keine Benutzer');
T::same(false, Users::hasActiveAdmin(), 'und keinen Verwalter');

$annaId = Users::create([
    'username' => 'anna', 'password' => 'einLangesPasswort',
    'role' => Users::ROLE_ADMIN, 'active' => 1,
]);

T::same(1, Users::count(), 'der Benutzer ist angelegt');
T::ok(Users::hasActiveAdmin(), 'jetzt gibt es einen Verwalter');

$anmeldung = Users::authenticate('anna', 'einLangesPasswort');
T::ok($anmeldung !== null, 'die Anmeldung gelingt');
T::same('admin', $anmeldung['role'], 'mit der richtigen Rolle');
T::ok($anmeldung['last_login_at'] !== null || Users::find($annaId)['last_login_at'] !== null,
    'der Zeitpunkt der Anmeldung wird vermerkt');

T::same(null, Users::authenticate('anna', 'falsch'), 'ein falsches Passwort scheitert');
T::same(null, Users::authenticate('gibtesnicht', 'einLangesPasswort'), 'ein unbekannter Name scheitert');
T::ok(Users::authenticate('ANNA', 'einLangesPasswort') !== null,
    'die Schreibweise des Namens ist gleichgueltig');

T::ok(Users::validate(['username' => 'Anna', 'password' => 'einLangesPasswort', 'role' => 'editor']) !== [],
    'derselbe Name in anderer Schreibweise wird abgelehnt');

T::group('Users - abgeschaltete Konten');

$bertaId = Users::create([
    'username' => 'berta', 'password' => 'einLangesPasswort',
    'role' => Users::ROLE_EDITOR, 'active' => 1,
]);
T::ok(Users::authenticate('berta', 'einLangesPasswort') !== null, 'berta kann sich anmelden');

Users::update($bertaId, ['active' => 0], 'anna');
T::same(null, Users::authenticate('berta', 'einLangesPasswort'),
    'ein abgeschaltetes Konto kommt nicht herein, obwohl das Passwort stimmt');

Users::update($bertaId, ['active' => 1], 'anna');
T::ok(Users::authenticate('berta', 'einLangesPasswort') !== null, 'wieder eingeschaltet geht es');

T::group('Users - der letzte Verwalter bleibt');

T::ok(Users::isLastAdmin($annaId), 'anna ist die einzige Verwalterin');
T::same(false, Users::remove($annaId, 'anna'), 'sie laesst sich nicht entfernen');
T::same(2, Users::count(), 'sie steht noch da');

// Mit einer zweiten Verwaltung ist keiner mehr der letzte.
$claraId = Users::create([
    'username' => 'clara', 'password' => 'einLangesPasswort',
    'role' => Users::ROLE_ADMIN, 'active' => 1,
]);
T::same(false, Users::isLastAdmin($annaId), 'zu zweit ist niemand mehr der letzte');
T::ok(Users::remove($annaId, 'clara'), 'jetzt laesst sich anna entfernen');
T::ok(Users::isLastAdmin($claraId), 'clara ist nun die letzte');

// Auch das Abschalten des letzten Verwalters darf nicht durchgehen.
T::ok(Users::isLastAdmin($claraId), 'clara ist weiterhin die letzte Verwalterin');
T::same(false, Users::remove($claraId, 'clara'), 'sie laesst sich nicht entfernen');

T::group('Users - Aenderungen sind protokolliert');

$eintraege = Db::all(
    "SELECT field, old_value, new_value, actor FROM change_log
      WHERE entity_type = 'user' ORDER BY id"
);
T::ok(count($eintraege) >= 5, 'jede Aenderung steht im Protokoll');

$felder = array_column($eintraege, 'field');
T::ok(in_array('created', $felder, true), 'das Anlegen');
T::ok(in_array('active', $felder, true), 'das Abschalten');
T::ok(in_array('removed', $felder, true), 'das Entfernen');

// Das Passwort selbst darf nirgends auftauchen.
$passwort = Users::update($claraId, ['password' => 'einNeuesLangesPasswort'], 'clara');
T::same(['password'], $passwort, 'die Passwortaenderung wird gemeldet');
T::same(0, (int)Db::value(
    "SELECT COUNT(*) FROM change_log WHERE new_value LIKE ? OR old_value LIKE ?",
    ['%einNeuesLangesPasswort%', '%einNeuesLangesPasswort%']
), 'aber das Passwort steht nicht im Protokoll');
T::ok(Users::authenticate('clara', 'einNeuesLangesPasswort') !== null, 'das neue Passwort gilt');
T::same(null, Users::authenticate('clara', 'einLangesPasswort'), 'das alte nicht mehr');

T::group('Users - Teilaenderungen lassen den Rest in Ruhe');

// Wer nur das Passwort aendert, darf damit nicht das Konto abschalten oder
// die Rolle verlieren. Ein fehlendes Feld heisst "nicht angefasst", nicht
// "auf den Standardwert setzen".
fresh_db();
$id = Users::create([
    'username' => 'dora', 'password' => 'einLangesPasswort',
    'role' => Users::ROLE_ADMIN, 'active' => 1,
]);

Users::update($id, ['password' => 'einAnderesLangesPasswort'], 'dora');
$nachher = Users::find($id);

T::same(1, (int)$nachher['active'], 'das Konto bleibt eingeschaltet');
T::same(Users::ROLE_ADMIN, $nachher['role'], 'die Rolle bleibt');
T::ok(Users::authenticate('dora', 'einAnderesLangesPasswort') !== null, 'die Anmeldung gelingt weiter');

Users::update($id, ['role' => Users::ROLE_EDITOR], 'dora');
T::same(1, (int)Users::find($id)['active'], 'auch eine Rollenaenderung schaltet nichts ab');

Users::update($id, ['active' => 0], 'dora');
T::same(Users::ROLE_EDITOR, Users::find($id)['role'], 'und das Abschalten aendert die Rolle nicht');

T::group('Users - das Protokoll nennt den Handelnden');

// Nicht "admin" oder "import", sondern der Mensch. Welche Quelle beteiligt
// war, steht ohnehin in source_id.
fresh_db();
$annaId = Users::create([
    'username' => 'anna', 'password' => 'einLangesPasswort',
    'role' => Users::ROLE_ADMIN, 'active' => 1,
], 'installer');

Users::create([
    'username' => 'berta', 'password' => 'einLangesPasswort',
    'role' => Users::ROLE_EDITOR, 'active' => 1,
], 'anna');

T::same('installer', (string)Db::value(
    "SELECT actor FROM change_log WHERE entity_type='user' AND entity_id = ?", [$annaId]
), 'der erste Zugang stammt vom Installer');
T::same('anna', (string)Db::value(
    "SELECT actor FROM change_log WHERE entity_type='user' AND new_value LIKE ?", ['berta%']
), 'berta wurde von anna angelegt');

// Auch ein Import traegt den Namen dessen, der ihn bestaetigt hat.
$parsed = (new KickerJsonAdapter())->parse(file_get_contents(ROOT . '/tests/fixtures/kicker-sample.json'));
$matcher = new TeamMatcher();
foreach ($matcher->unresolved($parsed['rows']) as $entry) {
    $matcher->createTeam($entry['name']);
}
$csId = competition_season_id('frlw');
$kicker = Db::one('SELECT id, priority FROM sources WHERE slug = ?', ['kicker']);
$diff = (new Differ($csId, (int)$kicker['priority'], 'Europe/Berlin'))->compare($parsed['rows'], $matcher);
(new Applier($csId, (int)$kicker['id'], 'Europe/Berlin', 'anna'))->apply($diff['rows'], $matcher);

T::ok((int)Db::value("SELECT COUNT(*) FROM change_log WHERE entity_type='match' AND actor = ?", ['anna']) > 0,
    'die Importaenderungen stehen unter anna');
T::same(0, (int)Db::value("SELECT COUNT(*) FROM change_log WHERE actor = ?", ['import']),
    'und nicht mehr unter dem allgemeinen "import"');

// Die Quelle geht dabei nicht verloren.
T::same('kicker', (string)Db::value(
    "SELECT s.slug FROM change_log cl JOIN sources s ON s.id = cl.source_id
      WHERE cl.entity_type='match' AND cl.actor = ? LIMIT 1", ['anna']
), 'welche Quelle beteiligt war, steht weiterhin daneben');
