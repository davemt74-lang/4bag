<?php

declare(strict_types=1);

namespace FourBag;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class LeagueService
{
    private const TEAM_NAMES = [
        'Red Bricks', 'Backyard Legends', 'Four Alarm', 'Bag & Order',
        'Corner Crew', 'Night Shift', 'Firehouse Four', 'Last Call',
        'Patio People', 'Lucky Fours', 'Side Pocket', 'The Tossers',
    ];

    public function __construct(private PDO $db) {}

    public function listSeasons(): array
    {
        return $this->db->query("SELECT s.id,s.name,s.status,s.start_date,s.weeks,s.team_limit,s.players_per_team,s.board_count,s.registration_fee_cents,v.name venue_name,v.city,v.state,COUNT(r.id) registered_players,SUM(CASE WHEN r.board_purchase=1 THEN 1 ELSE 0 END) board_buyers FROM league_seasons s JOIN venues v ON v.id=s.venue_id LEFT JOIN registrations r ON r.season_id=s.id GROUP BY s.id ORDER BY s.start_date,s.id")->fetchAll();
    }

    public function createVenue(array $input): int
    {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Venue name is required.');
        }

        $stmt = $this->db->prepare("INSERT INTO venues(name,slug,city,state,status,created_at,updated_at) VALUES(:name,:slug,:city,:state,'approved',NOW(),NOW())");
        $stmt->execute([
            'name' => $name,
            'slug' => $this->slug($name) . '-' . uniqid(),
            'city' => trim((string)($input['city'] ?? '')) ?: null,
            'state' => trim((string)($input['state'] ?? '')) ?: null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function createSeason(array $input): int
    {
        $venueId = (int)($input['venue_id'] ?? 0);
        if ($venueId < 1) {
            throw new RuntimeException('Valid venue_id is required.');
        }

        $name = trim((string)($input['name'] ?? 'FourBag League')) ?: 'FourBag League';
        $stmt = $this->db->prepare("INSERT INTO league_seasons(venue_id,name,slug,status,start_date,weeks,team_limit,players_per_team,board_count,registration_fee_cents,created_at,updated_at) VALUES(:venue_id,:name,:slug,'registration_open',:start_date,:weeks,:team_limit,:players_per_team,:board_count,:registration_fee,NOW(),NOW())");
        $stmt->execute([
            'venue_id' => $venueId,
            'name' => $name,
            'slug' => $this->slug($name) . '-' . uniqid(),
            'start_date' => (string)($input['start_date'] ?? date('Y-m-d', strtotime('+14 days'))),
            'weeks' => max(1, (int)($input['weeks'] ?? 8)),
            'team_limit' => max(2, (int)($input['team_limit'] ?? 8)),
            'players_per_team' => max(1, (int)($input['players_per_team'] ?? 4)),
            'board_count' => max(1, (int)($input['board_count'] ?? 4)),
            'registration_fee' => max(0, (int)($input['registration_fee_cents'] ?? 5000)),
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function registerPlayer(array $input): int
    {
        $seasonId = (int)($input['season_id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $email = strtolower(trim((string)($input['email'] ?? '')));
        if ($seasonId < 1 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('season_id, player name and valid email are required.');
        }

        $this->db->beginTransaction();
        try {
            $seasonStmt = $this->db->prepare('SELECT id,team_limit,players_per_team,registration_fee_cents,status FROM league_seasons WHERE id=:id FOR UPDATE');
            $seasonStmt->execute(['id' => $seasonId]);
            $season = $seasonStmt->fetch();
            if (!$season) {
                throw new RuntimeException('League season not found.');
            }
            if (!in_array($season['status'], ['registration_open', 'active'], true)) {
                throw new RuntimeException('This league is not accepting registrations.');
            }

            $stmt = $this->db->prepare("INSERT INTO players(name,email,created_at,updated_at) VALUES(:name,:email,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=NOW()");
            $stmt->execute(['name' => $name, 'email' => $email]);
            $q = $this->db->prepare('SELECT id FROM players WHERE email=:email LIMIT 1');
            $q->execute(['email' => $email]);
            $playerId = (int)$q->fetchColumn();

            $existing = $this->db->prepare('SELECT id,board_purchase FROM registrations WHERE season_id=:season_id AND player_id=:player_id LIMIT 1');
            $existing->execute(['season_id' => $seasonId, 'player_id' => $playerId]);
            $prior = $existing->fetch();

            if (!$prior) {
                $count = $this->db->prepare('SELECT COUNT(*) FROM registrations WHERE season_id=:season_id');
                $count->execute(['season_id' => $seasonId]);
                $capacity = (int)$season['team_limit'] * (int)$season['players_per_team'];
                if ((int)$count->fetchColumn() >= $capacity) {
                    throw new RuntimeException('This league is full.');
                }
            }

            $board = !empty($input['board_purchase']);
            $join = (string)($input['join_type'] ?? 'solo');
            if (!in_array($join, ['solo', 'friends', 'team'], true)) {
                $join = 'solo';
            }
            $fee = $board ? 0 : (int)$season['registration_fee_cents'];
            $paymentStatus = $board ? 'included_with_board' : 'pending';

            $r = $this->db->prepare("INSERT INTO registrations(season_id,player_id,join_type,requested_group,board_purchase,registration_fee_cents,payment_status,created_at,updated_at) VALUES(:season_id,:player_id,:join_type,:requested_group,:board_purchase,:fee,:payment_status,NOW(),NOW()) ON DUPLICATE KEY UPDATE join_type=VALUES(join_type),requested_group=VALUES(requested_group),board_purchase=VALUES(board_purchase),registration_fee_cents=VALUES(registration_fee_cents),payment_status=CASE WHEN payment_status='paid' AND VALUES(board_purchase)=0 THEN payment_status ELSE VALUES(payment_status) END,updated_at=NOW()");
            $r->execute([
                'season_id' => $seasonId,
                'player_id' => $playerId,
                'join_type' => $join,
                'requested_group' => trim((string)($input['requested_group'] ?? '')) ?: null,
                'board_purchase' => $board ? 1 : 0,
                'fee' => $fee,
                'payment_status' => $paymentStatus,
            ]);

            if ($board && (!$prior || !(bool)$prior['board_purchase'])) {
                $orderCheck = $this->db->prepare("SELECT id FROM orders WHERE player_id=:player_id AND season_id=:season_id AND order_type='fourbag_set' AND status NOT IN('cancelled','refunded') LIMIT 1");
                $orderCheck->execute(['player_id' => $playerId, 'season_id' => $seasonId]);
                if (!$orderCheck->fetchColumn()) {
                    $o = $this->db->prepare("INSERT INTO orders(player_id,season_id,order_type,subtotal_cents,status,created_at,updated_at) VALUES(:player_id,:season_id,'fourbag_set',19900,'pending',NOW(),NOW())");
                    $o->execute(['player_id' => $playerId, 'season_id' => $seasonId]);
                }
            }

            $this->db->commit();
            return $playerId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function seasonRoster(int $seasonId): array
    {
        $this->seasonOrFail($seasonId);
        $s = $this->db->prepare("SELECT p.id,p.name,p.email,r.join_type,r.requested_group,r.board_purchase,r.payment_status,t.id team_id,t.name team_name FROM registrations r JOIN players p ON p.id=r.player_id LEFT JOIN team_members tm ON tm.player_id=p.id AND tm.season_id=r.season_id LEFT JOIN teams t ON t.id=tm.team_id WHERE r.season_id=:season_id ORDER BY COALESCE(t.name,'ZZZ'),p.name");
        $s->execute(['season_id' => $seasonId]);
        return $s->fetchAll();
    }

    public function buildTeams(int $seasonId): array
    {
        $season = $this->seasonOrFail($seasonId);
        $finalized = $this->db->prepare("SELECT COUNT(*) FROM matches WHERE season_id=:season_id AND status='final'");
        $finalized->execute(['season_id' => $seasonId]);
        if ((int)$finalized->fetchColumn() > 0) {
            throw new RuntimeException('Teams cannot be rebuilt after match results have been finalized.');
        }

        $registrations = $this->db->prepare("SELECT r.player_id,r.join_type,r.requested_group,r.created_at,p.name FROM registrations r JOIN players p ON p.id=r.player_id WHERE r.season_id=:season_id ORDER BY r.created_at,r.id");
        $registrations->execute(['season_id' => $seasonId]);
        $rows = $registrations->fetchAll();
        if (!$rows) {
            throw new RuntimeException('No registered players are available to build teams.');
        }

        $teamSize = (int)$season['players_per_team'];
        $teamLimit = (int)$season['team_limit'];
        $groups = [];
        $singles = [];
        foreach ($rows as $row) {
            $groupName = trim((string)($row['requested_group'] ?? ''));
            if ($groupName !== '' && in_array($row['join_type'], ['friends', 'team'], true)) {
                $key = strtolower($groupName);
                if (!isset($groups[$key])) {
                    $groups[$key] = ['name' => $groupName, 'players' => []];
                }
                $groups[$key]['players'][] = (int)$row['player_id'];
            } else {
                $singles[] = (int)$row['player_id'];
            }
        }

        $units = [];
        foreach ($groups as $group) {
            foreach (array_chunk($group['players'], $teamSize) as $chunk) {
                $units[] = ['hint' => $group['name'], 'players' => $chunk];
            }
        }
        foreach ($singles as $playerId) {
            $units[] = ['hint' => null, 'players' => [$playerId]];
        }
        usort($units, static fn(array $a, array $b): int => count($b['players']) <=> count($a['players']));

        $bins = [];
        $unassigned = [];
        foreach ($units as $unit) {
            $placed = false;
            foreach ($bins as &$bin) {
                if (count($bin['players']) + count($unit['players']) <= $teamSize) {
                    array_push($bin['players'], ...$unit['players']);
                    if ($bin['hint'] === null && $unit['hint'] !== null) {
                        $bin['hint'] = $unit['hint'];
                    }
                    $placed = true;
                    break;
                }
            }
            unset($bin);
            if (!$placed && count($bins) < $teamLimit) {
                $bins[] = ['hint' => $unit['hint'], 'players' => $unit['players']];
                $placed = true;
            }
            if (!$placed) {
                array_push($unassigned, ...$unit['players']);
            }
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM matches WHERE season_id=:season_id')->execute(['season_id' => $seasonId]);
            $this->db->prepare('DELETE FROM team_members WHERE season_id=:season_id')->execute(['season_id' => $seasonId]);
            $this->db->prepare('DELETE FROM teams WHERE season_id=:season_id')->execute(['season_id' => $seasonId]);

            $usedNames = [];
            foreach ($bins as $index => $bin) {
                $baseName = trim((string)($bin['hint'] ?? '')) ?: self::TEAM_NAMES[$index % count(self::TEAM_NAMES)];
                $name = $this->uniqueTeamName($baseName, $usedNames);
                $usedNames[] = strtolower($name);
                $status = count($bin['players']) === $teamSize ? 'active' : 'forming';

                $team = $this->db->prepare('INSERT INTO teams(season_id,name,status,created_at,updated_at) VALUES(:season_id,:name,:status,NOW(),NOW())');
                $team->execute(['season_id' => $seasonId, 'name' => $name, 'status' => $status]);
                $teamId = (int)$this->db->lastInsertId();

                $member = $this->db->prepare('INSERT INTO team_members(team_id,season_id,player_id,joined_at) VALUES(:team_id,:season_id,:player_id,NOW())');
                foreach ($bin['players'] as $playerId) {
                    $member->execute(['team_id' => $teamId, 'season_id' => $seasonId, 'player_id' => $playerId]);
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'teams' => $this->listTeams($seasonId),
            'unassigned_player_ids' => $unassigned,
            'full_teams' => count(array_filter($bins, static fn(array $bin): bool => count($bin['players']) === $teamSize)),
            'forming_teams' => count(array_filter($bins, static fn(array $bin): bool => count($bin['players']) < $teamSize)),
        ];
    }

    public function listTeams(int $seasonId): array
    {
        $this->seasonOrFail($seasonId);
        $stmt = $this->db->prepare("SELECT t.id,t.name,t.status,COUNT(tm.player_id) player_count FROM teams t LEFT JOIN team_members tm ON tm.team_id=t.id WHERE t.season_id=:season_id GROUP BY t.id ORDER BY t.id");
        $stmt->execute(['season_id' => $seasonId]);
        return $stmt->fetchAll();
    }

    public function generateRoundRobin(int $seasonId): array
    {
        $season = $this->seasonOrFail($seasonId);
        $teamsStmt = $this->db->prepare("SELECT t.id,t.name,COUNT(tm.player_id) player_count FROM teams t LEFT JOIN team_members tm ON tm.team_id=t.id WHERE t.season_id=:season_id GROUP BY t.id ORDER BY t.id");
        $teamsStmt->execute(['season_id' => $seasonId]);
        $teams = $teamsStmt->fetchAll();
        if (count($teams) < 2) {
            throw new RuntimeException('At least two teams are required to generate a schedule.');
        }
        foreach ($teams as $team) {
            if ((int)$team['player_count'] !== (int)$season['players_per_team']) {
                throw new RuntimeException('All teams must be full before generating the round-robin schedule.');
            }
        }
        if ((int)ceil(count($teams) / 2) > (int)$season['board_count']) {
            throw new RuntimeException('This season does not have enough boards to run every matchup in the same time block.');
        }

        $finalCount = $this->db->prepare("SELECT COUNT(*) FROM matches WHERE season_id=:season_id AND status='final'");
        $finalCount->execute(['season_id' => $seasonId]);
        if ((int)$finalCount->fetchColumn() > 0) {
            throw new RuntimeException('The schedule cannot be regenerated after final scores exist.');
        }

        $ids = array_map(static fn(array $team): int => (int)$team['id'], $teams);
        if (count($ids) % 2 !== 0) {
            $ids[] = 0;
        }
        $slotCount = count($ids);
        $rounds = $slotCount - 1;
        $half = intdiv($slotCount, 2);
        $regularWeeks = min($rounds, max(1, (int)$season['weeks'] - 1));
        $rotation = $ids;
        $start = new DateTimeImmutable((string)$season['start_date'] . ' 18:00:00');

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM matches WHERE season_id=:season_id')->execute(['season_id' => $seasonId]);
            $insert = $this->db->prepare("INSERT INTO matches(season_id,week_no,board_no,stage,sequence_no,home_team_id,away_team_id,status,scheduled_at,created_at,updated_at) VALUES(:season_id,:week_no,:board_no,'regular',:sequence_no,:home_team_id,:away_team_id,'scheduled',:scheduled_at,NOW(),NOW())");

            for ($round = 0; $round < $regularWeeks; $round++) {
                $board = 1;
                for ($i = 0; $i < $half; $i++) {
                    $home = $rotation[$i];
                    $away = $rotation[$slotCount - 1 - $i];
                    if ($home !== 0 && $away !== 0) {
                        $insert->execute([
                            'season_id' => $seasonId,
                            'week_no' => $round + 1,
                            'board_no' => $board,
                            'sequence_no' => $board,
                            'home_team_id' => $home,
                            'away_team_id' => $away,
                            'scheduled_at' => $start->modify('+' . ($round * 7) . ' days')->format('Y-m-d H:i:s'),
                        ]);
                        $board++;
                    }
                }
                $last = array_pop($rotation);
                array_splice($rotation, 1, 0, [$last]);
            }

            $this->db->prepare("UPDATE league_seasons SET status='active',updated_at=NOW() WHERE id=:id")->execute(['id' => $seasonId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->seasonSchedule($seasonId);
    }

    public function seasonSchedule(int $seasonId): array
    {
        $this->seasonOrFail($seasonId);
        $stmt = $this->db->prepare("SELECT m.id,m.week_no,m.board_no,m.stage,m.sequence_no,m.home_team_id,m.away_team_id,ht.name home_team,at.name away_team,m.home_score,m.away_score,m.status,m.scheduled_at FROM matches m LEFT JOIN teams ht ON ht.id=m.home_team_id LEFT JOIN teams at ON at.id=m.away_team_id WHERE m.season_id=:season_id ORDER BY m.week_no,FIELD(m.stage,'regular','semifinal','final'),m.sequence_no,m.id");
        $stmt->execute(['season_id' => $seasonId]);
        return $stmt->fetchAll();
    }

    public function standings(int $seasonId): array
    {
        $this->seasonOrFail($seasonId);
        $teamsStmt = $this->db->prepare("SELECT id,name,status FROM teams WHERE season_id=:season_id ORDER BY id");
        $teamsStmt->execute(['season_id' => $seasonId]);
        $table = [];
        foreach ($teamsStmt->fetchAll() as $team) {
            $id = (int)$team['id'];
            $table[$id] = [
                'team_id' => $id,
                'team_name' => $team['name'],
                'team_status' => $team['status'],
                'played' => 0,
                'wins' => 0,
                'losses' => 0,
                'points_for' => 0,
                'points_against' => 0,
                'differential' => 0,
            ];
        }

        $matches = $this->db->prepare("SELECT home_team_id,away_team_id,home_score,away_score FROM matches WHERE season_id=:season_id AND stage='regular' AND status='final'");
        $matches->execute(['season_id' => $seasonId]);
        foreach ($matches->fetchAll() as $match) {
            $homeId = (int)$match['home_team_id'];
            $awayId = (int)$match['away_team_id'];
            if (!isset($table[$homeId], $table[$awayId])) {
                continue;
            }
            $homeScore = (int)$match['home_score'];
            $awayScore = (int)$match['away_score'];
            $table[$homeId]['played']++;
            $table[$awayId]['played']++;
            $table[$homeId]['points_for'] += $homeScore;
            $table[$homeId]['points_against'] += $awayScore;
            $table[$awayId]['points_for'] += $awayScore;
            $table[$awayId]['points_against'] += $homeScore;
            if ($homeScore > $awayScore) {
                $table[$homeId]['wins']++;
                $table[$awayId]['losses']++;
            } else {
                $table[$awayId]['wins']++;
                $table[$homeId]['losses']++;
            }
        }

        foreach ($table as &$row) {
            $row['differential'] = $row['points_for'] - $row['points_against'];
        }
        unset($row);

        $rows = array_values($table);
        usort($rows, static function (array $a, array $b): int {
            return $b['wins'] <=> $a['wins']
                ?: $b['differential'] <=> $a['differential']
                ?: $b['points_for'] <=> $a['points_for']
                ?: strcmp((string)$a['team_name'], (string)$b['team_name']);
        });
        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);

        return $rows;
    }

    public function recordScore(int $matchId, int $homeScore, int $awayScore, string $status = 'final', ?string $recordedBy = null): array
    {
        if ($homeScore < 0 || $awayScore < 0 || $homeScore > 999 || $awayScore > 999) {
            throw new RuntimeException('Scores must be between 0 and 999.');
        }
        if (!in_array($status, ['live', 'final'], true)) {
            throw new RuntimeException('Score status must be live or final.');
        }
        if ($status === 'final' && $homeScore === $awayScore) {
            throw new RuntimeException('A finalized FourBag match cannot end in a tie.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM matches WHERE id=:id FOR UPDATE');
            $stmt->execute(['id' => $matchId]);
            $match = $stmt->fetch();
            if (!$match) {
                throw new RuntimeException('Match not found.');
            }
            if (!$match['home_team_id'] || !$match['away_team_id']) {
                throw new RuntimeException('This bracket match does not have both teams yet.');
            }

            $update = $this->db->prepare('UPDATE matches SET home_score=:home_score,away_score=:away_score,status=:status,updated_at=NOW() WHERE id=:id');
            $update->execute(['home_score' => $homeScore, 'away_score' => $awayScore, 'status' => $status, 'id' => $matchId]);
            $event = $this->db->prepare('INSERT INTO match_score_events(match_id,home_score,away_score,match_status,recorded_by,created_at) VALUES(:match_id,:home_score,:away_score,:match_status,:recorded_by,NOW())');
            $event->execute([
                'match_id' => $matchId,
                'home_score' => $homeScore,
                'away_score' => $awayScore,
                'match_status' => $status,
                'recorded_by' => $recordedBy ? substr($recordedBy, 0, 120) : null,
            ]);

            if ($status === 'final' && $match['stage'] === 'semifinal') {
                $this->advanceChampionshipFinal((int)$match['season_id']);
            }
            if ($status === 'final' && $match['stage'] === 'final') {
                $winnerId = $homeScore > $awayScore ? (int)$match['home_team_id'] : (int)$match['away_team_id'];
                $this->db->prepare("UPDATE teams SET status=CASE WHEN id=:winner_id THEN 'champion' ELSE status END,updated_at=NOW() WHERE season_id=:season_id")->execute([
                    'winner_id' => $winnerId,
                    'season_id' => (int)$match['season_id'],
                ]);
                $this->db->prepare("UPDATE league_seasons SET status='completed',updated_at=NOW() WHERE id=:season_id")->execute(['season_id' => (int)$match['season_id']]);
            }

            $this->db->commit();
            return $this->matchById($matchId);
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function createChampionship(int $seasonId): array
    {
        $season = $this->seasonOrFail($seasonId);
        $remaining = $this->db->prepare("SELECT COUNT(*) FROM matches WHERE season_id=:season_id AND stage='regular' AND status<>'final'");
        $remaining->execute(['season_id' => $seasonId]);
        if ((int)$remaining->fetchColumn() > 0) {
            throw new RuntimeException('All regular-season matches must be final before seeding the championship.');
        }

        $standings = $this->standings($seasonId);
        if (count($standings) < 4) {
            throw new RuntimeException('At least four teams are required for the championship bracket.');
        }

        $existing = $this->db->prepare("SELECT COUNT(*) FROM matches WHERE season_id=:season_id AND stage IN('semifinal','final') AND status='final'");
        $existing->execute(['season_id' => $seasonId]);
        if ((int)$existing->fetchColumn() > 0) {
            throw new RuntimeException('The championship bracket cannot be reseeded after championship results are final.');
        }

        $week = (int)$season['weeks'];
        $start = new DateTimeImmutable((string)$season['start_date'] . ' 18:00:00');
        $champDate = $start->modify('+' . (($week - 1) * 7) . ' days');

        $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM matches WHERE season_id=:season_id AND stage IN('semifinal','final')")->execute(['season_id' => $seasonId]);
            $insert = $this->db->prepare("INSERT INTO matches(season_id,week_no,board_no,stage,sequence_no,home_team_id,away_team_id,status,scheduled_at,created_at,updated_at) VALUES(:season_id,:week_no,:board_no,:stage,:sequence_no,:home_team_id,:away_team_id,'scheduled',:scheduled_at,NOW(),NOW())");
            $pairs = [[$standings[0]['team_id'], $standings[3]['team_id']], [$standings[1]['team_id'], $standings[2]['team_id']]];
            foreach ($pairs as $index => $pair) {
                $insert->execute([
                    'season_id' => $seasonId,
                    'week_no' => $week,
                    'board_no' => $index + 1,
                    'stage' => 'semifinal',
                    'sequence_no' => $index + 1,
                    'home_team_id' => $pair[0],
                    'away_team_id' => $pair[1],
                    'scheduled_at' => $champDate->format('Y-m-d H:i:s'),
                ]);
            }
            $insert->execute([
                'season_id' => $seasonId,
                'week_no' => $week,
                'board_no' => 1,
                'stage' => 'final',
                'sequence_no' => 3,
                'home_team_id' => null,
                'away_team_id' => null,
                'scheduled_at' => $champDate->modify('+90 minutes')->format('Y-m-d H:i:s'),
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->seasonSchedule($seasonId);
    }

    public function seasonOperations(int $seasonId): array
    {
        $season = $this->seasonOrFail($seasonId);
        $roster = $this->seasonRoster($seasonId);
        $teams = $this->listTeams($seasonId);
        $schedule = $this->seasonSchedule($seasonId);
        $standings = $this->standings($seasonId);
        $capacity = (int)$season['team_limit'] * (int)$season['players_per_team'];

        return [
            'season' => $season,
            'capacity' => $capacity,
            'registered_players' => count($roster),
            'board_buyers' => count(array_filter($roster, static fn(array $row): bool => (bool)$row['board_purchase'])),
            'roster' => $roster,
            'teams' => $teams,
            'schedule' => $schedule,
            'standings' => $standings,
        ];
    }

    public function dashboard(): array
    {
        return [
            'venues' => (int)$this->db->query("SELECT COUNT(*) FROM venues WHERE status IN('approved','active')")->fetchColumn(),
            'seasons' => (int)$this->db->query("SELECT COUNT(*) FROM league_seasons WHERE status IN('registration_open','active')")->fetchColumn(),
            'players' => (int)$this->db->query('SELECT COUNT(*) FROM registrations')->fetchColumn(),
            'boardOrders' => (int)$this->db->query("SELECT COUNT(*) FROM orders WHERE order_type='fourbag_set'")->fetchColumn(),
            'finalMatches' => (int)$this->db->query("SELECT COUNT(*) FROM matches WHERE status='final'")->fetchColumn(),
        ];
    }

    private function advanceChampionshipFinal(int $seasonId): void
    {
        $semis = $this->db->prepare("SELECT id,home_team_id,away_team_id,home_score,away_score FROM matches WHERE season_id=:season_id AND stage='semifinal' AND status='final' ORDER BY sequence_no");
        $semis->execute(['season_id' => $seasonId]);
        $rows = $semis->fetchAll();
        if (count($rows) !== 2) {
            return;
        }
        $winners = [];
        foreach ($rows as $row) {
            $winners[] = (int)$row['home_score'] > (int)$row['away_score'] ? (int)$row['home_team_id'] : (int)$row['away_team_id'];
        }
        $this->db->prepare("UPDATE matches SET home_team_id=:home_team_id,away_team_id=:away_team_id,updated_at=NOW() WHERE season_id=:season_id AND stage='final'")->execute([
            'home_team_id' => $winners[0],
            'away_team_id' => $winners[1],
            'season_id' => $seasonId,
        ]);
    }

    private function matchById(int $matchId): array
    {
        $stmt = $this->db->prepare("SELECT m.*,ht.name home_team,at.name away_team FROM matches m LEFT JOIN teams ht ON ht.id=m.home_team_id LEFT JOIN teams at ON at.id=m.away_team_id WHERE m.id=:id");
        $stmt->execute(['id' => $matchId]);
        $match = $stmt->fetch();
        if (!$match) {
            throw new RuntimeException('Match not found.');
        }
        return $match;
    }

    private function seasonOrFail(int $seasonId): array
    {
        if ($seasonId < 1) {
            throw new RuntimeException('Valid season_id is required.');
        }
        $stmt = $this->db->prepare('SELECT * FROM league_seasons WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $seasonId]);
        $season = $stmt->fetch();
        if (!$season) {
            throw new RuntimeException('League season not found.');
        }
        return $season;
    }

    private function uniqueTeamName(string $baseName, array $usedNames): string
    {
        $baseName = substr(trim($baseName), 0, 150) ?: 'FourBag Team';
        $candidate = $baseName;
        $suffix = 2;
        while (in_array(strtolower($candidate), $usedNames, true)) {
            $candidate = substr($baseName, 0, 145) . ' ' . $suffix;
            $suffix++;
        }
        return $candidate;
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-') ?: 'fourbag';
    }
}
