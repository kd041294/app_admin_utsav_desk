<?php

declare(strict_types=1);

function loadAppEnvironment(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $loaded = true;
    $environmentFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

    if (!is_file($environmentFile)) {
        return;
    }

    $variables = parse_ini_file($environmentFile, false, INI_SCANNER_RAW);

    if (!is_array($variables)) {
        throw new RuntimeException('The application environment file is invalid.');
    }

    foreach ($variables as $name => $value) {
        if (getenv($name) === false) {
            putenv($name . '=' . (string) $value);
            $_ENV[$name] = (string) $value;
        }
    }
}
