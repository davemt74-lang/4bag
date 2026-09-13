<?php

declare(strict_types=1);

use FourBag\Database;
use FourBag\LeagueService;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LeagueService.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function requireOperatorKey(): void
{
    $configured = (string)(getenv('FOURBAG_OPERATOR_KEY') ?: '');
    if ($configured === '') {
        throw new RuntimeException('Operator API is not configured. Set FOURBAG_OPERATOR_KEY.');
    }

    $provided = (string)($_SERVER['HTTP_X_FOURBAG_OPERATOR_KEY'] ?? '');
    if ($provided === '' || !hash_equals($configured, $provided)) {
        throw new RuntimeException('Valid FourBag operator key required.');
    }
}

try {
    $service = new LeagueService(Database::connect());
    $action = (string)($_GET['action'] ?? 'dashboard');
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $payload = [];

    if ($method !== 'GET') {
        $payload = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
    }

    $protectedActions = [
        'roster', 'operations', 'venue.create', 'season.create', 'teams.build',
        'schedule.generate', 'score.record', 'championship.create',
    ];
    if (in_array($action, $protectedActions, true)) {
        requireOperatorKey();
    }

    $data = match ($action) {
        'dashboard' => $service->dashboard(),
        'seasons' => $service->listSeasons(),
        'schedule' => $service->seasonSchedule((int)($_GET['season_id'] ?? 0)),
        'standings' => $service->standings((int)($_GET['season_id'] ?? 0)),
        'roster' => $service->seasonRoster((int)($_GET['season_id'] ?? 0)),
        'operations' => $service->seasonOperations((int)($_GET['season_id'] ?? 0)),
        'venue.create' => $method === 'POST'
            ? ['id' => $service->createVenue($payload)]
            : throw new RuntimeException('POST required.'),
        'season.create' => $method === 'POST'
            ? ['id' => $service->createSeason($payload)]
            : throw new RuntimeException('POST required.'),
        'player.register' => $method === 'POST'
            ? ['player_id' => $service->registerPlayer($payload)]
            : throw new RuntimeException('POST required.'),
        'teams.build' => $method === 'POST'
            ? $service->buildTeams((int)($payload['season_id'] ?? 0))
            : throw new RuntimeException('POST required.'),
        'schedule.generate' => $method === 'POST'
            ? $service->generateRoundRobin((int)($payload['season_id'] ?? 0))
            : throw new RuntimeException('POST required.'),
        'score.record' => $method === 'POST'
            ? $service->recordScore(
                (int)($payload['match_id'] ?? 0),
                (int)($payload['home_score'] ?? -1),
                (int)($payload['away_score'] ?? -1),
                (string)($payload['status'] ?? 'final'),
                isset($payload['recorded_by']) ? (string)$payload['recorded_by'] : null
            )
            : throw new RuntimeException('POST required.'),
        'championship.create' => $method === 'POST'
            ? $service->createChampionship((int)($payload['season_id'] ?? 0))
            : throw new RuntimeException('POST required.'),
        default => throw new RuntimeException('Unknown API action.'),
    };

    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
