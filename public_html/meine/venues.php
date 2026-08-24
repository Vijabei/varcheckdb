<?php
declare(strict_types=1);

/** Spielorte pflegen. */

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/normalize.php';
require_once __DIR__ . '/../lib/venues.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$config = App::boot();
Auth::require();

$errors = [];
$notices = [];

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$eingabe = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::tokenValid()) {
    switch ((string)($_POST['action'] ?? '')) {
        case 'create':
            $eingabe = [
                'name'     => trim((string)($_POST['name'] ?? '')),
                'city'     => trim((string)($_POST['city'] ?? '')),
                'capacity' => trim((string)($_POST['capacity'] ?? '')),
            ];
            $errors = Venues::validate($eingabe);

            if ($errors === []) {
                Venues::create($eingabe, Auth::username());
                $notices[] = sprintf('"%s" angelegt.', $eingabe['name']);
                $eingabe = [];
            }
            break;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $werte = [
                'name'     => trim((string)($_POST['name'] ?? '')),
                'city'     => trim((string)($_POST['city'] ?? '')),
                'capacity' => trim((string)($_POST['capacity'] ?? '')),
            ];
            $errors = Venues::validate($werte, $id);

            if ($errors === []) {
                $changed = Venues::update($id, $werte, Auth::username());
                $notices[] = $changed === []
                    ? 'Nichts geändert.'
                    : sprintf('Geändert: %s.', implode(', ', $changed));
            }
            break;

        case 'remove':
            $id = (int)($_POST['id'] ?? 0);
            $ort = Venues::find($id);

            if ($ort === null) {
                $errors[] = 'Diesen Spielort gibt es nicht.';
            } elseif (!Venues::remove($id, Auth::username())) {
                $errors[] = sprintf(
                    '"%s" wird noch an %d Spiel(en) verwendet und bleibt deshalb stehen.',
                    $ort['name'],
                    Venues::inUse($id)
                );
            } else {
                $notices[] = sprintf('"%s" entfernt.', $ort['name']);
            }
            break;

        case 'bulk':
            // Eine Liste, wie sie aus einer Tabelle kopiert wird. Trennzeichen
            // ist Tabulator oder Semikolon - beides kommt aus dem Kopieren
            // ganzer Spalten heraus.
            $zeilen = preg_split('/\R/', (string)($_POST['liste'] ?? '')) ?: [];
            $angelegt = 0;
            $bekannt = 0;

            foreach ($zeilen as $nummer => $zeile) {
                if (trim($zeile) === '') {
                    continue;
                }

                $teile = array_map('trim', preg_split('/[\t;]/', $zeile) ?: []);
                $name = $teile[0] ?? '';

                // Zwei Spalten heissen Name und Fassungsvermoegen, drei
                // schieben die Stadt dazwischen.
                if (count($teile) >= 3) {
                    $satz = ['name' => $name, 'city' => $teile[1], 'capacity' => $teile[2]];
                } else {
                    $satz = ['name' => $name, 'city' => '', 'capacity' => $teile[1] ?? ''];
                }

                if ($name === '') {
                    continue;
                }

                if (Venues::byName($name, $satz['city'] ?: null) !== null) {
                    $bekannt++;
                    continue;
                }

                $fehler = Venues::validate($satz);

                if ($fehler !== []) {
                    $errors[] = sprintf('Zeile %d (%s): %s', $nummer + 1, $name, implode(' ', $fehler));
                    continue;
                }

                Venues::create($satz, Auth::username());
                $angelegt++;
            }

            if ($angelegt > 0) {
                $notices[] = sprintf('%d Spielort(e) angelegt.', $angelegt);
            }
            if ($bekannt > 0) {
                $notices[] = sprintf('%d waren schon vorhanden und blieben unverändert.', $bekannt);
            }
            if ($angelegt === 0 && $bekannt === 0 && $errors === []) {
                $errors[] = 'In der Liste stand nichts Verwertbares.';
            }
            break;
    }
}

$spielorte = Venues::all();
$bearbeiten = (int)($_GET['edit'] ?? 0);
$editRow = $bearbeiten > 0 ? Venues::find($bearbeiten) : null;

admin_head('Spielorte', $config);
admin_nav('venues.php', $config);
?>

<h1>Spielorte</h1>

<?php foreach ($errors as $error): ?>
  <div class="msg bad"><?= e($error) ?></div>
<?php endforeach; ?>
<?php foreach ($notices as $notice): ?>
  <div class="msg good"><?= e($notice) ?></div>
<?php endforeach; ?>

<div class="card">
  <p>Ein Spielort gehört <strong>nicht</strong> zu einem Verein, sondern wird am
     einzelnen Spiel angegeben &ndash; sonst ließen sich ausgerechnet die
     interessanten Fälle nicht abbilden: Ausweichplatz, Heimspiel beim Gegner,
     Endspiel auf neutralem Boden.</p>
  <p class="note">Das Fassungsvermögen ist optional. Es dient dem Eintragen:
     Wer die Zuschauerzahl erfasst, sieht daneben, was der Platz hergibt.</p>
</div>

<?php if ($editRow !== null): ?>
  <div class="card">
    <h2 style="margin-top:0"><?= e($editRow['name']) ?> ändern</h2>
    <form method="post">
      <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
      <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <div style="flex:2;min-width:14rem">
          <label for="e_name">Name</label>
          <input type="text" id="e_name" name="name" value="<?= e($editRow['name']) ?>" required>
        </div>
        <div style="flex:1;min-width:8rem">
          <label for="e_city">Stadt</label>
          <input type="text" id="e_city" name="city" value="<?= e($editRow['city']) ?>">
        </div>
        <div style="flex:1;min-width:8rem">
          <label for="e_capacity">Fassungsvermögen</label>
          <input type="text" id="e_capacity" name="capacity" inputmode="numeric"
                 value="<?= e((string)$editRow['capacity']) ?>">
        </div>
      </div>
      <div class="actions">
        <button type="submit">Speichern</button>
        <a href="venues.php" class="note">Abbrechen</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0">Vorhanden</h2>
  <?php if ($spielorte === []): ?>
    <p class="empty">Noch kein Spielort angelegt.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Name</th><th>Stadt</th><th>Fasst</th><th>Spiele</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($spielorte as $ort): ?>
        <tr>
          <td><?= e($ort['name']) ?></td>
          <td><?= $ort['city'] === null ? '<span class="empty">—</span>' : e($ort['city']) ?></td>
          <td><?= $ort['capacity'] === null
                ? '<span class="empty">—</span>'
                : number_format((int)$ort['capacity'], 0, ',', '.') ?></td>
          <td><?= (int)$ort['match_count'] ?></td>
          <td>
            <a href="?edit=<?= (int)$ort['id'] ?>">ändern</a>
            <?php if ((int)$ort['match_count'] === 0): ?>
              &middot;
              <form method="post" style="display:inline" onsubmit="return confirm('<?= e($ort['name']) ?> entfernen?')">
                <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="id" value="<?= (int)$ort['id'] ?>">
                <button type="submit" class="ghost" style="padding:.1rem .5rem;font-size:.85rem">entfernen</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0">Anlegen</h2>
  <form method="post">
    <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
    <input type="hidden" name="action" value="create">
    <div style="display:flex;gap:1rem;flex-wrap:wrap">
      <div style="flex:2;min-width:14rem">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?= e($eingabe['name'] ?? '') ?>"
               placeholder="Stadion Rote Erde" required>
      </div>
      <div style="flex:1;min-width:8rem">
        <label for="city">Stadt</label>
        <input type="text" id="city" name="city" value="<?= e($eingabe['city'] ?? '') ?>"
               placeholder="Dortmund">
      </div>
      <div style="flex:1;min-width:8rem">
        <label for="capacity">Fassungsvermögen</label>
        <input type="text" id="capacity" name="capacity" inputmode="numeric"
               value="<?= e($eingabe['capacity'] ?? '') ?>" placeholder="9500">
      </div>
    </div>
    <div class="actions"><button type="submit">Anlegen</button></div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Mehrere auf einmal</h2>
  <p class="note">Eine Zeile je Spielort, direkt aus einer Tabelle kopiert.
     Zwei Spalten sind <code>Name</code> und <code>Fassungsvermögen</code>, drei
     schieben die <code>Stadt</code> dazwischen. Was es schon gibt, bleibt
     unverändert &ndash; die Liste kann also gefahrlos zweimal hinein.</p>
  <form method="post">
    <input type="hidden" name="token" value="<?= e(Auth::token()) ?>">
    <input type="hidden" name="action" value="bulk">
    <label for="liste">Liste</label>
    <textarea id="liste" name="liste" rows="8" style="width:100%;padding:.55rem .65rem;
              border:1px solid var(--line);border-radius:.3rem;font:inherit"
              placeholder="Stadion Rote Erde&#9;9500&#10;Tönnies-Arena&#9;3562"></textarea>
    <div class="actions"><button type="submit">Übernehmen</button></div>
  </form>
</div>

<?php
admin_foot();
