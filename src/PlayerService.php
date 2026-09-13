<?php

declare(strict_types=1);

namespace FourBag;

use PDO;
use RuntimeException;
use Throwable;

final class PlayerService
{
    public function __construct(private PDO $db) {}

    public function profileForUser(array $user): array
    {
        $userId = (int)($user['id'] ?? 0);
        $email = strtolower(trim((string)($user['email'] ?? '')));
        if ($userId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Authenticated user account required.');
        }

        $player = $this->playerForUser($userId);
        if (!$player) {
            // Do not disclose whether an unlinked player record exists for this email.
            // FourBag accounts do not yet require email verification, so legacy-history
            // discovery is intentionally restricted to the administrator queue.
            return [
                'linked' => false,
                'account' => $this->accountProjection($user),
                'player' => null,
                'stats' => $this->emptyStats(),
                'registrations' => [],
                'upcoming_matches' => [],
                'recent_results' => [],
                'legacy_link_policy' => 'admin_verification_required',
            ];
        }

        $playerId = (int)$player['id'];
        return [
            'linked' => true,
            'account' => $this->accountProjection($user),
            'player' => [
                'id' => $playerId,
                'name' => (string)$player['name'],
                'email' => (string)$player['email'],
                'created_at' => (string)$player['created_at'],
            ],
            'stats' => $this->careerStats($playerId),
            'registrations' => $this->registrations($playerId),
            'upcoming_matches' => $this->matches($playerId, false),
            'recent_results' => $this->matches($playerId, true),
            'legacy_link_policy' => null,
        ];
    }

    /**
     * Defense-in-depth for automatic registration linking. The API only calls this
     * for a brand-new player record or a player already linked to the same account.
     * This method additionally refuses a self-link if the account was created after
     * the player's first registration.
     */
    public function linkFromRegistration(array $user, int $playerId): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId < 1 || $playerId < 1) {
            return ['linked' => false, 'reason' => 'invalid_identity'];
        }

        $this->db->beginTransaction();
        try {
            $userStmt = $this->db->prepare("SELECT id,email,status,created_at FROM users WHERE id=:id FOR UPDATE");
            $userStmt->execute(['id' => $userId]);
            $account = $userStmt->fetch();
            if (!$account || $account['status'] !== 'active') {
                throw new RuntimeException('Active user account required.');
            }

            $playerStmt = $this->db->prepare('SELECT id,user_id,email FROM players WHERE id=:id FOR UPDATE');
            $playerStmt->execute(['id' => $playerId]);
            $player = $playerStmt->fetch();
            if (!$player) {
                throw new RuntimeException('Player not found.');
            }

            if (strtolower((string)$player['email']) !== strtolower((string)$account['email'])) {
                $this->db->commit();
                return ['linked' => false, 'reason' => 'email_mismatch'];
            }
            if ($player['user_id'] !== null) {
                $linked = (int)$player['user_id'] === $userId;
                $this->db->commit();
                return ['linked' => $linked, 'reason' => $linked ? 'already_linked' : 'linked_to_other_account'];
            }

            $firstRegistration = $this->db->prepare('SELECT MIN(created_at) FROM registrations WHERE player_id=:player_id');
            $firstRegistration->execute(['player_id' => $playerId]);
            $firstRegisteredAt = $firstRegistration->fetchColumn();
            if ($firstRegisteredAt !== false && $firstRegisteredAt !== null && (string)$account['created_at'] > (string)$firstRegisteredAt) {
                $this->db->commit();
                return ['linked' => false, 'reason' => 'historical_verification_required'];
            }

            $this->db->prepare('UPDATE players SET user_id=:user_id,updated_at=NOW() WHERE id=:player_id AND user_id IS NULL')->execute([
                'user_id' => $userId,
                'player_id' => $playerId,
            ]);
            $this->db->commit();
            return ['linked' => true, 'reason' => 'account_preexisting'];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                throw new RuntimeException('This FourBag account or player profile is already linked.');
            }
            throw $e;
        }
    }

    public function adminLinkByEmail(string $playerEmail, string $userEmail): array
    {
        $playerEmail = strtolower(trim($playerEmail));
        $userEmail = strtolower(trim($userEmail));
        if (!filter_var($playerEmail, FILTER_VALIDATE_EMAIL) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Valid player and account email addresses are required.');
        }
        if ($playerEmail !== $userEmail) {
            throw new RuntimeException('Player and account email must match for a history link.');
        }

        $this->db->beginTransaction();
        try {
            $userStmt = $this->db->prepare("SELECT id,email,status FROM users WHERE email=:email LIMIT 1 FOR UPDATE");
            $userStmt->execute(['email' => $userEmail]);
            $user = $userStmt->fetch();
            if (!$user || $user['status'] !== 'active') {
                throw new RuntimeException('Active FourBag account not found for that email.');
            }

            $playerStmt = $this->db->prepare('SELECT id,user_id,email,name FROM players WHERE email=:email LIMIT 1 FOR UPDATE');
            $playerStmt->execute(['email' => $playerEmail]);
            $player = $playerStmt->fetch();
            if (!$player) {
                throw new RuntimeException('Player history not found for that email.');
            }
            if ($player['user_id'] !== null && (int)$player['user_id'] !== (int)$user['id']) {
                throw new RuntimeException('Player history is already linked to another account.');
            }

            $this->db->prepare('UPDATE players SET user_id=:user_id,updated_at=NOW() WHERE id=:player_id')->execute([
                'user_id' => (int)$user['id'],
                'player_id' => (int)$player['id'],
            ]);
            $this->db->commit();

            return [
                'linked' => true,
                'player_id' => (int)$player['id'],
                'user_id' => (int)$user['id'],
                'email' => (string)$player['email'],
                'name' => (string)$player['name'],
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                throw new RuntimeException('This FourBag account or player profile is already linked.');
            }
            throw $e;
        }
    }

    public function unlinkedPlayers(int $limit = 100): array
    {
        $limit = max(1, min(250, $limit));
        $sql = "SELECT p.id player_id,p.name,p.email,p.created_at,
                       COUNT(DISTINCT r.id) registration_count,
                       MIN(r.created_at) first_registered_at,
                       MAX(r.created_at) last_registered_at,
                       u.id account_id,u.display_name account_name,u.status account_status
                FROM players p
                LEFT JOIN registrations r ON r.player_id=p.id
                LEFT JOIN users u ON u.email=p.email
                WHERE p.user_id IS NULL
                GROUP BY p.id,u.id
                HAVING COUNT(DISTINCT r.id)>0
                ORDER BY last_registered_at DESC,p.id DESC
                LIMIT {$limit}";
        return $this->db->query($sql)->fetchAll();
    }

    private function playerForUser(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT id,user_id,name,email,created_at,updated_at FROM players WHERE user_id=:user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $player = $stmt->fetch();
        return $player ?: null;
    }

    private function careerStats(int $playerId): array
    {
        $registrationStmt = $this->db->prepare('SELECT COUNT(DISTINCT season_id) seasons,COALESCE(SUM(board_purchase=1),0) board_purchases FROM registrations WHERE player_id=:player_id');
        $registrationStmt->execute(['player_id' => $playerId]);
        $registration = $registrationStmt->fetch() ?: ['seasons' => 0, 'board_purchases' => 0];

        $matchesStmt = $this->db->prepare("SELECT
                    COUNT(*) matches_played,
                    COALESCE(SUM(CASE WHEN (m.home_team_id=tm.team_id AND m.home_score>m.away_score) OR (m.away_team_id=tm.team_id AND m.away_score>m.home_score) THEN 1 ELSE 0 END),0) wins,
                    COALESCE(SUM(CASE WHEN (m.home_team_id=tm.team_id AND m.home_score<m.away_score) OR (m.away_team_id=tm.team_id AND m.away_score<m.home_score) THEN 1 ELSE 0 END),0) losses,
                    COALESCE(SUM(CASE WHEN m.home_score=m.away_score THEN 1 ELSE 0 END),0) ties
                FROM team_members tm
                JOIN matches m ON m.season_id=tm.season_id AND (m.home_team_id=tm.team_id OR m.away_team_id=tm.team_id)
                WHERE tm.player_id=:player_id AND m.status='final'");
        $matchesStmt->execute(['player_id' => $playerId]);
        $matches = $matchesStmt->fetch() ?: ['matches_played' => 0, 'wins' => 0, 'losses' => 0, 'ties' => 0];

        $championStmt = $this->db->prepare("SELECT COUNT(DISTINCT t.season_id) FROM team_members tm JOIN teams t ON t.id=tm.team_id WHERE tm.player_id=:player_id AND t.status='champion'");
        $championStmt->execute(['player_id' => $playerId]);

        return [
            'seasons' => (int)$registration['seasons'],
            'board_purchases' => (int)$registration['board_purchases'],
            'matches_played' => (int)$matches['matches_played'],
            'wins' => (int)$matches['wins'],
            'losses' => (int)$matches['losses'],
            'ties' => (int)$matches['ties'],
            'championships' => (int)$championStmt->fetchColumn(),
        ];
    }

    private function registrations(int $playerId): array
    {
        $stmt = $this->db->prepare("SELECT r.id registration_id,r.season_id,r.join_type,r.requested_group,r.board_purchase,r.payment_status,r.created_at registered_at,
                    s.name season_name,s.status season_status,s.start_date,s.weeks,
                    v.id venue_id,v.name venue_name,v.city,v.state,
                    t.id team_id,t.name team_name,t.status team_status
                FROM registrations r
                JOIN league_seasons s ON s.id=r.season_id
                JOIN venues v ON v.id=s.venue_id
                LEFT JOIN team_members tm ON tm.player_id=r.player_id AND tm.season_id=r.season_id
                LEFT JOIN teams t ON t.id=tm.team_id
                WHERE r.player_id=:player_id
                ORDER BY s.start_date DESC,r.id DESC");
        $stmt->execute(['player_id' => $playerId]);
        return $stmt->fetchAll();
    }

    private function matches(int $playerId, bool $final): array
    {
        $statusSql = $final ? "m.status='final'" : "m.status IN('scheduled','live')";
        $orderSql = $final ? 'm.scheduled_at DESC,m.id DESC' : 'm.scheduled_at IS NULL,m.scheduled_at,m.id';
        $stmt = $this->db->prepare("SELECT m.id,m.season_id,m.week_no,m.board_no,m.stage,m.status,m.scheduled_at,m.home_score,m.away_score,
                    ht.name home_team,at.name away_team,
                    CASE WHEN m.home_team_id=tm.team_id THEN at.name ELSE ht.name END opponent,
                    CASE
                        WHEN m.status<>'final' THEN NULL
                        WHEN m.home_score=m.away_score THEN 'T'
                        WHEN (m.home_team_id=tm.team_id AND m.home_score>m.away_score) OR (m.away_team_id=tm.team_id AND m.away_score>m.home_score) THEN 'W'
                        ELSE 'L'
                    END result,
                    s.name season_name,v.name venue_name
                FROM team_members tm
                JOIN matches m ON m.season_id=tm.season_id AND (m.home_team_id=tm.team_id OR m.away_team_id=tm.team_id)
                JOIN teams ht ON ht.id=m.home_team_id
                JOIN teams at ON at.id=m.away_team_id
                JOIN league_seasons s ON s.id=m.season_id
                JOIN venues v ON v.id=s.venue_id
                WHERE tm.player_id=:player_id AND {$statusSql}
                ORDER BY {$orderSql}
                LIMIT 20");
        $stmt->execute(['player_id' => $playerId]);
        return $stmt->fetchAll();
    }

    private function accountProjection(array $user): array
    {
        return [
            'id' => (int)($user['id'] ?? 0),
            'email' => (string)($user['email'] ?? ''),
            'display_name' => (string)($user['display_name'] ?? ''),
            'system_role' => (string)($user['system_role'] ?? 'user'),
        ];
    }

    private function emptyStats(): array
    {
        return [
            'seasons' => 0,
            'board_purchases' => 0,
            'matches_played' => 0,
            'wins' => 0,
            'losses' => 0,
            'ties' => 0,
            'championships' => 0,
        ];
    }
}
