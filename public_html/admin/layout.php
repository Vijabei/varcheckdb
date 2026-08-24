<?php
declare(strict_types=1);

/** Gemeinsamer Rahmen aller Adminseiten. */

require_once __DIR__ . '/../lib/users.php';

function admin_head(string $title, array $config): void
{
    $name = htmlspecialchars((string)($config['site_name'] ?? 'Spieldaten'), ENT_QUOTES, 'UTF-8');
    $safe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    ?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $safe ?> &ndash; <?= $name ?></title>
<style>
:root { --bg:#f4f5f7; --card:#fff; --ink:#1b1d21; --muted:#6b7280; --line:#dcdfe4;
        --accent:#14532d; --ok:#1a7f47; --warn:#9a6700; --bad:#b3261e; }
* { box-sizing:border-box; }
body { margin:0; background:var(--bg); color:var(--ink);
       font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
header { background:var(--accent); color:#fff; padding:.85rem 1rem; }
header .inner { max-width:60rem; margin:0 auto; display:flex; gap:1.25rem; align-items:center; flex-wrap:wrap; }
header a { color:#fff; text-decoration:none; opacity:.85; font-size:.92rem; }
header a:hover, header a.on { opacity:1; text-decoration:underline; }
header .name { font-weight:700; margin-right:auto; }
main { max-width:60rem; margin:0 auto; padding:1.5rem 1rem 3rem; }
h1 { font-size:1.35rem; margin:0 0 1rem; }
h2 { font-size:1.05rem; margin:1.75rem 0 .6rem; }
.card { background:var(--card); border:1px solid var(--line); border-radius:.5rem; padding:1.25rem; margin-bottom:1rem; }
.stats { display:flex; flex-wrap:wrap; gap:1.75rem; }
.stat b { display:block; font-size:1.5rem; } .stat span { color:var(--muted); font-size:.85rem; }
table { width:100%; border-collapse:collapse; font-size:.92rem; }
th,td { text-align:left; padding:.45rem .6rem; border-bottom:1px solid var(--line); }
th { color:var(--muted); font-size:.78rem; text-transform:uppercase; letter-spacing:.03em; }
label { display:block; margin:1rem 0 .3rem; font-weight:600; font-size:.9rem; }
input[type=text],input[type=password],select { width:100%; padding:.55rem .65rem;
  border:1px solid var(--line); border-radius:.3rem; font:inherit; }
button { background:var(--accent); color:#fff; border:0; border-radius:.35rem;
  padding:.55rem 1.2rem; font:inherit; font-weight:600; cursor:pointer; }
button.ghost { background:#e7e9ec; color:var(--ink); }
.actions { margin-top:1.25rem; display:flex; gap:.75rem; align-items:center; }
.msg { padding:.75rem 1rem; border-radius:.35rem; margin-bottom:1rem; font-size:.92rem; }
.msg.bad { background:#fdeceb; border:1px solid #f2c4c0; color:var(--bad); }
.msg.good { background:#e8f4ec; border:1px solid #bcdcc8; color:var(--ok); }
.note { color:var(--muted); font-size:.85rem; }
code { background:#eef0f2; padding:.1rem .35rem; border-radius:.2rem; font-size:.88em; }
a { color:var(--accent); }
.empty { color:var(--muted); }
</style>
</head>
<body>
<?php
}

function admin_nav(string $current, array $config): void
{
    // Nur zeigen, was die Rolle auch darf - ein Verweis, der zu
    // "nicht erlaubt" fuehrt, ist eine Zumutung.
    $items = ['index.php' => 'Übersicht'];

    if (Auth::can('matches.edit')) {
        $items['matches.php'] = 'Spielplan';
    }
    if (Auth::can('import.csv')) {
        $items['import.php'] = 'Import';
    }
    if (Auth::can('competitions.manage')) {
        $items['competitions.php'] = 'Wettbewerbe';
    }
    if (Auth::can('users.manage')) {
        $items['users.php'] = 'Benutzer';
    }
    ?>
<header><div class="inner">
  <span class="name"><?= htmlspecialchars((string)($config['site_name'] ?? 'Spieldaten'), ENT_QUOTES, 'UTF-8') ?></span>
  <?php foreach ($items as $file => $label): ?>
    <a href="<?= $file ?>" class="<?= $file === $current ? 'on' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
  <a href="../">Öffentliche Seite</a>
  <a href="index.php?logout=1" title="<?= htmlspecialchars(Auth::username() . ' — ' . (Users::ROLES[Auth::role()] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars(Auth::username(), ENT_QUOTES, 'UTF-8') ?> abmelden
  </a>
</div></header>
<main>
<?php
}

function admin_foot(): void
{
    echo "</main>\n</body>\n</html>\n";
}
