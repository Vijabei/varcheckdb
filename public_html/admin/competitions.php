<?php
declare(strict_types=1);

/** Wettbewerbe anlegen und entfernen. */

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/competitions.php';
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
            Competitions::create($eingabe);
            $notices[] = sprintf(
                'Wettbewerb "%s" angelegt. Als Nächstes eine Importdatei hochladen.',
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
    } elseif ($bestaetigung !== $ziel['shortcut']) {
        // Ein Knopf allein reicht hier nicht: es geht um Daten, die sonst
        // nirgends mehr stehen.
        $errors[] = sprintf(
            'Zum Entfernen muss das Kürzel "%s" genau eingetippt werden.',
            $ziel['shortcut']
        );
    } else {
        try {
            $weg = Competitions::remove($id);
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

if (($_POST['action'] ?? '') === 'remove_team' && Auth::tokenValid()) {
    $entfernt = 0;
    foreach ((array)($_POST['team'] ?? []) as $teamId) {
        if (Competitions::removeTeam((int)$teamId)) {
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
      <thead><tr><th>Kürzel</th><th>Name</th><th>Saison</th><th>Spiele</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($competitions as $row): ?>
        <?php $haengt = Competitions::dependents((int)$row['id']); ?>
        <tr>
          <td><code><?= e($row['shortcut']) ?></code></td>
          <td><?= e($row['competition_name']) ?></td>
          <td><?= e($row['season_name']) ?></td>
          <td><?= $haengt['matches'] ?></td>
          <td>
            <a href="matches.php?competition=<?= (int)$row['id'] ?>">Spielplan</a> &middot;
            <a href="?loeschen=<?= (int)$row['id'] ?>">entfernen</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
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

<div class="card">
  <h2 style="margin-top:0">Neu anlegen</h2>

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
