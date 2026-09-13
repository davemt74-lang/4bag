<?php

declare(strict_types=1);

use FourBag\AccessService;
use FourBag\AuthService;
use FourBag\Database;
use FourBag\LeagueService;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/AccessService.php';
require_once __DIR__ . '/../src/LeagueService.php';

function authAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "AUTH FAIL: {$message}\n");
        exit(1);
    }
}

$db = Database::connect();
$auth = new AuthService($db);
$access = new AccessService($db);
$league = new LeagueService($db);

$suffix = bin2hex(random_bytes(4));
$admin = $auth->createUser('Network Admin', "admin-{$suffix}@example.test", 'AdminPassword!123', 'admin');
$manager = $auth->register('Venue Manager', "manager-{$suffix}@example.test", 'ManagerPassword!123');
$scorekeeper = $auth->register('Score Keeper', "score-{$suffix}@example.test", 'ScorePassword!123');
$outsider = $auth->register('Regular Player', "player-{$suffix}@example.test", 'PlayerPassword!123');
$crew = $auth->createUser('FourBag Crew', "crew-{$suffix}@example.test", 'CrewPassword!123', 'crew');

$venueId = $league->createVenue(['name' => "Access Test Venue {$suffix}", 'city' => 'Phoenix', 'state' => 'AZ']);
$seasonId = $league->createSeason(['venue_id' => $venueId, 'name' => "Access Test Season {$suffix}"]);

$access->assignVenueRole($venueId, (int)$manager['id'], 'manager');
$access->assignVenueRole($venueId, (int)$scorekeeper['id'], 'scorekeeper');

authAssert($access->canManageVenue($admin, $venueId), 'Admin should manage every venue.');
authAssert($access->canManageVenue($manager, $venueId), 'Venue manager should manage assigned venue.');
authAssert(!$access->canManageVenue($scorekeeper, $venueId), 'Scorekeeper should not receive manager privileges.');
authAssert(!$access->canManageVenue($outsider, $venueId), 'Unassigned user should not manage the venue.');
authAssert($access->canManageSeason($manager, $seasonId), 'Venue manager should manage venue season.');
authAssert($access->canScoreSeason($scorekeeper, $seasonId), 'Scorekeeper should score assigned venue season.');
authAssert($access->canScoreSeason($crew, $seasonId), 'FourBag crew should score network seasons.');
authAssert(!$access->canScoreSeason($outsider, $seasonId), 'Unassigned player should not score matches.');

authAssert(AccessService::allowsLegacyOperatorKey('score.record'), 'Legacy operator key should remain available for transitional league operations.');
authAssert(AccessService::allowsLegacyOperatorKey('operations'), 'Legacy operator key should remain available for transitional venue operations.');
authAssert(!AccessService::allowsLegacyOperatorKey('admin.venues'), 'Legacy operator key must never grant network administration access.');
authAssert(!AccessService::allowsLegacyOperatorKey('venue.members'), 'Legacy operator key must never expose venue account membership administration.');
authAssert(!AccessService::allowsLegacyOperatorKey('venue.member.assign'), 'Legacy operator key must never assign venue account roles.');
authAssert(!AccessService::allowsLegacyOperatorKey('venue.member.revoke'), 'Legacy operator key must never revoke venue account roles.');

$login = $auth->login($manager['email'], 'ManagerPassword!123');
authAssert(isset($login['token']) && strlen($login['token']) === 64, 'Login should issue a 64-character session token.');
$current = $auth->currentUser($login['token']);
authAssert($current !== null && (int)$current['id'] === (int)$manager['id'], 'Session token should resolve to manager account.');
$storedHash = $db->query('SELECT token_hash FROM auth_sessions WHERE user_id=' . (int)$manager['id'] . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
authAssert(is_string($storedHash) && $storedHash !== $login['token'] && $storedHash === hash('sha256', $login['token']), 'Database must store only the session token hash.');

$badLoginRejected = false;
try {
    $auth->login($manager['email'], 'wrong-password');
} catch (RuntimeException $e) {
    $badLoginRejected = $e->getMessage() === 'Invalid email or password.';
}
authAssert($badLoginRejected, 'Invalid password should be rejected with a generic message.');

$duplicateRejected = false;
try {
    $auth->register('Duplicate Manager', $manager['email'], 'AnotherPassword!123');
} catch (RuntimeException $e) {
    $duplicateRejected = str_contains($e->getMessage(), 'already exists');
}
authAssert($duplicateRejected, 'Duplicate account email should be rejected.');

$members = $access->venueMembers($venueId);
authAssert(count($members) === 2, 'Venue should have manager and scorekeeper memberships.');
$access->revokeVenueRole($venueId, (int)$scorekeeper['id']);
authAssert(!$access->canScoreSeason($scorekeeper, $seasonId), 'Revoked scorekeeper should immediately lose score access.');

$auth->logout($login['token']);
authAssert($auth->currentUser($login['token']) === null, 'Logged-out token should no longer resolve.');

$db->prepare("UPDATE users SET status='disabled' WHERE id=:id")->execute(['id' => (int)$outsider['id']]);
$disabledRejected = false;
try {
    $auth->login($outsider['email'], 'PlayerPassword!123');
} catch (RuntimeException $e) {
    $disabledRejected = $e->getMessage() === 'Invalid email or password.';
}
authAssert($disabledRejected, 'Disabled account must not log in.');

echo "FourBag authentication and access-control checks passed.\n";
