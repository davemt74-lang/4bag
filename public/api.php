<?php

declare(strict_types=1);

use FourBag\AccessService;
use FourBag\AdminService;
use FourBag\AuthService;
use FourBag\BillingService;
use FourBag\Database;
use FourBag\LeagueService;
use FourBag\PaymentService;
use FourBag\PlayerService;
use FourBag\RegistrationService;
use FourBag\StripePaymentProvider;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LeagueService.php';
require_once __DIR__ . '/../src/RegistrationService.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/AccessService.php';
require_once __DIR__ . '/../src/AdminService.php';
require_once __DIR__ . '/../src/BillingService.php';
require_once __DIR__ . '/../src/PlayerService.php';
require_once __DIR__ . '/../src/PaymentProviderInterface.php';
require_once __DIR__ . '/../src/StripePaymentProvider.php';
require_once __DIR__ . '/../src/PaymentService.php';

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

function manualPaymentCompletionEnabled(): bool
{
    return filter_var((string)(getenv('FOURBAG_ALLOW_MANUAL_PAYMENT_COMPLETION') ?: '0'), FILTER_VALIDATE_BOOLEAN);
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

function requireAuthenticatedUser(?array $user): array
{
    if (!$user) {
        throw new RuntimeException('Sign in to access your FourBag player account.');
    }
    return $user;
}

function registerPublicPlayer(
    RegistrationService $service,
    PlayerService $playerService,
    PDO $db,
    array $payload,
    ?array $currentUser
): array {
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

    $result = $service->register($payload);
    if ($currentUser !== null
        && strtolower(trim((string)($payload['email'] ?? ''))) === strtolower(trim((string)($currentUser['email'] ?? '')))
    ) {
        $result['profile_link'] = $playerService->linkFromRegistration($currentUser, (int)$result['player_id']);
    }
    return $result;
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

function seasonOperationsForActor(LeagueService $service, AccessService $access, int $seasonId, ?array $user): array
{
    $operations = $service->seasonOperations($seasonId);
    if ($user !== null && !$access->canManageSeason($user, $seasonId)) {
        $operations['roster'] = [];
        $operations['board_buyers'] = null;
        $operations['limited_access'] = true;
    } else {
        $operations['limited_access'] = false;
    }
    return $operations;
}

function completeBoardOrderManually(RegistrationService $registrationService, int $orderId): array
{
    if (!manualPaymentCompletionEnabled()) {
        throw new RuntimeException('Manual payment completion is disabled. Verified payment webhooks are required.');
    }
    return $registrationService->completeBoardOrder($orderId);
}

function authorizeOperatorAction(
    string $action,
    array $payload,
    ?array $user,
    AccessService $access,
    BillingService $billing,
    PDO $db
): void {
    if (hasLegacyOperatorKey() && AccessService::allowsLegacyOperatorKey($action)) {
        return;
    }

    switch ($action) {
        case 'venue.create':
        case 'order.board_paid':
        case 'venue.member.assign':
        case 'venue.member.revoke':
        case 'admin.venues':
        case 'admin.host_fee.create':
        case 'admin.players.unlinked':
        case 'admin.player.link':
            $access->requireAdmin($user);
            return;

        case 'season.create':
            $access->requireVenueManager($user, (int)($payload['venue_id'] ?? 0));
            return;

        case 'venue.members':
        case 'venue.invoices':
            $access->requireVenueManager($user, (int)($_GET['venue_id'] ?? 0));
            return;

        case 'invoice.checkout':
            $venueId = $billing->venueIdForInvoice((int)($payload['invoice_id'] ?? 0));
            $access->requireVenueManager($user, $venueId);
            return;

        case 'roster':
            $access->requireSeasonManager($user, (int)($_GET['season_id'] ?? 0));
            return;

        case 'operations':
            $access->requireSeasonScorer($user, (int)($_GET['season_id'] ?? 0));
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
    $adminService = new AdminService($db);
    $billingService = new BillingService($db);
    $playerService = new PlayerService($db);
    $paymentProvider = StripePaymentProvider::fromEnvironment();
    $paymentService = new PaymentService(
        $db,
        $paymentProvider,
        $registrationService,
        PaymentService::baseUrlFromEnvironment()
    );
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
        'venue.members', 'venue.member.assign', 'venue.member.revoke', 'admin.venues',
        'admin.host_fee.create', 'venue.invoices', 'invoice.checkout',
        'admin.players.unlinked', 'admin.player.link',
    ];
    if (in_array($action, $protectedActions, true)) {
        authorizeOperatorAction($action, $payload, $currentUser, $accessService, $billingService, $db);
    }

    $data = match ($action) {
        'dashboard' => $service->dashboard(),
        'seasons' => $service->listSeasons(),
        'schedule' => $service->seasonSchedule((int)($_GET['season_id'] ?? 0)),
        'standings' => $service->standings((int)($_GET['season_id'] ?? 0)),
        'roster' => $service->seasonRoster((int)($_GET['season_id'] ?? 0)),
        'operations' => seasonOperationsForActor($service, $accessService, (int)($_GET['season_id'] ?? 0), $currentUser),

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

        'player.profile' => $playerService->profileForUser(requireAuthenticatedUser($currentUser)),

        'admin.venues' => $adminService->venues(),
        'admin.players.unlinked' => $playerService->unlinkedPlayers(),
        'admin.player.link' => $method === 'POST'
            ? $playerService->adminLinkByEmail(
                (string)($payload['player_email'] ?? ''),
                (string)($payload['user_email'] ?? '')
            )
            : throw new RuntimeException('POST required.'),
        'admin.host_fee.create' => $method === 'POST'
            ? $billingService->createHostFeeInvoice(
                (int)($payload['season_id'] ?? 0),
                (int)($payload['amount_cents'] ?? 0),
                isset($payload['due_date']) && trim((string)$payload['due_date']) !== '' ? (string)$payload['due_date'] : null,
                isset($payload['description']) ? (string)$payload['description'] : null,
                isset($currentUser['id']) ? (int)$currentUser['id'] : null
            )
            : throw new RuntimeException('POST required.'),
        'venue.create' => $method === 'POST'
            ? ['id' => $service->createVenue($payload)]
            : throw new RuntimeException('POST required.'),
        'season.create' => $method === 'POST'
            ? ['id' => $service->createSeason($payload)]
            : throw new RuntimeException('POST required.'),
        'venue.members' => $accessService->venueMembers((int)($_GET['venue_id'] ?? 0)),
        'venue.invoices' => $billingService->venueInvoices((int)($_GET['venue_id'] ?? 0)),
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
            ? registerPublicPlayer($registrationService, $playerService, $db, $payload, $currentUser)
            : throw new RuntimeException('POST required.'),
        'checkout.create' => $method === 'POST'
            ? $paymentService->createCheckout(
                (int)($payload['order_id'] ?? 0),
                isset($payload['checkout_token']) ? (string)$payload['checkout_token'] : null,
                false
            )
            : throw new RuntimeException('POST required.'),
        'invoice.checkout' => $method === 'POST'
            ? $paymentService->createCheckout($billingService->orderIdForInvoice((int)($payload['invoice_id'] ?? 0)), null, true)
            : throw new RuntimeException('POST required.'),
        'order.board_paid' => $method === 'POST'
            ? completeBoardOrderManually($registrationService, (int)($payload['order_id'] ?? 0))
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
