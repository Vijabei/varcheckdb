<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$only = $argv[1] ?? null;
$files = glob(__DIR__ . '/test_*.php') ?: [];
sort($files);

echo "\nvijabei Spieldatenbank - Tests\n";

foreach ($files as $file) {
    if ($only !== null && !str_contains(basename($file), $only)) {
        continue;
    }
    require $file;
}

echo "\n" . str_repeat('-', 60) . "\n";

if (T::$failures === []) {
    printf("\033[32m%d Pruefungen bestanden.\033[0m\n\n", T::$passed);
    exit(0);
}

printf("\033[31m%d von %d Pruefungen fehlgeschlagen:\033[0m\n", count(T::$failures), T::$passed + count(T::$failures));
foreach (T::$failures as $failure) {
    echo '  - ' . $failure . "\n";
}
echo "\n";
exit(1);
