<?php

declare(strict_types=1);

$required = [
    __DIR__ . '/../src/Database.php',
    __DIR__ . '/../src/LeagueService.php',
    __DIR__ . '/../database/migrations/001_initial_schema.sql',
    __DIR__ . '/../public/index.php',
    __DIR__ . '/../public/api.php',
];

foreach ($required as $file) {
    if (!is_file($file) || filesize($file) === 0) {
        fwrite(STDERR, "Missing required file: {$file}\n");
        exit(1);
    }
}

$sql = file_get_contents(__DIR__ . '/../database/migrations/001_initial_schema.sql');
foreach (['venues','league_seasons','players','registrations','teams','matches','equipment_kits','sponsors','broadcasts','orders'] as $table) {
    if (!preg_match('/CREATE TABLE\\s+' . preg_quote($table, '/') . '\\b/i', $sql)) {
        fwrite(STDERR, "Migration missing table {$table}\n");
        exit(1);
    }
}

echo "FourBag smoke checks passed.\n";
