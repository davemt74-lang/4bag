<?php

declare(strict_types=1);

use FourBag\AccessService;
use FourBag\AuthService;
use FourBag\Database;
use FourBag\LeagueService;
use FourBag\RegistrationService;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LeagueService.php';
require_once __DIR__ . '/../src/RegistrationService.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/AccessService.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function requestSessionToken(): ?string
{
    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $authorization, $matches)) {
        return strtolower($matches[1]);
    }
    $cookie = trim((string)($_COOKIE['fourbag_session'] ?? ''));
    return $cookie !== '' ? $cookie : null;
}

function hasLegacyOperatorKey(): bool
{
    $configured = (string)(getenv('FOURBAG_OPERATOR_KEY') ?: '');
    $provided = (string)($_SERVER['HTTP_X_FOURBAG_OPERATOR_KEY'] ?? '');
    return $configured !== '' && $provided !== '' && hash_equals($configured, $provided);
}

function setAuthCookie(string $token, string $expiresAt): void
{
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    setcookie('fourbag_session', $token, [
        'expires' => strtotime($expiresAt) ?: time() + 1209600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function clearAuthCookie(): void
{
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    setcookie('fourbag_session', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
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

function seasonIdForMatch(PDO $db, int $matchId): int
{
    $stmt = $db->prepare('SELECT season_id FROM matches WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $matchId]);
    $seasonId = $stmt->fetchColumn();
    if ($seasonId === false) {
        throw new RuntimeException('Match not found.');
    }
    return (int)$seasonId;
}

function authorizeOperatorAction(string $action, array $payload, ?array $user, AccessService $access, PDO $db): void
{
    if (hasLegacyOperatorKey()) {
        return;
    }

    switch ($action) {
        case 'venue.create':
        case 'order.board_paid':
        case 'venue.member.assign':
        case 'venue.member.revoke':
            $access->requireAdmin($user);
            return;

        case 'season.create':
            $access->requireVenueManager($user, (int)($payload['venue_id'] ?? 0));
            return;

        case 'venue.members':
            $access->requireVenueManager($user, (int)($_GET['venue_id'] ?? 0));
            return;

        case 'roster':
        case 'operations':
            $access->requireSeasonManager($user, (int)($_GET['season_id'] ?? 0));
            return;

        case 'teams.build':
        case 'schedule.generate':
        case 'championship.create':
            $access->requireSeasonManager($user, (int)($payload['season_id'] ?? 0));
            return;

        case 'score.record':
            $seasonId = seasonIdForMatch($db, (int)($payload['match_id'] ?? 0));
            $access->requireSeasonScorer($user, $seasonId);
            return;
    }
}

try {
    $db = Database::connect();
    $service = new LeagueService($db);
    $registrationService = new RegistrationService($db);
    $authService = new AuthService($db);
    $accessService = new AccessService($db);
    $sessionToken = requestSessionToken();
    $currentUser = $authService->currentUser($sessionToken);

    $action = (string)($_GET['action'] ?? 'dashboard');
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $payload = [];

    if ($method !== 'GET') {
        $payload = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
    }

    $protectedActions = [
        'roster', 'operations', 'venue.create', 'season.create', 'teams.build',
        'schedule.generate', 'score.record', 'championship.create', 'order.board_paid',
        'venue.members', 'venue.member.assign', 'venue.member.revoke',
    ];
    if (in_array($action, $protectedActions, true)) {
        authorizeOperatorAction($action, $payload, $currentUser, $accessService, $db);
    }

    $data = match ($action) {
        'dashboard' => $service->dashboard(),
        'seasons' => $service->listSeasons(),
        'schedule' => $service->seasonSchedule((int)($_GET['season_id'] ?? 0)),
        'standings' => $service->standings((int)($_GET['season_id'] ?? 0)),
        'roster' => $service->seasonRoster((int)($_GET['season_id'] ?? 0)),
        'operations' => $service->seasonOperations((int)($_GET['season_id'] ?? 0)),

        'auth.register' => $method === 'POST'
            ? (function () use ($authService, $payload): array {
                $authService->register(
                    (string)($payload['display_name'] ?? ''),
                    (string)($payload['email'] ?? ''),
                    (string)($payload['password'] ?? '')
                );
                $login = $authService->login((string)($payload['email'] ?? ''), (string)($payload['password'] ?? ''));
                setAuthCookie($login['token'], $login['expires_at']);
                unset($login['token']);
                return $login;
            })()
            : throw new RuntimeException('POST required.'),
        'auth.login' => $method === 'POST'
            ? (function () use ($authService, $payload): array {
                $login = $authService->login((string)($payload['email'] ?? ''), (string)($payload['password'] ?? ''));
                setAuthCookie($login['token'], $login['expires_at']);
                unset($login['token']);
                return $login;
            })()
            : throw new RuntimeException('POST required.'),
        'auth.logout' => $method === 'POST'
            ? (function () use ($authService, $sessionToken): array {
                $authService->logout($sessionToken);
                clearAuthCookie();
                return ['logged_out' => true];
            })()
            : throw new RuntimeException('POST required.'),
        'auth.me' => ['user' => $currentUser],

        'venue.create' => $method === 'POST'
            ? ['id' => $service->createVenue($payload)]
            : throw new RuntimeException('POST required.'),
        'season.create' => $method === 'POST'
            ? ['id' => $service->createSeason($payload)]
            : throw new RuntimeException('POST required.'),
        'venue.members' => $accessService->venueMembers((int)($_GET['venue_id'] ?? 0)),
        'venue.member.assign' => $method === 'POST'
            ? (function () use ($authService, $accessService, $payload): array {
                $user = $authService->userByEmail((string)($payload['email'] ?? ''));
                if (!$user) {
                    throw new RuntimeException('User account not found for that email address.');
                }
                return $accessService->assignVenueRole((int)($payload['venue_id'] ?? 0), (int)$user['id'], (string)($payload['role'] ?? 'scorekeeper'));
            })()
            : throw new RuntimeException('POST required.'),
        'venue.member.revoke' => $method === 'POST'
            ? (function () use ($accessService, $payload): array {
                $accessService->revokeVenueRole((int)($payload['venue_id'] ?? 0), (int)($payload['user_id'] ?? 0));
                return ['revoked' => true];
            })()
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
                isset($currentUser['email']) ? (string)$currentUser['email'] : (isset($payload['recorded_by']) ? (string)$payload['recorded_by'] : null)
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
