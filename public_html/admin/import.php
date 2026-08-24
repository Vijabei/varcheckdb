<?php
declare(strict_types=1);

/**
 * Import in drei Stufen: hochladen, Mannschaften zuordnen, Vorschau bestaetigen.
 *
 * Der Vorgang liegt zwischen den Stufen in der Datenbank, nicht in der
 * Sitzung. Ein abgebrochener Import laesst sich dadurch fortsetzen.
 */

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/import/Adapter.php';
require_once __DIR__ . '/../lib/import/AdapterFactory.php';
require_once __DIR__ . '/../lib/import/KickerJsonAdapter.php';
require_once __DIR__ . '/../lib/import/CsvAdapter.php';
require_once __DIR__ . '/../lib/import/WorldfootballHtmlAdapter.php';
require_once __DIR__ . '/../lib/import/TeamMatcher.php';
require_once __DIR__ . '/../lib/import/FieldSource.php';
require_once __DIR__ . '/../lib/import/Differ.php';
require_once __DIR__ . '/../lib/import/Applier.php';
require_once __DIR__ . '/../lib/import/Batch.php';
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

/** Anstoss lesbar machen. */
function moment(?string $utc, string $timezone): string
{
    if ($utc === null || $utc === '') {
        return 'ohne Termin';
    }

    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($timezone))
        ->format('d.m.Y H:i');
}

$batchId = (int)($_GET['batch'] ?? $_POST['batch'] ?? 0);
$batch = $batchId > 0 ? Batch::find($batchId) : null;

if ($batch !== null && $batch['status'] !== 'pending') {
    $notices[] = 'Dieser Vorgang ist bereits abgeschlossen.';
    $batch = null;
    $batchId = 0;
}

// ------------------------------------------------------------------ Hochladen

if (($_POST['action'] ?? '') === 'upload' && Auth::tokenValid()) {
    $file = $_FILES['datei'] ?? null;
    $competitionSeasonId = (int)($_POST['competition'] ?? 0);

    if ($competitionSeasonId <= 0) {
        $errors[] = 'Bitte einen Wettbewerb auswaehlen.';
    } elseif ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'Die Datei ist groesser als der Server erlaubt (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_NO_FILE => 'Es wurde keine Datei ausgewaehlt.',
            UPLOAD_ERR_PARTIAL => 'Die Datei wurde nur teilweise uebertragen.',
            default            => 'Der Upload ist fehlgeschlagen.',
        };
    } else {
        $content = (string)file_get_contents($file['tmp_name']);
        $detected = AdapterFactory::detect($content, (string)$file['name']);

        if ($detected['adapter'] === null) {
            $errors[] = $detected['reason'];
        } else {
            try {
                $parsed = $detected['adapter']->parse($content);

                $sourceId = (int)Db::value(
                    'SELECT id FROM sources WHERE slug = ?',
                    [$detected['adapter']->sourceSlug()]
                );

                $batchId = Batch::create(
                    $sourceId,
                    $competitionSeasonId,
                    $detected['adapter']->sourceSlug(),
                    (string)$file['name'],
                    $parsed['rows']
                );

                $notices[] = sprintf(
                    '%d Spiele aus %s eingelesen (%s).',
                    count($parsed['rows']),
                    $detected['name'],
                    Encoding::describe($content)
                );
                foreach ($parsed['notices'] as $notice) {
                    $notices[] = $notice;
                }

                $batch = Batch::find($batchId);
            } catch (ImportException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------- Mannschaften zuordnen

if (($_POST['action'] ?? '') === 'map' && Auth::tokenValid() && $batch !== null) {
    $matcher = new TeamMatcher();
    $mapped = 0;
    $created = 0;

    foreach ((array)($_POST['zuordnung'] ?? []) as $name => $choice) {
        $name = (string)$name;
        $choice = (string)$choice;

        if ($choice === '' || $matcher->resolve($name) !== null) {
            continue;
        }

        if ($choice === 'neu') {
            $matcher->createTeam($name);
            $created++;
            continue;
        }

        $matcher->addAlias((int)$choice, $name, (int)$batch['source_id']);
        $mapped++;
    }

    if ($mapped > 0 || $created > 0) {
        $notices[] = sprintf('%d Namen zugeordnet, %d Mannschaften neu angelegt.', $mapped, $created);
    }
}

// -------------------------------------------------------------- Uebernehmen

if (($_POST['action'] ?? '') === 'apply' && Auth::tokenValid() && $batch !== null) {
    $matcher = new TeamMatcher();
    $rows = Batch::rows($batchId);

    $source = Db::one('SELECT id, priority FROM sources WHERE id = ?', [$batch['source_id']]);
    $csId = (int)$batch['competition_season_id'];

    $diff = (new Differ($csId, (int)$source['priority'], $timezone))->compare($rows, $matcher);

    // Auswahl bei Konflikten einsammeln.
    $decisions = [];
    foreach ((array)($_POST['konflikt'] ?? []) as $lineNo => $index) {
        // Index 0 ist die Vorauswahl des Adapters. Sie stehenzulassen ist
        // keine Entscheidung des Admins und wird deshalb nicht als solche
        // vermerkt - sonst waere jeder Termin nach dem ersten Import
        // gegen kuenftige Korrekturen der Quelle gesperrt.
        if ($index === '' || (int)$index === 0) {
            continue;
        }
        foreach ($diff['rows'] as $row) {
            if ((int)$row['line_no'] === (int)$lineNo && isset($row['alternatives'][(int)$index])) {
                $decisions[(int)$lineNo] = [
                    'alternative' => $row['alternatives'][(int)$index],
                    'confirm'     => true,
                ];
            }
        }
    }

    try {
        $result = (new Applier($csId, (int)$source['id'], $timezone))
            ->apply($diff['rows'], $matcher, $decisions, (string)$batch['filename']);

        Batch::markApplied($batchId);
        Batch::cleanup();

        $notices[] = sprintf(
            '%d Spiele angelegt, %d aktualisiert, %d uebersprungen.',
            $result['created'],
            $result['updated'],
            $result['skipped']
        );

        $batch = null;
        $batchId = 0;
    } catch (Throwable $e) {
        $errors[] = 'Die Uebernahme ist fehlgeschlagen, es wurde nichts geaendert: ' . $e->getMessage();
    }
}

if (($_POST['action'] ?? '') === 'discard' && Auth::tokenValid() && $batch !== null) {
    Batch::discard($batchId);
    $notices[] = 'Der Vorgang wurde verworfen.';
    $batch = null;
    $batchId = 0;
}

// ------------------------------------------------------------------ Anzeige

$competitions = Repo::competitions();
$pending = Batch::pending();

$unresolved = [];
$diff = null;
$teams = [];

if ($batch !== null) {
    $matcher = new TeamMatcher();
    $rows = Batch::rows($batchId);
    $unresolved = $matcher->unresolved($rows);

    if ($unresolved === []) {
        $source = Db::one('SELECT priority FROM sources WHERE id = ?', [$batch['source_id']]);
        $diff = (new Differ(
            (int)$batch['competition_season_id'],
            (int)$source['priority'],
            $timezone
        ))->compare($rows, $matcher);
    } else {
        $teams = Db::all('SELECT id, name FROM teams ORDER BY name');
    }
}

admin_head('Import', $config);
admin_nav('import.php', $config);
?>

<h1>Import</h1>

<?php foreach ($errors as $error): ?>
  <div class="msg bad"><?= e($error) ?></div>
<?php endforeach; ?>
<?php foreach ($notices as $notice): ?>
  <div class="msg good"><?= e($notice) ?></div>
<?php endforeach; ?>

<?php if ($batch === null): ?>

  <?php if ($pending !== []): ?>
    <div class="card">
      <h2 style="margin-top:0">Offene Vorgänge</h2>
      <table>
        <thead><tr><th>Datei</th><th>Quelle</th><th>Wettbewerb</th><th>Zeilen</th><th>Angelegt</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $row): ?>
          <tr>
            <td><?= e($row['filename']) ?></td>
            <td><?= e($row['source_name']) ?></td>
            <td><?= e($row['competition_name']) ?></td>
            <td><?= (int)$row['row_count'] ?></td>
            <td><?= e((string)$row['created_at']) ?></td>
            <td><a href="import.php?batch=<?= (int)$row['id'] ?>">fortsetzen</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2 style="margin-top:0">Datei hochladen</h2>

    <?php if ($competitions === []): ?>
      <p class="empty">Es ist kein Wettbewerb angelegt.</p>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
        <input type="hidden" name="action" value="upload">

        <label for="competition">Wettbewerb</label>
        <select id="competition" name="competition">
          <?php foreach ($competitions as $row): ?>
            <option value="<?= (int)$row['id'] ?>">
              <?= e($row['competition_name']) ?> &ndash; <?= e($row['season_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label for="datei">Datei</label>
        <input type="file" id="datei" name="datei" accept=".json,.html,.htm,.txt,.csv" required>
        <p class="note">
          Erkannt werden JSON im Format <code>varcheckdb-import/1</code>, CSV und
          gespeicherte HTML-Spielpläne. Das Format wird am Inhalt erkannt, nicht an
          der Dateiendung. Höchstens <?= e((string)ini_get('upload_max_filesize')) ?>.
        </p>

        <div class="actions"><button type="submit">Einlesen</button></div>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Woher die Datei kommt</h2>
    <p>Der Server ruft von sich aus nichts ab. Die Datei wird auf deinem eigenen
       Rechner erzeugt und hier hochgeladen.</p>
    <p class="note">Aufbau der beiden Formate: <code>docs/import.md</code>. Für
       laufende Korrekturen ist der Weg über den
       <a href="matches.php">Spielplan</a> meist schneller: CSV herunterladen,
       in der Tabellenkalkulation ändern, hier wieder hochladen.</p>
  </div>

<?php elseif ($unresolved !== []): ?>

  <div class="card">
    <h2 style="margin-top:0">Mannschaften zuordnen</h2>
    <p>Diese Namen aus <code><?= e($batch['filename']) ?></code> sind noch keiner
       Mannschaft zugeordnet. Die Zuordnung wird gespeichert und gilt ab dem nächsten
       Import automatisch.</p>

    <form method="post">
      <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
      <input type="hidden" name="action" value="map">
      <input type="hidden" name="batch" value="<?= $batchId ?>">

      <table>
        <thead><tr><th>Name in der Datei</th><th>Spiele</th><th>Zuordnen zu</th></tr></thead>
        <tbody>
        <?php foreach ($unresolved as $entry): ?>
          <tr>
            <td><strong><?= e($entry['name']) ?></strong></td>
            <td><?= (int)$entry['count'] ?></td>
            <td>
              <select name="zuordnung[<?= e($entry['name']) ?>]">
                <option value="neu">— neu anlegen —</option>
                <?php foreach ($entry['suggestions'] as $index => $suggestion): ?>
                  <option value="<?= (int)$suggestion['team_id'] ?>" <?= $index === 0 ? 'selected' : '' ?>>
                    <?= e($suggestion['name']) ?> (<?= number_format($suggestion['score'] * 100, 0) ?> %)
                  </option>
                <?php endforeach; ?>
                <?php if ($entry['suggestions'] !== []): ?><option disabled>──────────</option><?php endif; ?>
                <?php foreach ($teams as $team): ?>
                  <option value="<?= (int)$team['id'] ?>"><?= e($team['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="actions">
        <button type="submit">Zuordnen und weiter</button>
      </div>
      <p class="note">Vorgeschlagen wird der beste Treffer. Reserve- und erste Mannschaften
         (etwa „SGS Essen II“ und „SGS Essen“) werden bewusst nie automatisch zusammengeführt.</p>
    </form>
  </div>

<?php else: ?>

  <?php
  $summary = $diff['summary'];
  $abweichend = array_values(array_filter($diff['rows'], fn(array $r): bool => $r['alternatives'] !== []));
  $changed = array_values(array_filter($diff['rows'], fn(array $r): bool => in_array($r['action'], ['create', 'update'], true)));
  $protected = array_values(array_filter($diff['rows'], fn(array $r): bool => ($r['protected'] ?? []) !== []));
  ?>

  <form method="post">
    <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
    <input type="hidden" name="batch" value="<?= $batchId ?>">

    <div class="card">
      <h2 style="margin-top:0">Vorschau</h2>
      <p class="note"><code><?= e($batch['filename']) ?></code> &middot;
         <?= (int)$batch['row_count'] ?> Zeilen</p>
      <div class="stats">
        <div class="stat"><b><?= $summary['create'] ?></b><span>neu</span></div>
        <div class="stat"><b><?= $summary['update'] ?></b><span>geändert</span></div>
        <div class="stat"><b><?= $summary['unchanged'] ?></b><span>unverändert</span></div>
        <div class="stat"><b><?= $summary['skip'] ?></b><span>übersprungen</span></div>
        <div class="stat"><b><?= $summary['ambiguous'] ?></b><span>Termin abweichend</span></div>
      </div>
    </div>

    <?php if ($abweichend !== []): ?>
      <div class="card">
        <h2 style="margin-top:0">Abweichende Terminangaben</h2>
        <p>Für diese Paarungen führt die Quelle zwei Datensätze &ndash; die ursprüngliche und
           die geänderte Ansetzung. Übernommen wird der neuere; er ist unten vorausgewählt.
           Wählst du die andere Angabe, gilt sie als von dir bestätigt und wird von späteren
           Importen nicht mehr überschrieben.</p>
        <table>
          <thead><tr><th>ST</th><th>Paarung</th><th>Termin</th><th>Art</th></tr></thead>
          <tbody>
          <?php foreach ($abweichend as $row): ?>
            <?php
            $daten = array_unique(array_column($row['alternatives'], 'kickoff_date'));
            $art = count($daten) > 1 ? 'verlegt' : 'nur Uhrzeit';
            ?>
            <tr>
              <td><?= (int)$row['round'] ?></td>
              <td><?= e($row['home']) ?> &ndash; <?= e($row['away']) ?></td>
              <td>
                <?php foreach ($row['alternatives'] as $index => $alt): ?>
                  <label class="inline" style="font-weight:400">
                    <input type="radio" name="konflikt[<?= (int)$row['line_no'] ?>]"
                           value="<?= $index ?>" <?= $index === 0 ? 'checked' : '' ?>>
                    <?= e($alt['kickoff_date']) ?> <?= e($alt['kickoff_time']) ?>
                    <?php if ($index === 0): ?><span class="note">(neuer)</span><?php endif; ?>
                  </label>
                <?php endforeach; ?>
              </td>
              <td class="note"><?= $art ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="note">Ein verlegtes Spiel behält seinen Spieltag &ndash; auch wenn es
           vom Ostersamstag in den Mai rutscht.</p>
      </div>
    <?php endif; ?>

    <?php if ($protected !== []): ?>
      <div class="card">
        <h2 style="margin-top:0">Geschützt, bleibt unverändert</h2>
        <p class="note">Diese Felder hast du selbst bestätigt. Die Quelle sagt etwas anderes,
           setzt sich aber nicht durch.</p>
        <table>
          <thead><tr><th>Paarung</th><th>Feld</th><th>Bestand</th><th>Quelle sagt</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($protected, 0, 30) as $row): ?>
            <?php foreach ($row['protected'] as $field => $change): ?>
              <tr>
                <td><?= e($row['home']) ?> &ndash; <?= e($row['away']) ?></td>
                <td><code><?= e($field) ?></code></td>
                <td><?= e((string)$change['from']) ?></td>
                <td><?= e((string)$change['to']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($changed !== []): ?>
      <div class="card">
        <h2 style="margin-top:0">Änderungen</h2>
        <table>
          <thead><tr><th>ST</th><th>Paarung</th><th>Was</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($changed, 0, 60) as $row): ?>
            <tr>
              <td><?= (int)$row['round'] ?></td>
              <td><?= e($row['home']) ?> &ndash; <?= e($row['away']) ?></td>
              <td>
                <?php if ($row['action'] === 'create'): ?>
                  <span class="note">neu:</span>
                  <?= e(moment($row['changes']['kickoff_utc'] ?? null, $timezone)) ?>
                  <?php if (isset($row['changes']['home_goals'])): ?>
                    &middot; <?= (int)$row['changes']['home_goals'] ?>:<?= (int)$row['changes']['away_goals'] ?>
                  <?php endif; ?>
                <?php else: ?>
                  <?php foreach ($row['changes'] as $field => $change): ?>
                    <code><?= e($field) ?></code>
                    <?= e($change['from'] === null ? '—' : (string)$change['from']) ?>
                    &rarr; <?= e((string)$change['to']) ?><br>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (count($changed) > 60): ?>
          <p class="note">… und <?= count($changed) - 60 ?> weitere.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="actions">
        <button type="submit" name="action" value="apply">Übernehmen</button>
        <button type="submit" name="action" value="discard" class="ghost">Verwerfen</button>
        <span class="note">Bis hierher wurde nichts geändert.</span>
      </div>
    </div>
  </form>

<?php endif; ?>

<?php
admin_foot();
