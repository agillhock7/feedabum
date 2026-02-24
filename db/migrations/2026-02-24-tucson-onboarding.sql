SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE recipients
    ADD COLUMN IF NOT EXISTS city VARCHAR(120) NOT NULL DEFAULT 'Tucson, AZ' AFTER zone,
    ADD COLUMN IF NOT EXISTS latitude DECIMAL(10,7) NULL AFTER city,
    ADD COLUMN IF NOT EXISTS longitude DECIMAL(10,7) NULL AFTER latitude,
    ADD COLUMN IF NOT EXISTS signup_source ENUM('admin', 'self') NOT NULL DEFAULT 'admin' AFTER longitude,
    ADD COLUMN IF NOT EXISTS onboarding_status ENUM('new', 'reviewed', 'verified') NOT NULL DEFAULT 'verified' AFTER signup_source,
    ADD COLUMN IF NOT EXISTS contact_email VARCHAR(191) NULL AFTER onboarding_status,
    ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(40) NULL AFTER contact_email;

ALTER TABLE recipients
    ADD INDEX IF NOT EXISTS idx_recipients_city (city),
    ADD INDEX IF NOT EXISTS idx_recipients_onboarding_status (onboarding_status),
    ADD INDEX IF NOT EXISTS idx_recipients_coords (latitude, longitude);

UPDATE recipients
SET city = 'Tucson, AZ'
WHERE city IS NULL OR city = '';
