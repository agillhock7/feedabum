<?php

declare(strict_types=1);

final class Config
{
    public static function load(string $configFile): array
    {
        $fileValues = [];
        if (is_file($configFile)) {
            $loaded = require $configFile;
            if (!is_array($loaded)) {
                throw new HttpException(500, 'Invalid config.php format.');
            }
            $fileValues = $loaded;
        }

        $keys = [
            'DB_HOST',
            'DB_NAME',
            'DB_USER',
            'DB_PASS',
            'DB_PORT',
            'APP_ENV',
            'APP_BASE_URL',
            'STRIPE_SECRET_KEY',
            'STRIPE_WEBHOOK_SECRET',
            'SESSION_COOKIE_SECURE',
            'SESSION_COOKIE_SAMESITE',
            'RATE_LIMIT_LOOKUP_MAX',
            'RATE_LIMIT_LOOKUP_WINDOW',
            'RATE_LIMIT_DONATION_MAX',
            'RATE_LIMIT_DONATION_WINDOW',
            'RATE_LIMIT_LOGIN_MAX',
            'RATE_LIMIT_LOGIN_WINDOW',
            'RATE_LIMIT_SIGNUP_MAX',
            'RATE_LIMIT_SIGNUP_WINDOW',
            'TOKEN_SIGNING_SECRET',
            'STRIPE_PUBLISHABLE_KEY',
            'DEFAULT_PARTNER_ID',
            'DEFAULT_CITY',
        ];

        $values = [];
        foreach ($keys as $key) {
            $envValue = getenv($key);
            if ($envValue !== false && $envValue !== '') {
                $values[$key] = $envValue;
                continue;
            }

            if (array_key_exists($key, $fileValues)) {
                $values[$key] = $fileValues[$key];
            }
        }

        $defaults = [
            'DB_PORT' => 3306,
            'APP_ENV' => 'dev',
            'APP_BASE_URL' => 'http://localhost:8000',
            'SESSION_COOKIE_SECURE' => false,
            'SESSION_COOKIE_SAMESITE' => 'Lax',
            'RATE_LIMIT_LOOKUP_MAX' => 60,
            'RATE_LIMIT_LOOKUP_WINDOW' => 60,
            'RATE_LIMIT_DONATION_MAX' => 30,
            'RATE_LIMIT_DONATION_WINDOW' => 60,
            'RATE_LIMIT_LOGIN_MAX' => 10,
            'RATE_LIMIT_LOGIN_WINDOW' => 300,
            'RATE_LIMIT_SIGNUP_MAX' => 6,
            'RATE_LIMIT_SIGNUP_WINDOW' => 3600,
            'STRIPE_PUBLISHABLE_KEY' => '',
            'DEFAULT_PARTNER_ID' => 1,
            'DEFAULT_CITY' => 'Tucson, AZ',
        ];

        foreach ($defaults as $key => $defaultValue) {
            if (!array_key_exists($key, $values)) {
                $values[$key] = $defaultValue;
            }
        }

        $values['DB_PORT'] = (int) $values['DB_PORT'];
        $values['SESSION_COOKIE_SECURE'] = self::toBool($values['SESSION_COOKIE_SECURE']);
        $values['RATE_LIMIT_LOOKUP_MAX'] = (int) $values['RATE_LIMIT_LOOKUP_MAX'];
        $values['RATE_LIMIT_LOOKUP_WINDOW'] = (int) $values['RATE_LIMIT_LOOKUP_WINDOW'];
        $values['RATE_LIMIT_DONATION_MAX'] = (int) $values['RATE_LIMIT_DONATION_MAX'];
        $values['RATE_LIMIT_DONATION_WINDOW'] = (int) $values['RATE_LIMIT_DONATION_WINDOW'];
        $values['RATE_LIMIT_LOGIN_MAX'] = (int) $values['RATE_LIMIT_LOGIN_MAX'];
        $values['RATE_LIMIT_LOGIN_WINDOW'] = (int) $values['RATE_LIMIT_LOGIN_WINDOW'];
        $values['RATE_LIMIT_SIGNUP_MAX'] = (int) $values['RATE_LIMIT_SIGNUP_MAX'];
        $values['RATE_LIMIT_SIGNUP_WINDOW'] = (int) $values['RATE_LIMIT_SIGNUP_WINDOW'];
        $values['DEFAULT_PARTNER_ID'] = (int) $values['DEFAULT_PARTNER_ID'];

        $required = [
            'DB_HOST',
            'DB_NAME',
            'DB_USER',
            'DB_PASS',
            'STRIPE_SECRET_KEY',
            'STRIPE_WEBHOOK_SECRET',
            'TOKEN_SIGNING_SECRET',
        ];

        $missing = [];
        foreach ($required as $key) {
            if (!isset($values[$key]) || $values[$key] === '') {
                $missing[] = $key;
            }
        }

        $appEnv = strtolower((string) ($values['APP_ENV'] ?? 'dev'));
        if ($missing !== []) {
            if ($appEnv === 'dev') {
                throw new HttpException(
                    500,
                    'Missing config values: ' . implode(', ', $missing) . '. Set environment variables or create /api/config/config.php from config.example.php.'
                );
            }

            throw new HttpException(500, 'Server configuration is incomplete.');
        }

        $values['APP_ENV'] = $appEnv;

        return $values;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
