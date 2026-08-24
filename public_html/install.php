<?php
declare(strict_types=1);

/**
 * Installer fuer die vijabei.net Spieldatenbank.
 *
 * Eine einzelne Datei ohne Abhaengigkeiten ausser den Klassen unter lib/.
 * Nach erfolgreicher Installation muss sie geloescht werden; der letzte
 * Schritt bietet das an.
 *
 * Ablauf:
 *   1 Voraussetzungen   Umgebung pruefen
 *   2 Datenbank         Zugang eintragen, Verbindung und Rechte pruefen
 *   3 Webseite          Titel, Adresse, Zeitzone, Adminpasswort
 *   4 Installation      Schema einspielen, config.php schreiben
 *   5 Fertig            Installer entfernen
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/lib/setup/Requirements.php';
require_once __DIR__ . '/lib/setup/Installer.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/users.php';

session_start();

const CONFIG_FILE = __DIR__ . '/config.php';
const STEPS = [
    1 => 'Voraussetzungen',
    2 => 'Datenbank',
    3 => 'Webseite',
    4 => 'Installation',
    5 => 'Fertig',
];

// ---------------------------------------------------------------- Hilfsmittel

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function stored(string $key, string $default = ''): string
{
    return (string)($_SESSION['install'][$key] ?? $default);
}

function store(array $values): void
{
    $_SESSION['install'] = array_merge($_SESSION['install'] ?? [], $values);
}

function token(): string
{
    return $_SESSION['install_token'] ??= bin2hex(random_bytes(16));
}

function tokenValid(): bool
{
    return isset($_POST['token']) && hash_equals(token(), (string)$_POST['token']);
}

/** Adresse der Seite aus der Anfrage ableiten, als Vorschlag. */
function guessBaseUrl(): string
{
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');

    return $scheme . '://' . $host . $path;
}

// ------------------------------------------------------------------- Ablauf

$step = max(1, min(5, (int)($_POST['step'] ?? $_GET['step'] ?? 1)));
$errors = [];
$notices = [];

// Eine vorhandene Konfiguration wird nie ueberschrieben.
$alreadyInstalled = is_file(CONFIG_FILE);

if ($alreadyInstalled && $step < 5) {
    $step = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !tokenValid()) {
    $errors[] = 'Die Sitzung ist abgelaufen. Bitte von vorn beginnen.';
    // Die Sperre gegen Neuinstallation behaelt Vorrang, sonst waere die
    // Auskunft irrefuehrend: es liegt nicht an der Sitzung.
    $step = $alreadyInstalled ? 0 : 1;
}

// --- Schritt 2 abgeschickt: Datenbank pruefen
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST' && $errors === []) {
    $host     = post('db_host', 'localhost');
    $port     = (int)(post('db_port', '3306') ?: 3306);
    $database = post('db_name');
    $user     = post('db_user');
    $password = (string)($_POST['db_password'] ?? '');

    $sslOn     = ($_POST['db_ssl'] ?? '') === '1';
    $sslCa     = post('db_ssl_ca');
    $sslVerify = ($_POST['db_ssl_verify'] ?? '') === '1';

    $schemaDir = post('schema_dir');
    if ($schemaDir !== '') {
        $resolved = Installer::resolveSchemaDir($schemaDir, __DIR__);
        if ($resolved['dir'] === null) {
            $errors[] = sprintf(
                'Unter "%s" liegen nicht beide Dateien schema.mysql.sql und seed.sql. Geprueft: %s',
                $schemaDir,
                implode(', ', $resolved['tried'])
            );
        } else {
            $schemaDir = $resolved['dir'];
        }
    }

    if ($errors !== [] ) {
        // Fehler im Pfad zuerst melden, bevor eine Verbindung versucht wird.
    } elseif ($database === '' || $user === '') {
        $errors[] = 'Datenbankname und Benutzername sind erforderlich.';
    } else {
        $sslOptions = Installer::sslOptions($sslOn, $sslCa, $sslVerify);
        $connection = Installer::connect($host, $database, $user, $password, $port, $sslOptions);

        if (!$connection['ok']) {
            $errors[] = $connection['message'];
        } else {
            $privileges = Installer::checkPrivileges($connection['pdo']);

            if (!$privileges['ok']) {
                $errors[] = $privileges['message'];
            } else {
                $existing = Installer::existingTables($connection['pdo']);

                if ($existing !== []) {
                    $errors[] = sprintf(
                        'In dieser Datenbank liegen bereits %d Tabellen der Anwendung (%s%s). '
                        . 'Der Installer wuerde vorhandene Daten zerstoeren und bricht deshalb ab. '
                        . 'Entweder eine leere Datenbank waehlen oder die Tabellen zuerst entfernen.',
                        count($existing),
                        implode(', ', array_slice($existing, 0, 3)),
                        count($existing) > 3 ? ', ...' : ''
                    );
                } else {
                    store([
                        'db_host' => $host, 'db_port' => (string)$port, 'db_name' => $database,
                        'db_user' => $user, 'db_password' => $password,
                        'db_server' => (string)$connection['server'],
                        'db_ssl' => $sslOn ? '1' : '', 'db_ssl_ca' => $sslCa,
                        'db_ssl_verify' => $sslVerify ? '1' : '',
                        'schema_dir' => $schemaDir,
                    ]);

                    // Ob die Verbindung wirklich verschluesselt ist, sagt der
                    // Server - nicht die Absicht im Formular.
                    $cipher = '';
                    try {
                        $row = $connection['pdo']->query("SHOW STATUS LIKE 'Ssl_cipher'")?->fetch();
                        $cipher = (string)($row['Value'] ?? '');
                    } catch (PDOException) {
                        // Manche Server geben den Status nicht preis.
                    }

                    $notices[] = sprintf(
                        'Verbindung steht (Server %s, %s). %s',
                        $connection['server'],
                        $cipher !== '' ? 'verschluesselt mit ' . $cipher : 'unverschluesselt',
                        $privileges['message']
                    );

                    if ($sslOn && $cipher === '') {
                        $notices[] = 'Hinweis: Verschluesselung war angehakt, die Verbindung ist '
                            . 'aber unverschluesselt. Der Server bietet sie offenbar nicht an.';
                    }
                }
            }
        }
    }

    if ($errors !== []) {
        $step = 2;
    }
}

// --- Schritt 3 abgeschickt: Webseitendaten pruefen und installieren
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST' && $errors === []) {
    $siteName    = post('site_name', 'Spieldaten');
    $baseUrl     = rtrim(post('base_url'), '/');
    $timezone    = post('timezone', 'Europe/Berlin');
    $attribution = post('attribution');
    $adminUser   = trim((string)($_POST['admin_username'] ?? ''));
    $adminMail   = trim((string)($_POST['admin_email'] ?? ''));
    $adminPass   = (string)($_POST['admin_password'] ?? '');
    $adminRepeat = (string)($_POST['admin_password_repeat'] ?? '');

    if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'Die Adresse der Webseite ist keine gueltige URL.';
    }
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        $errors[] = 'Die Zeitzone ist unbekannt.';
    }
    if (preg_match(Users::USERNAME_PATTERN, $adminUser) !== 1) {
        $errors[] = 'Der Benutzername darf Buchstaben, Ziffern, Punkt, Bindestrich und '
            . 'Unterstrich enthalten und muss 2 bis 32 Zeichen lang sein.';
    }
    if (!filter_var($adminMail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Die Mailadresse sieht nicht wie eine Adresse aus. Ohne sie kannst '
            . 'du dein eigenes Passwort nicht zuruecksetzen.';
    }
    if (mb_strlen($adminPass) < Users::MIN_PASSWORD_LENGTH) {
        $errors[] = sprintf('Das Passwort muss mindestens %d Zeichen haben.', Users::MIN_PASSWORD_LENGTH);
    }
    if ($adminPass !== $adminRepeat) {
        $errors[] = 'Die beiden Passwoerter stimmen nicht ueberein.';
    }
    if (stored('db_name') === '') {
        $errors[] = 'Die Datenbankdaten fehlen. Bitte bei Schritt 2 beginnen.';
    }

    if ($errors === []) {
        store([
            'site_name' => $siteName, 'base_url' => $baseUrl,
            'timezone' => $timezone, 'attribution' => $attribution,
        ]);

        $connection = Installer::connect(
            stored('db_host'), stored('db_name'), stored('db_user'),
            stored('db_password'), (int)stored('db_port', '3306'),
            Installer::sslOptions(
                stored('db_ssl') === '1',
                stored('db_ssl_ca'),
                stored('db_ssl_verify') === '1'
            )
        );

        if (!$connection['ok']) {
            $errors[] = $connection['message'];
        } else {
            $pdo = $connection['pdo'];

            $schemaDir = stored('schema_dir') ?: (Installer::findSchemaDir(__DIR__)['dir'] ?? '');

            $schema = Installer::runSql($pdo, $schemaDir . '/schema.mysql.sql');
            if (!$schema['ok']) {
                $errors[] = 'Das Schema liess sich nicht einspielen. ' . $schema['message'];
            } else {
                $notices[] = sprintf('Schema eingespielt (%d Anweisungen).', $schema['executed']);

                $seed = Installer::runSql($pdo, $schemaDir . '/seed.sql');
                if (!$seed['ok']) {
                    $errors[] = 'Die Grunddaten liessen sich nicht einspielen. ' . $seed['message'];
                } else {
                    $notices[] = sprintf('Grunddaten eingetragen (%d Anweisungen).', $seed['executed']);

                    // Der erste Zugang wird als Benutzer angelegt, nicht nur
                    // als Passwort in der Konfiguration.
                    Db::set($pdo);
                    $adminId = Users::create([
                        'username' => $adminUser,
                        'email'    => $adminMail,
                        'password' => $adminPass,
                        'role'     => Users::ROLE_ADMIN,
                        'active'   => 1,
                    ], 'installer');

                    // Die eigene Adresse gilt als bestaetigt: sie wurde hier
                    // gerade eingetippt, und ohne sie kaeme der Webadmin bei
                    // einem vergessenen Passwort nicht mehr herein.
                    Users::markVerified($adminId);
                    $notices[] = sprintf('Webadmin "%s" angelegt.', $adminUser);

                    $written = Installer::writeConfig(CONFIG_FILE, [
                        'created_at'          => date('d.m.Y H:i'),
                        'dsn'                 => Installer::dsn(stored('db_host'), stored('db_name'), (int)stored('db_port', '3306')),
                        'db_user'             => stored('db_user'),
                        'db_password'         => stored('db_password'),
                        'db_options'          => Installer::sslOptions(
                            stored('db_ssl') === '1',
                            stored('db_ssl_ca'),
                            stored('db_ssl_verify') === '1'
                        ),
                        'site_name'           => $siteName,
                        'base_url'            => $baseUrl,
                        'timezone'            => $timezone,
                        'attribution'         => $attribution,
                        'admin_password_hash' => password_hash($adminPass, PASSWORD_DEFAULT),
                        'mail_from'           => '',
                    ]);

                    if (!$written['ok']) {
                        $errors[] = $written['message'];
                    } else {
                        $notices[] = 'config.php geschrieben.';
                        // Zugangsdaten nicht laenger als noetig in der Sitzung halten.
                        unset($_SESSION['install']);
                        $step = 5;
                    }
                }
            }
        }
    }

    if ($errors !== []) {
        $step = 3;
    }
}

// --- Schritt 5: Installer entfernen
if ($step === 5 && ($_POST['action'] ?? '') === 'remove' && tokenValid()) {
    if (@unlink(__FILE__)) {
        header('Location: ' . rtrim(guessBaseUrl(), '/') . '/admin/');
        exit;
    }
    $errors[] = 'Die Datei liess sich nicht loeschen. Bitte install.php von Hand entfernen.';
}

// In Schritt 1 kann der Pfad zu den Schemadateien eingetragen werden,
// falls die automatische Suche nichts findet.
if (($_POST['action'] ?? '') === 'check_schema' && tokenValid()) {
    store(['schema_dir' => post('schema_dir')]);
    $step = 1;
}

$schemaOverride = stored('schema_dir');
$checks = Requirements::all(__DIR__, $schemaOverride === '' ? null : $schemaOverride);
$blockers = array_values(array_filter(
    $checks,
    static fn(array $c): bool => $c['level'] === 'required' && !$c['ok'] && !str_contains($c['name'], 'Konfiguration')
));

$schemaMissing = (bool)array_filter(
    $blockers,
    static fn(array $c): bool => str_contains($c['name'], 'Schemadateien')
);

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Installation - vijabei.net Spieldaten</title>
<style>
:root {
  --bg: #f4f5f7; --card: #fff; --ink: #1b1d21; --muted: #6b7280;
  --line: #dcdfe4; --ok: #1a7f47; --warn: #9a6700; --bad: #b3261e; --accent: #14532d;
}
* { box-sizing: border-box; }
body {
  margin: 0; padding: 2rem 1rem; background: var(--bg); color: var(--ink);
  font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
}
.wrap { max-width: 46rem; margin: 0 auto; }
h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
h2 { font-size: 1.15rem; margin: 1.75rem 0 .5rem; }
.lead { color: var(--muted); margin: 0 0 1.5rem; }
.card { background: var(--card); border: 1px solid var(--line); border-radius: .5rem; padding: 1.5rem; }
ol.steps { display: flex; flex-wrap: wrap; gap: .4rem; list-style: none; padding: 0; margin: 0 0 1.5rem; font-size: .82rem; }
ol.steps li { padding: .3rem .7rem; border-radius: 1rem; background: #e7e9ec; color: var(--muted); }
ol.steps li.now { background: var(--accent); color: #fff; }
ol.steps li.done { background: #d6e9dc; color: var(--ok); }
table { width: 100%; border-collapse: collapse; font-size: .9rem; }
th, td { text-align: left; padding: .5rem .6rem; border-bottom: 1px solid var(--line); vertical-align: top; }
th { font-weight: 600; color: var(--muted); font-size: .8rem; text-transform: uppercase; letter-spacing: .03em; }
td.state { white-space: nowrap; font-weight: 600; }
.ok { color: var(--ok); } .warn { color: var(--warn); } .bad { color: var(--bad); }
.hint { display: block; color: var(--muted); font-size: .84rem; margin-top: .2rem; }
label { display: block; margin: 1rem 0 .3rem; font-weight: 600; font-size: .9rem; }
label.inline { display: flex; align-items: center; gap: .5rem; font-weight: 400; }
label.inline input { width: auto; }
input[type=text], input[type=password], input[type=url], select {
  width: 100%; padding: .55rem .65rem; border: 1px solid var(--line);
  border-radius: .3rem; font: inherit; background: #fff;
}
input:focus, select:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
.row { display: flex; gap: 1rem; } .row > * { flex: 1; } .row > .narrow { flex: 0 0 8rem; }
.note { font-size: .85rem; color: var(--muted); margin-top: .25rem; }
ul.note { margin: .4rem 0; padding-left: 1.2rem; }
ul.note li { margin: .15rem 0; }
.msg { padding: .8rem 1rem; border-radius: .35rem; margin-bottom: 1rem; font-size: .92rem; }
.msg.bad { background: #fdeceb; border: 1px solid #f2c4c0; color: var(--bad); }
.msg.good { background: #e8f4ec; border: 1px solid #bcdcc8; color: var(--ok); }
.msg ul { margin: .4rem 0 0; padding-left: 1.1rem; }
.actions { margin-top: 1.75rem; display: flex; gap: .75rem; align-items: center; }
button {
  background: var(--accent); color: #fff; border: 0; border-radius: .35rem;
  padding: .6rem 1.3rem; font: inherit; font-weight: 600; cursor: pointer;
}
button:hover { filter: brightness(1.15); }
button.ghost { background: #e7e9ec; color: var(--ink); }
button[disabled] { opacity: .45; cursor: not-allowed; }
a { color: var(--accent); }
code { background: #eef0f2; padding: .1rem .3rem; border-radius: .2rem; font-size: .88em; }
</style>
</head>
<body>
<div class="wrap">

<h1>vijabei.net Spieldaten</h1>
<p class="lead">Installation</p>

<ol class="steps">
<?php foreach (STEPS as $number => $label): ?>
  <li class="<?= $number === $step ? 'now' : ($number < $step ? 'done' : '') ?>">
    <?= $number ?>. <?= e($label) ?>
  </li>
<?php endforeach; ?>
</ol>

<div class="card">

<?php if ($errors !== []): ?>
  <div class="msg bad">
    <strong>Das hat noch nicht geklappt:</strong>
    <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if ($notices !== []): ?>
  <div class="msg good">
    <ul><?php foreach ($notices as $notice): ?><li><?= e($notice) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<?php if ($step === 0): ?>

  <h2>Bereits installiert</h2>
  <p>Es gibt schon eine <code>config.php</code>. Der Installer ruehrt sie nicht an,
     um eine laufende Installation nicht zu zerstoeren.</p>
  <p>Wenn du wirklich neu installieren willst, loesche zuerst <code>config.php</code>
     und leere die Datenbank. Andernfalls loesche <code>install.php</code> &mdash;
     die Datei sollte auf einem laufenden System nicht erreichbar sein.</p>
  <div class="actions">
    <a href="admin/"><button type="button">Zur Anmeldung</button></a>
  </div>

<?php elseif ($step === 1): ?>

  <h2>Voraussetzungen</h2>
  <p class="note">Geprueft wird die Umgebung, in der PHP hier laeuft.</p>

  <table>
    <thead><tr><th>Punkt</th><th>Gefunden</th><th>Erwartet</th><th>Ergebnis</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $check): ?>
      <tr>
        <td>
          <?= e($check['name']) ?>
          <?php if ($check['hint'] !== null): ?><span class="hint"><?= e($check['hint']) ?></span><?php endif; ?>
        </td>
        <td><?= e($check['actual']) ?></td>
        <td><?= e($check['expected']) ?></td>
        <td class="state <?= $check['ok'] ? 'ok' : ($check['level'] === 'required' ? 'bad' : 'warn') ?>">
          <?php if ($check['level'] === 'info'): ?>&ndash;
          <?php elseif ($check['ok']): ?>in Ordnung
          <?php elseif ($check['level'] === 'required'): ?>fehlt
          <?php else: ?>empfohlen<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($schemaMissing || $schemaOverride !== ''): ?>
    <h2>Pfad zu den Schemadateien</h2>
    <p class="note">Trage das Verzeichnis ein, in dem <code>schema.mysql.sql</code> und
       <code>seed.sql</code> liegen. Erlaubt sind alle drei Formen:</p>
    <ul class="note">
      <li><code>../db</code> &ndash; relativ zu dieser Installation</li>
      <li><code>/public_html/db</code> &ndash; so wie es im FTP-Programm aussieht</li>
      <li><code>/usr/www/users/kunde/public_html/db</code> &ndash; der volle Pfad</li>
    </ul>
    <p class="note">Dein FTP-Programm zeigt in aller Regel einen anderen Pfad als der
       Server selbst. Diese Installation liegt fuer den Server unter
       <code><?= e(__DIR__) ?></code>.</p>
    <form method="post">
      <input type="hidden" name="token" value="<?= e(token()) ?>">
      <input type="hidden" name="action" value="check_schema">
      <label for="schema_dir_1">Verzeichnis</label>
      <input type="text" id="schema_dir_1" name="schema_dir"
             value="<?= e($schemaOverride) ?>" placeholder="/usr/www/users/beispiel/db">
      <div class="actions">
        <button type="submit" class="ghost">Pfad pruefen</button>
      </div>
    </form>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="token" value="<?= e(token()) ?>">
    <input type="hidden" name="step" value="2">
    <div class="actions">
      <button type="submit" <?= $blockers !== [] ? 'disabled' : '' ?>>Weiter zur Datenbank</button>
      <?php if ($blockers !== []): ?>
        <span class="note"><?= count($blockers) ?> Punkt(e) muessen zuerst behoben werden.</span>
      <?php else: ?>
        <a href="?step=1" class="note">Erneut pruefen</a>
      <?php endif; ?>
    </div>
  </form>

<?php elseif ($step === 2): ?>

  <h2>Datenbank</h2>
  <p class="note">Die Datenbank muss in der Hetzner-Konsole bereits angelegt sein &mdash;
     der Installer legt keine an. Er traegt nur die Tabellen ein.</p>

  <form method="post">
    <input type="hidden" name="token" value="<?= e(token()) ?>">
    <input type="hidden" name="step" value="3">

    <div class="row">
      <div>
        <label for="db_host">Server</label>
        <input type="text" id="db_host" name="db_host" value="<?= e(stored('db_host', 'localhost')) ?>" required>
        <p class="note">Bei Hetzner meist <code>localhost</code>.</p>
      </div>
      <div class="narrow">
        <label for="db_port">Port</label>
        <input type="text" id="db_port" name="db_port" value="<?= e(stored('db_port', '3306')) ?>">
      </div>
    </div>

    <label for="db_name">Datenbankname</label>
    <input type="text" id="db_name" name="db_name" value="<?= e(stored('db_name')) ?>" required>

    <label for="db_user">Benutzername</label>
    <input type="text" id="db_user" name="db_user" value="<?= e(stored('db_user')) ?>" required>

    <label for="db_password">Passwort</label>
    <input type="password" id="db_password" name="db_password" autocomplete="new-password">

    <h2>Verschluesselung</h2>
    <label class="inline">
      <input type="checkbox" name="db_ssl" value="1" id="db_ssl"
             <?= stored('db_ssl') === '1' ? 'checked' : '' ?>>
      Verbindung per TLS verschluesseln
    </label>
    <p class="note">Viele Hoster bieten das nicht an, weil die Datenbank ohnehin ueber
       den lokalen Socket erreicht wird. Im Zweifel weglassen &mdash; der Installer meldet
       anschliessend, ob die Verbindung tatsaechlich verschluesselt ist.</p>

    <div id="ssl_details">
      <label for="db_ssl_ca">CA-Zertifikat (Pfad, optional)</label>
      <input type="text" id="db_ssl_ca" name="db_ssl_ca" value="<?= e(stored('db_ssl_ca')) ?>"
             placeholder="/pfad/zu/ca.pem">
      <label class="inline">
        <input type="checkbox" name="db_ssl_verify" value="1"
               <?= stored('db_ssl_verify') === '1' ? 'checked' : '' ?>>
        Serverzertifikat pruefen
      </label>
      <p class="note">Ohne CA-Zertifikat laesst sich das Serverzertifikat nicht pruefen.
         Die Verbindung ist dann verschluesselt, aber nicht authentifiziert.</p>
    </div>

    <input type="hidden" name="schema_dir" value="<?= e($schemaOverride) ?>">
    <?php
    $schemaIn = $schemaOverride !== '' ? $schemaOverride : (Installer::findSchemaDir(__DIR__)['dir'] ?? '');
    if ($schemaIn !== ''): ?>
      <p class="note">Schema wird eingespielt aus <code><?= e($schemaIn) ?></code>.</p>
    <?php endif; ?>

    <div class="actions">
      <button type="submit">Verbindung pruefen und weiter</button>
    </div>
    <p class="note">Geprueft wird die Verbindung, ob Umlaute unveraendert zurueckkommen,
       ob der Benutzer Tabellen anlegen, aendern und loeschen darf, und ob die
       Datenbank noch leer ist.</p>
  </form>

<?php elseif ($step === 3): ?>

  <h2>Webseite</h2>

  <form method="post">
    <input type="hidden" name="token" value="<?= e(token()) ?>">
    <input type="hidden" name="step" value="4">

    <label for="site_name">Name der Seite</label>
    <input type="text" id="site_name" name="site_name"
           value="<?= e(stored('site_name', 'vijabei.net Spieldaten')) ?>" required>

    <label for="base_url">Adresse</label>
    <input type="url" id="base_url" name="base_url"
           value="<?= e(stored('base_url') ?: guessBaseUrl()) ?>" required>
    <p class="note">Ohne Schraegstrich am Ende. Wird fuer die Adressen in der API gebraucht.</p>

    <label for="timezone">Zeitzone</label>
    <select id="timezone" name="timezone">
      <?php
      $chosen = stored('timezone', 'Europe/Berlin');
      foreach (['Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich', 'UTC'] as $zone): ?>
        <option value="<?= e($zone) ?>" <?= $zone === $chosen ? 'selected' : '' ?>><?= e($zone) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="note">Anstosszeiten werden in UTC gespeichert und in dieser Zone angezeigt.</p>

    <label for="attribution">Quellenhinweis</label>
    <input type="text" id="attribution" name="attribution"
           value="<?= e(stored('attribution', 'Daten gepflegt von vijabei.net')) ?>">
    <p class="note">Wird in den API-Antworten mitgeliefert.</p>

    <h2>Erster Zugang</h2>
    <label for="admin_username">Benutzername</label>
    <input type="text" id="admin_username" name="admin_username"
           value="<?= e(stored('admin_username', 'admin')) ?>" autocomplete="username" required>
    <p class="note">Dieser Zugang bekommt die Rolle Verwaltung und darf Benutzer,
       Wettbewerbe und Importe verwalten. Weitere Zugänge legst du später im
       Bereich „Benutzer" an.</p>

    <label for="admin_email">Mailadresse</label>
    <input type="text" id="admin_email" name="admin_email"
           value="<?= e(stored('admin_email')) ?>" autocomplete="email" required>
    <p class="note">Für das Zurücksetzen des eigenen Passworts. Sie gilt sofort als
       bestätigt &ndash; du hast sie ja gerade eingetippt.</p>

    <label for="admin_password">Passwort</label>
    <input type="password" id="admin_password" name="admin_password" autocomplete="new-password" required>
    <p class="note">Mindestens <?= Users::MIN_PASSWORD_LENGTH ?> Zeichen. Es wird nur als Hash
       gespeichert, nie im Klartext.</p>

    <label for="admin_password_repeat">Passwort wiederholen</label>
    <input type="password" id="admin_password_repeat" name="admin_password_repeat" autocomplete="new-password" required>

    <div class="actions">
      <button type="submit">Installieren</button>
    </div>
  </form>

<?php elseif ($step === 5): ?>

  <h2>Fertig</h2>
  <p>Die Datenbank ist eingerichtet und <code>config.php</code> geschrieben.</p>

  <h2>Ein Schritt fehlt noch</h2>
  <p><code>install.php</code> muss vom Server verschwinden. Solange die Datei erreichbar ist,
     kann jemand die Installation erneut anstossen.</p>

  <form method="post">
    <input type="hidden" name="token" value="<?= e(token()) ?>">
    <input type="hidden" name="step" value="5">
    <input type="hidden" name="action" value="remove">
    <div class="actions">
      <button type="submit">Installer löschen und anmelden</button>
      <a href="admin/" class="note">Ohne Loeschen weiter</a>
    </div>
  </form>

  <h2>Wie es weitergeht</h2>
  <p>Nach der Anmeldung laedst du die erste Importdatei hoch &ndash; JSON oder CSV.
     Beim ersten Import ordnest du die Mannschaftsnamen einmalig zu; danach greift
     die Zuordnung automatisch.</p>
  <p>Aufbau der Formate: <code>docs/import.md</code>.</p>

<?php endif; ?>

</div>
</div>
<script>
(function () {
  var box = document.getElementById('db_ssl');
  var details = document.getElementById('ssl_details');
  if (!box || !details) { return; }
  function sync() { details.hidden = !box.checked; }
  box.addEventListener('change', sync);
  sync();
})();
</script>
</body>
</html>
