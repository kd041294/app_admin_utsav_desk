<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

loadAppEnvironment();

function appConfigValue(string $name, ?string $default = null): ?string
{
    $value = getenv($name);

    return $value === false ? $default : $value;
}

define('APP_ENV', appConfigValue('APP_ENV', 'production'));

$databaseHost = appConfigValue('DB_HOST', '127.0.0.1');
$databaseName = appConfigValue('DB_NAME', 'utsavdesk_admin_db');
$databaseUser = appConfigValue('DB_USER');
$databasePassword = appConfigValue('DB_PASS');

if ($databaseUser === null || $databaseUser === '') {
    throw new RuntimeException('Database configuration is incomplete.');
}

if (APP_ENV !== 'local' && ($databasePassword === null || $databasePassword === '')) {
    throw new RuntimeException('A database password is required outside local development.');
}

define('DB_HOST', $databaseHost);
define('DB_NAME', $databaseName);
define('DB_USER', $databaseUser);
define('DB_PASS', $databasePassword ?? '');
define('SESSION_IDLE_TIMEOUT', 1800);

$localBaseUrl = rtrim(
    (string) appConfigValue('APP_BASE_URL_LOCAL', 'http://localhost/admin_utsav_desk'),
    '/'
);
$productionBaseUrl = rtrim((string) appConfigValue('APP_BASE_URL_PROD', ''), '/');

if (filter_var($localBaseUrl, FILTER_VALIDATE_URL) === false) {
    throw new RuntimeException('The local application base URL is invalid.');
}

if (APP_ENV !== 'local' && (
    filter_var($productionBaseUrl, FILTER_VALIDATE_URL) === false
    || parse_url($productionBaseUrl, PHP_URL_SCHEME) !== 'https'
    || strpos($productionBaseUrl, 'example.com') !== false
)) {
    throw new RuntimeException('Set APP_BASE_URL_PROD to the production HTTPS URL.');
}

define('APP_BASE_URL_LOCAL', $localBaseUrl);
define('APP_BASE_URL_PROD', $productionBaseUrl);
define('APP_BASE_URL', APP_ENV === 'local' ? APP_BASE_URL_LOCAL : APP_BASE_URL_PROD);
