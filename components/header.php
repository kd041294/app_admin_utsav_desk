<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/config.php';
require_once __DIR__ . '/../common/functions.php';

$adminPageDefinitions = [
    'activity/index.php' => [
        'title' => 'Activity Logs',
        'stylesheets' => ['activity.css'],
        'active' => 'activity',
        'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS', 'SUPPORT', 'FINANCE', 'VIEWER'],
    ],
    'access-codes/index.php' => [
        'title' => 'Access Codes',
        'stylesheets' => [],
        'active' => 'access-codes',
        'roles' => ['SUPER_ADMIN', 'ADMIN'],
    ],
    'analytics/index.php' => [
        'title' => 'Analytics',
        'stylesheets' => ['analytics.css'],
        'active' => 'analytics',
        'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS', 'FINANCE', 'VIEWER'],
    ],
    'announcements/index.php' => [
        'title' => 'Announcements',
        'stylesheets' => ['announcements.css'],
        'active' => 'announcements',
        'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS'],
    ],
    'billing/index.php' => [
        'title' => 'Billing',
        'stylesheets' => ['billing.css'],
        'active' => 'billing',
        'roles' => ['SUPER_ADMIN', 'ADMIN', 'FINANCE', 'VIEWER'],
    ],
    'caterers/add.php' => [
        'title' => 'Add Caterer',
        'stylesheets' => ['caterer-add.css'],
        'active' => 'caterers',
        'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS'],
    ],
    'caterers/index.php' => [
        'title' => 'Caterers',
        'stylesheets' => ['caterer.css'],
        'active' => 'caterers',
        'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS', 'VIEWER'],
    ],
    'caterers/view.php' => [
        'title' => 'Caterer Details',
        'stylesheets' => ['caterer.css'],
        'active' => 'caterers',
        'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS', 'VIEWER'],
    ],
    'dashboard/index.php' => [
        'title' => 'Dashboard',
        'stylesheets' => [],
        'active' => 'dashboard',
        'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS', 'SUPPORT', 'FINANCE', 'VIEWER'],
    ],
    'settings/index.php' => [
        'title' => 'Settings',
        'stylesheets' => ['settings.css'],
        'active' => 'settings',
        'roles' => ['SUPER_ADMIN', 'ADMIN'],
    ],
    'subscriptions/index.php' => [
        'title' => 'Subscriptions',
        'stylesheets' => ['subscriptions.css'],
        'active' => 'subscriptions',
        'roles' => ['SUPER_ADMIN', 'ADMIN', 'FINANCE', 'VIEWER'],
    ],
    'support/index.php' => [
        'title' => 'Support',
        'stylesheets' => ['support.css'],
        'active' => 'support',
        'roles' => ['SUPER_ADMIN', 'ADMIN', 'SUPPORT', 'VIEWER'],
    ],
    'users/index.php' => [
        'title' => 'Users',
        'stylesheets' => ['users.css'],
        'active' => 'users',
        'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS', 'SUPPORT', 'VIEWER'],
    ],
];

$scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$pathSegments = explode('/', trim($scriptPath, '/'));
$adminPageKey = implode('/', array_slice($pathSegments, -2));
$adminPage = $adminPageDefinitions[$adminPageKey] ?? null;

if ($adminPage === null) {
    http_response_code(404);
    exit;
}

$pageTitle = $adminPage['title'];
$pageStylesheets = $adminPage['stylesheets'];
$activePage = $adminPage['active'];

sendSecurityHeaders();
requirePlatformAdmin($adminPage['roles']);
define('UTSAVDESK_ADMIN_HEADER_INCLUDED', true);

function renderAdminHead(string $title, array $stylesheets): void
{
    $branding = platformSettingsRuntime();
    $initialToast = udGetToast();
?>

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow">
        <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <title><?= htmlspecialchars($title . ' | ' . $branding['platform_name'] . ' Admin', ENT_QUOTES, 'UTF-8') ?></title>
        <link rel="icon" type="image/png" href="<?= htmlspecialchars(APP_BASE_URL . '/assets/images/logo.png', ENT_QUOTES, 'UTF-8') ?>">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
        <link rel="stylesheet" href="<?= htmlspecialchars(APP_BASE_URL . '/assets/css/admin.css', ENT_QUOTES, 'UTF-8') ?>">
        <link rel="stylesheet" href="<?= htmlspecialchars(APP_BASE_URL . '/assets/css/dashboard.css', ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($stylesheets as $stylesheet): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars(APP_BASE_URL . '/assets/css/' . $stylesheet, ENT_QUOTES, 'UTF-8') ?>">
        <?php endforeach; ?>
        <link rel="stylesheet" href="<?= htmlspecialchars(APP_BASE_URL . '/assets/css/theme.css?v=20260928', ENT_QUOTES, 'UTF-8') ?>">
        <?= udToastScript($initialToast ?? []) ?>
    </head>
<?php
}
