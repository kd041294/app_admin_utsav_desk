<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function cmsDatabaseConnection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $environment = APP_ENV === 'local' ? 'LOCAL' : 'PROD';
    $host = appConfigValue('CMS_DB_HOST_' . $environment, APP_ENV === 'local' ? DB_HOST : null);
    $name = appConfigValue('CMS_DB_NAME_' . $environment, 'cms');
    $user = appConfigValue('CMS_DB_USER_' . $environment, APP_ENV === 'local' ? DB_USER : null);
    $password = appConfigValue('CMS_DB_PASS_' . $environment, APP_ENV === 'local' ? DB_PASS : null);

    if ($host === null || $host === '' || $name === null || $name === '' || $user === null || $user === '') {
        throw new RuntimeException('CMS database configuration is incomplete for ' . strtolower($environment) . '.');
    }

    if ($environment === 'PROD' && ($password === null || $password === '' || strpos($host, 'replace_with_') === 0 || strpos($user, 'replace_with_') === 0)) {
        throw new RuntimeException('Set production CMS database credentials before enabling the production dashboard.');
    }

    $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';
    $connection = new PDO($dsn, $user, $password ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);

    return $connection;
}
