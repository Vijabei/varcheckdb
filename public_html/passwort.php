<?php
declare(strict_types=1);

/**
 * Passwort vergessen und neu setzen.
 *
 * Zwei Schritte: Adresse eingeben, dann ueber den Verweis aus der Mail ein
 * neues Passwort setzen.
 *
 * Ob es die Adresse gibt, wird nicht verraten - sonst liesse sich ausprobieren,
 * wer hier ein Konto hat. Scheitert der Versand, wird das aber gesagt: eine
 * Mail, auf die jemand vergeblich wartet, ist schlimmer als eine Absage.
 */

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$config = App::boot();
Auth::start();

$errors = [];
$notices = [];
$marke = trim((string)($_GET['marke'] ?? $_POST['marke'] ?? ''));
$fertig = false;

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$konto = $marke !== '' ? Users::peekToken($marke, 'reset') : null;

// --- Schritt 1: Adresse eingeben
if (($_POST['action'] ?? '') === 'anfordern' && Auth::tokenValid()) {
    $adresse = trim((string)($_POST['email'] ?? ''));

    $neutral = 'Wenn diese Adresse hier bekannt und bestätigt ist, ist eine Nachricht '
        . 'mit einem Verweis unterwegs. Er gilt eine Stunde.';

    // Ob ueberhaupt versendet werden kann, wird vor der Suche geklaert.
    // Sonst unterschiede sich die Antwort fuer bekannte und unbekannte
    // Adressen - und damit liesse sich abfragen, wer hier ein Konto hat.
    if (!Mail::enabled($config)) {
        $errors[] = 'Der Mailversand ist auf dieser Installation nicht eingerichtet. '
            . 'Ein Passwort kann derzeit nur der Webadmin zurücksetzen.';
        $adresse = '';
    }

    $wer = $adresse === '' ? null : Users::byEmail($adresse);

    if ($wer !== null && (int)$wer['active'] === 1 && Users::isVerified($wer)) {
        $token = Users::createToken((int)$wer['id'], 'reset');
        $link = rtrim((string)($config['base_url'] ?? ''), '/')
            . '/admin/passwort.php?marke=' . urlencode($token);

        $ergebnis = Mail::send(
            $config,
            (string)$wer['email'],
            'Passwort zurücksetzen',
            sprintf(
                "Hallo %s,\n\nüber diesen Verweis kannst du ein neues Passwort setzen:\n\n%s\n\n"
                . "Er gilt eine Stunde und nur einmal.\n\n"
                . "Hast du das nicht angefordert, kannst du diese Nachricht übergehen - "
                . "dein Passwort bleibt dann unverändert.\n",
                $wer['username'],
                $link
            )
        );

        $notices[] = $neutral;

        if (!$ergebnis['ok']) {
            // Die Auskunft bleibt neutral - sonst waere an ihr ablesbar, dass
            // es die Adresse gibt. Der Fehlschlag kommt ins Protokoll, damit
            // der Webadmin ihn sieht.
            Db::insert('change_log', [
                'entity_type' => 'user',
                'entity_id'   => (int)$wer['id'],
                'field'       => 'mail_fehlgeschlagen',
                'old_value'   => 'reset',
                'new_value'   => mb_substr($ergebnis['message'], 0, 255),
                'actor'       => 'system',
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ]);
        }
    } elseif ($adresse !== '') {
        $notices[] = $neutral;
    }
}

// --- Schritt 2: neues Passwort setzen
if (($_POST['action'] ?? '') === 'setzen' && Auth::tokenValid()) {
    $passwort = (string)($_POST['password'] ?? '');
    $wiederholung = (string)($_POST['password_repeat'] ?? '');

    if ($konto === null) {
        $errors[] = 'Dieser Verweis gilt nicht mehr. Fordere einen neuen an.';
    } elseif (mb_strlen($passwort) < Users::MIN_PASSWORD_LENGTH) {
        $errors[] = sprintf('Das Passwort muss mindestens %d Zeichen haben.', Users::MIN_PASSWORD_LENGTH);
    } elseif ($passwort !== $wiederholung) {
        $errors[] = 'Die beiden Passwörter stimmen nicht überein.';
    } else {
        // Erst jetzt wird die Marke verbraucht.
        $wer = Users::useToken($marke, 'reset');

        if ($wer === null) {
            $errors[] = 'Dieser Verweis gilt nicht mehr. Fordere einen neuen an.';
        } else {
            Users::setPassword((int)$wer['id'], $passwort, 'ruecksetzung');
            $fertig = true;
        }
    }
}

admin_head('Passwort', $config);
?>
<main style="max-width:26rem;margin:4rem auto;padding:0 1rem">
  <h1>Passwort</h1>

  <div class="card">
    <?php foreach ($errors as $error): ?>
      <div class="msg bad"><?= e($error) ?></div>
    <?php endforeach; ?>
    <?php foreach ($notices as $notice): ?>
      <div class="msg good"><?= e($notice) ?></div>
    <?php endforeach; ?>

    <?php if ($fertig): ?>
      <p>Das Passwort ist gesetzt. Du kannst dich jetzt anmelden.</p>
      <div class="actions"><a href="login.php"><button type="button">Zur Anmeldung</button></a></div>

    <?php elseif ($konto !== null): ?>
      <p class="note">Neues Passwort für <strong><?= e($konto['username']) ?></strong>.</p>
      <form method="post">
        <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
        <input type="hidden" name="action" value="setzen">
        <input type="hidden" name="marke" value="<?= e($marke) ?>">

        <label for="password">Neues Passwort</label>
        <input type="password" id="password" name="password" autocomplete="new-password" autofocus required>

        <label for="password_repeat">Wiederholen</label>
        <input type="password" id="password_repeat" name="password_repeat" autocomplete="new-password" required>
        <p class="note">Mindestens <?= Users::MIN_PASSWORD_LENGTH ?> Zeichen.</p>

        <div class="actions"><button type="submit">Passwort setzen</button></div>
      </form>

    <?php elseif ($marke !== ''): ?>
      <div class="msg bad">Dieser Verweis gilt nicht mehr &ndash; er ist abgelaufen oder
        schon benutzt worden.</div>
      <div class="actions"><a href="passwort.php"><button type="button">Neuen anfordern</button></a></div>

    <?php else: ?>
      <form method="post">
        <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
        <input type="hidden" name="action" value="anfordern">

        <label for="email">Deine Mailadresse</label>
        <input type="text" id="email" name="email" autocomplete="email" autofocus required>

        <div class="actions">
          <button type="submit">Verweis anfordern</button>
          <a href="login.php" class="note">Zurück</a>
        </div>
      </form>
      <p class="note">Nur bestätigte Adressen bekommen einen Verweis. Ist deine noch
         nicht bestätigt, hilft der Webadmin.</p>
    <?php endif; ?>
  </div>
</main>
<?php
admin_foot();
