<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

[$config, $pdo] = fab_bootstrap(false);
fab_process_stripe_webhook($config, $pdo);
