ALTER TABLE orders
    MODIFY order_type ENUM('fourbag_set','bag_set','custom_board','league_host_fee') NOT NULL,
    ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD' AFTER subtotal_cents,
    ADD COLUMN checkout_token_hash CHAR(64) NULL AFTER status,
    ADD UNIQUE KEY uq_orders_checkout_token (checkout_token_hash);

CREATE TABLE payment_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(32) NOT NULL,
    provider_session_id VARCHAR(255) NULL,
    idempotency_key CHAR(64) NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    status ENUM('created','pending','paid','failed','cancelled') NOT NULL DEFAULT 'created',
    checkout_url TEXT NULL,
    failure_message VARCHAR(500) NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_payment_attempt_idempotency (idempotency_key),
    UNIQUE KEY uq_payment_attempt_provider_session (provider, provider_session_id),
    INDEX idx_payment_attempt_order_status (order_id, status),
    CONSTRAINT fk_payment_attempt_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    provider_event_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    provider_session_id VARCHAR(255) NULL,
    order_id BIGINT UNSIGNED NULL,
    payload_hash CHAR(64) NOT NULL,
    status ENUM('processed','ignored') NOT NULL DEFAULT 'processed',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_payment_event_provider_id (provider, provider_event_id),
    INDEX idx_payment_event_order (order_id),
    CONSTRAINT fk_payment_event_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE venue_invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue_id BIGINT UNSIGNED NOT NULL,
    season_id BIGINT UNSIGNED NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    invoice_type ENUM('league_host_fee') NOT NULL DEFAULT 'league_host_fee',
    amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    status ENUM('open','paid','void') NOT NULL DEFAULT 'open',
    due_date DATE NULL,
    description VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_venue_invoice_order (order_id),
    INDEX idx_venue_invoice_venue_status (venue_id, status),
    INDEX idx_venue_invoice_season (season_id),
    CONSTRAINT fk_venue_invoice_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
    CONSTRAINT fk_venue_invoice_season FOREIGN KEY (season_id) REFERENCES league_seasons(id) ON DELETE SET NULL,
    CONSTRAINT fk_venue_invoice_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_venue_invoice_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
