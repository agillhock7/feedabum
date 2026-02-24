SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Dev credentials: email admin@feedabum.local / password DevPass!234
INSERT INTO partners (id, name, email, password_hash, created_at)
VALUES
    (1, 'Downtown Outreach Partner', 'admin@feedabum.local', '$2y$10$FqAWmuo9J6BmtR3Zpvu9Z.9OWNOXk2eoaY6vvRuhOPdGOO1t6g6C2', UTC_TIMESTAMP()) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    password_hash = new.password_hash;

INSERT INTO recipients (id, partner_id, nickname, story, needs, zone, verified_at, status, created_at, updated_at)
VALUES
    (
        1,
        1,
        'Coach Ray',
        'Former maintenance worker rebuilding stability while mentoring neighborhood kids.',
        'Hot meals, socks, and bus fare for work search.',
        'Downtown Core',
        UTC_TIMESTAMP(),
        'active',
        UTC_TIMESTAMP(),
        UTC_TIMESTAMP()
    ) AS new
ON DUPLICATE KEY UPDATE
    nickname = new.nickname,
    story = new.story,
    needs = new.needs,
    zone = new.zone,
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
