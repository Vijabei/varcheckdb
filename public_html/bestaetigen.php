<?php
declare(strict_types=1);

/** Mailadresse über den Verweis aus der Bestätigungsmail freischalten. */

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$config = App::boot();
Auth::start();

$marke = trim((string)($_GET['marke'] ?? ''));
$wer = $marke !== '' ? Users::useToken($marke, 'verify') : null;

if ($wer !== null) {
    Users::markVerified((int)$wer['id']);
}

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

admin_head('Adresse bestätigen', $config);
?>
<main style="max-width:26rem;margin:4rem auto;padding:0 1rem">
  <h1>Mailadresse</h1>
  <div class="card">
    <?php if ($wer !== null): ?>
      <div class="msg good">Die Adresse von <strong><?= e($wer['username']) ?></strong> ist bestätigt.</div>
      <p class="note">Ein vergessenes Passwort kannst du jetzt selbst zurücksetzen.</p>
      <div class="actions"><a href="login.php"><button type="button">Zur Anmeldung</button></a></div>
    <?php else: ?>
      <div class="msg bad">Dieser Verweis gilt nicht mehr &ndash; er ist abgelaufen oder
        schon benutzt worden.</div>
      <p class="note">Melde dich an; dort kannst du dir einen neuen schicken lassen.</p>
      <div class="actions"><a href="login.php"><button type="button">Zur Anmeldung</button></a></div>
    <?php endif; ?>
  </div>
</main>
<?php
admin_foot();
