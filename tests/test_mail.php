<?php
declare(strict_types=1);

/** Mailadressen, Marken und was passiert, wenn der Versand nicht geht. */

$config = ['base_url' => 'https://beispiel.test', 'site_name' => 'Testliga',
           'mail' => ['enabled' => false]];

T::group('Mail - Absender');

T::same('Testliga <noreply@beispiel.test>', Mail::sender($config),
    'der Absender wird aus der Adresse der Seite gebildet');
// array_merge, nicht +: bei doppelten Schluesseln behaelt + den linken Wert.
T::same('Eigener <post@anders.test>',
    Mail::sender(array_merge($config, ['mail' => ['from' => 'Eigener <post@anders.test>']])),
    'ein eingetragener Absender hat Vorrang');

// Der Absender muss zur Domain passen, sonst scheitert die SPF-Pruefung.
T::ok(str_contains(Mail::sender($config), '@beispiel.test'),
    'er liegt auf derselben Domain wie die Seite');

T::group('Mail - Betreff mit Umlauten');

T::same('Passwort zuruecksetzen', Mail::encodeSubject('Passwort zuruecksetzen'),
    'reiner ASCII-Betreff bleibt wie er ist');
$kodiert = Mail::encodeSubject('Passwort zurücksetzen');
T::ok(str_starts_with($kodiert, '=?UTF-8?B?'), 'mit Umlaut wird kodiert');
T::same('Passwort zurücksetzen', base64_decode(substr($kodiert, 10, -2)), 'und laesst sich zurueckwandeln');

T::group('Mail - ausgeschalteter Versand wird gemeldet');

// Das ist der Punkt: ein Versand, der stillschweigend scheitert, ist
// schlimmer als gar keiner - der Benutzer wartet auf eine Mail, die nie kommt.
Mail::vergessen();
$ergebnis = Mail::send($config, 'wer@example.org', 'Betreff', 'Text');

T::same(false, $ergebnis['ok'], 'der Versand meldet, dass er nicht stattfand');
T::ok(str_contains($ergebnis['message'], 'ausgeschaltet'), 'und warum');
T::ok(Mail::letzte() !== null, 'die Nachricht liegt trotzdem vor');
T::same('wer@example.org', Mail::letzte()['to'], 'mit Empfaenger');
T::same('Text', Mail::letzte()['body'], 'und Inhalt');

T::group('Mail - unbrauchbare Adresse');

$ergebnis = Mail::send(array_merge($config, ['mail' => ['enabled' => true]]), 'keine-adresse', 'B', 'T');
T::same(false, $ergebnis['ok'], 'eine unbrauchbare Adresse wird abgelehnt');

T::group('Benutzer - Mailadresse ist Pflicht');

fresh_db();
$basis = ['username' => 'anna', 'password' => 'einLangesPasswort', 'role' => Users::ROLE_USER];

$ohne = Users::validate($basis);
T::ok($ohne !== [], 'ohne Adresse wird abgelehnt');
T::ok(str_contains($ohne[0], 'zuruecksetzen'), 'mit der Begruendung, warum sie gebraucht wird');

T::ok(Users::validate($basis + ['email' => 'keine-adresse']) !== [], 'eine unsinnige Adresse ebenso');
T::same([], Users::validate($basis + ['email' => 'anna@example.org']), 'eine gueltige wird angenommen');

$annaId = Users::create($basis + ['email' => 'Anna@Example.ORG', 'active' => 1], 'anmeldung');
T::same('anna@example.org', (string)Db::value('SELECT email_normalized FROM users WHERE id = ?', [$annaId]),
    'die Adresse wird zum Vergleich kleingeschrieben');
T::same('Anna@Example.ORG', (string)Db::value('SELECT email FROM users WHERE id = ?', [$annaId]),
    'angezeigt wird sie wie eingegeben');

T::ok(Users::byEmail('ANNA@example.org') !== null, 'die Schreibweise ist beim Suchen gleichgueltig');

$doppelt = Users::validate(['username' => 'berta', 'password' => 'einLangesPasswort',
    'role' => Users::ROLE_USER, 'email' => 'anna@EXAMPLE.org']);
T::ok($doppelt !== [], 'dieselbe Adresse zweimal wird abgelehnt');
T::ok(!str_contains($doppelt[0], 'anna'), 'ohne zu verraten, wem sie gehoert');

T::group('Benutzer - Bestaetigung der Adresse');

T::same(false, Users::isVerified(Users::find($annaId)), 'zu Beginn ist sie unbestaetigt');

$marke = Users::createToken($annaId, 'verify');
T::same(64, strlen($marke), 'die Marke ist lang genug');
T::same(0, (int)Db::value('SELECT COUNT(*) FROM user_tokens WHERE token_hash = ?', [$marke]),
    'im Klartext steht sie nicht in der Datenbank');
T::same(1, (int)Db::value('SELECT COUNT(*) FROM user_tokens WHERE token_hash = ?', [hash('sha256', $marke)]),
    'nur ihr Hash');

$wer = Users::useToken($marke, 'verify');
T::ok($wer !== null, 'die Marke laesst sich einloesen');
T::same($annaId, (int)$wer['id'], 'und fuehrt zum richtigen Konto');

Users::markVerified($annaId);
T::ok(Users::isVerified(Users::find($annaId)), 'die Adresse gilt als bestaetigt');

T::group('Benutzer - Marken gelten einmal und kurz');

T::same(null, Users::useToken($marke, 'verify'), 'eine verbrauchte Marke geht nicht noch einmal');
T::same(null, Users::useToken('erfunden', 'verify'), 'eine erfundene Marke geht nicht');

$reset = Users::createToken($annaId, 'reset');
T::same(null, Users::useToken($reset, 'verify'), 'eine Marke gilt nur fuer ihren Zweck');
T::ok(Users::useToken($reset, 'reset') !== null, 'fuer den richtigen schon');

// Abgelaufene Marken werden nicht angenommen.
$alt = Users::createToken($annaId, 'reset');
Db::run('UPDATE user_tokens SET expires_at = ? WHERE token_hash = ?',
    [gmdate('Y-m-d H:i:s', time() - 60), hash('sha256', $alt)]);
T::same(null, Users::useToken($alt, 'reset'), 'eine abgelaufene Marke geht nicht');

// Eine neue Marke macht die vorige ungueltig.
$erste = Users::createToken($annaId, 'reset');
$zweite = Users::createToken($annaId, 'reset');
T::same(null, Users::useToken($erste, 'reset'), 'die aeltere Marke verfaellt');
T::ok(Users::useToken($zweite, 'reset') !== null, 'die neuere gilt');

T::group('Benutzer - Passwort zuruecksetzen');

Users::setPassword($annaId, 'einGanzNeuesPasswort', 'ruecksetzung');
T::ok(Users::authenticate('anna', 'einGanzNeuesPasswort') !== null, 'das neue Passwort gilt');
T::same(null, Users::authenticate('anna', 'einLangesPasswort'), 'das alte nicht mehr');
T::same(0, (int)Db::value('SELECT COUNT(*) FROM change_log WHERE new_value LIKE ?', ['%einGanzNeuesPasswort%']),
    'im Protokoll steht das Passwort nicht');

T::group('Benutzer - eine neue Adresse ist wieder unbestaetigt');

Users::update($annaId, ['email' => 'neu@example.org'], 'anna');
T::same(false, Users::isVerified(Users::find($annaId)),
    'sonst koennte man den Reset auf eine fremde Adresse umlenken');
T::same('neu@example.org', (string)Db::value('SELECT email_normalized FROM users WHERE id = ?', [$annaId]),
    'die neue Adresse steht drin');

// Dieselbe Adresse noch einmal setzen aendert nichts.
Users::markVerified($annaId);
Users::update($annaId, ['email' => 'neu@example.org'], 'anna');
T::ok(Users::isVerified(Users::find($annaId)), 'unveraendert bleibt sie bestaetigt');

T::group('Benutzer - Marke ansehen verbraucht sie nicht');

// Sonst waere die Marke schon beim Anzeigen des Formulars aufgebraucht, und
// ein Tippfehler im Passwort haette sie wertlos gemacht.
fresh_db();
$id = Users::create(['username' => 'carla', 'password' => 'einLangesPasswort',
    'email' => 'carla@example.org', 'role' => Users::ROLE_USER, 'active' => 1], 'anmeldung');

$m = Users::createToken($id, 'reset');
T::ok(Users::peekToken($m, 'reset') !== null, 'die Marke laesst sich ansehen');
T::ok(Users::peekToken($m, 'reset') !== null, 'auch ein zweites Mal');
T::ok(Users::useToken($m, 'reset') !== null, 'und danach noch einloesen');
T::same(null, Users::peekToken($m, 'reset'), 'einmal eingeloest, ist sie auch beim Ansehen weg');

$abgelaufen = Users::createToken($id, 'reset');
Db::run('UPDATE user_tokens SET expires_at = ? WHERE token_hash = ?',
    [gmdate('Y-m-d H:i:s', time() - 60), hash('sha256', $abgelaufen)]);
T::same(null, Users::peekToken($abgelaufen, 'reset'), 'eine abgelaufene Marke auch beim Ansehen nicht');

T::group('Passwort vergessen - keine Auskunft ueber vorhandene Konten');

// Der Weg darf nicht verraten, ob es eine Adresse gibt. Sonst laesst sich
// abfragen, wer hier ein Konto hat. Das gilt auch dann, wenn der Versand
// scheitert: dann muss die Antwort fuer beide Faelle gleich aussehen.
fresh_db();
$config = ['base_url' => 'https://beispiel.test', 'site_name' => 'T', 'mail' => ['enabled' => false]];

$id = Users::create(['username' => 'dora', 'password' => 'einLangesPasswort',
    'email' => 'dora@example.org', 'role' => Users::ROLE_USER, 'active' => 1], 'anmeldung');
Users::markVerified($id);

/** Bildet nach, was die Seite tut: erst pruefen, ob versendet werden kann. */
$antwort = static function (string $adresse, array $config): string {
    if (!Mail::enabled($config)) {
        return 'versand-aus';
    }

    $wer = Users::byEmail($adresse);

    if ($wer !== null && (int)$wer['active'] === 1 && Users::isVerified($wer)) {
        Mail::send($config, (string)$wer['email'], 'x', 'y');
    }

    return 'neutral';
};

T::same(
    $antwort('dora@example.org', $config),
    $antwort('gibtesnicht@example.org', $config),
    'bei ausgeschaltetem Versand ist die Antwort fuer beide gleich'
);

$an = ['base_url' => 'https://beispiel.test', 'site_name' => 'T', 'mail' => ['enabled' => true]];
T::same(
    $antwort('dora@example.org', $an),
    $antwort('gibtesnicht@example.org', $an),
    'und bei eingeschaltetem ebenso, auch wenn der Versand scheitert'
);

T::group('Passwort vergessen - nur bestaetigte Adressen');

$unbestaetigt = Users::create(['username' => 'emil', 'password' => 'einLangesPasswort',
    'email' => 'emil@example.org', 'role' => Users::ROLE_USER, 'active' => 1], 'anmeldung');

T::same(false, Users::isVerified(Users::find($unbestaetigt)), 'emils Adresse ist unbestaetigt');

// Eine unbestaetigte Adresse bekommt keinen Verweis - sonst koennte man
// beim Anmelden eine fremde Adresse eintragen und darueber deren Konto
// uebernehmen.
Mail::vergessen();
$antwort('emil@example.org', $an);
T::same(null, Mail::letzte(), 'an eine unbestaetigte Adresse geht nichts hinaus');

Mail::vergessen();
$antwort('dora@example.org', $an);
T::ok(Mail::letzte() !== null, 'an eine bestaetigte schon');

T::group('Passwort vergessen - abgeschaltete Konten');

Users::update($id, ['active' => 0], 'webadmin');
Mail::vergessen();
$antwort('dora@example.org', $an);
T::same(null, Mail::letzte(), 'ein abgeschaltetes Konto bekommt keinen Verweis');
