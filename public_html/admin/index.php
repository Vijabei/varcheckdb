<?php
declare(strict_types=1);

/** Anmeldung und Uebersicht. */

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$config = App::boot();
Auth::start();

$errors = [];

if (isset($_GET['logout'])) {
    Auth::logout();
    header('Location: index.php?abgemeldet=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::tokenValid()) {
        $errors[] = 'Die Sitzung ist abgelaufen. Bitte erneut anmelden.';
    } elseif (!Auth::check((string)($_POST['password'] ?? ''), $config)) {
        $errors[] = 'Das Passwort stimmt nicht.';
        // Wiederholtes Raten ausbremsen, ohne den Server zu blockieren.
        usleep(400000);
    } else {
        header('Location: index.php');
        exit;
    }
}

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ------------------------------------------------------------- Anmeldeseite

if (!Auth::isLoggedIn()) {
    admin_head('Anmeldung', $config);
    ?>
    <main style="max-width:24rem;margin:4rem auto;padding:0 1rem">
      <h1>Anmeldung</h1>
      <div class="card">
        <?php foreach ($errors as $error): ?>
          <div class="msg bad"><?= e($error) ?></div>
        <?php endforeach; ?>
        <?php if (isset($_GET['abgemeldet'])): ?>
          <div class="msg good">Du bist abgemeldet.</div>
        <?php endif; ?>

        <form method="post">
          <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
          <label for="password">Passwort</label>
          <input type="password" id="password" name="password" autocomplete="current-password" autofocus required>
          <div class="actions"><button type="submit">Anmelden</button></div>
        </form>
      </div>
      <p class="note">Das Passwort wurde bei der Installation vergeben.</p>
    </main>
    <?php
    admin_foot();
    exit;
}

// ---------------------------------------------------------------- Uebersicht

$stats = Repo::stats();
$competitions = Repo::competitions();

admin_head('Übersicht', $config);
admin_nav('index.php', $config);
?>

<h1>Übersicht</h1>

<div class="card">
  <div class="stats">
    <div class="stat"><b><?= $stats['competitions'] ?></b><span>Wettbewerbe</span></div>
    <div class="stat"><b><?= $stats['teams'] ?></b><span>Mannschaften</span></div>
    <div class="stat"><b><?= $stats['matches'] ?></b><span>Spiele</span></div>
    <div class="stat"><b><?= $stats['finished'] ?></b><span>gespielt</span></div>
    <div class="stat"><b><?= $stats['aliases'] ?></b><span>Namenszuordnungen</span></div>
  </div>
</div>

<?php if ($stats['matches'] === 0): ?>
  <div class="card">
    <h2 style="margin-top:0">Noch keine Spiele</h2>
    <p>Die Datenbank ist eingerichtet, aber leer. Importdateien werden auf deinem
       eigenen Rechner erzeugt und hier hochgeladen &ndash; als JSON oder CSV.</p>
    <div class="actions"><a href="import.php"><button type="button">Zum Import</button></a></div>
  </div>
<?php endif; ?>

<h2>Wettbewerbe</h2>
<div class="card">
  <?php if ($competitions === []): ?>
    <p class="empty">Keine Wettbewerbe angelegt.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Wettbewerb</th><th>Saison</th><th>Kürzel</th><th>Spiele</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($competitions as $row): ?>
        <?php $count = (int)Db::value('SELECT COUNT(*) FROM matches WHERE competition_season_id = ?', [$row['id']]); ?>
        <tr>
          <td><?= e($row['competition_name']) ?></td>
          <td><?= e($row['season_name']) ?></td>
          <td><code><?= e($row['shortcut']) ?></code></td>
          <td><?= $count ?></td>
          <td><a href="matches.php?competition=<?= (int)$row['id'] ?>">Spielplan</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($stats['last_change'] !== null): ?>
  <p class="note">Letzte Änderung: <?= e((string)$stats['last_change']) ?> (UTC)</p>
<?php endif; ?>

<?php
admin_foot();
