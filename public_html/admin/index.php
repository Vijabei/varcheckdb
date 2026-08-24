<?php
declare(strict_types=1);

/**
 * Werkzeuge des Webadmins.
 *
 * Hier steht, was den Betrieb der Anwendung betrifft - Konten, Datenbank,
 * Umgebung. Was mit Ligen zu tun hat, liegt in meine/ und gehoert jedem
 * Angemeldeten.
 */

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/users.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$config = App::boot();
Auth::requireCapability('system.manage');

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$stats = Repo::stats();
$benutzer = Users::all();
$aktiv = count(array_filter($benutzer, static fn(array $u): bool => (int)$u['active'] === 1));
$ohneMail = count(array_filter(
    $benutzer,
    static fn(array $u): bool => ($u['email'] ?? '') === '' || $u['email_verified_at'] === null
));

$installer = is_file(__DIR__ . '/../install.php');

admin_head('Verwaltung', $config);
admin_nav('index.php', $config, 'admin');
?>

<h1>Verwaltung</h1>

<?php if ($installer): ?>
  <div class="msg bad">
    <strong><code>install.php</code> liegt noch auf dem Server.</strong>
    Solange sie erreichbar ist, kann jeder sie aufrufen. Nach der Installation
    gehört sie gelöscht.
  </div>
<?php endif; ?>

<div class="card">
  <div class="stats">
    <div class="stat"><b><?= count($benutzer) ?></b><span>Konten</span></div>
    <div class="stat"><b><?= $aktiv ?></b><span>davon aktiv</span></div>
    <div class="stat"><b><?= $stats['competitions'] ?></b><span>Wettbewerbe</span></div>
    <div class="stat"><b><?= $stats['matches'] ?></b><span>Spiele</span></div>
  </div>
</div>

<h2>Konten</h2>
<div class="card">
  <p>Anlegen, Rolle ändern, abschalten. Wer eine Liga betreut, entscheidet dort
     selbst über Co-Admins &ndash; das läuft nicht über diese Seite.</p>
  <?php if ($ohneMail > 0): ?>
    <p class="note"><?= $ohneMail ?> Konto/Konten ohne bestätigte Mailadresse. Ohne sie
       ist kein Passwortreset möglich; dann bleibt nur, hier eines zu setzen.</p>
  <?php endif; ?>
  <div class="actions"><a href="users.php"><button type="button">Benutzer verwalten</button></a></div>
</div>

<h2>Datenbank</h2>
<div class="card">
  <p>Nach einem Update der Anwendung fehlen der Datenbank unter Umständen neue
     Tabellen oder Spalten. <code>update.php</code> prüft den Stand und spielt
     ein, was offen ist.</p>
  <p class="note">Die Seite hat einen eigenen Zugang über
     <code>admin_password_hash</code> aus <code>config.php</code> &ndash; absichtlich
     kein Benutzerkonto, denn unter Umständen ist gerade die Benutzertabelle das,
     was migriert werden muss.</p>
  <div class="actions">
    <a href="../update.php"><button type="button" class="ghost">Datenbankstand prüfen</button></a>
  </div>
</div>

<h2>Umgebung</h2>
<div class="card">
  <table>
    <tbody>
      <tr><td>PHP</td><td><?= e(PHP_VERSION) ?></td></tr>
      <tr><td>Datenbank</td><td><?= e((string)Db::pdo()->getAttribute(PDO::ATTR_SERVER_VERSION)) ?></td></tr>
      <tr><td>Zeitzone</td><td><?= e((string)($config['timezone'] ?? 'Europe/Berlin')) ?></td></tr>
      <tr><td>Mailversand</td><td><?= function_exists('mail') ? 'verfügbar' : '<span class="empty">nicht verfügbar</span>' ?></td></tr>
      <?php if ($stats['last_change'] !== null): ?>
        <tr><td>Letzte Änderung</td><td><?= e((string)$stats['last_change']) ?> (UTC)</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php
admin_foot();
