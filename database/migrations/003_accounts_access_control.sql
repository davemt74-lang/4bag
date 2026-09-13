CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    system_role ENUM('user','crew','admin') NOT NULL DEFAULT 'user',
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_users_email (email),
    INDEX idx_users_role_status (system_role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_auth_sessions_token (token_hash),
    INDEX idx_auth_sessions_user_expires (user_id, expires_at),
    CONSTRAINT fk_auth_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE venue_user_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner','manager','scorekeeper') NOT NULL DEFAULT 'scorekeeper',
    status ENUM('active','revoked') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_venue_user_role (venue_id, user_id),
    INDEX idx_venue_user_user_status (user_id, status),
    CONSTRAINT fk_venue_user_role_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
    CONSTRAINT fk_venue_user_role_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE players
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
    ADD UNIQUE KEY uq_players_user (user_id),
    ADD CONSTRAINT fk_players_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
