<?php
declare(strict_types=1);

/** Wer darf was, an welcher Liga. */

/** Legt Webadmin, zwei Mitmacher und zwei Ligen an. */
function access_fixture(): array
{
    fresh_db();

    $webadmin = Users::create(['username' => 'webadmin', 'password' => 'einLangesPasswort',
        'role' => Users::ROLE_ADMIN, 'active' => 1], 'installer');
    $anna  = Users::create(['username' => 'anna',  'password' => 'einLangesPasswort',
        'role' => Users::ROLE_USER, 'active' => 1], 'anmeldung');
    $berta = Users::create(['username' => 'berta', 'password' => 'einLangesPasswort',
        'role' => Users::ROLE_USER, 'active' => 1], 'anmeldung');

    // anna legt eine Liga an und wird ihre Besitzerin.
    $csId = Competitions::create([
        'slug' => 'annas-liga', 'shortcut' => 'anna1', 'name' => 'Annas Liga',
        'start_year' => 2026,
    ], 'anna');
    $annasLiga = (int)Access::competitionOf($csId);
    Access::makeOwner($annasLiga, $anna);

    // Die Liga aus den Grunddaten gehoert niemandem.
    $herrenlos = (int)Db::value('SELECT competition_id FROM competition_seasons WHERE shortcut = ?', ['frlw']);

    return compact('webadmin', 'anna', 'berta', 'annasLiga', 'herrenlos', 'csId');
}

T::group('Access - globale Rollen');

fresh_db();
T::ok(Access::isWebadmin(Users::ROLE_ADMIN), 'admin ist Webadmin');
T::ok(!Access::isWebadmin(Users::ROLE_USER), 'ein Mitmacher nicht');
T::ok(!Access::isWebadmin(null), 'ein Gast erst recht nicht');

T::ok(Access::mayCreateCompetition(Users::ROLE_USER), 'jeder Angemeldete darf eine Liga anlegen');
T::ok(Access::mayCreateCompetition(Users::ROLE_ADMIN), 'der Webadmin auch');
T::ok(!Access::mayCreateCompetition(null), 'ohne Anmeldung nicht');

T::ok(Users::can(Users::ROLE_ADMIN, 'users.manage'), 'nur der Webadmin verwaltet Benutzer');
T::ok(!Users::can(Users::ROLE_USER, 'users.manage'), 'ein Mitmacher nicht');

T::group('Access - der Anlegende besitzt seine Liga');

extract(access_fixture());

T::same(Access::OWNER, Access::memberRole($anna, $annasLiga), 'anna ist Besitzerin');
T::same(null, Access::memberRole($berta, $annasLiga), 'berta ist nichts');

T::ok(Access::mayEdit($anna, Users::ROLE_USER, $annasLiga), 'anna darf ihre Liga pflegen');
T::ok(Access::mayManage($anna, Users::ROLE_USER, $annasLiga), 'und Rechte vergeben');

T::group('Access - fremde Ligen bleiben unberuehrt');

T::ok(!Access::mayEdit($berta, Users::ROLE_USER, $annasLiga), 'berta darf annas Liga nicht pflegen');
T::ok(!Access::mayManage($berta, Users::ROLE_USER, $annasLiga), 'und keine Rechte vergeben');
T::ok(!Access::mayEdit($anna, Users::ROLE_USER, $herrenlos), 'anna darf auch keine fremde Liga pflegen');

// Genau das macht offene Anmeldung unbedenklich.
$neu = Users::create(['username' => 'fremder', 'password' => 'einLangesPasswort',
    'role' => Users::ROLE_USER, 'active' => 1], 'anmeldung');
T::ok(!Access::mayEdit($neu, Users::ROLE_USER, $annasLiga),
    'ein frisch angemeldetes Konto kann an bestehenden Ligen nichts aendern');
T::ok(!Access::mayEdit($neu, Users::ROLE_USER, $herrenlos), 'an gar keiner');

T::group('Access - der Webadmin steht ueber allem');

T::ok(Access::mayEdit($webadmin, Users::ROLE_ADMIN, $annasLiga), 'er darf annas Liga pflegen');
T::ok(Access::mayManage($webadmin, Users::ROLE_ADMIN, $annasLiga), 'und dort Rechte vergeben');
T::ok(Access::mayEdit($webadmin, Users::ROLE_ADMIN, $herrenlos), 'auch eine Liga ohne Besitzer');
T::same(null, Access::memberRole($webadmin, $annasLiga), 'ohne selbst Mitglied zu sein');

T::group('Access - Co-Admin');

Access::grant($annasLiga, $berta, Access::COADMIN, $anna);

T::same(Access::COADMIN, Access::memberRole($berta, $annasLiga), 'berta ist Co-Admin');
T::ok(Access::mayEdit($berta, Users::ROLE_USER, $annasLiga), 'sie darf jetzt pflegen');
T::ok(!Access::mayManage($berta, Users::ROLE_USER, $annasLiga), 'aber keine Rechte weitergeben');
T::ok(!Access::mayManage($berta, Users::ROLE_USER, $annasLiga), 'und die Liga nicht entfernen');

T::same(2, count(Access::members($annasLiga)), 'die Liga hat zwei Mitglieder');
T::same(1, count(Access::competitionsOf($berta)), 'berta arbeitet an einer Liga mit');

T::group('Access - Rechte nehmen');

T::ok(Access::revoke($annasLiga, $berta, $anna), 'anna nimmt berta die Rechte');
T::same(null, Access::memberRole($berta, $annasLiga), 'berta ist wieder aussen vor');
T::ok(!Access::mayEdit($berta, Users::ROLE_USER, $annasLiga), 'und darf nichts mehr');
T::same(false, Access::revoke($annasLiga, $berta, $anna), 'ein zweites Mal geht ins Leere');

T::group('Access - die letzte Besitzerin bleibt');

// Ohne Besitzer koennte nur noch der Webadmin die Liga pflegen, und niemand
// koennte mehr Rechte vergeben.
T::same(1, Access::ownerCount($annasLiga), 'anna ist die einzige Besitzerin');
T::same(false, Access::revoke($annasLiga, $anna, $anna), 'sie laesst sich nicht entfernen');
T::same(Access::OWNER, Access::memberRole($anna, $annasLiga), 'sie ist weiterhin Besitzerin');

// Mit einer zweiten Besitzerin geht es.
Access::grant($annasLiga, $berta, Access::OWNER, $anna);
T::same(2, Access::ownerCount($annasLiga), 'jetzt sind es zwei');
T::ok(Access::revoke($annasLiga, $anna, $berta), 'anna kann nun gehen');
T::same(1, Access::ownerCount($annasLiga), 'berta bleibt als Besitzerin zurueck');

T::group('Access - Rolle wechseln');

Access::grant($annasLiga, $anna, Access::COADMIN, $berta);
T::same(Access::COADMIN, Access::memberRole($anna, $annasLiga), 'anna ist jetzt Co-Admin');
T::same(2, count(Access::members($annasLiga)), 'und kein zweiter Eintrag entstanden');

Access::grant($annasLiga, $anna, Access::OWNER, $berta);
T::same(Access::OWNER, Access::memberRole($anna, $annasLiga), 'und wieder Besitzerin');
T::same(2, count(Access::members($annasLiga)), 'weiterhin zwei Mitglieder');

T::group('Access - ueber die Saison gefragt');

T::ok(Access::mayEditSeason($anna, Users::ROLE_USER, $csId), 'die Saison gehoert zur Liga');
T::ok(Access::mayManageSeason($anna, Users::ROLE_USER, $csId), 'auch fuer die Verwaltung');
T::ok(!Access::mayEditSeason(999, Users::ROLE_USER, $csId), 'ein Fremder darf nicht');
T::same(false, Access::mayEditSeason($anna, Users::ROLE_USER, 999999), 'eine unbekannte Saison ergibt nein');

T::group('Access - Vergaben sind protokolliert');

$eintraege = Db::all("SELECT actor, field, old_value, new_value FROM change_log
                       WHERE entity_type = 'competition_member' ORDER BY id");
T::ok(count($eintraege) >= 4, 'jede Vergabe steht im Protokoll');
T::ok(in_array('granted', array_column($eintraege, 'field'), true), 'das Vergeben');
T::ok(in_array('revoked', array_column($eintraege, 'field'), true), 'und das Entziehen');
T::ok(str_contains((string)$eintraege[1]['new_value'], 'berta'), 'mit dem Namen des Betroffenen');
