SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @db_name = DATABASE();

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'recipients'
          AND COLUMN_NAME = 'city'
    ),
    'SELECT 1',
    'ALTER TABLE recipients ADD COLUMN city VARCHAR(120) NOT NULL DEFAULT ''Tucson, AZ'' AFTER zone'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'recipients'
          AND COLUMN_NAME = 'latitude'
    ),
    'SELECT 1',
    'ALTER TABLE recipients ADD COLUMN latitude DECIMAL(10,7) NULL AFTER city'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'recipients'
          AND COLUMN_NAME = 'longitude'
    ),
    'SELECT 1',
    'ALTER TABLE recipients ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'recipients'
          AND COLUMN_NAME = 'signup_source'
    ),
    'SELECT 1',
    'ALTER TABLE recipients ADD COLUMN signup_source ENUM(''admin'', ''self'') NOT NULL DEFAULT ''admin'' AFTER longitude'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'recipients'
          AND COLUMN_NAME = 'onboarding_status'
    ),
    'SELECT 1',
    'ALTER TABLE recipients ADD COLUMN onboarding_status ENUM(''new'', ''reviewed'', ''verified'') NOT NULL DEFAULT ''verified'' AFTER signup_source'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'recipients'
          AND COLUMN_NAME = 'contact_email'
    ),
    'SELECT 1',
    'ALTER TABLE recipients ADD COLUMN contact_email VARCHAR(191) NULL AFTER onboarding_status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'recipients'
          AND COLUMN_NAME = 'contact_phone'
    ),
    'SELECT 1',
    'ALTER TABLE recipients ADD COLUMN contact_phone VARCHAR(40) NULL AFTER contact_email'
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
          AND INDEX_NAME = 'idx_recipients_city'
    ),
    'SELECT 1',
    'ALTER TABLE recipients ADD INDEX idx_recipients_city (city)'
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
          AND INDEX_NAME = 'idx_recipients_onboarding_status'
    ),
    'SELECT 1',
    'ALTER TABLE recipients ADD INDEX idx_recipients_onboarding_status (onboarding_status)'
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
          AND INDEX_NAME = 'idx_recipients_coords'
    ),
    'SELECT 1',
    'ALTER TABLE recipients ADD INDEX idx_recipients_coords (latitude, longitude)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE recipients
SET city = 'Tucson, AZ'
WHERE city IS NULL OR city = '';
