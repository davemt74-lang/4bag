<?php

declare(strict_types=1);

use FourBag\Database;

require_once __DIR__ . '/../src/Database.php';

$db = Database::connect();
$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    $check = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration');
    $check->execute(['migration' => $name]);
    if ((int)$check->fetchColumn() > 0) {
        echo "skip {$name}\n";
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Unable to read {$name}");
    }

    // MySQL/MariaDB DDL statements implicitly commit, so migrations are applied
    // statement-by-statement and are only recorded after every statement succeeds.
    $statements = preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $db->exec($statement);
    }

    $insert = $db->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (:migration, NOW())');
    $insert->execute(['migration' => $name]);
    echo "applied {$name}\n";
}
