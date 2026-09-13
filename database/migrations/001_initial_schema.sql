CREATE TABLE venues (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(180) NOT NULL UNIQUE,
    city VARCHAR(120) NULL,
    state VARCHAR(80) NULL,
    contact_name VARCHAR(160) NULL,
    contact_email VARCHAR(190) NULL,
    status ENUM('application','approved','active','inactive') NOT NULL DEFAULT 'application',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE league_seasons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(210) NOT NULL UNIQUE,
    status ENUM('draft','registration_open','active','completed','cancelled') NOT NULL DEFAULT 'draft',
    start_date DATE NOT NULL,
    weeks SMALLINT UNSIGNED NOT NULL DEFAULT 8,
    team_limit SMALLINT UNSIGNED NOT NULL DEFAULT 8,
    players_per_team SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    registration_fee_cents INT UNSIGNED NOT NULL DEFAULT 5000,
    league_night VARCHAR(20) NULL,
    host_fee_cents INT UNSIGNED NOT NULL DEFAULT 50000,
    sponsored_beer_price_cents INT UNSIGNED NOT NULL DEFAULT 600,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_seasons_venue_status (venue_id, status),
    CONSTRAINT fk_seasons_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE players (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(40) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE registrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    season_id BIGINT UNSIGNED NOT NULL,
    player_id BIGINT UNSIGNED NOT NULL,
    join_type ENUM('solo','friends','team') NOT NULL DEFAULT 'solo',
    requested_group VARCHAR(160) NULL,
    board_purchase TINYINT(1) NOT NULL DEFAULT 0,
    registration_fee_cents INT UNSIGNED NOT NULL DEFAULT 5000,
    payment_status ENUM('pending','paid','included_with_board','refunded','waived') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_registration (season_id, player_id),
    CONSTRAINT fk_registrations_season FOREIGN KEY (season_id) REFERENCES league_seasons(id) ON DELETE CASCADE,
    CONSTRAINT fk_registrations_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE teams (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    season_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    status ENUM('forming','active','eliminated','champion') NOT NULL DEFAULT 'forming',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_team_name (season_id, name),
    CONSTRAINT fk_teams_season FOREIGN KEY (season_id) REFERENCES league_seasons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team_members (
    team_id BIGINT UNSIGNED NOT NULL,
    season_id BIGINT UNSIGNED NOT NULL,
    player_id BIGINT UNSIGNED NOT NULL,
    joined_at DATETIME NOT NULL,
    PRIMARY KEY (team_id, player_id),
    UNIQUE KEY uq_player_team_per_season (season_id, player_id),
    CONSTRAINT fk_team_members_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_members_season FOREIGN KEY (season_id) REFERENCES league_seasons(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_members_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    season_id BIGINT UNSIGNED NOT NULL,
    week_no SMALLINT UNSIGNED NOT NULL,
    board_no SMALLINT UNSIGNED NOT NULL,
    home_team_id BIGINT UNSIGNED NOT NULL,
    away_team_id BIGINT UNSIGNED NOT NULL,
    home_score SMALLINT NULL,
    away_score SMALLINT NULL,
    status ENUM('scheduled','live','final','cancelled') NOT NULL DEFAULT 'scheduled',
    scheduled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_matches_season_week (season_id, week_no),
    CONSTRAINT fk_matches_season FOREIGN KEY (season_id) REFERENCES league_seasons(id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_home FOREIGN KEY (home_team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_away FOREIGN KEY (away_team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipment_kits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kit_code VARCHAR(80) NOT NULL UNIQUE,
    status ENUM('available','deployed','maintenance','retired') NOT NULL DEFAULT 'available',
    board_count SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    bag_count SMALLINT UNSIGNED NOT NULL DEFAULT 16,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipment_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_kit_id BIGINT UNSIGNED NOT NULL,
    venue_id BIGINT UNSIGNED NOT NULL,
    season_id BIGINT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL,
    returned_at DATETIME NULL,
    CONSTRAINT fk_equipment_assignment_kit FOREIGN KEY (equipment_kit_id) REFERENCES equipment_kits(id) ON DELETE CASCADE,
    CONSTRAINT fk_equipment_assignment_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
    CONSTRAINT fk_equipment_assignment_season FOREIGN KEY (season_id) REFERENCES league_seasons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sponsors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    sponsor_type ENUM('presenting','beer','weekly_prize','broadcast','championship') NOT NULL,
    status ENUM('prospect','active','completed','inactive') NOT NULL DEFAULT 'prospect',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sponsorship_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sponsor_id BIGINT UNSIGNED NOT NULL,
    venue_id BIGINT UNSIGNED NULL,
    season_id BIGINT UNSIGNED NULL,
    cash_value_cents INT UNSIGNED NOT NULL DEFAULT 0,
    in_kind_description VARCHAR(255) NULL,
    starts_on DATE NULL,
    ends_on DATE NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_campaign_sponsor FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE SET NULL,
    CONSTRAINT fk_campaign_season FOREIGN KEY (season_id) REFERENCES league_seasons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE broadcasts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    season_id BIGINT UNSIGNED NOT NULL,
    match_id BIGINT UNSIGNED NULL,
    coverage_level ENUM('score_feed','featured','full_crew') NOT NULL DEFAULT 'score_feed',
    status ENUM('planned','live','completed','cancelled') NOT NULL DEFAULT 'planned',
    scheduled_at DATETIME NULL,
    viewer_count INT UNSIGNED NOT NULL DEFAULT 0,
    ad_impressions INT UNSIGNED NOT NULL DEFAULT 0,
    stream_url VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_broadcast_season FOREIGN KEY (season_id) REFERENCES league_seasons(id) ON DELETE CASCADE,
    CONSTRAINT fk_broadcast_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id BIGINT UNSIGNED NULL,
    season_id BIGINT UNSIGNED NULL,
    order_type ENUM('fourbag_set','bag_set','custom_board') NOT NULL,
    subtotal_cents INT UNSIGNED NOT NULL,
    status ENUM('pending','paid','fulfilled','cancelled','refunded') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_orders_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_season FOREIGN KEY (season_id) REFERENCES league_seasons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
