<?php
declare(strict_types=1);

/**
 * Anmeldung.
 *
 * Liegt bewusst in der Wurzel und nicht in einem der beiden geschuetzten
 * Ordner: angemeldet wird sich einmal, danach trennen sich die Wege in
 * meine/ (jeder) und admin/ (Webadmin).
 */

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$config = App::boot();
Auth::start();

$errors = [];

if (isset($_GET['logout'])) {
    Auth::logout();
    header('Location: login.php?abgemeldet=1');
    exit;
}

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (Auth::isLoggedIn() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: meine/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::tokenValid()) {
        $errors[] = 'Die Sitzung ist abgelaufen. Bitte erneut anmelden.';
    } elseif (!Auth::login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
        // Keine Auskunft darueber, ob der Name oder das Passwort falsch war.
        $errors[] = 'Benutzername oder Passwort stimmt nicht.';
        // Wiederholtes Raten ausbremsen, ohne den Server zu blockieren.
        usleep(400000);
    } else {
        header('Location: meine/');
        exit;
    }
}

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
    <?php if (isset($_GET['gesperrt'])): ?>
      <div class="msg bad">Dein Zugang wurde abgeschaltet.</div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">

      <label for="username">Benutzername</label>
      <input type="text" id="username" name="username" autocomplete="username" autofocus required>

      <label for="password">Passwort</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>

      <div class="actions"><button type="submit">Anmelden</button></div>
    </form>
  </div>

  <p class="note">Noch kein Konto? <a href="register.php">Hier anlegen</a> &ndash;
     damit kannst du eigene Ligen führen.</p>
  <p class="note"><a href="passwort.php">Passwort vergessen?</a> &middot;
     <a href="./">Zur öffentlichen Seite</a></p>
</main>
<?php
admin_foot();
