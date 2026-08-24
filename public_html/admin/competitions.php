<?php
declare(strict_types=1);

/** Wettbewerbe anlegen und entfernen. */

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/competitions.php';
require_once __DIR__ . '/../lib/access.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$config = App::boot();
Auth::require();

$errors = [];
$notices = [];
$eingabe = [];

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ------------------------------------------------------------------ Anlegen

if (($_POST['action'] ?? '') === 'create' && Auth::tokenValid()) {
    $eingabe = [
        'slug'       => trim((string)($_POST['slug'] ?? '')),
        'shortcut'   => trim((string)($_POST['shortcut'] ?? '')),
        'name'       => trim((string)($_POST['name'] ?? '')),
        'gender'     => (string)($_POST['gender'] ?? ''),
        'age_group'  => trim((string)($_POST['age_group'] ?? '')),
        'region'     => trim((string)($_POST['region'] ?? '')),
        'level'      => trim((string)($_POST['level'] ?? '')),
        'organizer'  => trim((string)($_POST['organizer'] ?? '')),
        'start_year' => (int)($_POST['start_year'] ?? 0),
        'team_count' => (string)($_POST['team_count'] ?? ''),
    ];

    $errors = Competitions::validate($eingabe);

    if ($errors === []) {
        try {
            $csId = Competitions::create($eingabe, Auth::username());

            // Wer anlegt, besitzt. Der Webadmin ohne eigenes Konto - also
            // ueber den Erstzugang - laesst die Liga besitzerlos; er kommt
            // ohnehin ueberall heran.
            $competitionId = Access::competitionOf($csId);
            if ($competitionId !== null && Auth::userId() !== null) {
                Access::makeOwner($competitionId, (int)Auth::userId());
            }

            $notices[] = sprintf(
                'Wettbewerb "%s" angelegt. Du bist sein Besitzer. Als Nächstes eine '
                . 'Importdatei hochladen.',
                $eingabe['name']
            );
            $eingabe = [];
        } catch (Throwable $e) {
            $errors[] = 'Anlegen fehlgeschlagen: ' . $e->getMessage();
        }
    }
}

// ----------------------------------------------------------------- Entfernen

if (($_POST['action'] ?? '') === 'remove' && Auth::tokenValid()) {
    $id = (int)($_POST['competition_season_id'] ?? 0);
    $bestaetigung = trim((string)($_POST['bestaetigung'] ?? ''));

    $ziel = Db::one('SELECT shortcut, name FROM competition_seasons WHERE id = ?', [$id]);

    if ($ziel === null) {
        $errors[] = 'Diesen Wettbewerb gibt es nicht.';
    } elseif (!Access::mayManageSeason(Auth::userId(), Auth::role(), $id)) {
        $errors[] = 'Nur der Besitzer dieser Liga kann sie entfernen.';
    } elseif ($bestaetigung !== $ziel['shortcut']) {
        // Ein Knopf allein reicht hier nicht: es geht um Daten, die sonst
        // nirgends mehr stehen.
        $errors[] = sprintf(
            'Zum Entfernen muss das Kürzel "%s" genau eingetippt werden.',
            $ziel['shortcut']
        );
    } else {
        try {
            $weg = Competitions::remove($id, Auth::username());
            $notices[] = sprintf(
                '"%s" entfernt: %d Spiele, %d Spieltage, %d Importvorgänge.',
                $ziel['name'],
                $weg['matches'] ?? 0,
                $weg['rounds'] ?? 0,
                $weg['import_batches'] ?? 0
            );

            $verwaist = Competitions::orphanedTeams();
            if ($verwaist !== []) {
                $notices[] = sprintf(
                    '%d Mannschaften kommen jetzt in keinem Spiel mehr vor. Sie bleiben '
                    . 'stehen, damit ihre Namenszuordnungen bei einem späteren Import '
                    . 'wieder greifen.',
                    count($verwaist)
                );
            }
        } catch (Throwable $e) {
            $errors[] = 'Entfernen fehlgeschlagen, es wurde nichts geändert: ' . $e->getMessage();
        }
    }
}

if (($_POST['action'] ?? '') === 'grant' && Auth::tokenValid()) {
    $competitionId = (int)($_POST['competition_id'] ?? 0);
    $name = trim((string)($_POST['mitglied'] ?? ''));
    $rolle = (string)($_POST['mitglied_rolle'] ?? Access::COADMIN);

    if (!Access::mayManage(Auth::userId(), Auth::role(), $competitionId)) {
        $errors[] = 'Nur der Besitzer dieser Liga kann Rechte vergeben.';
    } else {
        $wer = Users::byName($name);

        if ($wer === null) {
            $errors[] = sprintf('Den Benutzer "%s" gibt es nicht.', $name);
        } elseif ((int)$wer['active'] !== 1) {
            $errors[] = sprintf('Das Konto "%s" ist abgeschaltet.', $wer['username']);
        } else {
            Access::grant($competitionId, (int)$wer['id'], $rolle, Auth::userId());
            $notices[] = sprintf(
                '"%s" ist jetzt %s dieser Liga.',
                $wer['username'],
                Access::MEMBER_ROLES[$rolle] ?? $rolle
            );
        }
    }
}

if (($_POST['action'] ?? '') === 'revoke' && Auth::tokenValid()) {
    $competitionId = (int)($_POST['competition_id'] ?? 0);
    $userId = (int)($_POST['user_id'] ?? 0);

    if (!Access::mayManage(Auth::userId(), Auth::role(), $competitionId)) {
        $errors[] = 'Nur der Besitzer dieser Liga kann Rechte entziehen.';
    } elseif (!Access::revoke($competitionId, $userId, Auth::userId())) {
        $errors[] = 'Das geht nicht: eine Liga braucht mindestens einen Besitzer.';
    } else {
        $notices[] = 'Die Rechte wurden entzogen.';
    }
}

if (($_POST['action'] ?? '') === 'remove_team' && Auth::tokenValid()) {
    // Mannschaften gehoeren keiner einzelnen Liga; sie zu entfernen ist
    // Aufraeumen am gemeinsamen Bestand.
    if (!Users::can(Auth::role(), 'system.manage')) {
        $errors[] = 'Mannschaften entfernt der Webadmin.';
        $_POST['team'] = [];
    }

    $entfernt = 0;
    foreach ((array)($_POST['team'] ?? []) as $teamId) {
        if (Competitions::removeTeam((int)$teamId, Auth::username())) {
            $entfernt++;
        }
    }
    $notices[] = sprintf('%d Mannschaften entfernt.', $entfernt);
}

$competitions = Repo::competitions();
$verwaist = Competitions::orphanedTeams();
$loeschen = (int)($_GET['loeschen'] ?? 0);

admin_head('Wettbewerbe', $config);
admin_nav('competitions.php', $config);
?>

<h1>Wettbewerbe</h1>

<?php foreach ($errors as $error): ?>
  <div class="msg bad"><?= e($error) ?></div>
<?php endforeach; ?>
<?php foreach ($notices as $notice): ?>
  <div class="msg good"><?= e($notice) ?></div>
<?php endforeach; ?>

<div class="card">
  <h2 style="margin-top:0">Vorhanden</h2>
  <?php if ($competitions === []): ?>
    <p class="empty">Noch kein Wettbewerb angelegt.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Kürzel</th><th>Name</th><th>Saison</th><th>Spiele</th><th>Betreut von</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($competitions as $row): ?>
        <?php
        $haengt = Competitions::dependents((int)$row['id']);
        $competitionId = (int)Access::competitionOf((int)$row['id']);
        $mitglieder = Access::members($competitionId);
        $darfPflegen = Access::mayEdit(Auth::userId(), Auth::role(), $competitionId);
        $darfVerwalten = Access::mayManage(Auth::userId(), Auth::role(), $competitionId);
        ?>
        <tr>
          <td><code><?= e($row['shortcut']) ?></code></td>
          <td><?= e($row['competition_name']) ?></td>
          <td><?= e($row['season_name']) ?></td>
          <td><?= $haengt['matches'] ?></td>
          <td class="note">
            <?php if ($mitglieder === []): ?>
              <span class="empty">niemand</span>
            <?php else: ?>
              <?= e(implode(', ', array_map(
                  static fn(array $m): string => $m['username']
                      . ($m['role'] === Access::OWNER ? '' : ' (Co)'),
                  $mitglieder
              ))) ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($darfPflegen): ?>
              <a href="matches.php?competition=<?= (int)$row['id'] ?>">Spielplan</a>
            <?php endif; ?>
            <?php if ($darfVerwalten): ?>
              &middot; <a href="?rechte=<?= $competitionId ?>">Rechte</a>
              &middot; <a href="?loeschen=<?= (int)$row['id'] ?>">entfernen</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="note">Ligen, bei denen keine Verweise stehen, gehören anderen. Wer
       mitarbeiten möchte, fragt deren Besitzer.</p>
  <?php endif; ?>
</div>

<?php
$ziel = null;
foreach ($competitions as $row) {
    if ((int)$row['id'] === $loeschen) {
        $ziel = $row;
    }
}
?>

<?php if ($ziel !== null): ?>
  <?php $haengt = Competitions::dependents((int)$ziel['id']); ?>
  <div class="card" style="border-color:#f2c4c0">
    <h2 style="margin-top:0"><?= e($ziel['competition_name']) ?> entfernen</h2>

    <p>Damit verschwindet:</p>
    <table>
      <tbody>
        <tr><td>Spiele</td><td><strong><?= $haengt['matches'] ?></strong></td></tr>
        <tr><td>Spieltage</td><td><?= $haengt['rounds'] ?></td></tr>
        <tr><td>Herkunftsvermerke</td><td><?= $haengt['field_sources'] ?></td></tr>
        <tr><td>Importvorgänge</td><td><?= $haengt['import_batches'] ?></td></tr>
      </tbody>
    </table>

    <?php if ($haengt['confirmed'] > 0): ?>
      <div class="msg bad" style="margin-top:1rem">
        <strong><?= $haengt['confirmed'] ?> von Hand bestätigte Angaben</strong> gehen dabei
        verloren. Was du selbst korrigiert hast, steht nach dem Entfernen nirgends mehr
        &ndash; ein erneuter Import bringt es nicht zurück.
      </div>
    <?php endif; ?>

    <p class="note">Mannschaften und ihre Namenszuordnungen bleiben stehen. Das
       Änderungsprotokoll bleibt ebenfalls; das Entfernen wird darin vermerkt.</p>

    <form method="post">
      <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
      <input type="hidden" name="action" value="remove">
      <input type="hidden" name="competition_season_id" value="<?= (int)$ziel['id'] ?>">

      <label for="bestaetigung">Zum Bestätigen <code><?= e($ziel['shortcut']) ?></code> eintippen</label>
      <input type="text" id="bestaetigung" name="bestaetigung" autocomplete="off" autofocus>

      <div class="actions">
        <button type="submit">Endgültig entfernen</button>
        <a href="competitions.php" class="note">Abbrechen</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php
$rechteId = (int)($_GET['rechte'] ?? 0);
$rechteLiga = $rechteId > 0
    ? Db::one('SELECT id, name FROM competitions WHERE id = ?', [$rechteId])
    : null;

if ($rechteLiga !== null && !Access::mayManage(Auth::userId(), Auth::role(), $rechteId)) {
    $rechteLiga = null;
}
?>

<?php if ($rechteLiga !== null): ?>
  <div class="card">
    <h2 style="margin-top:0">Rechte an „<?= e($rechteLiga['name']) ?>"</h2>

    <table>
      <thead><tr><th>Benutzer</th><th>Rolle</th><th>Seit</th><th></th></tr></thead>
      <tbody>
      <?php foreach (Access::members($rechteId) as $m): ?>
        <tr>
          <td>
            <?= e($m['username']) ?>
            <?php if ((int)$m['user_id'] === Auth::userId()): ?><span class="note">(du)</span><?php endif; ?>
            <?php if ((int)$m['active'] !== 1): ?><span class="note">— abgeschaltet</span><?php endif; ?>
          </td>
          <td><?= e(Access::MEMBER_ROLES[$m['role']] ?? $m['role']) ?></td>
          <td class="note"><?= e(substr((string)$m['created_at'], 0, 10)) ?></td>
          <td>
            <?php if (!($m['role'] === Access::OWNER && Access::ownerCount($rechteId) <= 1)): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="competition_id" value="<?= $rechteId ?>">
                <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
                <button type="submit" class="ghost" style="padding:.2rem .6rem;font-size:.85rem">entziehen</button>
              </form>
            <?php else: ?>
              <span class="note">letzter Besitzer</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <form method="post" style="margin-top:1.5rem">
      <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
      <input type="hidden" name="action" value="grant">
      <input type="hidden" name="competition_id" value="<?= $rechteId ?>">

      <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
        <div style="flex:2;min-width:12rem">
          <label for="mitglied">Benutzername</label>
          <input type="text" id="mitglied" name="mitglied" autocomplete="off" required>
        </div>
        <div style="flex:1;min-width:9rem">
          <label for="mitglied_rolle">Rolle</label>
          <select id="mitglied_rolle" name="mitglied_rolle">
            <?php foreach (Access::MEMBER_ROLES as $k => $v): ?>
              <option value="<?= $k ?>" <?= $k === Access::COADMIN ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><button type="submit">Hinzufügen</button></div>
      </div>
      <p class="note">Ein <strong>Co-Admin</strong> pflegt und importiert. Ein
         <strong>Besitzer</strong> kann zusätzlich Rechte vergeben und die Liga entfernen.
         Eine Liga braucht immer mindestens einen Besitzer.</p>
    </form>

    <div class="actions"><a href="competitions.php" class="note">Fertig</a></div>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0">Neu anlegen</h2>
  <p class="note">Wer eine Liga anlegt, betreut sie und kann andere dazunehmen.</p>

  <form method="post">
    <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
    <input type="hidden" name="action" value="create">

    <label for="name">Name</label>
    <input type="text" id="name" name="name" value="<?= e($eingabe['name'] ?? '') ?>"
           placeholder="Frauen-Regionalliga West" required>
    <p class="note">Ohne Saison &ndash; die wird angehängt.</p>

    <div style="display:flex;gap:1rem;flex-wrap:wrap">
      <div style="flex:1;min-width:11rem">
        <label for="shortcut">Kürzel</label>
        <input type="text" id="shortcut" name="shortcut" value="<?= e($eingabe['shortcut'] ?? '') ?>"
               placeholder="frlw" required>
      </div>
      <div style="flex:2;min-width:13rem">
        <label for="slug">Kurzform für Adressen</label>
        <input type="text" id="slug" name="slug" value="<?= e($eingabe['slug'] ?? '') ?>"
               placeholder="frauen-regionalliga-west" required>
      </div>
    </div>
    <p class="note">
      Das <strong>Kürzel</strong> ist der <code>leagueShortcut</code> der
      OpenLigaDB-Ausgabe und damit Teil der öffentlichen Schnittstelle &ndash;
      nachträglich geändert brechen fremde Abfragen. Regel: Geschlechtspräfix,
      dann die Liga, ohne Trennzeichen &ndash; <code>frlw</code>,
      <code>mrlw</code>, <code>fwfl</code>.
    </p>

    <div style="display:flex;gap:1rem;flex-wrap:wrap">
      <div style="flex:1;min-width:8rem">
        <label for="start_year">Saison beginnt</label>
        <input type="text" id="start_year" name="start_year"
               value="<?= e((string)($eingabe['start_year'] ?? date('Y'))) ?>" required>
      </div>
      <div style="flex:1;min-width:8rem">
        <label for="gender">Bereich</label>
        <select id="gender" name="gender">
          <?php foreach (['women' => 'Frauen', 'men' => 'Männer', '' => '—'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($eingabe['gender'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1;min-width:8rem">
        <label for="team_count">Mannschaften</label>
        <input type="text" id="team_count" name="team_count" value="<?= e($eingabe['team_count'] ?? '') ?>"
               placeholder="16">
      </div>
    </div>

    <div style="display:flex;gap:1rem;flex-wrap:wrap">
      <div style="flex:1;min-width:8rem">
        <label for="region">Region</label>
        <input type="text" id="region" name="region" value="<?= e($eingabe['region'] ?? '') ?>" placeholder="West">
      </div>
      <div style="flex:1;min-width:8rem">
        <label for="level">Ebene</label>
        <input type="text" id="level" name="level" value="<?= e($eingabe['level'] ?? '') ?>" placeholder="Regionalliga">
      </div>
      <div style="flex:1;min-width:8rem">
        <label for="organizer">Veranstalter</label>
        <input type="text" id="organizer" name="organizer" value="<?= e($eingabe['organizer'] ?? '') ?>" placeholder="WDFV">
      </div>
    </div>

    <div class="actions"><button type="submit">Anlegen</button></div>
  </form>
</div>

<?php if ($verwaist !== []): ?>
  <div class="card">
    <h2 style="margin-top:0">Mannschaften ohne Spiel</h2>
    <p class="note">Diese <?= count($verwaist) ?> Mannschaften kommen in keinem Spiel vor.
       Sie schaden nicht &ndash; ihre Namenszuordnungen greifen bei einem späteren Import
       wieder. Entfernen lohnt nur beim Aufräumen.</p>

    <form method="post">
      <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
      <input type="hidden" name="action" value="remove_team">
      <table>
        <thead><tr><th></th><th>Name</th></tr></thead>
        <tbody>
        <?php foreach ($verwaist as $team): ?>
          <tr>
            <td><input type="checkbox" name="team[]" value="<?= (int)$team['id'] ?>"></td>
            <td><?= e($team['name']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="actions"><button type="submit" class="ghost">Ausgewählte entfernen</button></div>
    </form>
  </div>
<?php endif; ?>

<?php
admin_foot();
