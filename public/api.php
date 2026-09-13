<?php

declare(strict_types=1);

use FourBag\Database;
use FourBag\LeagueService;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LeagueService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $service = new LeagueService(Database::connect());
    $action = $_GET['action'] ?? 'dashboard';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $payload = [];

    if ($method !== 'GET') {
        $payload = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
    }

    $data = match ($action) {
        'dashboard' => $service->dashboard(),
        'seasons' => $service->listSeasons(),
        'roster' => $service->seasonRoster((int)($_GET['season_id'] ?? 0)),
        'venue.create' => $method === 'POST' ? ['id' => $service->createVenue($payload)] : throw new RuntimeException('POST required.'),
        'season.create' => $method === 'POST' ? ['id' => $service->createSeason($payload)] : throw new RuntimeException('POST required.'),
        'player.register' => $method === 'POST' ? ['player_id' => $service->registerPlayer($payload)] : throw new RuntimeException('POST required.'),
        default => throw new RuntimeException('Unknown API action.'),
    };

    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
