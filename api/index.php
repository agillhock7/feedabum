<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

[$config, $pdo] = fab_bootstrap(true);

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = fab_route_path();

$throttle = new Throttle($pdo);
$tokenService = new TokenService($pdo, $config['TOKEN_SIGNING_SECRET']);
$stripeClient = new StripeClient($config['STRIPE_SECRET_KEY']);

switch ($method . ' ' . $path) {
    case 'GET /':
        Response::ok(['service' => 'Feed a Bum API']);

    case 'POST /auth/login':
        $input = fab_json_input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new HttpException(422, 'Email and password are required.');
        }

        $throttleKey = 'login:' . fab_client_ip() . ':' . $email;
        $attempt = $throttle->hit($throttleKey, $config['RATE_LIMIT_LOGIN_MAX'], $config['RATE_LIMIT_LOGIN_WINDOW']);
        if (!$attempt['allowed']) {
            Response::error('Too many login attempts.', 429, ['retry_after' => $attempt['retry_after']]);
        }

        $stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM partners WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $partner = $stmt->fetch();

        if (!$partner || !password_verify($password, (string) $partner['password_hash'])) {
            throw new HttpException(401, 'Invalid credentials.');
        }

        $throttle->clear($throttleKey);
        session_regenerate_id(true);
        $_SESSION['partner_id'] = (int) $partner['id'];

        fab_audit($pdo, 'partner', (int) $partner['id'], 'auth.login', ['email' => $email]);

        Response::ok([
            'partner' => [
                'id' => (int) $partner['id'],
                'name' => (string) $partner['name'],
                'email' => (string) $partner['email'],
            ],
        ]);

    case 'POST /auth/logout':
        $partnerId = $_SESSION['partner_id'] ?? null;
        if (is_int($partnerId)) {
            fab_audit($pdo, 'partner', $partnerId, 'auth.logout', []);
        }
        Session::destroy();
        Response::ok();

    case 'GET /recipient/by-token':
        $token = trim((string) ($_GET['token'] ?? ''));
        if ($token === '') {
            throw new HttpException(422, 'Token is required.');
        }

        $attempt = $throttle->hit('lookup:token:' . fab_client_ip(), $config['RATE_LIMIT_LOOKUP_MAX'], $config['RATE_LIMIT_LOOKUP_WINDOW']);
        if (!$attempt['allowed']) {
            Response::error('Too many lookup requests.', 429, ['retry_after' => $attempt['retry_after']]);
        }

        $tokenHash = $tokenService->hashToken($token);
        $recipient = fab_recipient_public_by_clause($pdo, 't.token_hash = :token_hash', ['token_hash' => $tokenHash]);
        if (!$recipient) {
            throw new HttpException(404, 'Recipient not found.');
        }

        Response::ok(['recipient' => $recipient]);

    case 'GET /recipient/by-code':
        $code = strtoupper(trim((string) ($_GET['code'] ?? '')));
        if ($code === '') {
            throw new HttpException(422, 'Code is required.');
        }

        $attempt = $throttle->hit('lookup:code:' . fab_client_ip(), $config['RATE_LIMIT_LOOKUP_MAX'], $config['RATE_LIMIT_LOOKUP_WINDOW']);
        if (!$attempt['allowed']) {
            Response::error('Too many lookup requests.', 429, ['retry_after' => $attempt['retry_after']]);
        }

        $recipient = fab_recipient_public_by_clause($pdo, 't.code_short = :code_short', ['code_short' => $code]);
        if (!$recipient) {
            throw new HttpException(404, 'Recipient not found.');
        }

        Response::ok(['recipient' => $recipient]);

    case 'POST /donation/create-intent':
        $attempt = $throttle->hit('donation:intent:' . fab_client_ip(), $config['RATE_LIMIT_DONATION_MAX'], $config['RATE_LIMIT_DONATION_WINDOW']);
        if (!$attempt['allowed']) {
            Response::error('Too many donation requests.', 429, ['retry_after' => $attempt['retry_after']]);
        }

        $input = fab_json_input();
        $recipientId = (int) ($input['recipient_id'] ?? 0);
        $amountCents = (int) ($input['amount_cents'] ?? 0);
        $currency = strtolower(trim((string) ($input['currency'] ?? 'usd')));
        $donorEmail = strtolower(trim((string) ($input['donor_email'] ?? '')));

        if ($recipientId <= 0 || $amountCents < 100) {
            throw new HttpException(422, 'recipient_id and minimum amount 100 cents are required.');
        }

        if (!in_array($currency, ['usd'], true)) {
            throw new HttpException(422, 'Only USD is supported in MVP.');
        }

        fab_require_active_recipient($pdo, $recipientId);

        $intent = $stripeClient->createPaymentIntent($amountCents, $currency, $recipientId, $donorEmail !== '' ? $donorEmail : null);

        $donorId = fab_create_or_get_donor($pdo, $donorEmail !== '' ? $donorEmail : null);

        $insert = $pdo->prepare(
            'INSERT INTO donations (donor_id, recipient_id, amount_cents, currency, stripe_payment_intent_id, status, created_at)
             VALUES (:donor_id, :recipient_id, :amount_cents, :currency, :stripe_payment_intent_id, :status, UTC_TIMESTAMP())'
        );
        $insert->execute([
            'donor_id' => $donorId,
            'recipient_id' => $recipientId,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'stripe_payment_intent_id' => (string) ($intent['id'] ?? ''),
            'status' => (string) ($intent['status'] ?? 'pending'),
        ]);

        Response::ok([
            'payment_intent_id' => (string) ($intent['id'] ?? ''),
            'client_secret' => (string) ($intent['client_secret'] ?? ''),
            'publishable_key' => (string) ($config['STRIPE_PUBLISHABLE_KEY'] ?? ''),
        ]);

    case 'POST /subscription/create':
        $attempt = $throttle->hit('subscription:create:' . fab_client_ip(), $config['RATE_LIMIT_DONATION_MAX'], $config['RATE_LIMIT_DONATION_WINDOW']);
        if (!$attempt['allowed']) {
            Response::error('Too many subscription requests.', 429, ['retry_after' => $attempt['retry_after']]);
        }

        $input = fab_json_input();
        $recipientId = (int) ($input['recipient_id'] ?? 0);
        $amountCents = (int) ($input['amount_cents'] ?? 0);
        $interval = trim((string) ($input['interval'] ?? 'month'));
        $donorEmail = strtolower(trim((string) ($input['donor_email'] ?? '')));

        if ($recipientId <= 0 || $amountCents < 100) {
            throw new HttpException(422, 'recipient_id and minimum amount 100 cents are required.');
        }

        if (!in_array($interval, ['week', 'month'], true)) {
            throw new HttpException(422, 'Interval must be week or month.');
        }

        fab_require_active_recipient($pdo, $recipientId);

        $successUrl = rtrim((string) $config['APP_BASE_URL'], '/') . '/donation-success?type=subscription';
        $cancelUrl = rtrim((string) $config['APP_BASE_URL'], '/') . '/';

        $session = $stripeClient->createSubscriptionCheckoutSession(
            $recipientId,
            $amountCents,
            $interval,
            $successUrl,
            $cancelUrl,
            $donorEmail !== '' ? $donorEmail : null
        );

        Response::ok([
            'checkout_session_id' => (string) ($session['id'] ?? ''),
            'checkout_url' => (string) ($session['url'] ?? ''),
        ]);

    case 'POST /webhook/stripe':
        fab_process_stripe_webhook($config, $pdo);

    case 'GET /admin/recipients':
        $partnerId = Auth::requirePartnerId();

        $stmt = $pdo->prepare(
            'SELECT
                r.id,
                r.nickname,
                r.story,
                r.needs,
                r.zone,
                r.verified_at,
                r.status,
                r.created_at,
                r.updated_at,
                rt.code_short,
                COALESCE((
                    SELECT SUM(w.amount_cents)
                    FROM wallet_ledger w
                    WHERE w.recipient_id = r.id AND w.type = "credit"
                ), 0) AS total_received_cents,
                COALESCE((
                    SELECT COUNT(DISTINCT d.donor_id)
                    FROM donations d
                    WHERE d.recipient_id = r.id AND d.status = "succeeded" AND d.donor_id IS NOT NULL
                ), 0) + COALESCE((
                    SELECT COUNT(DISTINCT s.donor_id)
                    FROM subscriptions s
                    WHERE s.recipient_id = r.id AND s.status IN ("active", "trialing") AND s.donor_id IS NOT NULL
                ), 0) AS supporters_count
             FROM recipients r
             LEFT JOIN (
                SELECT recipient_id, MAX(id) AS max_id
                FROM recipient_tokens
                WHERE active = 1
                GROUP BY recipient_id
             ) token_ptr ON token_ptr.recipient_id = r.id
             LEFT JOIN recipient_tokens rt ON rt.id = token_ptr.max_id
             WHERE r.partner_id = :partner_id
             ORDER BY r.created_at DESC'
        );
        $stmt->execute(['partner_id' => $partnerId]);
        $recipients = $stmt->fetchAll();

        $partner = Auth::currentPartner($pdo);

        Response::ok([
            'partner' => $partner,
            'recipients' => $recipients,
        ]);

    case 'POST /admin/recipient/create':
        $partnerId = Auth::requirePartnerId();
        $input = fab_json_input();

        $nickname = trim((string) ($input['nickname'] ?? ''));
        $story = trim((string) ($input['story'] ?? ''));
        $needs = trim((string) ($input['needs'] ?? ''));
        $zone = trim((string) ($input['zone'] ?? ''));
        $verified = (bool) ($input['verified'] ?? true);

        if ($nickname === '' || $story === '' || $needs === '' || $zone === '') {
            throw new HttpException(422, 'nickname, story, needs, and zone are required.');
        }

        $status = 'active';
        $verifiedAt = $verified ? gmdate('Y-m-d H:i:s') : null;

        $stmt = $pdo->prepare(
            'INSERT INTO recipients (partner_id, nickname, story, needs, zone, verified_at, status, created_at, updated_at)
             VALUES (:partner_id, :nickname, :story, :needs, :zone, :verified_at, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'partner_id' => $partnerId,
            'nickname' => $nickname,
            'story' => $story,
            'needs' => $needs,
            'zone' => $zone,
            'verified_at' => $verifiedAt,
            'status' => $status,
        ]);

        $recipientId = (int) $pdo->lastInsertId();
        $tokenData = $tokenService->createForRecipient($recipientId);

        fab_audit($pdo, 'partner', $partnerId, 'recipient.create', ['recipient_id' => $recipientId]);

        Response::ok([
            'recipient_id' => $recipientId,
            'token' => $tokenData['token'],
            'code_short' => $tokenData['code_short'],
        ], 201);

    case 'POST /admin/recipient/update':
        $partnerId = Auth::requirePartnerId();
        $input = fab_json_input();

        $recipientId = (int) ($input['recipient_id'] ?? 0);
        if ($recipientId <= 0) {
            throw new HttpException(422, 'recipient_id is required.');
        }

        fab_assert_partner_owns_recipient($pdo, $partnerId, $recipientId);

        $allowedStatus = ['active', 'suspended'];
        $updates = [];
        $params = ['recipient_id' => $recipientId, 'partner_id' => $partnerId];

        foreach (['nickname', 'story', 'needs', 'zone'] as $field) {
            if (array_key_exists($field, $input)) {
                $updates[] = $field . ' = :' . $field;
                $params[$field] = trim((string) $input[$field]);
            }
        }

        if (array_key_exists('status', $input)) {
            $status = trim((string) $input['status']);
            if (!in_array($status, $allowedStatus, true)) {
                throw new HttpException(422, 'status must be active or suspended.');
            }
            $updates[] = 'status = :status';
            $params['status'] = $status;
        }

        if (array_key_exists('verified', $input)) {
            $verified = (bool) $input['verified'];
            if ($verified) {
                $updates[] = 'verified_at = COALESCE(verified_at, UTC_TIMESTAMP())';
            } else {
                $updates[] = 'verified_at = NULL';
            }
        }

        if ($updates === []) {
            throw new HttpException(422, 'No updates were provided.');
        }

        $updates[] = 'updated_at = UTC_TIMESTAMP()';

        $sql = 'UPDATE recipients SET ' . implode(', ', $updates) . ' WHERE id = :recipient_id AND partner_id = :partner_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        fab_audit($pdo, 'partner', $partnerId, 'recipient.update', ['recipient_id' => $recipientId]);

        Response::ok();

    case 'POST /admin/recipient/rotate-token':
        $partnerId = Auth::requirePartnerId();
        $input = fab_json_input();
        $recipientId = (int) ($input['recipient_id'] ?? 0);

        if ($recipientId <= 0) {
            throw new HttpException(422, 'recipient_id is required.');
        }

        fab_assert_partner_owns_recipient($pdo, $partnerId, $recipientId);

        $tokenService->revokeActiveTokens($recipientId);
        $tokenData = $tokenService->createForRecipient($recipientId);

        fab_audit($pdo, 'partner', $partnerId, 'recipient.rotate_token', ['recipient_id' => $recipientId]);

        Response::ok([
            'recipient_id' => $recipientId,
            'token' => $tokenData['token'],
            'code_short' => $tokenData['code_short'],
        ]);

    default:
        Response::error('Route not found.', 404, ['path' => $path, 'method' => $method]);
}

function fab_route_path(): string
{
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($uriPath) && $uriPath !== '' ? $uriPath : '/';

    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/api/index.php');
    $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($base !== '' && $base !== '.' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }

    if ($path === '' || $path === false) {
        return '/';
    }

    $normalized = '/' . ltrim($path, '/');
    return $normalized === '/' ? '/' : rtrim($normalized, '/');
}

function fab_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new HttpException(400, 'Invalid JSON payload.');
    }

    return $decoded;
}

function fab_client_ip(): string
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown';

    if (str_contains($ip, ',')) {
        $parts = explode(',', $ip);
        return trim($parts[0]);
    }

    return trim((string) $ip);
}

function fab_recipient_public_by_clause(PDO $pdo, string $whereClause, array $params): ?array
{
    $sql = 'SELECT
                r.id,
                r.nickname,
                r.story,
                r.needs,
                r.zone,
                r.verified_at,
                p.name AS verified_by_partner,
                COALESCE((
                    SELECT SUM(w.amount_cents)
                    FROM wallet_ledger w
                    WHERE w.recipient_id = r.id AND w.type = "credit"
                ), 0) AS total_received_cents,
                COALESCE((
                    SELECT COUNT(DISTINCT d.donor_id)
                    FROM donations d
                    WHERE d.recipient_id = r.id AND d.status = "succeeded" AND d.donor_id IS NOT NULL
                ), 0) + COALESCE((
                    SELECT COUNT(DISTINCT s.donor_id)
                    FROM subscriptions s
                    WHERE s.recipient_id = r.id AND s.status IN ("active", "trialing") AND s.donor_id IS NOT NULL
                ), 0) AS supporters_count
            FROM recipient_tokens t
            INNER JOIN recipients r ON r.id = t.recipient_id
            INNER JOIN partners p ON p.id = r.partner_id
            WHERE ' . $whereClause . ' AND t.active = 1 AND r.status = "active"
            ORDER BY t.id DESC
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recipient = $stmt->fetch();

    return $recipient ?: null;
}

function fab_require_active_recipient(PDO $pdo, int $recipientId): void
{
    $stmt = $pdo->prepare('SELECT id FROM recipients WHERE id = :id AND status = :status LIMIT 1');
    $stmt->execute([
        'id' => $recipientId,
        'status' => 'active',
    ]);

    if (!$stmt->fetch()) {
        throw new HttpException(404, 'Recipient not found or inactive.');
    }
}

function fab_assert_partner_owns_recipient(PDO $pdo, int $partnerId, int $recipientId): void
{
    $stmt = $pdo->prepare('SELECT id FROM recipients WHERE id = :id AND partner_id = :partner_id LIMIT 1');
    $stmt->execute([
        'id' => $recipientId,
        'partner_id' => $partnerId,
    ]);

    if (!$stmt->fetch()) {
        throw new HttpException(404, 'Recipient not found for this partner.');
    }
}

function fab_audit(PDO $pdo, string $actorType, int $actorId, string $action, array $meta): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (actor_type, actor_id, action, meta_json, created_at)
         VALUES (:actor_type, :actor_id, :action, :meta_json, UTC_TIMESTAMP())'
    );
    $stmt->execute([
        'actor_type' => $actorType,
        'actor_id' => $actorId,
        'action' => $action,
        'meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function fab_create_or_get_donor(PDO $pdo, ?string $email): ?int
{
    if ($email === null || $email === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM donors WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();

    if ($row) {
        return (int) $row['id'];
    }

    $insert = $pdo->prepare('INSERT INTO donors (email, created_at) VALUES (:email, UTC_TIMESTAMP())');
    $insert->execute(['email' => $email]);

    return (int) $pdo->lastInsertId();
}
