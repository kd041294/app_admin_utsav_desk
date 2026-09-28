<?php

declare(strict_types=1);

if (!defined('UTSAVDESK_ADMIN_HEADER_INCLUDED')) {
    http_response_code(404);
    exit;
}

$sessionAdmin = $_SESSION['PLATFORM_ADMIN'] ?? [];
$adminName = (string) ($sessionAdmin['NAME'] ?? 'Platform Admin');
$adminRole = (string) ($sessionAdmin['ROLE'] ?? 'ADMIN');
$sidebarActivePage = (string) ($GLOBALS['activePage'] ?? '');
$sidebarSettings = platformSettingsRuntime();
$sidebarAlerts = [];
try {
    if (!empty($sidebarSettings['billing_notifications']) && in_array($adminRole, ['SUPER_ADMIN', 'ADMIN', 'FINANCE', 'VIEWER'], true)) {
        require_once __DIR__ . '/../common/cms_db.php';
        $expiring = (int)cmsDatabaseConnection()->query("SELECT COUNT(*) FROM user_subscription WHERE status='ACTIVE' AND starts_on<=CURRENT_DATE AND ends_on>CURRENT_DATE AND ends_on<=CURRENT_DATE+INTERVAL 7 DAY")->fetchColumn();
        if ($expiring) $sidebarAlerts[] = ['url' => '/subscriptions/?status=ACTIVE', 'text' => $expiring . ' subscriptions expire within 7 days'];
    }
    if (!empty($sidebarSettings['security_notifications']) && in_array($adminRole, ['SUPER_ADMIN', 'ADMIN'], true)) {
        $warnings = (int)databaseConnection()->query("SELECT COUNT(*) FROM activity_log WHERE type='SECURITY' AND (action='LOGIN_FAILED' OR severity IN ('WARNING','DANGER')) AND created_at>=NOW()-INTERVAL 24 HOUR")->fetchColumn();
        if ($warnings) $sidebarAlerts[] = ['url' => '/activity/?type=SECURITY', 'text' => $warnings . ' security alerts in the last 24 hours'];
    }
} catch (Throwable $exception) {
    error_log('Sidebar alerts unavailable.');
}
$sidebarCounts = [];
try {
    require_once __DIR__ . '/../common/cms_db.php';
    $sidebarCounts = cmsDatabaseConnection()->query("SELECT
        (SELECT COUNT(*) FROM catering_info) AS caterers,
        (SELECT COUNT(*) FROM user_info WHERE is_active=1 AND status=1) AS users,
        (SELECT COUNT(DISTINCT user_id) FROM user_subscription WHERE status='ACTIVE' AND starts_on<=CURRENT_DATE AND ends_on>CURRENT_DATE) AS subscriptions")->fetch();
} catch (Throwable $exception) {
    error_log('Sidebar counts unavailable: ' . $exception->getMessage());
}
$sidebarSections = [
    'Overview' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'url' => '/dashboard/', 'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS', 'SUPPORT', 'FINANCE', 'VIEWER']],
    ],
    'Management' => [
        ['key' => 'caterers', 'label' => 'Caterers', 'icon' => 'bi-buildings', 'url' => '/caterers/', 'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS', 'VIEWER']],
        ['key' => 'subscriptions', 'label' => 'Subscriptions', 'icon' => 'bi-stars', 'url' => '/subscriptions/', 'roles' => ['SUPER_ADMIN', 'ADMIN', 'FINANCE', 'VIEWER']],
        ['key' => 'billing', 'label' => 'Billing', 'icon' => 'bi-credit-card', 'url' => '/billing/', 'roles' => ['SUPER_ADMIN', 'ADMIN', 'FINANCE', 'VIEWER']],
        ['key' => 'users', 'label' => 'Users', 'icon' => 'bi-people', 'url' => '/users/', 'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS', 'SUPPORT', 'VIEWER']],
    ],
    'Platform' => [
        ['key' => 'analytics', 'label' => 'Analytics', 'icon' => 'bi-bar-chart', 'url' => '/analytics/', 'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS', 'FINANCE', 'VIEWER']],
        ['key' => 'support', 'label' => 'Support', 'icon' => 'bi-headset', 'url' => '/support/', 'roles' => ['SUPER_ADMIN', 'ADMIN', 'SUPPORT', 'VIEWER']],
        ['key' => 'activity', 'label' => 'Activity Logs', 'icon' => 'bi-clock-history', 'url' => '/activity/', 'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS', 'SUPPORT', 'FINANCE', 'VIEWER']],
        ['key' => 'announcements', 'label' => 'Announcements', 'icon' => 'bi-megaphone', 'url' => '/announcements/', 'roles' => ['SUPER_ADMIN', 'ADMIN', 'OPERATIONS']],
    ],
    'System' => [
        ['key' => 'access-codes', 'label' => 'Access Codes', 'icon' => 'bi-key', 'url' => '/access-codes/', 'roles' => ['SUPER_ADMIN', 'ADMIN']],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'bi-gear', 'url' => '/settings/', 'roles' => ['SUPER_ADMIN', 'ADMIN']],
    ],
];
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-brand">
        <a href="<?= htmlspecialchars(APP_BASE_URL . '/dashboard/', ENT_QUOTES, 'UTF-8') ?>">
            <div class="sidebar-logo">UD</div>
            <div>
                <div class="sidebar-brand-name"><?= htmlspecialchars($sidebarSettings['platform_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="sidebar-brand-label">Platform Admin</div>
            </div>
        </a>
        <button type="button" class="sidebar-mobile-close" id="sidebarClose" aria-label="Close navigation">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <div class="sidebar-navigation">
        <?php foreach ($sidebarAlerts as $alert): ?><a class="sidebar-link" href="<?= htmlspecialchars(APP_BASE_URL . $alert['url'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-bell"></i><span><?= htmlspecialchars($alert['text'], ENT_QUOTES, 'UTF-8') ?></span></a><?php endforeach; ?>
        <?php foreach ($sidebarSections as $section => $items): ?>
            <?php
            $visibleItems = array_filter($items, static function (array $item) use ($adminRole): bool {
                return in_array($adminRole, $item['roles'], true);
            });
            ?>
            <?php if ($visibleItems !== []): ?>
                <div class="sidebar-section-label"><?= htmlspecialchars($section, ENT_QUOTES, 'UTF-8') ?></div>
                <?php foreach ($visibleItems as $item): ?>
                    <?php if (isset($sidebarCounts[$item['key']])) {
                        $item['count'] = (string) $sidebarCounts[$item['key']];
                    } ?>
                    <a
                        href="<?= htmlspecialchars(APP_BASE_URL . $item['url'], ENT_QUOTES, 'UTF-8') ?>"
                        class="sidebar-link<?= $sidebarActivePage === $item['key'] ? ' active' : '' ?>">
                        <i class="bi <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                        <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if (isset($item['count'])): ?>
                            <span class="<?= htmlspecialchars($item['countClass'] ?? 'sidebar-count', ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($item['count'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-user-avatar"><?= htmlspecialchars(strtoupper(substr($adminName, 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="sidebar-user-info">
                <strong><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $adminRole))), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <form method="post" action="<?= htmlspecialchars(APP_BASE_URL . '/logout.php', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="sidebar-logout border-0 bg-transparent p-0" title="Logout" aria-label="Logout">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </form>
        </div>
    </div>
</aside>