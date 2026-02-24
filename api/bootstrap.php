<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/HttpException.php';
require_once __DIR__ . '/lib/Response.php';
require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Session.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Throttle.php';
require_once __DIR__ . '/lib/TokenService.php';
require_once __DIR__ . '/lib/StripeClient.php';
require_once __DIR__ . '/lib/StripeWebhookHandler.php';

function fab_bootstrap(bool $withSession = true): array
{
    static $initialized = false;
    if (!$initialized) {
        $initialized = true;

        ini_set('display_errors', '0');
        error_reporting(E_ALL);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
            if (!(error_reporting() & $severity)) {
                return;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $exception): void {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $statusCode = $exception instanceof HttpException ? $exception->statusCode : 500;
            $appEnv = (string) ($GLOBALS['fab_app_env'] ?? 'prod');
            $message = 'Internal server error.';

            if ($statusCode < 500 || $appEnv === 'dev') {
                $message = $exception->getMessage();
            }

            $payload = ['ok' => false, 'error' => $message];
            if ($appEnv === 'dev') {
                $payload['exception'] = get_class($exception);
            }

            if (!headers_sent()) {
                http_response_code($statusCode);
                header('Content-Type: application/json; charset=utf-8');
            }

            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }

            echo json_encode(['ok' => false, 'error' => 'Fatal server error.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        });
    }

    $config = Config::load(__DIR__ . '/config/config.php');
    $GLOBALS['fab_app_env'] = $config['APP_ENV'];

    fab_apply_cors($config);

    if ($withSession) {
        Session::start($config);
    }

    $pdo = Database::connect($config);

    return [$config, $pdo];
}

function fab_apply_cors(array $config): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

    if ($config['APP_ENV'] === 'dev' && is_string($origin) && $origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, Stripe-Signature');
        header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function fab_process_stripe_webhook(array $config, PDO $pdo): never
{
    $payload = file_get_contents('php://input');
    if (!is_string($payload)) {
        throw new HttpException(400, 'Webhook payload missing.');
    }

    $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    if (!is_string($signature) || $signature === '') {
        throw new HttpException(400, 'Stripe signature header missing.');
    }

    $handler = new StripeWebhookHandler($pdo, $config);
    $result = $handler->handle($payload, $signature);

    Response::ok(['data' => $result]);
}
