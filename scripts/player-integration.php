<?php

declare(strict_types=1);

use FourBag\AuthService;
use FourBag\Database;
use FourBag\LeagueService;
use FourBag\PlayerService;
use FourBag\RegistrationService;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/LeagueService.php';
require_once __DIR__ . '/../src/RegistrationService.php';
require_once __DIR__ . '/../src/PlayerService.php';

function playerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PLAYER FAIL: {$message}\n");
        exit(1);
    }
}

$db = Database::connect();
$auth = new AuthService($db);
$league = new LeagueService($db);
$registration = new RegistrationService($db);
$players = new PlayerService($db);
$suffix = bin2hex(random_bytes(4));

$venueId = $league->createVenue(['name' => "Player History Venue {$suffix}", 'city' => 'Phoenix', 'state' => 'AZ']);
$seasonId = $league->createSeason(['venue_id' => $venueId, 'name' => "Player Season {$suffix}"]);

// An account that predates its first registration may safely link automatically.
$user = $auth->createUser('Linked Player', "linked-{$suffix}@example.test", 'LinkedPlayer!123');
$registered = $registration->register([
    'season_id' => $seasonId,
    'name' => 'Linked Player',
    'email' => "linked-{$suffix}@example.test",
    'join_type' => 'solo',
]);
$link = $players->linkFromRegistration($user, (int)$registered['player_id']);
playerAssert($link['linked'] === true, 'Pre-existing account should link its first registration.');

$teamA = $db->prepare("INSERT INTO teams(season_id,name,status,created_at,updated_at) VALUES(:season_id,:name,'active',NOW(),NOW())");
$teamA->execute(['season_id' => $seasonId, 'name' => "History Heroes {$suffix}"]);
$teamAId = (int)$db->lastInsertId();
$teamB = $db->prepare("INSERT INTO teams(season_id,name,status,created_at,updated_at) VALUES(:season_id,:name,'active',NOW(),NOW())");
$teamB->execute(['season_id' => $seasonId, 'name' => "Test Tossers {$suffix}"]);
$teamBId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO team_members(team_id,season_id,player_id,joined_at) VALUES(:team_id,:season_id,:player_id,NOW())')->execute([
    'team_id' => $teamAId,
    'season_id' => $seasonId,
    'player_id' => (int)$registered['player_id'],
]);
$db->prepare("INSERT INTO matches(season_id,week_no,board_no,stage,sequence_no,home_team_id,away_team_id,home_score,away_score,status,scheduled_at,created_at,updated_at) VALUES(:season_id,1,1,'regular',1,:home,:away,21,15,'final',NOW(),NOW(),NOW())")->execute([
    'season_id' => $seasonId,
    'home' => $teamAId,
    'away' => $teamBId,
]);
$db->prepare("INSERT INTO matches(season_id,week_no,board_no,stage,sequence_no,home_team_id,away_team_id,status,scheduled_at,created_at,updated_at) VALUES(:season_id,2,1,'regular',1,:home,:away,'scheduled',DATE_ADD(NOW(),INTERVAL 7 DAY),NOW(),NOW())")->execute([
    'season_id' => $seasonId,
    'home' => $teamBId,
    'away' => $teamAId,
]);
$db->prepare("UPDATE teams SET status='champion',updated_at=NOW() WHERE id=:id")->execute(['id' => $teamAId]);

$profile = $players->profileForUser($user);
playerAssert($profile['linked'] === true, 'Linked account must return a player profile.');
playerAssert($profile['stats']['seasons'] === 1, 'Player profile must count league seasons.');
playerAssert($profile['stats']['matches_played'] === 1, 'Player profile must count final matches.');
playerAssert($profile['stats']['wins'] === 1 && $profile['stats']['losses'] === 0, 'Player profile must calculate match record.');
playerAssert($profile['stats']['championships'] === 1, 'Player profile must count championships.');
playerAssert(count($profile['registrations']) === 1, 'Player profile must expose registration history.');
playerAssert(count($profile['upcoming_matches']) === 1, 'Player profile must expose upcoming matches.');
playerAssert(count($profile['recent_results']) === 1 && $profile['recent_results'][0]['result'] === 'W', 'Player profile must expose recent results.');

// History that predates a newly-created account is deliberately not self-claimed.
$legacySeasonId = $league->createSeason(['venue_id' => $venueId, 'name' => "Legacy Season {$suffix}"]);
$legacy = $registration->register([
    'season_id' => $legacySeasonId,
    'name' => 'Legacy Player',
    'email' => "legacy-{$suffix}@example.test",
    'join_type' => 'friends',
    'requested_group' => 'Old Friends',
]);
$legacyUser = $auth->createUser('Legacy Player', "legacy-{$suffix}@example.test", 'LegacyPlayer!123');
$db->prepare('UPDATE users SET created_at=DATE_ADD(NOW(),INTERVAL 1 MINUTE) WHERE id=:id')->execute(['id' => (int)$legacyUser['id']]);
$legacyLink = $players->linkFromRegistration($legacyUser, (int)$legacy['player_id']);
playerAssert($legacyLink['linked'] === false && $legacyLink['reason'] === 'historical_verification_required', 'New account must not automatically claim older player history.');
$legacyProfile = $players->profileForUser($legacyUser);
playerAssert($legacyProfile['linked'] === false, 'Unverified legacy account must remain unlinked.');
playerAssert($legacyProfile['legacy_link_policy'] === 'admin_verification_required', 'Unlinked accounts should be told only the verification policy.');
playerAssert(!array_key_exists('legacy_history_available', $legacyProfile) && !array_key_exists('legacy_registration_count', $legacyProfile), 'Normal accounts must not learn whether matching legacy history exists.');

$unlinked = $players->unlinkedPlayers();
$legacyRows = array_values(array_filter($unlinked, static fn(array $row): bool => (int)$row['player_id'] === (int)$legacy['player_id']));
playerAssert(count($legacyRows) === 1, 'Admin queue must include unlinked historical player records.');
playerAssert((int)$legacyRows[0]['account_id'] === (int)$legacyUser['id'], 'Admin queue should identify a matching account by exact email.');

$mismatchRejected = false;
try {
    $players->adminLinkByEmail("legacy-{$suffix}@example.test", "linked-{$suffix}@example.test");
} catch (RuntimeException $e) {
    $mismatchRejected = str_contains($e->getMessage(), 'must match');
}
playerAssert($mismatchRejected, 'Admin history linking must reject different account/player emails.');

$adminLink = $players->adminLinkByEmail("legacy-{$suffix}@example.test", "legacy-{$suffix}@example.test");
playerAssert($adminLink['linked'] === true, 'Administrator should be able to link verified matching legacy history.');
$verifiedLegacyProfile = $players->profileForUser($legacyUser);
playerAssert($verifiedLegacyProfile['linked'] === true && count($verifiedLegacyProfile['registrations']) === 1, 'Verified legacy link must restore historical registration visibility.');

$idempotentAdminLink = $players->adminLinkByEmail("legacy-{$suffix}@example.test", "legacy-{$suffix}@example.test");
playerAssert($idempotentAdminLink['linked'] === true, 'Repeating the same verified admin link should be idempotent.');

echo "FourBag player accounts and history checks passed.\n";
