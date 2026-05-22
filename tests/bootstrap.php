<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// Force test environment regardless of container env vars.
// The Docker container sets APP_ENV=dev but tests must run as 'test'.
$_ENV['APP_ENV'] = 'test';
$_SERVER['APP_ENV'] = 'test';
putenv('APP_ENV=test');

new Dotenv()->bootEnv(dirname(__DIR__) . '/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0o000);
}
