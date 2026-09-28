<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function platformAdminBasePath(): string
{
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $basePath = dirname(dirname($scriptPath));

    return ($basePath === '.' || $basePath === '/') ? '/' : rtrim($basePath, '/') . '/';
}

function clearPlatformAdminSession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => (bool) $cookie['secure'],
            'httponly' => (bool) $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function requirePlatformAdmin(array $allowedRoles = []): void
{
    sendSecurityHeaders();
    startSecureSession();

    $sessionAdmin = $_SESSION['PLATFORM_ADMIN'] ?? null;
    $adminId = is_array($sessionAdmin) ? (int) ($sessionAdmin['ID'] ?? 0) : 0;

    if (empty($sessionAdmin['IS_LOGGED_IN']) || $adminId < 1) {
        header('Location: ' . platformAdminBasePath(), true, 302);
        exit;
    }

    $statement = databaseConnection()->prepare(
        "SELECT id, admin_code, first_name, last_name, display_name, email, role
		 FROM admin_user
		 WHERE id = :id
		   AND is_active = 1
		   AND account_status = 'ACTIVE'
		   AND deleted_at IS NULL
		 LIMIT 1"
    );
    $statement->execute(['id' => $adminId]);
    $admin = $statement->fetch();

    if (!$admin) {
        clearPlatformAdminSession();
        header('Location: ' . platformAdminBasePath(), true, 302);
        exit;
    }

    if ($allowedRoles !== [] && !in_array((string) $admin['role'], $allowedRoles, true)) {
        http_response_code(403);
        echo 'You are not authorized to access this page.';
        exit;
    }

    $name = trim((string) ($admin['display_name'] ?: ($admin['first_name'] . ' ' . $admin['last_name'])));
    $_SESSION['PLATFORM_ADMIN'] = [
        'ID' => (int) $admin['id'],
        'ADMIN_CODE' => (string) $admin['admin_code'],
        'NAME' => $name,
        'EMAIL' => (string) $admin['email'],
        'ROLE' => (string) $admin['role'],
        'IS_LOGGED_IN' => true,
    ];
}
