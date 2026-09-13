<?php

declare(strict_types=1);

$required = [
    __DIR__ . '/../src/Database.php',
    __DIR__ . '/../src/LeagueService.php',
    __DIR__ . '/../src/RegistrationService.php',
    __DIR__ . '/../src/AuthService.php',
    __DIR__ . '/../src/AccessService.php',
    __DIR__ . '/../src/AdminService.php',
    __DIR__ . '/../database/migrations/001_initial_schema.sql',
    __DIR__ . '/../database/migrations/002_league_operations.sql',
    __DIR__ . '/../database/migrations/003_accounts_access_control.sql',
    __DIR__ . '/../public/index.php',
    __DIR__ . '/../public/api.php',
    __DIR__ . '/../public/operator.php',
    __DIR__ . '/../public/admin.php',
    __DIR__ . '/integration.php',
    __DIR__ . '/auth-integration.php',
    __DIR__ . '/create-admin.php',
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
foreach (['board_count', 'stage', 'sequence_no', 'idx_match_slot', 'match_score_events', 'awaiting_board_payment', 'registration_credit_order_id'] as $needle) {
    if ($opsSql === false || !str_contains($opsSql, $needle)) {
        fwrite(STDERR, "Operations migration missing {$needle}\n");
        exit(1);
    }
}

$authSql = file_get_contents(__DIR__ . '/../database/migrations/003_accounts_access_control.sql');
foreach (['CREATE TABLE users', 'CREATE TABLE auth_sessions', 'CREATE TABLE venue_user_roles', 'system_role', 'scorekeeper', 'user_id'] as $needle) {
    if ($authSql === false || !str_contains($authSql, $needle)) {
        fwrite(STDERR, "Accounts migration missing {$needle}\n");
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

$registrationService = file_get_contents(__DIR__ . '/../src/RegistrationService.php') ?: '';
foreach (['function register', 'function completeBoardOrder', 'awaiting_board_payment', 'included_with_board', 'cannot be converted', 'orderIsPaid'] as $needle) {
    if (!str_contains($registrationService, $needle)) {
        fwrite(STDERR, "RegistrationService contract missing {$needle}\n");
        exit(1);
    }
}

$authService = file_get_contents(__DIR__ . '/../src/AuthService.php') ?: '';
foreach (['password_hash', 'password_verify', 'random_bytes', 'token_hash', 'function currentUser', 'function logout'] as $needle) {
    if (!str_contains($authService, $needle)) {
        fwrite(STDERR, "AuthService contract missing {$needle}\n");
        exit(1);
    }
}

$accessService = file_get_contents(__DIR__ . '/../src/AccessService.php') ?: '';
foreach (['assignVenueRole', 'requireAdmin', 'requireVenueManager', 'requireSeasonManager', 'requireSeasonScorer', 'allowsLegacyOperatorKey', 'LEGACY_OPERATOR_ACTIONS', "['owner', 'manager']"] as $needle) {
    if (!str_contains($accessService, $needle)) {
        fwrite(STDERR, "AccessService contract missing {$needle}\n");
        exit(1);
    }
}

$adminService = file_get_contents(__DIR__ . '/../src/AdminService.php') ?: '';
foreach (['class AdminService', 'function venues', 'member_count', 'season_count'] as $needle) {
    if (!str_contains($adminService, $needle)) {
        fwrite(STDERR, "AdminService contract missing {$needle}\n");
        exit(1);
    }
}

$api = file_get_contents(__DIR__ . '/../public/api.php') ?: '';
foreach (['FOURBAG_OPERATOR_KEY', 'auth.register', 'auth.login', 'auth.logout', 'auth.me', 'authorizeOperatorAction', 'AccessService::allowsLegacyOperatorKey', 'admin.venues', 'venue.members', 'venue.member.assign', 'venue.member.revoke', 'samesite', 'registerPublicPlayer', 'generateFullLeagueSchedule', 'teams.build', 'schedule.generate', 'score.record', 'championship.create', 'order.board_paid'] as $needle) {
    if (!str_contains($api, $needle)) {
        fwrite(STDERR, "API contract missing {$needle}\n");
        exit(1);
    }
}
if (str_contains($api, '->registerPlayer(')) {
    fwrite(STDERR, "Public API must use RegistrationService, not the legacy LeagueService registration path.\n");
    exit(1);
}

$operator = file_get_contents(__DIR__ . '/../public/operator.php') ?: '';
foreach (['auth.login', 'auth.logout', 'auth.me', 'credentials:\'same-origin\'', 'Legacy operator-key fallback'] as $needle) {
    if (!str_contains($operator, $needle)) {
        fwrite(STDERR, "Operator authentication UI contract missing {$needle}\n");
        exit(1);
    }
}

$admin = file_get_contents(__DIR__ . '/../public/admin.php') ?: '';
foreach (['admin.venues', 'venue.members', 'venue.member.assign', 'venue.member.revoke', 'auth.login', 'auth.logout', 'credentials:\'same-origin\''] as $needle) {
    if (!str_contains($admin, $needle)) {
        fwrite(STDERR, "Network administration UI contract missing {$needle}\n");
        exit(1);
    }
}

$index = file_get_contents(__DIR__ . '/../public/index.php') ?: '';
foreach (['escapeHtml', 'awaiting_board_payment', 'registration becomes included after the board order is paid'] as $needle) {
    if (!str_contains($index, $needle)) {
        fwrite(STDERR, "Public registration UI contract missing {$needle}\n");
        exit(1);
    }
}

echo "FourBag smoke checks passed.\n";
