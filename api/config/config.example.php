<?php

declare(strict_types=1);

return [
    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => 'feedabum',
    'DB_USER' => 'feedabum_user',
    'DB_PASS' => 'replace_me',
    'DB_PORT' => 3306,

    'APP_ENV' => 'prod', // dev|prod
    'APP_BASE_URL' => 'https://fab.gops.app',

    'STRIPE_SECRET_KEY' => 'sk_test_replace_me',
    'STRIPE_WEBHOOK_SECRET' => 'whsec_replace_me',
    'STRIPE_PUBLISHABLE_KEY' => 'pk_test_replace_me',

    'SESSION_COOKIE_SECURE' => true,
    'SESSION_COOKIE_SAMESITE' => 'Lax',

    'RATE_LIMIT_LOOKUP_MAX' => 60,
    'RATE_LIMIT_LOOKUP_WINDOW' => 60,
    'RATE_LIMIT_DONATION_MAX' => 30,
    'RATE_LIMIT_DONATION_WINDOW' => 60,
    'RATE_LIMIT_LOGIN_MAX' => 10,
    'RATE_LIMIT_LOGIN_WINDOW' => 300,
    'RATE_LIMIT_SIGNUP_MAX' => 6,
    'RATE_LIMIT_SIGNUP_WINDOW' => 3600,

    'TOKEN_SIGNING_SECRET' => 'replace_with_long_random_secret',
    'DEFAULT_PARTNER_ID' => 1,
    'DEFAULT_CITY' => 'Tucson, AZ',

    // Demo admin login toggle (read-only mode if used)
    'DEMO_LOGIN_ENABLED' => false,
    'DEMO_LOGIN_EMAIL' => 'admin@feedabum.local',
];
