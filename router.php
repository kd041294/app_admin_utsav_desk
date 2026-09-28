<?php

declare(strict_types=1);

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$segments = explode('/', rawurldecode(is_string($requestPath) ? $requestPath : '/'));

foreach ($segments as $segment) {
    if ($segment !== '' && $segment[0] === '.') {
        http_response_code(404);
        exit;
    }
}

return false;
