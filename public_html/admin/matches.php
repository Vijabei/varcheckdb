<?php
declare(strict_types=1);

/** Spielplan ansehen, einzeln und en bloc korrigieren, als CSV ausgeben. */

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/editor.php';
require_once __DIR__ . '/../lib/access.php';
require_once __DIR__ . '/../lib/import/Adapter.php';
require_once __DIR__ . '/../lib/import/CsvAdapter.php';
require_once __DIR__ . '/../lib/import/FieldSource.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$config = App::boot();
Auth::require();

$timezone = (string)($config['timezone'] ?? 'Europe/Berlin');
$errors = [];
$notices = [];

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$competitions = Repo::competitions();
$competitionId = (int)($_GET['competition'] ?? $_POST['competition'] ?? ($competitions[0]['id'] ?? 0));

// Lesen und Exportieren darf jeder Angemeldete; geaendert wird nur, wo man
// Mitglied ist. Der Export ist ohnehin oeffentlich ueber die Schnittstelle.
$darfAendern = $competitionId > 0
    && Access::mayEditSeason(Auth::userId(), Auth::role(), $competitionId);

$filter = [];
foreach (['round', 'status'] as $key) {
    $value = (string)($_GET[$key] ?? $_POST[$key] ?? '');
    if ($value !== '') {
        $filter[$key] = $value;
    }
}

// ------------------------------------------------------------------- CSV

if (($_GET['export'] ?? '') === 'csv' && $competitionId > 0) {
    $csv = CsvAdapter::export(Repo::matches($competitionId, $filter), $timezone);
    $competition = Db::one('SELECT cs.shortcut, s.start_year FROM competition_seasons cs
                              JOIN seasons s ON s.id = cs.season_id WHERE cs.id = ?', [$competitionId]);

    header('Content-Type: text/csv; charset=utf-8');
    header(sprintf(
        'Content-Disposition: attachment; filename="spielplan-%s-%s.csv"',
        $competition['shortcut'] ?? 'export',
        $competition['start_year'] ?? date('Y')
    ));
    echo $csv;
    exit;
}

// --------------------------------------------------------- Massenaenderung

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::tokenValid()) {
    $selected = array_map('intval', (array)($_POST['auswahl'] ?? []));
    $action = (string)($_POST['action'] ?? '');

    if (!$darfAendern) {
        $errors[] = 'An dieser Liga darfst du nichts ändern. Frag ihren Besitzer, '
            . 'ob er dich als Co-Admin dazunimmt.';
        $action = '';
    } elseif ($selected === [] && $action !== 'einzeln') {
        $errors[] = 'Es ist kein Spiel ausgewaehlt.';
    } else {
        switch ($action) {
            case 'termin':
                $date = trim((string)($_POST['datum'] ?? ''));
                $time = trim((string)($_POST['uhrzeit'] ?? ''));

                if ($date === '' && $time === '') {
                    $errors[] = 'Bitte Datum oder Uhrzeit angeben.';
                    break;
                }
                if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                    $errors[] = 'Das Datum muss die Form 2027-03-14 haben.';
                    break;
                }
                if ($time !== '' && preg_match('/^\d{1,2}:\d{2}$/', $time) !== 1) {
                    $errors[] = 'Die Uhrzeit muss die Form 15:00 haben.';
                    break;
                }

                $n = Editor::setKickoff($selected, $date ?: null, $time ?: null, $timezone, Auth::username());
                $notices[] = sprintf('%d von %d Spielen umgesetzt.', $n, count($selected));
                break;

            case 'verschieben':
                $days = (int)($_POST['tage'] ?? 0);
                if ($days === 0) {
                    $errors[] = 'Bitte eine Zahl von Tagen angeben.';
                    break;
                }
                $n = Editor::shift($selected, $days, $timezone, Auth::username());
                $notices[] = sprintf('%d Spiele um %+d Tage verschoben.', $n, $days);
                break;

            case 'bestaetigen':
                $n = Editor::setConfirmed($selected, true, Auth::username());
                $notices[] = sprintf('%d Termine als verbindlich markiert.', $n);
                break;

            case 'vorlaeufig':
                $n = Editor::setConfirmed($selected, false, Auth::username());
                $notices[] = sprintf('%d Termine als vorlaeufig markiert.', $n);
                break;

            case 'einzeln':
                $matchId = (int)($_POST['match_id'] ?? 0);
                $date = trim((string)($_POST['datum'] ?? ''));
                $time = trim((string)($_POST['uhrzeit'] ?? ''));

                $values = [
                    'home_goals'    => ($_POST['home_goals'] ?? '') === '' ? null : (int)$_POST['home_goals'],
                    'away_goals'    => ($_POST['away_goals'] ?? '') === '' ? null : (int)$_POST['away_goals'],
                    'home_goals_ht' => ($_POST['home_goals_ht'] ?? '') === '' ? null : (int)$_POST['home_goals_ht'],
                    'away_goals_ht' => ($_POST['away_goals_ht'] ?? '') === '' ? null : (int)$_POST['away_goals_ht'],
                    'status'        => (string)($_POST['status'] ?? 'scheduled'),
                    'note'          => ($_POST['note'] ?? '') === '' ? null : (string)$_POST['note'],
                ];

                $changed = Editor::update($matchId, $values, Auth::username());

                if ($date !== '' && $time !== '') {
                    $changed = array_merge($changed, Editor::setKickoff([$matchId], $date, $time, $timezone, Auth::username()) > 0 ? ['kickoff_utc'] : []);
                }

                $notices[] = $changed === []
                    ? 'Nichts geaendert.'
                    : sprintf('%d Feld(er) geaendert: %s.', count($changed), implode(', ', $changed));
                break;
        }
    }
}

$matches = $competitionId > 0 ? Repo::matches($competitionId, $filter) : [];
$rounds = $competitionId > 0
    ? Db::all('SELECT number, name FROM rounds WHERE competition_season_id = ? ORDER BY number', [$competitionId])
    : [];

$edit = (int)($_GET['edit'] ?? 0);
$editRow = null;
foreach ($matches as $row) {
    if ((int)$row['id'] === $edit) {
        $editRow = $row;
        break;
    }
}

/** Geschuetzte Felder eines Spiels, fuer die Kennzeichnung in der Liste. */
function protectedFields(int $matchId): array
{
    return array_column(
        Db::all(
            'SELECT field FROM match_field_sources WHERE match_id = ? AND confirmed = 1',
            [$matchId]
        ),
        'field'
    );
}

function ortszeit(?string $utc, string $timezone, string $format = 'd.m.Y H:i'): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }

    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($timezone))->format($format);
}

admin_head('Spielplan', $config);
admin_nav('matches.php', $config);
?>

<h1>Spielplan</h1>

<?php foreach ($errors as $error): ?>
  <div class="msg bad"><?= e($error) ?></div>
<?php endforeach; ?>
<?php foreach ($notices as $notice): ?>
  <div class="msg good"><?= e($notice) ?></div>
<?php endforeach; ?>

<div class="card">
  <form method="get">
    <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
      <div style="flex:2;min-width:14rem">
        <label for="competition">Wettbewerb</label>
        <select id="competition" name="competition" onchange="this.form.submit()">
          <?php foreach ($competitions as $row): ?>
            <option value="<?= (int)$row['id'] ?>" <?= (int)$row['id'] === $competitionId ? 'selected' : '' ?>>
              <?= e($row['competition_name']) ?> &ndash; <?= e($row['season_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1;min-width:8rem">
        <label for="round">Spieltag</label>
        <select id="round" name="round" onchange="this.form.submit()">
          <option value="">alle</option>
          <?php foreach ($rounds as $row): ?>
            <option value="<?= (int)$row['number'] ?>" <?= (string)($filter['round'] ?? '') === (string)$row['number'] ? 'selected' : '' ?>>
              <?= e($row['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1;min-width:8rem">
        <label for="status">Status</label>
        <select id="status" name="status" onchange="this.form.submit()">
          <option value="">alle</option>
          <?php foreach (['scheduled' => 'angesetzt', 'finished' => 'gespielt',
                          'postponed' => 'verlegt', 'cancelled' => 'abgesagt'] as $key => $label): ?>
            <option value="<?= $key ?>" <?= ($filter['status'] ?? '') === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <a href="?competition=<?= $competitionId ?><?= isset($filter['round']) ? '&round=' . (int)$filter['round'] : '' ?><?= isset($filter['status']) ? '&status=' . e($filter['status']) : '' ?>&export=csv">
          <button type="button" class="ghost">CSV herunterladen</button>
        </a>
      </div>
    </div>
  </form>
  <p class="note" style="margin-top:1rem">
    Für viele Änderungen auf einmal: CSV herunterladen, in der Tabellenkalkulation
    bearbeiten und unter <a href="import.php">Import</a> wieder hochladen. Die Vorschau
    zeigt dann genau, was sich ändert.
  </p>
</div>

<?php if ($editRow !== null && $darfAendern): ?>
  <?php $geschuetzt = protectedFields((int)$editRow['id']); ?>
  <div class="card">
    <h2 style="margin-top:0"><?= e($editRow['home_name']) ?> &ndash; <?= e($editRow['away_name']) ?></h2>
    <p class="note"><?= e($editRow['round_name']) ?></p>

    <form method="post">
      <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
      <input type="hidden" name="action" value="einzeln">
      <input type="hidden" name="match_id" value="<?= (int)$editRow['id'] ?>">
      <input type="hidden" name="competition" value="<?= $competitionId ?>">

      <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <div style="flex:1;min-width:9rem">
          <label for="datum">Datum</label>
          <input type="text" id="datum" name="datum" placeholder="2027-03-14"
                 value="<?= e(ortszeit($editRow['kickoff_utc'], $timezone, 'Y-m-d')) === '—' ? '' : e(ortszeit($editRow['kickoff_utc'], $timezone, 'Y-m-d')) ?>">
        </div>
        <div style="flex:1;min-width:7rem">
          <label for="uhrzeit">Uhrzeit</label>
          <input type="text" id="uhrzeit" name="uhrzeit" placeholder="15:00"
                 value="<?= e(ortszeit($editRow['kickoff_utc'], $timezone, 'H:i')) === '—' ? '' : e(ortszeit($editRow['kickoff_utc'], $timezone, 'H:i')) ?>">
        </div>
        <div style="flex:1;min-width:9rem">
          <label for="status_einzeln">Status</label>
          <select id="status_einzeln" name="status">
            <?php foreach (['scheduled' => 'angesetzt', 'live' => 'läuft', 'finished' => 'gespielt',
                            'postponed' => 'verlegt', 'cancelled' => 'abgesagt'] as $key => $label): ?>
              <option value="<?= $key ?>" <?= $editRow['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <div style="flex:1;min-width:6rem">
          <label for="hg">Tore Heim</label>
          <input type="text" id="hg" name="home_goals" value="<?= e((string)$editRow['home_goals']) ?>">
        </div>
        <div style="flex:1;min-width:6rem">
          <label for="ag">Tore Gast</label>
          <input type="text" id="ag" name="away_goals" value="<?= e((string)$editRow['away_goals']) ?>">
        </div>
        <div style="flex:1;min-width:6rem">
          <label for="hgh">Halbzeit Heim</label>
          <input type="text" id="hgh" name="home_goals_ht" value="<?= e((string)$editRow['home_goals_ht']) ?>">
        </div>
        <div style="flex:1;min-width:6rem">
          <label for="agh">Halbzeit Gast</label>
          <input type="text" id="agh" name="away_goals_ht" value="<?= e((string)$editRow['away_goals_ht']) ?>">
        </div>
      </div>

      <label for="note">Bemerkung</label>
      <input type="text" id="note" name="note" value="<?= e((string)$editRow['note']) ?>">

      <div class="actions">
        <button type="submit">Speichern</button>
        <a href="?competition=<?= $competitionId ?>" class="note">Abbrechen</a>
      </div>
      <p class="note">Was du hier speicherst, gilt als von dir bestätigt und wird von
         späteren Importen nicht überschrieben.
         <?php if ($geschuetzt !== []): ?>
           Bereits geschützt: <code><?= e(implode('</code>, <code>', $geschuetzt)) ?></code>.
         <?php endif; ?>
      </p>
    </form>
  </div>
<?php endif; ?>

<?php if ($matches === []): ?>
  <div class="card empty">Keine Spiele. Unter <a href="import.php">Import</a> eine Datei hochladen.</div>
<?php else: ?>

<?php if (!$darfAendern): ?>
  <div class="msg bad">
    Diese Liga wird von jemand anderem betreut. Du kannst sie ansehen und
    herunterladen, aber nichts ändern. Wer mitarbeiten möchte, fragt den Besitzer
    &ndash; er steht unter <a href="competitions.php">Wettbewerbe</a>.
  </div>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
  <input type="hidden" name="competition" value="<?= $competitionId ?>">
  <?php foreach ($filter as $key => $value): ?>
    <input type="hidden" name="<?= e($key) ?>" value="<?= e((string)$value) ?>">
  <?php endforeach; ?>

  <?php if ($darfAendern): ?>
  <div class="card">
    <h2 style="margin-top:0">Auswahl ändern</h2>
    <p class="note">Wirkt auf alle angehakten Spiele. Jede Änderung gilt als von dir
       bestätigt und wird von Importen nicht überschrieben.</p>

    <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
      <div style="flex:1;min-width:9rem">
        <label for="b_datum">Datum setzen</label>
        <input type="text" id="b_datum" name="datum" placeholder="2027-03-14">
      </div>
      <div style="flex:1;min-width:7rem">
        <label for="b_uhrzeit">Uhrzeit setzen</label>
        <input type="text" id="b_uhrzeit" name="uhrzeit" placeholder="15:00">
      </div>
      <div><button type="submit" name="action" value="termin">Termin setzen</button></div>
      <div style="flex:1;min-width:6rem">
        <label for="b_tage">Tage</label>
        <input type="text" id="b_tage" name="tage" placeholder="7">
      </div>
      <div><button type="submit" name="action" value="verschieben" class="ghost">Verschieben</button></div>
      <div><button type="submit" name="action" value="bestaetigen" class="ghost">Als verbindlich</button></div>
      <div><button type="submit" name="action" value="vorlaeufig" class="ghost">Als vorläufig</button></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th><?php if ($darfAendern): ?><input type="checkbox" id="alle"><?php endif; ?></th>
          <th>ST</th><th>Anstoß</th><th>Heim</th><th>Gast</th>
          <th>Ergebnis</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($matches as $row): ?>
        <?php $geschuetzt = protectedFields((int)$row['id']); ?>
        <tr>
          <td><?php if ($darfAendern): ?><input type="checkbox" name="auswahl[]" value="<?= (int)$row['id'] ?>"><?php endif; ?></td>
          <td><?= (int)$row['round_number'] ?></td>
          <td>
            <?= e(ortszeit($row['kickoff_utc'], $timezone)) ?>
            <?php if ((int)$row['kickoff_is_confirmed'] === 1): ?>
              <span title="verbindlich" style="color:var(--ok)">&#10003;</span>
            <?php endif; ?>
            <?php if (in_array('kickoff_utc', $geschuetzt, true)): ?>
              <span title="von dir bestätigt, gegen Importe geschützt">&#128274;</span>
            <?php endif; ?>
          </td>
          <td><?= e($row['home_name']) ?></td>
          <td><?= e($row['away_name']) ?></td>
          <td>
            <?php if ($row['home_goals'] !== null): ?>
              <?= (int)$row['home_goals'] ?>:<?= (int)$row['away_goals'] ?>
              <?php if ($row['home_goals_ht'] !== null): ?>
                <span class="note">(<?= (int)$row['home_goals_ht'] ?>:<?= (int)$row['away_goals_ht'] ?>)</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="note">—</span>
            <?php endif; ?>
          </td>
          <td><?= e($row['status']) ?></td>
          <td>
            <?php if ($darfAendern): ?>
              <a href="?competition=<?= $competitionId ?>&edit=<?= (int)$row['id'] ?>">ändern</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="note"><?= count($matches) ?> Spiele</p>
  </div>
</form>

<script>
document.getElementById('alle')?.addEventListener('change', function () {
  document.querySelectorAll('input[name="auswahl[]"]').forEach(function (box) {
    box.checked = this.checked;
  }, this);
});
</script>

<?php endif; ?>

<?php
admin_foot();
