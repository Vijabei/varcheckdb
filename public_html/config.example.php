<?php
/**
 * Vorlage. Als config.php kopieren und ausfuellen.
 * config.php gehoert NICHT ins Repo (steht in .gitignore).
 */

return [
    // Zugangsdaten aus der Hetzner-Konsole
    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=DATENBANK;charset=utf8mb4',
        'user'     => 'BENUTZER',
        'password' => 'PASSWORT',
    ],

    // Erzeugen mit:
    //   php -r 'echo password_hash("dein-passwort", PASSWORD_DEFAULT), "\n";'
    'admin_password_hash' => '$2y$10$AUSTAUSCHEN',

    // Zeitzone fuer die Anzeige und fuer matchDateTime in der OpenLigaDB-Ausgabe
    'timezone' => 'Europe/Berlin',

    // Quellenhinweis, der in den API-Antworten mitgeliefert wird
    'attribution' => 'Daten gepflegt von vijabei.net',

    // true zeigt PHP-Fehler im Browser. Auf dem Produktivsystem: false.
    'debug' => false,
];
