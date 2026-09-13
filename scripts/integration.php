<?php

declare(strict_types=1);

use FourBag\Database;
use FourBag\LeagueService;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LeagueService.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$db = Database::connect();
$service = new LeagueService($db);

$venueId = $service->createVenue([
    'name' => 'FourBag Integration Bar',
    'city' => 'Phoenix',
    'state' => 'AZ',
]);

$seasonId = $service->createSeason([
    'venue_id' => $venueId,
    'name' => 'Integration League',
    'start_date' => '2026-10-01',
    'weeks' => 8,
    'team_limit' => 8,
    'players_per_team' => 4,
    'board_count' => 4,
    'registration_fee_cents' => 5000,
]);

for ($i = 1; $i <= 32; $i++) {
    $joinType = $i <= 4 ? 'team' : ($i <= 8 ? 'friends' : 'solo');
    $group = $i <= 4 ? 'Integration Captains' : ($i <= 8 ? 'Patio Friends' : null);
    $service->registerPlayer([
        'season_id' => $seasonId,
        'name' => "Player {$i}",
        'email' => "player{$i}@example.test",
        'join_type' => $joinType,
        'requested_group' => $group,
        'board_purchase' => $i % 4 === 0,
    ]);
}

assertTrue(count($service->seasonRoster($seasonId)) === 32, 'Expected 32 registered players.');
assertTrue((int)$db->query("SELECT COUNT(*) FROM orders WHERE season_id={$seasonId} AND order_type='fourbag_set'")->fetchColumn() === 8, 'Expected eight FourBag set orders.');

// Re-registering an existing board buyer must not create a duplicate set order.
$service->registerPlayer([
    'season_id' => $seasonId,
    'name' => 'Player 4',
    'email' => 'player4@example.test',
    'join_type' => 'team',
    'requested_group' => 'Integration Captains',
    'board_purchase' => true,
]);
assertTrue((int)$db->query("SELECT COUNT(*) FROM orders WHERE season_id={$seasonId} AND order_type='fourbag_set'")->fetchColumn() === 8, 'Board-buyer updates must not duplicate FourBag orders.');

$capacityRejected = false;
try {
    $service->registerPlayer([
        'season_id' => $seasonId,
        'name' => 'Player 33',
        'email' => 'player33@example.test',
        'join_type' => 'solo',
    ]);
} catch (RuntimeException $e) {
    $capacityRejected = str_contains($e->getMessage(), 'full');
}
assertTrue($capacityRejected, 'The 33rd player must be rejected when an 8x4 league is full.');

$teamBuild = $service->buildTeams($seasonId);
assertTrue((int)$teamBuild['full_teams'] === 8, 'Expected eight full teams.');
assertTrue((int)$teamBuild['forming_teams'] === 0, 'Expected no forming teams.');
assertTrue(count($teamBuild['unassigned_player_ids']) === 0, 'Expected no unassigned players.');

$schedule = $service->generateRoundRobin($seasonId);
$regular = array_values(array_filter($schedule, static fn(array $match): bool => $match['stage'] === 'regular'));
assertTrue(count($regular) === 28, 'Eight teams should create 28 round-robin matches over seven weeks.');

$weekCounts = [];
foreach ($regular as $match) {
    $weekCounts[(int)$match['week_no']] = ($weekCounts[(int)$match['week_no']] ?? 0) + 1;
    $service->recordScore((int)$match['id'], 21, 10 + ((int)$match['board_no'] % 4), 'final', 'integration-test');
}
assertTrue(count($weekCounts) === 7, 'Regular season should use seven weeks.');
foreach ($weekCounts as $count) {
    assertTrue($count === 4, 'Each regular-season week should have four matches.');
}

$standings = $service->standings($seasonId);
assertTrue(count($standings) === 8, 'Expected eight teams in standings.');
assertTrue((int)$standings[0]['played'] === 7, 'Every team should play seven regular-season matches.');

$championship = $service->createChampionship($seasonId);
$semis = array_values(array_filter($championship, static fn(array $match): bool => $match['stage'] === 'semifinal'));
$finals = array_values(array_filter($championship, static fn(array $match): bool => $match['stage'] === 'final'));
assertTrue(count($semis) === 2, 'Expected two championship semifinals.');
assertTrue(count($finals) === 1, 'Expected one championship final.');
assertTrue($finals[0]['home_team_id'] === null && $finals[0]['away_team_id'] === null, 'Final should wait for semifinal winners.');

$service->recordScore((int)$semis[0]['id'], 21, 15, 'final', 'integration-test');
$service->recordScore((int)$semis[1]['id'], 17, 21, 'final', 'integration-test');

$afterSemis = $service->seasonSchedule($seasonId);
$final = array_values(array_filter($afterSemis, static fn(array $match): bool => $match['stage'] === 'final'))[0];
assertTrue($final['home_team_id'] !== null && $final['away_team_id'] !== null, 'Final should be populated after both semifinals finish.');

$service->recordScore((int)$final['id'], 21, 18, 'final', 'integration-test');
$operations = $service->seasonOperations($seasonId);
assertTrue($operations['season']['status'] === 'completed', 'Season should complete after the championship final.');
assertTrue(count(array_filter($operations['teams'], static fn(array $team): bool => $team['status'] === 'champion')) === 1, 'Exactly one team should be marked champion.');
assertTrue((int)$db->query('SELECT COUNT(*) FROM match_score_events')->fetchColumn() === 31, 'Expected an audit row for every finalized match score.');

echo "FourBag integration checks passed.\n";
