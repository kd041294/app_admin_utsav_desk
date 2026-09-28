<?php if (!defined('UTSAVDESK_ADMIN_HEADER_INCLUDED')) { http_response_code(404); exit; } ?>
<!doctype html><html lang="en">
<?php renderAdminHead($pageTitle, array_unique(array_merge($pageStylesheets, ['billing.css']))); ?>
<body class="admin-body">
<?php require __DIR__ . '/sidebar.php'; ?>
<div class="admin-main"><header class="admin-topbar"><div class="topbar-left"><button type="button" class="mobile-menu-button" id="sidebarOpen" aria-label="Open navigation"><i class="bi bi-list"></i></button><div class="topbar-title"><h1><?= billingEscape($pageTitle) ?></h1><span><?= billingEscape($pageSubtitle ?? 'Manual subscription payments and user access terms') ?></span></div></div><div class="topbar-actions"><?= billingEscape($_SESSION['PLATFORM_ADMIN']['NAME']) ?></div></header>
<main class="admin-content">
