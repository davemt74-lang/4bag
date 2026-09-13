<?php

declare(strict_types=1);

$required = [
    __DIR__ . '/../src/Database.php',
    __DIR__ . '/../src/LeagueService.php',
    __DIR__ . '/../database/migrations/001_initial_schema.sql',
    __DIR__ . '/../database/migrations/002_league_operations.sql',
    __DIR__ . '/../public/index.php',
    __DIR__ . '/../public/api.php',
    __DIR__ . '/../public/operator.php',
    __DIR__ . '/integration.php',
];

foreach ($required as $file) {
    if (!is_file($file) || filesize($file) === 0) {
        fwrite(STDERR, "Missing required file: {$file}\n");
        exit(1);
    }
}

$sql = file_get_contents(__DIR__ . '/../database/migrations/001_initial_schema.sql');
foreach (['venues','league_seasons','players','registrations','teams','matches','equipment_kits','sponsors','broadcasts','orders'] as $table) {
    if (!preg_match('/CREATE TABLE\s+' . preg_quote($table, '/') . '\b/i', $sql)) {
        fwrite(STDERR, "Migration missing table {$table}\n");
        exit(1);
    }
}

$opsSql = file_get_contents(__DIR__ . '/../database/migrations/002_league_operations.sql');
foreach (['board_count', 'stage', 'sequence_no', 'match_score_events'] as $needle) {
    if ($opsSql === false || !str_contains($opsSql, $needle)) {
        fwrite(STDERR, "Operations migration missing {$needle}\n");
        exit(1);
    }
}

$service = file_get_contents(__DIR__ . '/../src/LeagueService.php') ?: '';
foreach (['buildTeams', 'generateRoundRobin', 'standings', 'recordScore', 'createChampionship', 'seasonOperations'] as $method) {
    if (!str_contains($service, 'function ' . $method)) {
        fwrite(STDERR, "LeagueService missing {$method}\n");
        exit(1);
    }
}

$api = file_get_contents(__DIR__ . '/../public/api.php') ?: '';
foreach (['FOURBAG_OPERATOR_KEY', 'teams.build', 'schedule.generate', 'score.record', 'championship.create'] as $needle) {
    if (!str_contains($api, $needle)) {
        fwrite(STDERR, "API contract missing {$needle}\n");
        exit(1);
    }
}

echo "FourBag smoke checks passed.\n";
