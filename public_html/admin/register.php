<?php
declare(strict_types=1);

/**
 * Selbstregistrierung.
 *
 * Ein neues Konto kann niemandem etwas anhaben: Schreibrechte haengen immer
 * an einer eigenen Liga. Deshalb braucht es hier keine Freischaltung und
 * keine Mailadresse.
 */

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/users.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$config = App::boot();
Auth::start();

if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$errors = [];
$fertig = false;
$eingabe = [];

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eingabe = [
        'username'        => trim((string)($_POST['username'] ?? '')),
        'password'        => (string)($_POST['password'] ?? ''),
        'password_repeat' => (string)($_POST['password_repeat'] ?? ''),
        'role'            => Users::ROLE_USER,
        'active'          => 1,
    ];

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    if (!Auth::tokenValid()) {
        $errors[] = 'Die Sitzung ist abgelaufen. Bitte noch einmal versuchen.';
    } elseif (!Users::mayRegister($ip)) {
        $errors[] = 'Von hier wurden gerade mehrere Konten angelegt. Bitte in einer '
            . 'Stunde noch einmal versuchen.';
    } else {
        $errors = Users::validate($eingabe);

        if ($errors === []) {
            Users::create($eingabe, 'anmeldung');
            Users::noteRegistration($ip);
            $fertig = true;
            $eingabe = [];
        }
    }
}

admin_head('Anmelden', $config);
?>
<main style="max-width:26rem;margin:4rem auto;padding:0 1rem">
  <h1>Konto anlegen</h1>

  <?php if ($fertig): ?>
    <div class="card">
      <div class="msg good">Das Konto ist angelegt.</div>
      <p>Du kannst dich jetzt anmelden, eigene Ligen anlegen und pflegen.</p>
      <div class="actions"><a href="index.php"><button type="button">Zur Anmeldung</button></a></div>
    </div>
  <?php else: ?>
    <div class="card">
      <?php foreach ($errors as $error): ?>
        <div class="msg bad"><?= e($error) ?></div>
      <?php endforeach; ?>

      <form method="post">
        <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">

        <label for="username">Benutzername</label>
        <input type="text" id="username" name="username" value="<?= e($eingabe['username'] ?? '') ?>"
               autocomplete="username" autofocus required>

        <label for="password">Passwort</label>
        <input type="password" id="password" name="password" autocomplete="new-password" required>

        <label for="password_repeat">Wiederholen</label>
        <input type="password" id="password_repeat" name="password_repeat" autocomplete="new-password" required>
        <p class="note">Mindestens <?= Users::MIN_PASSWORD_LENGTH ?> Zeichen.</p>

        <div class="actions">
          <button type="submit">Konto anlegen</button>
          <a href="index.php" class="note">Schon angemeldet?</a>
        </div>
      </form>
    </div>

    <div class="card">
      <h2 style="margin-top:0;font-size:.95rem">Was du damit kannst</h2>
      <p class="note">Eigene Ligen anlegen und pflegen, Daten importieren und exportieren.
         Wer eine Liga anlegt, betreut sie und kann andere als Co-Admin dazunehmen.
         An fremden Ligen kannst du nichts ändern &ndash; dafür fragst du deren Besitzer.</p>
      <p class="note">Es wird keine Mailadresse gespeichert. Das heißt auch: ein vergessenes
         Passwort kann nur der Webadmin zurücksetzen.</p>
    </div>
  <?php endif; ?>
</main>
<?php
admin_foot();
