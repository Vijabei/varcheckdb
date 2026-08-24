<?php
declare(strict_types=1);

/**
 * Bringt die Datenbank auf den Stand der hochgeladenen Dateien.
 *
 * Gegenstueck zu install.php: hochladen, im Browser aufrufen, danach wieder
 * entfernen. Es fuehrt nur aus, was fehlt, und bricht vor jeder Aenderung ab,
 * wenn etwas im Weg steht.
 *
 * Der Zugang ist mit dem Passwort aus config.php gesichert - nicht mit einem
 * Benutzerkonto. Der Grund: gerade wenn eine Migration noetig ist, kann die
 * Benutzertabelle noch fehlen oder anders aussehen. Ein Zugang, der von dem
 * abhaengt, was er reparieren soll, taugt nicht.
 *
 * Dieselbe Arbeit erledigt tools/migrate.php auf der Kommandozeile. Die Logik
 * steht in Migrator, damit beide Wege sich nicht auseinanderentwickeln.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/lib/setup/Installer.php';
require_once __DIR__ . '/lib/setup/Migrator.php';

session_start();

const CONFIG_FILE = __DIR__ . '/config.php';
const SESSION_KEY = 'varcheckdb_update';

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function token(): string
{
    return $_SESSION['update_token'] ??= bin2hex(random_bytes(16));
}

function tokenValid(): bool
{
    return isset($_POST['token']) && hash_equals(token(), (string)$_POST['token']);
}

$errors = [];
$notices = [];
$protokoll = [];

$config = is_file(CONFIG_FILE) ? require CONFIG_FILE : null;

if (!is_array($config) || !isset($config['db']['dsn'])) {
    $errors[] = 'Es gibt noch keine brauchbare config.php. Diese Seite bringt eine '
        . 'bestehende Installation auf den neuen Stand; eine neue richtet install.php ein.';
    $config = null;
}

// ----------------------------------------------------------------- Zugang

$angemeldet = ($_SESSION[SESSION_KEY] ?? false) === true;

if (($_POST['action'] ?? '') === 'login' && $config !== null) {
    if (!tokenValid()) {
        $errors[] = 'Die Sitzung ist abgelaufen. Bitte noch einmal versuchen.';
    } else {
        $hash = (string)($config['admin_password_hash'] ?? '');
        $eingabe = (string)($_POST['password'] ?? '');

        if ($hash !== '' && password_verify($eingabe, $hash)) {
            session_regenerate_id(true);
            $_SESSION[SESSION_KEY] = true;
            $angemeldet = true;
        } else {
            // Gleiche Laufzeit, egal ob ein Hash hinterlegt ist.
            password_verify($eingabe, '$2y$10$ungueltigungueltigungueltigungueltigungueltigungueltigun');
            usleep(400000);
            $errors[] = $hash === ''
                ? 'In config.php steht kein admin_password_hash. Ohne ihn ist diese Seite '
                  . 'nicht zu benutzen; die Aktualisierung geht dann nur ueber die '
                  . 'Kommandozeile mit tools/migrate.php.'
                : 'Das Passwort stimmt nicht.';
        }
    }
}

if (($_GET['abmelden'] ?? '') !== '') {
    unset($_SESSION[SESSION_KEY]);
    header('Location: update.php');
    exit;
}

// --------------------------------------------------------------- Datenbank

$pdo = null;
$stand = [];

if ($config !== null && $angemeldet) {
    try {
        $pdo = new PDO(
            $config['db']['dsn'],
            $config['db']['user'] ?? null,
            $config['db']['password'] ?? null,
            ($config['db']['options'] ?? []) + [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (PDOException $e) {
        $errors[] = 'Die Datenbank ist nicht erreichbar: ' . $e->getMessage();
    }
}

// db/ liegt je nach Hoster woanders - dieselbe Suche wie beim Installer.
$migrator = new Migrator(dirname(__DIR__) . '/db/migrations');

if ($migrator->dateien() === []) {
    $gesucht = Installer::findSchemaDir(__DIR__);
    if ($gesucht['dir'] !== null) {
        $migrator = new Migrator($gesucht['dir'] . '/migrations');
    }
}

$verzeichnisGefunden = $migrator->dateien() !== [];

if ($pdo !== null) {
    if (!Migrator::eingerichtet($pdo)) {
        $errors[] = 'In dieser Datenbank steht noch kein Schema. Eine neue Installation '
            . 'richtet install.php ein.';
    } elseif (!$verzeichnisGefunden) {
        $errors[] = 'Es wurden keine Migrationsdateien gefunden. Der Ordner db/migrations '
            . 'muss mit hochgeladen sein.';
    } else {
        $stand = $migrator->stand($pdo);
    }
}

// -------------------------------------------------------------- Ausfuehren

if (($_POST['action'] ?? '') === 'run' && $angemeldet && $pdo !== null && $stand !== []) {
    if (!tokenValid()) {
        $errors[] = 'Die Sitzung ist abgelaufen. Bitte noch einmal versuchen.';
    } else {
        $ergebnis = $migrator->ausfuehren(
            $pdo,
            static function (string $art, string $text, array $zeilen) use (&$protokoll): void {
                $protokoll[] = ['art' => $art, 'text' => $text, 'zeilen' => $zeilen];
            }
        );

        if ($ergebnis['abgebrochen'] !== null) {
            $errors[] = sprintf(
                'Abgebrochen bei %s. Was davor lief, bleibt bestehen.',
                $ergebnis['abgebrochen']
            );
        } else {
            $notices[] = $ergebnis['ausgefuehrt'] > 0
                ? sprintf('%d Migrationen ausgeführt. Die Datenbank ist auf dem aktuellen Stand.', $ergebnis['ausgefuehrt'])
                : 'Nichts auszuführen. Die Datenbank ist auf dem aktuellen Stand.';

            if ($ergebnis['vermerkt'] > 0) {
                $notices[] = sprintf(
                    '%d Migrationen waren bereits vorhanden und wurden nur vermerkt.',
                    $ergebnis['vermerkt']
                );
            }
        }

        $stand = $migrator->stand($pdo);
    }
}

if (($_POST['action'] ?? '') === 'remove' && $angemeldet && tokenValid()) {
    if (@unlink(__FILE__)) {
        header('Location: login.php');
        exit;
    }
    $errors[] = 'Die Datei ließ sich nicht löschen. Bitte update.php von Hand entfernen.';
}

$offen = count(array_filter($stand, static fn(array $e): bool => $e['zustand'] === Migrator::OFFEN));
$blockiert = array_filter(
    $stand,
    static fn(array $e): bool => $e['zustand'] === Migrator::OFFEN && $e['blocker'] !== []
);

/** Gibt Abfrageergebnisse als Tabelle aus. */
function tabelle(array $zeilen): void
{
    if ($zeilen === []) {
        return;
    }
    ?>
    <table>
      <thead><tr><?php foreach (array_keys($zeilen[0]) as $spalte): ?>
        <th><?= e((string)$spalte) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($zeilen as $z): ?>
        <tr><?php foreach ($z as $w): ?><td><?= e((string)$w) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Aktualisierung</title>
<style>
:root { --bg:#f4f5f7; --card:#fff; --ink:#1b1d21; --muted:#6b7280; --line:#dcdfe4;
        --ok:#1a7f47; --warn:#9a6700; --bad:#b3261e; --accent:#14532d; }
* { box-sizing:border-box; }
body { margin:0; padding:2rem 1rem; background:var(--bg); color:var(--ink);
       font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
.wrap { max-width:48rem; margin:0 auto; }
h1 { font-size:1.5rem; margin:0 0 .25rem; }
h2 { font-size:1.1rem; margin:1.75rem 0 .6rem; }
.lead { color:var(--muted); margin:0 0 1.5rem; }
.card { background:var(--card); border:1px solid var(--line); border-radius:.5rem;
        padding:1.5rem; margin-bottom:1rem; }
table { width:100%; border-collapse:collapse; font-size:.9rem; margin:.5rem 0; }
th,td { text-align:left; padding:.45rem .6rem; border-bottom:1px solid var(--line); vertical-align:top; }
th { color:var(--muted); font-size:.78rem; text-transform:uppercase; letter-spacing:.03em; }
.zustand { font-weight:600; white-space:nowrap; }
.ok { color:var(--ok); } .warn { color:var(--warn); } .bad { color:var(--bad); }
label { display:block; margin:1rem 0 .3rem; font-weight:600; font-size:.9rem; }
input[type=password] { width:100%; padding:.55rem .65rem; border:1px solid var(--line);
                       border-radius:.3rem; font:inherit; }
button { background:var(--accent); color:#fff; border:0; border-radius:.35rem;
         padding:.6rem 1.3rem; font:inherit; font-weight:600; cursor:pointer; }
button.ghost { background:#e7e9ec; color:var(--ink); }
.actions { margin-top:1.5rem; display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; }
.msg { padding:.8rem 1rem; border-radius:.35rem; margin-bottom:1rem; font-size:.92rem; }
.msg.bad { background:#fdeceb; border:1px solid #f2c4c0; color:var(--bad); }
.msg.good { background:#e8f4ec; border:1px solid #bcdcc8; color:var(--ok); }
.note { color:var(--muted); font-size:.85rem; }
code { background:#eef0f2; padding:.1rem .3rem; border-radius:.2rem; font-size:.88em; }
pre { background:#eef0f2; padding:.75rem; border-radius:.3rem; overflow-x:auto;
      font-size:.85rem; margin:.5rem 0; }
a { color:var(--accent); }
</style>
</head>
<body>
<div class="wrap">

<h1>Aktualisierung</h1>
<p class="lead">Bringt die Datenbank auf den Stand der hochgeladenen Dateien</p>

<?php foreach ($errors as $error): ?>
  <div class="msg bad"><?= e($error) ?></div>
<?php endforeach; ?>
<?php foreach ($notices as $notice): ?>
  <div class="msg good"><?= e($notice) ?></div>
<?php endforeach; ?>

<?php if ($config === null): ?>

  <div class="card">
    <div class="actions"><a href="install.php"><button type="button">Zur Installation</button></a></div>
  </div>

<?php elseif (!$angemeldet): ?>

  <div class="card">
    <h2 style="margin-top:0">Zugang</h2>
    <p class="note">Das Passwort steht als <code>admin_password_hash</code> in
       <code>config.php</code> &ndash; es wurde bei der Installation vergeben. Absichtlich
       nicht das Benutzerkonto: wenn eine Migration nötig ist, kann die Benutzertabelle
       noch fehlen.</p>

    <form method="post">
      <input type="hidden" name="token" value="<?= e(token()) ?>">
      <input type="hidden" name="action" value="login">
      <label for="password">Passwort</label>
      <input type="password" id="password" name="password" autocomplete="current-password" autofocus required>
      <div class="actions"><button type="submit">Weiter</button></div>
    </form>
  </div>

<?php else: ?>

  <?php if ($protokoll !== []): ?>
    <div class="card">
      <h2 style="margin-top:0">Was gelaufen ist</h2>
      <?php foreach ($protokoll as $eintrag): ?>
        <?php if ($eintrag['art'] === 'bericht'): ?>
          <?php tabelle($eintrag['zeilen']); ?>
        <?php elseif ($eintrag['art'] === 'blockiert'): ?>
          <p class="bad"><strong><?= e($eintrag['text']) ?></strong> &ndash; hier steht etwas im Weg:</p>
          <?php tabelle($eintrag['zeilen']); ?>
        <?php elseif ($eintrag['art'] === 'fehler'): ?>
          <pre class="bad"><?= e($eintrag['text']) ?></pre>
        <?php else: ?>
          <p class="note"><?= e($eintrag['art']) ?>: <?= e($eintrag['text']) ?></p>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($stand !== []): ?>
    <div class="card">
      <h2 style="margin-top:0">Stand</h2>
      <table>
        <thead><tr><th>Migration</th><th>Zustand</th></tr></thead>
        <tbody>
        <?php foreach ($stand as $eintrag): ?>
          <tr>
            <td><code><?= e($eintrag['name']) ?></code></td>
            <td class="zustand <?= match (true) {
                $eintrag['zustand'] !== Migrator::OFFEN => 'ok',
                $eintrag['blocker'] !== []              => 'bad',
                default                                 => 'warn',
            } ?>">
              <?= match (true) {
                  $eintrag['zustand'] === Migrator::ERLEDIGT  => 'erledigt',
                  $eintrag['zustand'] === Migrator::VORHANDEN => 'bereits vorhanden',
                  $eintrag['blocker'] !== []                  => 'blockiert',
                  default                                     => 'offen',
              } ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="note">„Bereits vorhanden" heißt: der Zustand ist schon hergestellt &ndash;
         die Migration wird nur vermerkt, nicht ausgeführt.</p>
    </div>

    <?php foreach ($blockiert as $eintrag): ?>
      <div class="card" style="border-color:#f2c4c0">
        <h2 style="margin-top:0"><code><?= e($eintrag['name']) ?></code> kann nicht laufen</h2>
        <?php tabelle($eintrag['blocker']); ?>
        <?php if ($eintrag['hinweis'] !== null): ?>
          <p class="note" style="margin-top:1rem">Was zu tun ist:</p>
          <pre><?= e($eintrag['hinweis']) ?></pre>
        <?php endif; ?>
        <p class="note">Diese Anweisungen in phpMyAdmin oder der Datenbankkonsole
           ausführen, dann diese Seite neu laden.</p>
      </div>
    <?php endforeach; ?>

    <div class="card">
      <?php if ($offen === 0): ?>
        <p>Die Datenbank ist auf dem aktuellen Stand.</p>
      <?php else: ?>
        <p><strong><?= $offen ?></strong> Migration(en) stehen aus.</p>
        <?php if ($blockiert !== []): ?>
          <p class="note">Die blockierte läuft nicht mit &ndash; es wird bis dorthin
             ausgeführt und dann angehalten. Das oben Genannte lässt sich danach
             erledigen und die Seite neu laden.</p>
        <?php endif; ?>
        <p class="note">Vorher eine Sicherung der Datenbank anlegen. Es wird nur
           ausgeführt, was fehlt; steht etwas im Weg, bricht es vor der Änderung ab.</p>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="token" value="<?= e(token()) ?>">
        <input type="hidden" name="action" value="run">
        <div class="actions">
          <button type="submit" <?= $offen === 0 ? 'disabled style="opacity:.45;cursor:not-allowed"' : '' ?>>
            Jetzt aktualisieren
          </button>
          <a href="update.php" class="note">Neu laden</a>
          <a href="?abmelden=1" class="note">Abmelden</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2 style="margin-top:0">Danach entfernen</h2>
    <p><code>update.php</code> sollte nicht dauerhaft auf dem Server liegen. Beim
       nächsten Update lädst du sie einfach wieder mit hoch.</p>
    <form method="post">
      <input type="hidden" name="token" value="<?= e(token()) ?>">
      <input type="hidden" name="action" value="remove">
      <div class="actions">
        <button type="submit" class="ghost">Diese Datei löschen</button>
        <a href="login.php" class="note">Zur Anmeldung</a>
      </div>
    </form>
  </div>

<?php endif; ?>

</div>
</body>
</html>
