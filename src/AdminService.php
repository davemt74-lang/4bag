<?php

declare(strict_types=1);

namespace FourBag;

use PDO;

final class AdminService
{
    public function __construct(private PDO $db) {}

    public function venues(): array
    {
        $sql = "SELECT
                    v.id,
                    v.name,
                    v.slug,
                    v.city,
                    v.state,
                    v.status,
                    COUNT(DISTINCT CASE WHEN vur.status='active' THEN vur.id END) AS active_members,
                    COUNT(DISTINCT CASE WHEN s.status IN('registration_open','active') THEN s.id END) AS active_seasons,
                    COUNT(DISTINCT CASE WHEN ek.status='deployed' THEN ek.id END) AS deployed_kits
                FROM venues v
                LEFT JOIN venue_user_roles vur ON vur.venue_id=v.id
                LEFT JOIN league_seasons s ON s.venue_id=v.id
                LEFT JOIN equipment_kits ek ON ek.venue_id=v.id
                GROUP BY v.id
                ORDER BY v.name";
        return $this->db->query($sql)->fetchAll();
    }
}
