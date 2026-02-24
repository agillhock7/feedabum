SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Dev legacy partner credential (kept for compatibility): admin@feedabum.local / DevPass!234
INSERT INTO partners (id, name, email, password_hash, created_at)
VALUES
    (1, 'Dark Horses USA', 'admin@feedabum.local', '$2y$10$FqAWmuo9J6BmtR3Zpvu9Z.9OWNOXk2eoaY6vvRuhOPdGOO1t6g6C2', UTC_TIMESTAMP()) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    password_hash = new.password_hash;

-- Admin/member users
-- owner@feedabum.local / OwnerPass!234
-- admin@feedabum.local / OutreachPass!234
-- demo@feedabum.local / DemoPass!234
-- member@feedabum.local / MemberPass!234
INSERT INTO users (id, partner_id, email, password_hash, display_name, role, status, created_at, last_login_at)
VALUES
    (1, 1, 'owner@feedabum.local', '$2y$10$6bYcT9ojaMszQ7LpFvyU0.3Cks/G5zyXvyty1lplY1rb1K030zp1W', 'Platform Owner', 'admin_owner', 'active', UTC_TIMESTAMP(), NULL),
    (2, 1, 'admin@feedabum.local', '$2y$10$3hJkl83b89osBQPjwZe51.sK4U5HYfQcPgjA37SzBO74Ijjerk1U.', 'Dark Horses Outreach Admin', 'admin_outreach', 'active', UTC_TIMESTAMP(), NULL),
    (3, 1, 'demo@feedabum.local', '$2y$10$/EY0o1.S3PG0l7bws0sNV.4E.e.6djJ/ByEn3pRVENszznR96tf72', 'Demo Admin', 'admin_demo', 'active', UTC_TIMESTAMP(), NULL),
    (4, NULL, 'member@feedabum.local', '$2y$10$iw9f6WZuKxpVNmzfXk7.ru09Z8UioBHFefwiEXjcoRT6nbeygMkme', 'Sample Community Member', 'member', 'active', UTC_TIMESTAMP(), NULL)
AS new
ON DUPLICATE KEY UPDATE
    partner_id = new.partner_id,
    password_hash = new.password_hash,
    display_name = new.display_name,
    role = new.role,
    status = new.status;

INSERT INTO recipients (
    id,
    partner_id,
    user_id,
    nickname,
    story,
    needs,
    zone,
    city,
    latitude,
    longitude,
    signup_source,
    onboarding_status,
    contact_email,
    contact_phone,
    verified_at,
    status,
    created_at,
    updated_at
)
VALUES
    (
        1,
        1,
        4,
        'Coach Ray',
        'Former maintenance worker rebuilding stability while mentoring neighborhood kids in Tucson.',
        'Hot meals, socks, and bus fare for local work search.',
        'Downtown Tucson',
        'Tucson, AZ',
        32.2217000,
        -110.9692000,
        'admin',
        'verified',
        NULL,
        NULL,
        UTC_TIMESTAMP(),
        'active',
        UTC_TIMESTAMP(),
        UTC_TIMESTAMP()
    ) AS new
ON DUPLICATE KEY UPDATE
    user_id = new.user_id,
    nickname = new.nickname,
    story = new.story,
    needs = new.needs,
    zone = new.zone,
    city = new.city,
    latitude = new.latitude,
    longitude = new.longitude,
    signup_source = new.signup_source,
    onboarding_status = new.onboarding_status,
    contact_email = new.contact_email,
    contact_phone = new.contact_phone,
    verified_at = new.verified_at,
    status = new.status,
    updated_at = UTC_TIMESTAMP();

-- Raw demo token (show once): demo-recipient-token-abc123
-- Hash generated with TOKEN_SIGNING_SECRET=dev_token_signing_secret_change_me
INSERT INTO recipient_tokens (recipient_id, token_hash, code_short, active, created_at, revoked_at)
VALUES
    (1, '4ef03556e32c062663334f8acf75a888a38b4a668025bc29883aab6fc4205de3', 'FAB1234', 1, UTC_TIMESTAMP(), NULL) AS new
ON DUPLICATE KEY UPDATE
    active = new.active,
    revoked_at = new.revoked_at;
