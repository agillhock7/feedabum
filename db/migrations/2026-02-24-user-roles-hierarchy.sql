SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @db_name = DATABASE();

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id BIGINT UNSIGNED NULL,
    email VARCHAR(191) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    role ENUM('member', 'admin_owner', 'admin_outreach', 'admin_demo') NOT NULL DEFAULT 'member',
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    CONSTRAINT fk_users_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE SET NULL,
    INDEX idx_users_role (role),
    INDEX idx_users_partner (partner_id),
    INDEX idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'recipients'
          AND COLUMN_NAME = 'user_id'
    ),
    'SELECT 1',
    'ALTER TABLE recipients ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER partner_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'recipients'
          AND INDEX_NAME = 'idx_recipients_user'
    ),
    'SELECT 1',
    'ALTER TABLE recipients ADD INDEX idx_recipients_user (user_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'recipients'
          AND CONSTRAINT_NAME = 'fk_recipients_user'
    ),
    'SELECT 1',
    'ALTER TABLE recipients ADD CONSTRAINT fk_recipients_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'donors'
          AND COLUMN_NAME = 'user_id'
    ),
    'SELECT 1',
    'ALTER TABLE donors ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'donors'
          AND INDEX_NAME = 'idx_donors_user'
    ),
    'SELECT 1',
    'ALTER TABLE donors ADD INDEX idx_donors_user (user_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'donors'
          AND CONSTRAINT_NAME = 'fk_donors_user'
    ),
    'SELECT 1',
    'ALTER TABLE donors ADD CONSTRAINT fk_donors_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Baseline admin roles for partner 1 (Dark Horses USA in seed)
INSERT INTO users (partner_id, email, password_hash, display_name, role, status, created_at)
SELECT p.id, 'owner@feedabum.local', '$2y$10$6bYcT9ojaMszQ7LpFvyU0.3Cks/G5zyXvyty1lplY1rb1K030zp1W', 'Platform Owner', 'admin_owner', 'active', UTC_TIMESTAMP()
FROM partners p
WHERE p.id = 1
  AND NOT EXISTS (SELECT 1 FROM users WHERE email = 'owner@feedabum.local');

INSERT INTO users (partner_id, email, password_hash, display_name, role, status, created_at)
SELECT p.id, 'admin@feedabum.local', '$2y$10$FqAWmuo9J6BmtR3Zpvu9Z.9OWNOXk2eoaY6vvRuhOPdGOO1t6g6C2', 'Dark Horses Outreach Admin', 'admin_outreach', 'active', UTC_TIMESTAMP()
FROM partners p
WHERE p.id = 1
  AND NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@feedabum.local');

INSERT INTO users (partner_id, email, password_hash, display_name, role, status, created_at)
SELECT p.id, 'demo@feedabum.local', '$2y$10$/EY0o1.S3PG0l7bws0sNV.4E.e.6djJ/ByEn3pRVENszznR96tf72', 'Demo Admin', 'admin_demo', 'active', UTC_TIMESTAMP()
FROM partners p
WHERE p.id = 1
  AND NOT EXISTS (SELECT 1 FROM users WHERE email = 'demo@feedabum.local');
