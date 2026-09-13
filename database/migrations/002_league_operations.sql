ALTER TABLE league_seasons
    ADD COLUMN board_count SMALLINT UNSIGNED NOT NULL DEFAULT 4 AFTER players_per_team;

ALTER TABLE matches
    MODIFY home_team_id BIGINT UNSIGNED NULL,
    MODIFY away_team_id BIGINT UNSIGNED NULL,
    ADD COLUMN stage ENUM('regular','semifinal','final') NOT NULL DEFAULT 'regular' AFTER board_no,
    ADD COLUMN sequence_no SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER stage,
    ADD INDEX idx_match_slot (season_id, week_no, stage, sequence_no);

ALTER TABLE registrations
    MODIFY payment_status ENUM('pending','awaiting_board_payment','paid','included_with_board','refunded','waived') NOT NULL DEFAULT 'pending',
    ADD COLUMN registration_credit_order_id BIGINT UNSIGNED NULL AFTER payment_status,
    ADD INDEX idx_registration_credit_order (registration_credit_order_id),
    ADD CONSTRAINT fk_registration_credit_order FOREIGN KEY (registration_credit_order_id) REFERENCES orders(id) ON DELETE SET NULL;

CREATE TABLE match_score_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL,
    home_score SMALLINT UNSIGNED NOT NULL,
    away_score SMALLINT UNSIGNED NOT NULL,
    match_status ENUM('live','final') NOT NULL DEFAULT 'final',
    recorded_by VARCHAR(120) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_score_events_match_created (match_id, created_at),
    CONSTRAINT fk_score_events_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
