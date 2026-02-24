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
        Response::ok([
            'service' => 'Feed a Bum API',
            'demo_login_enabled' => (bool) $config['DEMO_LOGIN_ENABLED'],
            'demo_login_email' => (string) $config['DEMO_LOGIN_EMAIL'],
        ]);

    case 'POST /auth/login':
        $input = fab_json_input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new HttpException(422, 'Email and password are required.');
        }

        $throttleKey = 'login:admin:' . fab_client_ip() . ':' . $email;
        $attempt = $throttle->hit($throttleKey, $config['RATE_LIMIT_LOGIN_MAX'], $config['RATE_LIMIT_LOGIN_WINDOW']);
        if (!$attempt['allowed']) {
            Response::error('Too many login attempts.', 429, ['retry_after' => $attempt['retry_after']]);
        }

        $stmt = $pdo->prepare(
            'SELECT id, partner_id, email, password_hash, display_name, role, status
             FROM users
             WHERE email = :email
               AND role IN ("admin_owner", "admin_outreach", "admin_demo")
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, (string) $admin['password_hash'])) {
            throw new HttpException(401, 'Invalid credentials.');
        }

        if (($admin['status'] ?? 'disabled') !== 'active') {
            throw new HttpException(403, 'Admin account is disabled.');
        }

        $isDemoRole = (string) $admin['role'] === 'admin_demo';
        if ($isDemoRole) {
            if (!(bool) $config['DEMO_LOGIN_ENABLED']) {
                throw new HttpException(403, 'Demo login is currently disabled.');
            }

            if ($email !== (string) $config['DEMO_LOGIN_EMAIL']) {
                throw new HttpException(403, 'This demo account is not allowed by current demo settings.');
            }
        }

        $throttle->clear($throttleKey);
        session_regenerate_id(true);

        $_SESSION['admin_user_id'] = (int) $admin['id'];
        $_SESSION['admin_role'] = (string) $admin['role'];
        $_SESSION['admin_partner_id'] = isset($admin['partner_id']) ? (int) $admin['partner_id'] : null;
        $_SESSION['is_demo'] = $isDemoRole;

        $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => $admin['id']]);

        fab_audit($pdo, 'user', (int) $admin['id'], 'auth.login.admin', ['email' => $email, 'role' => $admin['role']]);

        Response::ok([
            'admin' => [
                'id' => (int) $admin['id'],
                'partner_id' => isset($admin['partner_id']) ? (int) $admin['partner_id'] : null,
                'email' => (string) $admin['email'],
                'display_name' => (string) $admin['display_name'],
                'role' => (string) $admin['role'],
            ],
            'is_demo' => $isDemoRole,
            'demo_login_enabled' => (bool) $config['DEMO_LOGIN_ENABLED'],
        ]);

    case 'POST /auth/logout':
        $adminUserId = $_SESSION['admin_user_id'] ?? null;
        if (is_int($adminUserId)) {
            fab_audit($pdo, 'user', $adminUserId, 'auth.logout.admin', []);
        }
        Session::destroy();
        Response::ok();

    case 'POST /auth/password/forgot':
        $input = fab_json_input();
        $email = fab_required_email((string) ($input['email'] ?? ''));

        $limitMax = (int) $config['RATE_LIMIT_PASSWORD_RESET_MAX'];
        $limitWindow = (int) $config['RATE_LIMIT_PASSWORD_RESET_WINDOW'];

        $ipAttempt = $throttle->hit('password-reset:ip:' . fab_client_ip(), $limitMax, $limitWindow);
        if (!$ipAttempt['allowed']) {
            Response::error('Too many password reset requests.', 429, ['retry_after' => $ipAttempt['retry_after']]);
        }

        $emailAttempt = $throttle->hit('password-reset:email:' . sha1($email), $limitMax, $limitWindow);
        if (!$emailAttempt['allowed']) {
            Response::error('Too many password reset requests.', 429, ['retry_after' => $emailAttempt['retry_after']]);
        }

        $stmt = $pdo->prepare(
            'SELECT id, email, display_name, status
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && (string) ($user['status'] ?? 'disabled') === 'active') {
            $ttlMinutes = max(10, (int) $config['PASSWORD_RESET_TTL_MINUTES']);
            $tokenRaw = bin2hex(random_bytes(32));
            $tokenHash = fab_password_reset_token_hash($tokenRaw, (string) $config['TOKEN_SIGNING_SECRET']);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'UPDATE password_reset_tokens
                     SET used_at = UTC_TIMESTAMP()
                     WHERE user_id = :user_id
                       AND used_at IS NULL
                       AND expires_at > UTC_TIMESTAMP()'
                )->execute(['user_id' => $user['id']]);

                $pdo->prepare(
                    'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, used_at, request_ip, user_agent, created_at)
                     VALUES (:user_id, :token_hash, :expires_at, NULL, :request_ip, :user_agent, UTC_TIMESTAMP())'
                )->execute([
                    'user_id' => $user['id'],
                    'token_hash' => $tokenHash,
                    'expires_at' => $expiresAt,
                    'request_ip' => fab_client_ip(),
                    'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]);

                $pdo->commit();
            } catch (Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            $resetLink = rtrim((string) $config['APP_BASE_URL'], '/') . '/password-reset?token=' . urlencode($tokenRaw);
            $displayName = (string) ($user['display_name'] ?? 'there');
            $sent = fab_send_password_reset_email(
                $config,
                (string) $user['email'],
                $displayName,
                $resetLink,
                $ttlMinutes
            );

            if (!$sent) {
                error_log('Feed A Bum password reset email failed for user_id=' . (string) $user['id']);
            }

            fab_audit($pdo, 'user', (int) $user['id'], 'auth.password_reset.request', []);
        }

        Response::ok([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);

    case 'POST /auth/password/reset':
        $input = fab_json_input();
        $token = trim((string) ($input['token'] ?? ''));
        $newPassword = (string) ($input['new_password'] ?? '');

        if ($token === '') {
            throw new HttpException(422, 'token is required.');
        }
        if (strlen($newPassword) < 8) {
            throw new HttpException(422, 'new_password must be at least 8 characters.');
        }

        $tokenHash = fab_password_reset_token_hash($token, (string) $config['TOKEN_SIGNING_SECRET']);

        $stmt = $pdo->prepare(
            'SELECT pr.id, pr.user_id, u.status
             FROM password_reset_tokens pr
             INNER JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = :token_hash
               AND pr.used_at IS NULL
               AND pr.expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $resetRow = $stmt->fetch();

        if (!$resetRow) {
            throw new HttpException(400, 'Reset token is invalid or expired.');
        }

        if ((string) ($resetRow['status'] ?? 'disabled') !== 'active') {
            throw new HttpException(403, 'Account is disabled.');
        }

        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE users
                 SET password_hash = :password_hash
                 WHERE id = :user_id'
            )->execute([
                'password_hash' => $passwordHash,
                'user_id' => $resetRow['user_id'],
            ]);

            $pdo->prepare(
                'UPDATE password_reset_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE id = :id
                    OR (user_id = :user_id AND used_at IS NULL)'
            )->execute([
                'id' => $resetRow['id'],
                'user_id' => $resetRow['user_id'],
            ]);

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        fab_audit($pdo, 'user', (int) $resetRow['user_id'], 'auth.password_reset.complete', []);

        Response::ok([
            'message' => 'Password has been reset successfully.',
        ]);

    case 'POST /user/register':
        $input = fab_json_input();

        $email = fab_required_email((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $displayName = trim((string) ($input['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = strstr($email, '@', true) ?: 'Community Member';
        }

        if (strlen($password) < 8) {
            throw new HttpException(422, 'Password must be at least 8 characters.');
        }

        $userId = fab_create_member_user($pdo, $email, $password, $displayName);

        Response::ok([
            'user_id' => $userId,
            'email' => $email,
            'display_name' => $displayName,
        ], 201);

    case 'POST /user/login':
        $input = fab_json_input();

        $email = fab_required_email((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        $throttleKey = 'login:user:' . fab_client_ip() . ':' . $email;
        $attempt = $throttle->hit($throttleKey, $config['RATE_LIMIT_LOGIN_MAX'], $config['RATE_LIMIT_LOGIN_WINDOW']);
        if (!$attempt['allowed']) {
            Response::error('Too many login attempts.', 429, ['retry_after' => $attempt['retry_after']]);
        }

        $stmt = $pdo->prepare(
            'SELECT id, email, password_hash, display_name, role, status
             FROM users
             WHERE email = :email
               AND role = "member"
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            throw new HttpException(401, 'Invalid credentials.');
        }

        if (($user['status'] ?? 'disabled') !== 'active') {
            throw new HttpException(403, 'User account is disabled.');
        }

        $throttle->clear($throttleKey);
        $_SESSION['member_user_id'] = (int) $user['id'];
        $_SESSION['member_user_email'] = (string) $user['email'];

        $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => $user['id']]);

        fab_audit($pdo, 'user', (int) $user['id'], 'auth.login.member', ['email' => $email]);

        Response::ok([
            'user' => [
                'id' => (int) $user['id'],
                'email' => (string) $user['email'],
                'display_name' => (string) $user['display_name'],
            ],
        ]);

    case 'POST /user/logout':
        unset($_SESSION['member_user_id'], $_SESSION['member_user_email']);
        Response::ok();

    case 'GET /user/me':
        $memberUserId = $_SESSION['member_user_id'] ?? null;
        if (!is_int($memberUserId)) {
            Response::ok(['user' => null]);
        }

        $stmt = $pdo->prepare('SELECT id, email, display_name, role, status FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $memberUserId]);
        $user = $stmt->fetch();

        if (!$user || (string) $user['role'] !== 'member') {
            Response::ok(['user' => null]);
        }

        Response::ok([
            'user' => [
                'id' => (int) $user['id'],
                'email' => (string) $user['email'],
                'display_name' => (string) $user['display_name'],
                'status' => (string) $user['status'],
            ],
        ]);

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

    case 'POST /recipient/signup':
        $attempt = $throttle->hit('recipient:signup:' . fab_client_ip(), $config['RATE_LIMIT_SIGNUP_MAX'], $config['RATE_LIMIT_SIGNUP_WINDOW']);
        if (!$attempt['allowed']) {
            Response::error('Too many signup attempts.', 429, ['retry_after' => $attempt['retry_after']]);
        }

        $input = fab_json_input();

        $nickname = trim((string) ($input['nickname'] ?? ''));
        $story = trim((string) ($input['story'] ?? ''));
        $needs = trim((string) ($input['needs'] ?? ''));
        $zone = trim((string) ($input['zone'] ?? ''));
        $city = trim((string) ($input['city'] ?? $config['DEFAULT_CITY']));
        $contactEmail = fab_required_email((string) ($input['contact_email'] ?? ''));
        $accountPassword = (string) ($input['account_password'] ?? '');
        $contactPhone = fab_optional_phone($input['contact_phone'] ?? null);
        $latitude = fab_optional_coordinate($input['latitude'] ?? null, -90.0, 90.0, 'latitude');
        $longitude = fab_optional_coordinate($input['longitude'] ?? null, -180.0, 180.0, 'longitude');
        $partnerId = (int) $config['DEFAULT_PARTNER_ID'];

        if ($nickname === '' || $story === '' || $needs === '' || $zone === '') {
            throw new HttpException(422, 'nickname, story, needs, and zone are required.');
        }

        if (strlen($accountPassword) < 8) {
            throw new HttpException(422, 'account_password must be at least 8 characters.');
        }

        if ($city === '') {
            $city = (string) $config['DEFAULT_CITY'];
        }

        fab_require_partner_exists($pdo, $partnerId);

        $existingStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $existingStmt->execute(['email' => $contactEmail]);
        if ($existingStmt->fetch()) {
            throw new HttpException(409, 'An account with this email already exists. Use login instead.');
        }

        $pdo->beginTransaction();
        try {
            $memberUserId = fab_create_member_user($pdo, $contactEmail, $accountPassword, $nickname);

            $stmt = $pdo->prepare(
                'INSERT INTO recipients (
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
                 ) VALUES (
                    :partner_id,
                    :user_id,
                    :nickname,
                    :story,
                    :needs,
                    :zone,
                    :city,
                    :latitude,
                    :longitude,
                    :signup_source,
                    :onboarding_status,
                    :contact_email,
                    :contact_phone,
                    NULL,
                    :status,
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP()
                 )'
            );
            $stmt->execute([
                'partner_id' => $partnerId,
                'user_id' => $memberUserId,
                'nickname' => $nickname,
                'story' => $story,
                'needs' => $needs,
                'zone' => $zone,
                'city' => $city,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'signup_source' => 'self',
                'onboarding_status' => 'new',
                'contact_email' => $contactEmail,
                'contact_phone' => $contactPhone,
                'status' => 'active',
            ]);

            $recipientId = (int) $pdo->lastInsertId();
            $tokenData = $tokenService->createForRecipient($recipientId);

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        fab_audit($pdo, 'user', $memberUserId, 'recipient.self_signup', [
            'recipient_id' => $recipientId,
            'city' => $city,
            'zone' => $zone,
        ]);

        $recipientUrl = rtrim((string) $config['APP_BASE_URL'], '/') . '/recipient?token=' . urlencode($tokenData['token']);

        Response::ok([
            'recipient_id' => $recipientId,
            'user_id' => $memberUserId,
            'onboarding_status' => 'new',
            'token' => $tokenData['token'],
            'code_short' => $tokenData['code_short'],
            'recipient_url' => $recipientUrl,
        ], 201);

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

        $memberUserId = $_SESSION['member_user_id'] ?? null;
        if (!is_int($memberUserId)) {
            $memberUserId = null;
        }

        if ($donorEmail === '' && $memberUserId !== null) {
            $emailStmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
            $emailStmt->execute(['id' => $memberUserId]);
            $emailRow = $emailStmt->fetch();
            $donorEmail = strtolower(trim((string) ($emailRow['email'] ?? '')));
        }

        $intent = $stripeClient->createPaymentIntent($amountCents, $currency, $recipientId, $donorEmail !== '' ? $donorEmail : null);

        $donorId = fab_create_or_get_donor($pdo, $donorEmail !== '' ? $donorEmail : null, $memberUserId);

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
        $adminContext = Auth::requireAdminContext();

        $where = '1=1';
        $params = [];

        $requestedPartnerId = isset($_GET['partner_id']) ? (int) $_GET['partner_id'] : null;
        if (!Auth::canManageAllPartners($adminContext)) {
            if (!is_int($adminContext['partner_id'])) {
                throw new HttpException(403, 'Partner scope is missing for this admin.');
            }
            $where = 'r.partner_id = :partner_id';
            $params['partner_id'] = $adminContext['partner_id'];
        } elseif ($requestedPartnerId && $requestedPartnerId > 0) {
            $where = 'r.partner_id = :partner_id';
            $params['partner_id'] = $requestedPartnerId;
        }

        $stmt = $pdo->prepare(
            'SELECT
                r.id,
                r.partner_id,
                p.name AS partner_name,
                r.user_id,
                r.nickname,
                r.story,
                r.needs,
                r.zone,
                r.city,
                r.latitude,
                r.longitude,
                r.signup_source,
                r.onboarding_status,
                r.contact_email,
                r.contact_phone,
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
             INNER JOIN partners p ON p.id = r.partner_id
             LEFT JOIN (
                SELECT recipient_id, MAX(id) AS max_id
                FROM recipient_tokens
                WHERE active = 1
                GROUP BY recipient_id
             ) token_ptr ON token_ptr.recipient_id = r.id
             LEFT JOIN recipient_tokens rt ON rt.id = token_ptr.max_id
             WHERE ' . $where . '
             ORDER BY r.created_at DESC'
        );
        $stmt->execute($params);
        $recipients = array_map('fab_normalize_recipient_row', $stmt->fetchAll());

        $summaryStmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS total_recipients,
                SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) AS active_recipients,
                SUM(CASE WHEN signup_source = "self" THEN 1 ELSE 0 END) AS self_signup_count,
                SUM(CASE WHEN onboarding_status = "new" THEN 1 ELSE 0 END) AS new_onboarding_count
             FROM recipients r
             WHERE ' . $where
        );
        $summaryStmt->execute($params);
        $summary = $summaryStmt->fetch() ?: [
            'total_recipients' => 0,
            'active_recipients' => 0,
            'self_signup_count' => 0,
            'new_onboarding_count' => 0,
        ];

        $admin = Auth::currentAdmin($pdo);
        $partner = Auth::currentPartner($pdo);

        Response::ok([
            'admin' => $admin,
            'partner' => $partner,
            'summary' => $summary,
            'recipients' => $recipients,
            'is_demo' => Auth::isDemoSession(),
            'can_manage_all' => Auth::canManageAllPartners($adminContext),
            'demo_login_enabled' => (bool) $config['DEMO_LOGIN_ENABLED'],
        ]);

    case 'POST /admin/recipient/create':
        $adminContext = Auth::requireAdminContext();
        Auth::requireWritableSession();
        $input = fab_json_input();

        $nickname = trim((string) ($input['nickname'] ?? ''));
        $story = trim((string) ($input['story'] ?? ''));
        $needs = trim((string) ($input['needs'] ?? ''));
        $zone = trim((string) ($input['zone'] ?? ''));
        $city = trim((string) ($input['city'] ?? $config['DEFAULT_CITY']));
        $latitude = fab_optional_coordinate($input['latitude'] ?? null, -90.0, 90.0, 'latitude');
        $longitude = fab_optional_coordinate($input['longitude'] ?? null, -180.0, 180.0, 'longitude');
        $contactEmail = fab_optional_email($input['contact_email'] ?? null);
        $contactPhone = fab_optional_phone($input['contact_phone'] ?? null);
        $verified = (bool) ($input['verified'] ?? true);

        if ($nickname === '' || $story === '' || $needs === '' || $zone === '') {
            throw new HttpException(422, 'nickname, story, needs, and zone are required.');
        }

        $partnerId = fab_resolve_target_partner_id($pdo, $adminContext, $input['partner_id'] ?? null, (int) $config['DEFAULT_PARTNER_ID']);

        $status = 'active';
        $verifiedAt = $verified ? gmdate('Y-m-d H:i:s') : null;
        $onboardingStatus = $verified ? 'verified' : 'reviewed';

        $stmt = $pdo->prepare(
            'INSERT INTO recipients (
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
             ) VALUES (
                :partner_id,
                NULL,
                :nickname,
                :story,
                :needs,
                :zone,
                :city,
                :latitude,
                :longitude,
                :signup_source,
                :onboarding_status,
                :contact_email,
                :contact_phone,
                :verified_at,
                :status,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
             )'
        );
        $stmt->execute([
            'partner_id' => $partnerId,
            'nickname' => $nickname,
            'story' => $story,
            'needs' => $needs,
            'zone' => $zone,
            'city' => $city,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'signup_source' => 'admin',
            'onboarding_status' => $onboardingStatus,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
            'verified_at' => $verifiedAt,
            'status' => $status,
        ]);

        $recipientId = (int) $pdo->lastInsertId();
        $tokenData = $tokenService->createForRecipient($recipientId);

        fab_audit($pdo, 'user', (int) $adminContext['user_id'], 'recipient.create', ['recipient_id' => $recipientId]);

        Response::ok([
            'recipient_id' => $recipientId,
            'token' => $tokenData['token'],
            'code_short' => $tokenData['code_short'],
        ], 201);

    case 'POST /admin/recipient/update':
        $adminContext = Auth::requireAdminContext();
        Auth::requireWritableSession();
        $input = fab_json_input();

        $recipientId = (int) ($input['recipient_id'] ?? 0);
        if ($recipientId <= 0) {
            throw new HttpException(422, 'recipient_id is required.');
        }

        fab_assert_admin_can_access_recipient($pdo, $adminContext, $recipientId);

        $allowedStatus = ['active', 'suspended'];
        $updates = [];
        $params = ['recipient_id' => $recipientId];

        foreach (['nickname', 'story', 'needs', 'zone', 'city', 'contact_email', 'contact_phone'] as $field) {
            if (array_key_exists($field, $input)) {
                $updates[] = $field . ' = :' . $field;
                if ($field === 'contact_email') {
                    $params[$field] = fab_optional_email($input[$field]);
                    continue;
                }
                if ($field === 'contact_phone') {
                    $params[$field] = fab_optional_phone($input[$field]);
                    continue;
                }
                $params[$field] = trim((string) $input[$field]);
            }
        }

        foreach (['latitude' => [-90.0, 90.0], 'longitude' => [-180.0, 180.0]] as $field => [$min, $max]) {
            if (array_key_exists($field, $input)) {
                $updates[] = $field . ' = :' . $field;
                $params[$field] = fab_optional_coordinate($input[$field], $min, $max, $field);
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

        if (array_key_exists('onboarding_status', $input)) {
            $onboardingStatus = trim((string) $input['onboarding_status']);
            if (!in_array($onboardingStatus, ['new', 'reviewed', 'verified'], true)) {
                throw new HttpException(422, 'onboarding_status must be new, reviewed, or verified.');
            }
            $updates[] = 'onboarding_status = :onboarding_status';
            $params['onboarding_status'] = $onboardingStatus;

            if (!array_key_exists('verified', $input)) {
                if ($onboardingStatus === 'verified') {
                    $updates[] = 'verified_at = COALESCE(verified_at, UTC_TIMESTAMP())';
                } else {
                    $updates[] = 'verified_at = NULL';
                }
            }
        }

        if (array_key_exists('verified', $input)) {
            $verified = (bool) $input['verified'];
            if ($verified) {
                $updates[] = 'verified_at = COALESCE(verified_at, UTC_TIMESTAMP())';
                if (!array_key_exists('onboarding_status', $input)) {
                    $updates[] = 'onboarding_status = :verified_onboarding_status';
                    $params['verified_onboarding_status'] = 'verified';
                }
            } else {
                $updates[] = 'verified_at = NULL';
                if (!array_key_exists('onboarding_status', $input)) {
                    $updates[] = 'onboarding_status = :reviewed_onboarding_status';
                    $params['reviewed_onboarding_status'] = 'reviewed';
                }
            }
        }

        if ($updates === []) {
            throw new HttpException(422, 'No updates were provided.');
        }

        $updates[] = 'updated_at = UTC_TIMESTAMP()';

        $sql = 'UPDATE recipients SET ' . implode(', ', $updates) . ' WHERE id = :recipient_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        fab_audit($pdo, 'user', (int) $adminContext['user_id'], 'recipient.update', ['recipient_id' => $recipientId]);

        Response::ok();

    case 'POST /admin/recipient/rotate-token':
        $adminContext = Auth::requireAdminContext();
        Auth::requireWritableSession();
        $input = fab_json_input();
        $recipientId = (int) ($input['recipient_id'] ?? 0);

        if ($recipientId <= 0) {
            throw new HttpException(422, 'recipient_id is required.');
        }

        fab_assert_admin_can_access_recipient($pdo, $adminContext, $recipientId);

        $tokenService->revokeActiveTokens($recipientId);
        $tokenData = $tokenService->createForRecipient($recipientId);

        fab_audit($pdo, 'user', (int) $adminContext['user_id'], 'recipient.rotate_token', ['recipient_id' => $recipientId]);

        Response::ok([
            'recipient_id' => $recipientId,
            'token' => $tokenData['token'],
            'code_short' => $tokenData['code_short'],
        ]);

    case 'GET /admin/users':
        $adminContext = Auth::requireAdminContext();

        if ($adminContext['role'] !== 'admin_owner') {
            throw new HttpException(403, 'Only owner admin can view admin users.');
        }

        $stmt = $pdo->query(
            'SELECT u.id, u.partner_id, p.name AS partner_name, u.email, u.display_name, u.role, u.status, u.created_at, u.last_login_at
             FROM users u
             LEFT JOIN partners p ON p.id = u.partner_id
             WHERE u.role IN ("admin_owner", "admin_outreach", "admin_demo")
             ORDER BY u.created_at DESC'
        );

        Response::ok([
            'users' => $stmt->fetchAll(),
        ]);

    case 'POST /admin/user/create':
        $adminContext = Auth::requireAdminContext();
        Auth::requireWritableSession();

        if ($adminContext['role'] !== 'admin_owner') {
            throw new HttpException(403, 'Only owner admin can create admin users.');
        }

        $input = fab_json_input();

        $email = fab_required_email((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $displayName = trim((string) ($input['display_name'] ?? ''));
        $role = trim((string) ($input['role'] ?? 'admin_outreach'));
        $partnerId = isset($input['partner_id']) ? (int) $input['partner_id'] : null;

        if (!in_array($role, ['admin_outreach', 'admin_demo', 'admin_owner'], true)) {
            throw new HttpException(422, 'role must be admin_outreach, admin_demo, or admin_owner.');
        }

        if (strlen($password) < 8) {
            throw new HttpException(422, 'Password must be at least 8 characters.');
        }

        if ($displayName === '') {
            $displayName = strstr($email, '@', true) ?: 'Admin User';
        }

        if ($role !== 'admin_owner') {
            if (!is_int($partnerId) || $partnerId <= 0) {
                throw new HttpException(422, 'partner_id is required for outreach/demo admin roles.');
            }
            fab_require_partner_exists($pdo, $partnerId);
        } else {
            $partnerId = null;
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            throw new HttpException(409, 'Email is already in use.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO users (partner_id, email, password_hash, display_name, role, status, created_at)
             VALUES (:partner_id, :email, :password_hash, :display_name, :role, :status, UTC_TIMESTAMP())'
        );
        $insert->execute([
            'partner_id' => $partnerId,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'display_name' => $displayName,
            'role' => $role,
            'status' => 'active',
        ]);

        $newUserId = (int) $pdo->lastInsertId();

        fab_audit($pdo, 'user', (int) $adminContext['user_id'], 'admin.user.create', ['user_id' => $newUserId, 'role' => $role]);

        Response::ok([
            'user_id' => $newUserId,
        ], 201);

    case 'POST /admin/user/update-status':
        $adminContext = Auth::requireAdminContext();
        Auth::requireWritableSession();

        if ($adminContext['role'] !== 'admin_owner') {
            throw new HttpException(403, 'Only owner admin can update admin users.');
        }

        $input = fab_json_input();
        $userId = (int) ($input['user_id'] ?? 0);
        $status = trim((string) ($input['status'] ?? ''));

        if ($userId <= 0) {
            throw new HttpException(422, 'user_id is required.');
        }

        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new HttpException(422, 'status must be active or disabled.');
        }

        $targetStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
        $targetStmt->execute(['id' => $userId]);
        $target = $targetStmt->fetch();
        if (!$target) {
            throw new HttpException(404, 'User not found.');
        }

        if ($status === 'disabled' && (string) $target['role'] === 'admin_owner') {
            $countStmt = $pdo->query('SELECT COUNT(*) AS total FROM users WHERE role = "admin_owner" AND status = "active"');
            $count = (int) (($countStmt->fetch()['total'] ?? 0));
            if ($count <= 1) {
                throw new HttpException(422, 'At least one active owner admin is required.');
            }
        }

        $pdo->prepare('UPDATE users SET status = :status WHERE id = :id')->execute([
            'status' => $status,
            'id' => $userId,
        ]);

        fab_audit($pdo, 'user', (int) $adminContext['user_id'], 'admin.user.update_status', [
            'target_user_id' => $userId,
            'status' => $status,
        ]);

        Response::ok();

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
                r.city,
                r.latitude,
                r.longitude,
                r.signup_source,
                r.onboarding_status,
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

    if (!$recipient) {
        return null;
    }

    return fab_normalize_recipient_row($recipient);
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

function fab_assert_admin_can_access_recipient(PDO $pdo, array $adminContext, int $recipientId): array
{
    $stmt = $pdo->prepare('SELECT id, partner_id FROM recipients WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $recipientId]);
    $recipient = $stmt->fetch();

    if (!$recipient) {
        throw new HttpException(404, 'Recipient not found.');
    }

    if (!Auth::canManageAllPartners($adminContext)) {
        $partnerId = $adminContext['partner_id'] ?? null;
        if (!is_int($partnerId) || $partnerId !== (int) $recipient['partner_id']) {
            throw new HttpException(403, 'This admin cannot access that recipient.');
        }
    }

    return $recipient;
}

function fab_resolve_target_partner_id(PDO $pdo, array $adminContext, mixed $inputPartnerId, int $defaultPartnerId): int
{
    if (Auth::canManageAllPartners($adminContext)) {
        $partnerId = (int) ($inputPartnerId ?? 0);
        if ($partnerId <= 0) {
            $partnerId = $defaultPartnerId;
        }

        fab_require_partner_exists($pdo, $partnerId);
        return $partnerId;
    }

    $partnerId = $adminContext['partner_id'] ?? null;
    if (!is_int($partnerId) || $partnerId <= 0) {
        throw new HttpException(403, 'Partner scope is required for this admin.');
    }

    return $partnerId;
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

function fab_create_or_get_donor(PDO $pdo, ?string $email, ?int $userId = null): ?int
{
    if ($userId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM donors WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        if ($row) {
            return (int) $row['id'];
        }
    }

    if ($email !== null && $email !== '') {
        $stmt = $pdo->prepare('SELECT id, user_id FROM donors WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        if ($row) {
            if ($userId !== null && !isset($row['user_id'])) {
                $pdo->prepare('UPDATE donors SET user_id = :user_id WHERE id = :id')->execute([
                    'user_id' => $userId,
                    'id' => $row['id'],
                ]);
            }
            return (int) $row['id'];
        }
    }

    if ($userId === null && ($email === null || $email === '')) {
        return null;
    }

    $insert = $pdo->prepare('INSERT INTO donors (user_id, email, created_at) VALUES (:user_id, :email, UTC_TIMESTAMP())');
    $insert->execute([
        'user_id' => $userId,
        'email' => $email,
    ]);

    return (int) $pdo->lastInsertId();
}

function fab_require_partner_exists(PDO $pdo, int $partnerId): void
{
    $stmt = $pdo->prepare('SELECT id FROM partners WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $partnerId]);
    if (!$stmt->fetch()) {
        throw new HttpException(500, 'Default partner is not configured.');
    }
}

function fab_required_email(string $value): string
{
    $email = strtolower(trim($value));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new HttpException(422, 'A valid email is required.');
    }

    return $email;
}

function fab_optional_email(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $email = strtolower(trim((string) $value));
    if ($email === '') {
        return null;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new HttpException(422, 'Invalid contact_email format.');
    }

    return $email;
}

function fab_optional_phone(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $phone = trim((string) $value);
    if ($phone === '') {
        return null;
    }

    if (strlen($phone) > 40) {
        throw new HttpException(422, 'contact_phone is too long.');
    }

    return $phone;
}

function fab_optional_coordinate(mixed $value, float $min, float $max, string $field): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        throw new HttpException(422, $field . ' must be numeric.');
    }

    $number = (float) $value;
    if ($number < $min || $number > $max) {
        throw new HttpException(422, $field . ' is outside valid range.');
    }

    return round($number, 7);
}

function fab_normalize_recipient_row(array $row): array
{
    foreach (['id', 'partner_id', 'user_id', 'total_received_cents', 'supporters_count'] as $intField) {
        if (array_key_exists($intField, $row) && $row[$intField] !== null && $row[$intField] !== '') {
            $row[$intField] = (int) $row[$intField];
        }
    }

    foreach (['latitude', 'longitude'] as $coordField) {
        if (!array_key_exists($coordField, $row) || $row[$coordField] === null || $row[$coordField] === '') {
            $row[$coordField] = null;
            continue;
        }
        $row[$coordField] = (float) $row[$coordField];
    }

    return $row;
}

function fab_create_member_user(PDO $pdo, string $email, string $password, string $displayName): int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        throw new HttpException(409, 'Email is already in use.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO users (partner_id, email, password_hash, display_name, role, status, created_at)
         VALUES (NULL, :email, :password_hash, :display_name, :role, :status, UTC_TIMESTAMP())'
    );
    $insert->execute([
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'display_name' => $displayName,
        'role' => 'member',
        'status' => 'active',
    ]);

    return (int) $pdo->lastInsertId();
}

function fab_password_reset_token_hash(string $token, string $tokenSigningSecret): string
{
    return hash('sha256', 'password-reset:' . $token . $tokenSigningSecret);
}

function fab_send_password_reset_email(
    array $config,
    string $toEmail,
    string $displayName,
    string $resetLink,
    int $ttlMinutes
): bool {
    $subject = 'Feed A Bum password reset';
    $body = implode("\n", [
        'Hi ' . $displayName . ',',
        '',
        'We received a request to reset your Feed A Bum password.',
        '',
        'Reset link:',
        $resetLink,
        '',
        'This link expires in ' . $ttlMinutes . ' minutes.',
        'If you did not request this, ignore this email.',
    ]);

    return Mailer::sendText(
        $toEmail,
        $subject,
        $body,
        (string) $config['MAIL_FROM_EMAIL'],
        (string) $config['MAIL_FROM_NAME']
    );
}
