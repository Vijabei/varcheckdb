<?php
declare(strict_types=1);

/**
 * Front-Controller: oeffentliche Startseite und API.
 *
 * Ohne mod_rewrite ist alles auch ueber ?route=... erreichbar, damit die
 * Anwendung nicht von der Serverkonfiguration abhaengt.
 */

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/json.php';
require_once __DIR__ . '/lib/repo.php';
require_once __DIR__ . '/lib/api/OpenLigaDbApi.php';
require_once __DIR__ . '/admin/auth.php';

$config = App::boot();

$route = isset($_GET['route'])
    ? '/' . trim((string)$_GET['route'], '/')
    : App::path();

$download = isset($_GET['download']);

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Anstoss als lokale Zeit und als UTC ausgeben. */
function kickoff(?string $utc, string $timezone): array
{
    if ($utc === null || $utc === '') {
        return ['local' => null, 'utc' => null, 'confirmed' => false];
    }

    $moment = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

    return [
        'local' => $moment->setTimezone(new DateTimeZone($timezone))->format('Y-m-d\TH:i:s'),
        'utc'   => $moment->format('Y-m-d\TH:i:s\Z'),
    ];
}

$timezone = (string)($config['timezone'] ?? 'Europe/Berlin');

/**
 * Ist jemand angemeldet?
 *
 * Geprueft wird nur, wenn ueberhaupt ein Sitzungskeks mitkommt. Sonst
 * bekaeme jeder Besucher der oeffentlichen Seite einen - und die liest nur.
 */
$angemeldet = isset($_COOKIE[session_name()]) && Auth::isLoggedIn();

// ------------------------------------------------------------------- Routen

if ($route === '/api/v1/ping') {
    Json::send(['ok' => true, 'time' => gmdate('c')]);
}

if ($route === '/api/v1/competitions') {
    $out = [];
    foreach (Repo::competitions() as $row) {
        $out[] = [
            'slug'      => $row['slug'],
            'shortcut'  => $row['shortcut'],
            'name'      => $row['competition_name'],
            'season'    => $row['season_name'],
            'startYear' => (int)$row['start_year'],
            'gender'    => $row['gender'],
            'region'    => $row['region'],
            'level'     => $row['level'],
            'teamCount' => $row['team_count'] === null ? null : (int)$row['team_count'],
        ];
    }

    Json::send(
        ['attribution' => $config['attribution'] ?? null, 'competitions' => $out],
        200,
        $download ? 'wettbewerbe.json' : null
    );
}

if (preg_match('#^/api/v1/competitions/([^/]+)/seasons/([^/]+)/(matches|table)$#', $route, $m) === 1) {
    $season = $m[2] === 'current' ? null : (int)$m[2];
    $competition = Repo::competitionSeason(urldecode($m[1]), $season);

    if ($competition === null) {
        Json::error('Wettbewerb oder Saison nicht gefunden.', 404);
    }

    $id = (int)$competition['id'];

    if ($m[3] === 'table') {
        Json::send([
            'attribution' => $config['attribution'] ?? null,
            'competition' => $competition['competition_name'],
            'season'      => $competition['season_name'],
            'table'       => Repo::table($id),
        ], 200, $download ? 'tabelle.json' : null);
    }

    $filter = [];
    foreach (['round', 'status', 'from', 'to'] as $key) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            $filter[$key] = $_GET[$key];
        }
    }

    $matches = [];
    foreach (Repo::matches($id, $filter) as $row) {
        $time = kickoff($row['kickoff_utc'], $timezone);
        $matches[] = [
            'id'        => (int)$row['id'],
            'round'     => (int)$row['round_number'],
            'roundName' => $row['round_name'],
            'kickoff'   => $time['local'],
            'kickoffUtc' => $time['utc'],
            'kickoffConfirmed' => (bool)$row['kickoff_is_confirmed'],
            'status'    => $row['status'],
            'home'      => ['name' => $row['home_name'], 'shortName' => $row['home_short'], 'logo' => $row['home_logo']],
            'away'      => ['name' => $row['away_name'], 'shortName' => $row['away_short'], 'logo' => $row['away_logo']],
            'result'    => $row['home_goals'] === null ? null : [
                'home' => (int)$row['home_goals'],
                'away' => (int)$row['away_goals'],
                'halfTimeHome' => $row['home_goals_ht'] === null ? null : (int)$row['home_goals_ht'],
                'halfTimeAway' => $row['away_goals_ht'] === null ? null : (int)$row['away_goals_ht'],
            ],
            'venue'     => $row['venue_name'],
        ];
    }

    Json::send([
        'attribution' => $config['attribution'] ?? null,
        'competition' => $competition['competition_name'],
        'season'      => $competition['season_name'],
        'timezone'    => $timezone,
        'count'       => count($matches),
        'matches'     => $matches,
    ], 200, $download ? sprintf('spielplan-%s-%s.json', $competition['slug'], $competition['start_year']) : null);
}

// ------------------------------------------------- OpenLigaDB-kompatibel

if (str_starts_with($route, '/api/openligadb/')) {
    $olb = new OpenLigaDbApi($timezone);
    $rest = substr($route, strlen('/api/openligadb/'));

    /** Wettbewerb nachschlagen oder mit 404 abbrechen. */
    $lookup = static function (string $shortcut, ?string $season) {
        $competition = Repo::competitionSeason(
            urldecode($shortcut),
            ($season === null || $season === '' || $season === 'current') ? null : (int)$season
        );

        if ($competition === null) {
            Json::error('Liga oder Saison nicht gefunden.', 404);
        }

        return $competition;
    };

    if ($rest === 'getavailableleagues') {
        Json::send($olb->availableLeagues(), 200, $download ? 'leagues.json' : null);
    }

    if (preg_match('#^getmatchdata/([^/]+)(?:/([^/]+))?(?:/(\d+))?$#', $rest, $m) === 1) {
        $competition = $lookup($m[1], $m[2] ?? null);
        $group = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : null;

        Json::send(
            $olb->matchData($competition, $group),
            200,
            $download ? sprintf('matchdata-%s-%s.json', $competition['shortcut'], $competition['start_year']) : null
        );
    }

    if (preg_match('#^getbltable/([^/]+)/([^/]+)$#', $rest, $m) === 1) {
        Json::send(
            $olb->table($lookup($m[1], $m[2])),
            200,
            $download ? sprintf('table-%s-%s.json', urldecode($m[1]), $m[2]) : null
        );
    }

    if (preg_match('#^getcurrentgroup/([^/]+)$#', $rest, $m) === 1) {
        $group = $olb->currentGroup($lookup($m[1], null));

        if ($group === null) {
            Json::error('Fuer diese Liga gibt es noch keinen Spieltag.', 404);
        }

        Json::send($group);
    }

    Json::error(
        'Diese Adresse gibt es nicht. Verfuegbar sind getavailableleagues, '
        . 'getmatchdata/{kuerzel}/{saison}[/{spieltag}], getbltable/{kuerzel}/{saison} '
        . 'und getcurrentgroup/{kuerzel}.',
        404
    );
}

if (str_starts_with($route, '/api/')) {
    Json::error('Diese Adresse gibt es nicht.', 404);
}

// ------------------------------------------------------------- Startseite

$competitions = Repo::competitions();
$stats = Repo::stats();

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($config['site_name'] ?? 'Spieldaten') ?></title>
<style>
:root { --bg:#f4f5f7; --card:#fff; --ink:#1b1d21; --muted:#6b7280; --line:#dcdfe4; --accent:#14532d; }
* { box-sizing: border-box; }
body { margin:0; padding:2rem 1rem; background:var(--bg); color:var(--ink);
  font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
.wrap { max-width:52rem; margin:0 auto; }
h1 { font-size:1.5rem; margin:0 0 .25rem; }
h2 { font-size:1.1rem; margin:2rem 0 .75rem; }
.lead { color:var(--muted); margin:0 0 2rem; }
.card { background:var(--card); border:1px solid var(--line); border-radius:.5rem; padding:1.25rem; margin-bottom:1rem; }
.stats { display:flex; flex-wrap:wrap; gap:1.5rem; }
.stat b { display:block; font-size:1.5rem; }
.stat span { color:var(--muted); font-size:.85rem; }
table { width:100%; border-collapse:collapse; font-size:.92rem; }
th,td { text-align:left; padding:.45rem .6rem; border-bottom:1px solid var(--line); }
th { color:var(--muted); font-size:.78rem; text-transform:uppercase; letter-spacing:.03em; }
code { background:#eef0f2; padding:.1rem .35rem; border-radius:.2rem; font-size:.88em; }
a { color:var(--accent); }
.foot { margin-top:2rem; color:var(--muted); font-size:.85rem; }
.kopf { display:flex; gap:1.5rem; align-items:flex-start; flex-wrap:wrap;
        justify-content:space-between; margin-bottom:2rem; }
.kopf h1 { margin-bottom:.25rem; }
.kopf .lead { margin:0; }
.zugang { display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; }
.zugang .wer { color:var(--muted); font-size:.9rem; }
.knopf { display:inline-block; background:var(--accent); color:#fff; text-decoration:none;
         border-radius:.35rem; padding:.5rem 1.1rem; font-size:.92rem; font-weight:600;
         white-space:nowrap; }
.knopf:hover { filter:brightness(1.15); }
.knopf.ghost { background:#e7e9ec; color:var(--ink); }
.aktionen { margin-top:1.25rem; display:flex; gap:.75rem; flex-wrap:wrap; }
.empty { color:var(--muted); }
</style>
</head>
<body>
<div class="wrap">

<div class="kopf">
  <div>
    <h1><?= h($config['site_name'] ?? 'Spieldaten') ?></h1>
    <p class="lead">Spielpläne und Ergebnisse als JSON</p>
  </div>
  <nav class="zugang">
    <?php if ($angemeldet): ?>
      <span class="wer"><?= h(Auth::username()) ?></span>
      <a href="admin/" class="knopf">Meine Ligen</a>
    <?php else: ?>
      <a href="admin/" class="knopf ghost">Anmelden</a>
      <a href="admin/register.php" class="knopf">Mitmachen</a>
    <?php endif; ?>
  </nav>
</div>

<div class="card">
  <div class="stats">
    <div class="stat"><b><?= $stats['competitions'] ?></b><span>Wettbewerbe</span></div>
    <div class="stat"><b><?= $stats['teams'] ?></b><span>Mannschaften</span></div>
    <div class="stat"><b><?= $stats['matches'] ?></b><span>Spiele</span></div>
    <div class="stat"><b><?= $stats['finished'] ?></b><span>davon gespielt</span></div>
  </div>
</div>

<h2>Wettbewerbe</h2>
<?php if ($competitions === []): ?>
  <div class="card empty">
    Noch keine Daten. <a href="admin/">Anmelden</a> und die erste Liga anlegen.
  </div>
<?php else: ?>
  <div class="card">
    <table>
      <thead><tr><th>Wettbewerb</th><th>Saison</th><th>Spielplan</th><th>Tabelle</th></tr></thead>
      <tbody>
      <?php foreach ($competitions as $row): ?>
        <tr>
          <td><?= h($row['competition_name']) ?></td>
          <td><?= h($row['season_name']) ?></td>
          <td><a href="api/v1/competitions/<?= h($row['slug']) ?>/seasons/<?= (int)$row['start_year'] ?>/matches">JSON</a></td>
          <td><a href="api/v1/competitions/<?= h($row['slug']) ?>/seasons/<?= (int)$row['start_year'] ?>/table">JSON</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<h2>Schnittstelle</h2>
<div class="card">
  <p>Alle Adressen liefern JSON. Mit <code>?download=1</code> wird die Antwort als Datei angeboten.</p>
  <table>
    <tbody>
      <tr><td><code>api/v1/competitions</code></td><td>alle Wettbewerbe</td></tr>
      <tr><td><code>api/v1/competitions/{slug}/seasons/{jahr}/matches</code></td><td>Spielplan</td></tr>
      <tr><td><code>api/v1/competitions/{slug}/seasons/{jahr}/table</code></td><td>Tabelle</td></tr>
    </tbody>
  </table>
  <p style="margin-bottom:0"><small>Filter für den Spielplan: <code>?round=</code>, <code>?status=</code>,
     <code>?from=</code>, <code>?to=</code>. Statt einer Jahreszahl geht auch <code>current</code>.</small></p>
</div>

<h2>OpenLigaDB-kompatibel</h2>
<div class="card">
  <p>Dieselben Daten in der Antwortform von <code>api.openligadb.de</code>. Auswertungen,
     die dagegen geschrieben sind, laufen ohne Änderung.</p>
  <table>
    <tbody>
      <tr><td><code>api/openligadb/getavailableleagues</code></td><td>alle Ligen</td></tr>
      <tr><td><code>api/openligadb/getmatchdata/{kuerzel}/{saison}</code></td><td>alle Spiele</td></tr>
      <tr><td><code>api/openligadb/getmatchdata/{kuerzel}/{saison}/{spieltag}</code></td><td>ein Spieltag</td></tr>
      <tr><td><code>api/openligadb/getbltable/{kuerzel}/{saison}</code></td><td>Tabelle</td></tr>
      <tr><td><code>api/openligadb/getcurrentgroup/{kuerzel}</code></td><td>aktueller Spieltag</td></tr>
    </tbody>
  </table>
  <p style="margin-bottom:0"><small><code>goals</code> bleibt leer &ndash; Torschützen liefert
     keine unserer Quellen.</small></p>
</div>

<?php if (!$angemeldet): ?>
  <h2>Mitmachen</h2>
  <div class="card">
    <p>Diese Datenbank lebt davon, dass jemand sie pflegt. Mit einem Konto kannst du
       <strong>eigene Ligen anlegen</strong> und betreuen: Spielpläne einspielen,
       Ergebnisse nachtragen, Termine korrigieren.</p>
    <p class="note">Wer eine Liga anlegt, betreut sie und entscheidet, wer daran
       mitarbeitet. An fremden Ligen kannst du nichts ändern &ndash; dafür fragst du
       deren Besitzer. Gebraucht werden ein Benutzername, eine Mailadresse für das
       Zurücksetzen des Passworts, und ein Passwort. Sonst nichts.</p>
    <div class="aktionen">
      <a href="admin/register.php" class="knopf">Konto anlegen</a>
      <a href="admin/" class="knopf ghost">Ich habe schon eins</a>
    </div>
  </div>
<?php endif; ?>

<p class="foot">
  <?php if ($config['attribution'] ?? null): ?><?= h($config['attribution']) ?><?php endif; ?>
</p>

</div>
</body>
</html>
