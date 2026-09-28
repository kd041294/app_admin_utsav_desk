<?php

declare(strict_types=1);

require_once __DIR__ . '/common/config.php';
require_once __DIR__ . '/common/security.php';
require_once __DIR__ . '/common/auth.php';

sendSecurityHeaders();
startSecureSession();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit('Your session expired. Refresh the page and try again.');
}

clearPlatformAdminSession();

header('Location: ' . APP_BASE_URL . '/', true, 302);
exit;
