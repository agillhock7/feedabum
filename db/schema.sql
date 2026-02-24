SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS partners (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(191) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id BIGINT UNSIGNED NOT NULL,
    nickname VARCHAR(120) NOT NULL,
    story TEXT NOT NULL,
    needs TEXT NOT NULL,
    zone VARCHAR(120) NOT NULL,
    city VARCHAR(120) NOT NULL DEFAULT 'Tucson, AZ',
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    signup_source ENUM('admin', 'self') NOT NULL DEFAULT 'admin',
    onboarding_status ENUM('new', 'reviewed', 'verified') NOT NULL DEFAULT 'verified',
    contact_email VARCHAR(191) NULL,
    contact_phone VARCHAR(40) NULL,
    verified_at DATETIME NULL,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_recipients_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE CASCADE,
    INDEX idx_recipients_partner (partner_id),
    INDEX idx_recipients_status (status),
    INDEX idx_recipients_city (city),
    INDEX idx_recipients_onboarding_status (onboarding_status),
    INDEX idx_recipients_coords (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipient_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    code_short VARCHAR(16) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    CONSTRAINT fk_recipient_tokens_recipient FOREIGN KEY (recipient_id) REFERENCES recipients (id) ON DELETE CASCADE,
    UNIQUE KEY uk_recipient_token_hash (token_hash),
    UNIQUE KEY uk_recipient_code_short (code_short),
    INDEX idx_recipient_tokens_recipient_active (recipient_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS donors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS donations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_id BIGINT UNSIGNED NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'usd',
    stripe_payment_intent_id VARCHAR(191) NULL UNIQUE,
    status VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_donations_donor FOREIGN KEY (donor_id) REFERENCES donors (id) ON DELETE SET NULL,
    CONSTRAINT fk_donations_recipient FOREIGN KEY (recipient_id) REFERENCES recipients (id) ON DELETE CASCADE,
    INDEX idx_donations_recipient (recipient_id),
    INDEX idx_donations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_id BIGINT UNSIGNED NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    `interval` ENUM('week', 'month') NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    stripe_subscription_id VARCHAR(191) NULL UNIQUE,
    status VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_subscriptions_donor FOREIGN KEY (donor_id) REFERENCES donors (id) ON DELETE SET NULL,
    CONSTRAINT fk_subscriptions_recipient FOREIGN KEY (recipient_id) REFERENCES recipients (id) ON DELETE CASCADE,
    INDEX idx_subscriptions_recipient (recipient_id),
    INDEX idx_subscriptions_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_id BIGINT UNSIGNED NOT NULL,
    type ENUM('credit', 'debit') NOT NULL,
    amount_cents INT NOT NULL,
    category VARCHAR(64) NULL,
    ref_type VARCHAR(64) NOT NULL,
    ref_id VARCHAR(191) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_ledger_recipient FOREIGN KEY (recipient_id) REFERENCES recipients (id) ON DELETE CASCADE,
    INDEX idx_wallet_ledger_recipient (recipient_id),
    INDEX idx_wallet_ledger_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type VARCHAR(32) NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    meta_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_actor (actor_type, actor_id),
    INDEX idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS throttle (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(191) NOT NULL,
    count INT UNSIGNED NOT NULL DEFAULT 0,
    reset_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_throttle_key (`key`),
    INDEX idx_throttle_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
