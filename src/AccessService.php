<?php

declare(strict_types=1);

namespace FourBag;

use PDO;
use RuntimeException;

final class AccessService
{
    private const LEGACY_OPERATOR_ACTIONS = [
        'roster',
        'operations',
        'venue.create',
        'season.create',
        'teams.build',
        'schedule.generate',
        'score.record',
        'championship.create',
    ];

    public function __construct(private PDO $db) {}

    public static function allowsLegacyOperatorKey(string $action): bool
    {
        return in_array($action, self::LEGACY_OPERATOR_ACTIONS, true);
    }

    public function assignVenueRole(int $venueId, int $userId, string $role): array
    {
        if (!in_array($role, ['owner', 'manager', 'scorekeeper'], true)) {
            throw new RuntimeException('Invalid venue role.');
        }
        $venue = $this->db->prepare('SELECT id FROM venues WHERE id=:id LIMIT 1');
        $venue->execute(['id' => $venueId]);
        if (!$venue->fetchColumn()) {
            throw new RuntimeException('Venue not found.');
        }
        $user = $this->db->prepare("SELECT id FROM users WHERE id=:id AND status='active' LIMIT 1");
        $user->execute(['id' => $userId]);
        if (!$user->fetchColumn()) {
            throw new RuntimeException('Active user not found.');
        }

        $stmt = $this->db->prepare("INSERT INTO venue_user_roles(venue_id,user_id,role,status,created_at,updated_at) VALUES(:venue_id,:user_id,:role,'active',NOW(),NOW()) ON DUPLICATE KEY UPDATE role=VALUES(role),status='active',updated_at=NOW()");
        $stmt->execute(['venue_id' => $venueId, 'user_id' => $userId, 'role' => $role]);
        return $this->venueMembership($venueId, $userId) ?? throw new RuntimeException('Unable to assign venue role.');
    }

    public function revokeVenueRole(int $venueId, int $userId): void
    {
        $stmt = $this->db->prepare("UPDATE venue_user_roles SET status='revoked',updated_at=NOW() WHERE venue_id=:venue_id AND user_id=:user_id");
        $stmt->execute(['venue_id' => $venueId, 'user_id' => $userId]);
    }

    public function venueMembers(int $venueId): array
    {
        $stmt = $this->db->prepare("SELECT vur.venue_id,vur.user_id,vur.role,vur.status,u.email,u.display_name,u.system_role FROM venue_user_roles vur JOIN users u ON u.id=vur.user_id WHERE vur.venue_id=:venue_id ORDER BY FIELD(vur.role,'owner','manager','scorekeeper'),u.display_name");
        $stmt->execute(['venue_id' => $venueId]);
        return $stmt->fetchAll();
    }

    public function canManageVenue(array $user, int $venueId): bool
    {
        if (($user['system_role'] ?? '') === 'admin') {
            return true;
        }
        $membership = $this->venueMembership($venueId, (int)($user['id'] ?? 0));
        return $membership !== null
            && $membership['status'] === 'active'
            && in_array($membership['role'], ['owner', 'manager'], true);
    }

    public function canManageSeason(array $user, int $seasonId): bool
    {
        $venueId = $this->seasonVenueId($seasonId);
        return $this->canManageVenue($user, $venueId);
    }

    public function canScoreSeason(array $user, int $seasonId): bool
    {
        if (in_array(($user['system_role'] ?? ''), ['admin', 'crew'], true)) {
            return true;
        }
        $venueId = $this->seasonVenueId($seasonId);
        $membership = $this->venueMembership($venueId, (int)($user['id'] ?? 0));
        return $membership !== null && $membership['status'] === 'active';
    }

    public function requireAdmin(?array $user): array
    {
        if (!$user || ($user['system_role'] ?? '') !== 'admin') {
            throw new RuntimeException('Administrator access required.');
        }
        return $user;
    }

    public function requireVenueManager(?array $user, int $venueId): array
    {
        if (!$user || !$this->canManageVenue($user, $venueId)) {
            throw new RuntimeException('Venue owner or manager access required.');
        }
        return $user;
    }

    public function requireSeasonManager(?array $user, int $seasonId): array
    {
        if (!$user || !$this->canManageSeason($user, $seasonId)) {
            throw new RuntimeException('League manager access required.');
        }
        return $user;
    }

    public function requireSeasonScorer(?array $user, int $seasonId): array
    {
        if (!$user || !$this->canScoreSeason($user, $seasonId)) {
            throw new RuntimeException('League scorekeeper access required.');
        }
        return $user;
    }

    public function venueMembership(int $venueId, int $userId): ?array
    {
        if ($venueId < 1 || $userId < 1) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id,venue_id,user_id,role,status,created_at,updated_at FROM venue_user_roles WHERE venue_id=:venue_id AND user_id=:user_id LIMIT 1');
        $stmt->execute(['venue_id' => $venueId, 'user_id' => $userId]);
        $membership = $stmt->fetch();
        return $membership ?: null;
    }

    public function seasonVenueId(int $seasonId): int
    {
        $stmt = $this->db->prepare('SELECT venue_id FROM league_seasons WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $seasonId]);
        $venueId = $stmt->fetchColumn();
        if ($venueId === false) {
            throw new RuntimeException('League season not found.');
        }
        return (int)$venueId;
    }
}
