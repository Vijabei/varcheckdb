<?php
declare(strict_types=1);

/** Benutzer anlegen, ändern, entfernen. */

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/users.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$config = App::boot();
Auth::requireCapability('users.manage');

$errors = [];
$notices = [];
$eingabe = [];

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (($_POST['action'] ?? '') === 'create' && Auth::tokenValid()) {
    $eingabe = [
        'username'        => trim((string)($_POST['username'] ?? '')),
        'email'           => trim((string)($_POST['email'] ?? '')),
        'password'        => (string)($_POST['password'] ?? ''),
        'password_repeat' => (string)($_POST['password_repeat'] ?? ''),
        'role'            => (string)($_POST['role'] ?? Users::ROLE_USER),
        'active'          => 1,
    ];

    $errors = Users::validate($eingabe);

    if ($errors === []) {
        Users::create($eingabe, Auth::username());
        $notices[] = sprintf('Benutzer "%s" angelegt.', $eingabe['username']);
        $eingabe = [];
    }
}

if (($_POST['action'] ?? '') === 'update' && Auth::tokenValid()) {
    $id = (int)($_POST['user_id'] ?? 0);
    $user = Users::find($id);

    if ($user === null) {
        $errors[] = 'Diesen Benutzer gibt es nicht.';
    } else {
        $neu = [
            'username'        => $user['username'],
            'email'           => trim((string)($_POST['email'] ?? '')),
            'role'            => (string)($_POST['role'] ?? $user['role']),
            'active'          => isset($_POST['active']) ? 1 : 0,
            'password'        => (string)($_POST['password'] ?? ''),
            'password_repeat' => (string)($_POST['password_repeat'] ?? ''),
        ];

        $errors = Users::validate($neu, $id);

        // Der letzte Verwalter darf sich nicht selbst die Grundlage entziehen.
        $verliert = $neu['role'] !== Users::ROLE_ADMIN || $neu['active'] === 0;
        if ($errors === [] && $verliert && Users::isLastAdmin($id)) {
            $errors[] = sprintf(
                '"%s" ist der einzige aktive Zugang zur Verwaltung. Erst einen zweiten '
                . 'anlegen, sonst kommt niemand mehr an die Benutzerverwaltung.',
                $user['username']
            );
        }

        if ($errors === []) {
            $geaendert = Users::update($id, $neu, Auth::username());
            $notices[] = $geaendert === []
                ? 'Nichts geändert.'
                : sprintf('"%s": %s geändert.', $user['username'], implode(', ', $geaendert));
        }
    }
}

if (($_POST['action'] ?? '') === 'remove' && Auth::tokenValid()) {
    $id = (int)($_POST['user_id'] ?? 0);
    $user = Users::find($id);

    if ($user === null) {
        $errors[] = 'Diesen Benutzer gibt es nicht.';
    } elseif ($id === Auth::userId()) {
        $errors[] = 'Den eigenen Zugang kann man nicht entfernen.';
    } elseif (!Users::remove($id, Auth::username())) {
        $errors[] = sprintf(
            '"%s" ist der einzige aktive Zugang zur Verwaltung und bleibt bestehen.',
            $user['username']
        );
    } else {
        $notices[] = sprintf('Benutzer "%s" entfernt.', $user['username']);
    }
}

$benutzer = Users::all();
$aendern = (int)($_GET['aendern'] ?? 0);

admin_head('Benutzer', $config);
admin_nav('users.php', $config, 'admin');
?>

<h1>Benutzer</h1>

<?php foreach ($errors as $error): ?>
  <div class="msg bad"><?= e($error) ?></div>
<?php endforeach; ?>
<?php foreach ($notices as $notice): ?>
  <div class="msg good"><?= e($notice) ?></div>
<?php endforeach; ?>

<div class="card">
  <h2 style="margin-top:0">Vorhanden</h2>
  <?php if ($benutzer === []): ?>
    <p class="empty">Noch kein Benutzer angelegt.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Name</th><th>Mailadresse</th><th>Rolle</th><th>Zustand</th><th>Zuletzt angemeldet</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($benutzer as $u): ?>
        <tr>
          <td>
            <?= e($u['username']) ?>
            <?php if ((int)$u['id'] === Auth::userId()): ?><span class="note">(du)</span><?php endif; ?>
          </td>
          <td class="note">
            <?= e($u['email'] ?? '—') ?>
            <?php if (($u['email'] ?? null) !== null && !Users::isVerified($u)): ?>
              <span title="noch nicht bestätigt" class="warn">&#9888;</span>
            <?php endif; ?>
          </td>
          <td><?= e(Users::ROLES[$u['role']] ?? $u['role']) ?></td>
          <td>
            <?php if ((int)$u['active'] === 1): ?>
              <span style="color:var(--ok)">aktiv</span>
            <?php else: ?>
              <span class="note">abgeschaltet</span>
            <?php endif; ?>
            <?php if (Users::isLastAdmin((int)$u['id'])): ?>
              <span class="note" title="einziger aktiver Zugang zur Verwaltung">&#128274;</span>
            <?php endif; ?>
          </td>
          <td class="note"><?= e($u['last_login_at'] ?? 'noch nie') ?></td>
          <td><a href="?aendern=<?= (int)$u['id'] ?>">ändern</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php
$ziel = null;
foreach ($benutzer as $u) {
    if ((int)$u['id'] === $aendern) {
        $ziel = $u;
    }
}
?>

<?php if ($ziel !== null): ?>
  <div class="card">
    <h2 style="margin-top:0"><?= e($ziel['username']) ?> ändern</h2>

    <form method="post">
      <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="user_id" value="<?= (int)$ziel['id'] ?>">

      <label for="email_edit">Mailadresse</label>
      <input type="text" id="email_edit" name="email" value="<?= e($ziel['email'] ?? '') ?>"
             autocomplete="off" required>
      <p class="note">
        <?php if (Users::isVerified($ziel)): ?>
          Bestätigt am <?= e(substr((string)$ziel['email_verified_at'], 0, 10)) ?>.
          Eine Änderung setzt das zurück.
        <?php else: ?>
          Noch nicht bestätigt &ndash; ein Passwort-Reset ist damit nicht möglich.
        <?php endif; ?>
      </p>

      <label for="role_edit">Rolle</label>
      <select id="role_edit" name="role">
        <?php foreach (Users::ROLES as $k => $v): ?>
          <option value="<?= $k ?>" <?= $ziel['role'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>

      <label class="inline" style="margin-top:1rem">
        <input type="checkbox" name="active" value="1" <?= (int)$ziel['active'] === 1 ? 'checked' : '' ?>>
        aktiv
      </label>
      <p class="note">Ein abgeschaltetes Konto kommt nicht herein, auch mit richtigem Passwort.
         Laufende Sitzungen enden beim nächsten Seitenaufruf.</p>

      <label for="password_edit">Neues Passwort</label>
      <input type="password" id="password_edit" name="password" autocomplete="new-password">
      <label for="password_repeat_edit">Wiederholen</label>
      <input type="password" id="password_repeat_edit" name="password_repeat" autocomplete="new-password">
      <p class="note">Leer lassen, um das Passwort nicht zu ändern.</p>

      <div class="actions">
        <button type="submit">Speichern</button>
        <a href="users.php" class="note">Abbrechen</a>
      </div>
    </form>

    <?php if ((int)$ziel['id'] !== Auth::userId() && !Users::isLastAdmin((int)$ziel['id'])): ?>
      <form method="post" style="margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--line)">
        <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
        <input type="hidden" name="action" value="remove">
        <input type="hidden" name="user_id" value="<?= (int)$ziel['id'] ?>">
        <div class="actions">
          <button type="submit" class="ghost">Benutzer entfernen</button>
          <span class="note">Was diese Person geändert hat, bleibt im Protokoll stehen.</span>
        </div>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0">Neu anlegen</h2>

  <form method="post">
    <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
    <input type="hidden" name="action" value="create">

    <div style="display:flex;gap:1rem;flex-wrap:wrap">
      <div style="flex:2;min-width:12rem">
        <label for="username">Benutzername</label>
        <input type="text" id="username" name="username" value="<?= e($eingabe['username'] ?? '') ?>"
               autocomplete="off" required>
      </div>
      <div style="flex:1;min-width:10rem">
        <label for="role">Rolle</label>
        <select id="role" name="role">
          <?php foreach (Users::ROLES as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($eingabe['role'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <label for="email">Mailadresse</label>
    <input type="text" id="email" name="email" value="<?= e($eingabe['email'] ?? '') ?>"
           autocomplete="off" required>
    <p class="note">Für das Zurücksetzen des Passworts. Sie gilt zunächst als
       unbestätigt; der Benutzer bestätigt sie über den Verweis in der Mail.</p>

    <div style="display:flex;gap:1rem;flex-wrap:wrap">
      <div style="flex:1;min-width:12rem">
        <label for="password">Passwort</label>
        <input type="password" id="password" name="password" autocomplete="new-password" required>
      </div>
      <div style="flex:1;min-width:12rem">
        <label for="password_repeat">Wiederholen</label>
        <input type="password" id="password_repeat" name="password_repeat" autocomplete="new-password" required>
      </div>
    </div>
    <p class="note">Mindestens <?= Users::MIN_PASSWORD_LENGTH ?> Zeichen. Es wird nur als Hash gespeichert.</p>

    <div class="actions"><button type="submit">Anlegen</button></div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Was die Rollen dürfen</h2>
  <table>
    <thead><tr><th></th><th>Webadmin</th><th>Mitmachen</th></tr></thead>
    <tbody>
      <tr><td>Lesen und exportieren</td><td>&#10003;</td><td>&#10003;</td></tr>
      <tr><td>Eigene Ligen anlegen</td><td>&#10003;</td><td>&#10003;</td></tr>
      <tr><td>Eigene Ligen pflegen und importieren</td><td>&#10003;</td><td>&#10003;</td></tr>
      <tr><td>Co-Admins für eigene Ligen benennen</td><td>&#10003;</td><td>&#10003;</td></tr>
      <tr><td>An <em>fremden</em> Ligen arbeiten</td><td>&#10003;</td><td>&ndash;</td></tr>
      <tr><td>Benutzer verwalten</td><td>&#10003;</td><td>&ndash;</td></tr>
      <tr><td>Mannschaften aufräumen</td><td>&#10003;</td><td>&ndash;</td></tr>
    </tbody>
  </table>
  <p class="note">Wer eine Liga anlegt, wird ihr Besitzer und entscheidet, wer daran
     mitarbeitet. Deshalb ist die offene Anmeldung unbedenklich: ein neues Konto kann
     an bestehenden Ligen nichts ändern.</p>
</div>

<?php
admin_foot();
