<?php

declare(strict_types=1);

use FourBag\Database;
use FourBag\LeagueService;
use FourBag\RegistrationService;
use PDO;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LeagueService.php';
require_once __DIR__ . '/../src/RegistrationService.php';

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

function registerPublicPlayer(RegistrationService $service, PDO $db, array $payload): array
{
    $seasonId = (int)($payload['season_id'] ?? 0);
    $stmt = $db->prepare('SELECT status FROM league_seasons WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $seasonId]);
    $status = $stmt->fetchColumn();
    if ($status === false) {
        throw new RuntimeException('League season not found.');
    }
    if ($status !== 'registration_open') {
        throw new RuntimeException('Public registration is closed for this league.');
    }

    return $service->register($payload);
}

function generateFullLeagueSchedule(LeagueService $service, PDO $db, int $seasonId): array
{
    $season = $db->prepare('SELECT team_limit FROM league_seasons WHERE id=:id LIMIT 1');
    $season->execute(['id' => $seasonId]);
    $teamLimit = $season->fetchColumn();
    if ($teamLimit === false) {
        throw new RuntimeException('League season not found.');
    }

    $teams = $db->prepare('SELECT COUNT(*) FROM teams WHERE season_id=:season_id');
    $teams->execute(['season_id' => $seasonId]);
    if ((int)$teams->fetchColumn() !== (int)$teamLimit) {
        throw new RuntimeException('The standard FourBag schedule requires the full league field before league play starts.');
    }

    return $service->generateRoundRobin($seasonId);
}

try {
    $db = Database::connect();
    $service = new LeagueService($db);
    $registrationService = new RegistrationService($db);
    $action = (string)($_GET['action'] ?? 'dashboard');
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $payload = [];

    if ($method !== 'GET') {
        $payload = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
    }

    $protectedActions = [
        'roster', 'operations', 'venue.create', 'season.create', 'teams.build',
        'schedule.generate', 'score.record', 'championship.create', 'order.board_paid',
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
            ? registerPublicPlayer($registrationService, $db, $payload)
            : throw new RuntimeException('POST required.'),
        'order.board_paid' => $method === 'POST'
            ? $registrationService->completeBoardOrder((int)($payload['order_id'] ?? 0))
            : throw new RuntimeException('POST required.'),
        'teams.build' => $method === 'POST'
            ? $service->buildTeams((int)($payload['season_id'] ?? 0))
            : throw new RuntimeException('POST required.'),
        'schedule.generate' => $method === 'POST'
            ? generateFullLeagueSchedule($service, $db, (int)($payload['season_id'] ?? 0))
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
