<?php

declare(strict_types=1);

require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../common/cms_db.php';
require_once __DIR__ . '/../common/billing.php';
$subscriptionSummary = null;
try { $subscriptionSummary = billingSummary(cmsDatabaseConnection()); }
catch (Throwable $exception) { error_log('Dashboard subscription summary unavailable: ' . $exception->getMessage()); }

$admin = [
    'name' => (string) ($_SESSION['PLATFORM_ADMIN']['NAME'] ?? 'Platform Admin'),
    'email' => (string) ($_SESSION['PLATFORM_ADMIN']['EMAIL'] ?? ''),
    'role' => (string) ($_SESSION['PLATFORM_ADMIN']['ROLE'] ?? 'ADMIN'),
];

$stats = [
    'total_caterers' => 0,
    'active_caterers' => 0,
    'upcoming_events' => 0,
    'events_created_this_month' => 0,
    'total_booking_value' => 0.0,
];
$recentCaterers = [];
$leadStatusSummary = [];
$activities = [];
$eventStats = ['total' => 0];
$dashboardDataAvailable = false;
$monthStart = new DateTimeImmutable('first day of this month 00:00:00');
$chartStart = $monthStart->modify('-11 months');
$chartEnd = $monthStart->modify('+1 month');
$chartLabels = [];
$bookingValueSeries = [];

for ($month = $chartStart; $month < $chartEnd; $month = $month->modify('+1 month')) {
    $chartLabels[] = $month->format('M Y');
    $bookingValueSeries[$month->format('Y-m')] = 0.0;
}

try {
    $cms = cmsDatabaseConnection();

    $catererStats = $cms->query(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN is_active = 1 AND status = 1 THEN 1 ELSE 0 END), 0) AS active
         FROM catering_info'
    )->fetch();
    $stats['total_caterers'] = (int) $catererStats['total'];
    $stats['active_caterers'] = (int) $catererStats['active'];

    $eventStatement = $cms->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN event_date >= CURRENT_DATE
                    AND event_status IN ('UPCOMING', 'CONFIRMED') THEN 1 ELSE 0 END), 0) AS upcoming,
                COALESCE(SUM(CASE WHEN created_at >= :month_start AND created_at < :next_month
                    THEN 1 ELSE 0 END), 0) AS created_this_month,
                COALESCE(SUM(CASE WHEN event_status <> 'CANCELLED'
                    THEN contract_amount ELSE 0 END), 0) AS booking_value
         FROM event_details
         WHERE is_active = 1 AND status = 1"
    );
    $eventStatement->execute([
        'month_start' => $monthStart->format('Y-m-d H:i:s'),
        'next_month' => $chartEnd->format('Y-m-d H:i:s'),
    ]);
    $eventStats = $eventStatement->fetch();
    $stats['upcoming_events'] = (int) $eventStats['upcoming'];
    $stats['events_created_this_month'] = (int) $eventStats['created_this_month'];
    $stats['total_booking_value'] = (float) $eventStats['booking_value'];

    $monthlyStatement = $cms->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS period,
                COALESCE(SUM(CASE WHEN event_status <> 'CANCELLED' THEN contract_amount ELSE 0 END), 0) AS booking_value
         FROM event_details
         WHERE is_active = 1 AND status = 1 AND created_at >= :chart_start AND created_at < :chart_end
         GROUP BY DATE_FORMAT(created_at, '%Y-%m')"
    );
    $monthlyStatement->execute([
        'chart_start' => $chartStart->format('Y-m-d H:i:s'),
        'chart_end' => $chartEnd->format('Y-m-d H:i:s'),
    ]);

    foreach ($monthlyStatement as $monthRow) {
        if (isset($bookingValueSeries[$monthRow['period']])) {
            $bookingValueSeries[$monthRow['period']] = (float) $monthRow['booking_value'] / 100000;
        }
    }

    $recentCaterers = $cms->query(
        "SELECT c.catering_code AS code,
                c.catering_name AS name,
                COALESCE(c.city, '') AS city,
                CASE WHEN c.is_active = 1 AND c.status = 1 THEN 'ACTIVE' ELSE 'INACTIVE' END AS status,
                DATE_FORMAT(c.created_at, '%d %b %Y') AS date,
                COALESCE(e.event_count, 0) AS event_count
         FROM catering_info c
         LEFT JOIN (
             SELECT catering_id, COUNT(*) AS event_count
             FROM event_details
             WHERE is_active = 1 AND status = 1
             GROUP BY catering_id
         ) e ON e.catering_id = c.id
         ORDER BY c.created_at DESC
         LIMIT 5"
    )->fetchAll();

    $leadStatusSummary = $cms->query(
        'SELECT query_status AS status, COUNT(*) AS total
         FROM customer_query
         WHERE is_active = 1 AND status = 1
         GROUP BY query_status
         ORDER BY FIELD(query_status, \'NEW\', \'CONTACTED\', \'FOLLOW_UP\', \'QUOTATION_SENT\', \'NEGOTIATION\', \'CONFIRMED\', \'CANCELLED\', \'LOST\')'
    )->fetchAll();

    $activityRows = $cms->query(
        "SELECT module, action,
            TIMESTAMPDIFF(SECOND, created_at, CURRENT_TIMESTAMP) AS age_seconds,
            DATE_FORMAT(created_at, '%d %b %Y, %H:%i') AS created_label
         FROM activity_log
         ORDER BY created_at DESC
         LIMIT 5"
    )->fetchAll();
    $activityIcons = [
        'CREATE' => 'bi-plus-circle',
        'UPDATE' => 'bi-pencil-square',
        'DELETE' => 'bi-trash',
        'LOGIN' => 'bi-box-arrow-in-right',
        'LOGOUT' => 'bi-box-arrow-right',
        'STATUS_CHANGE' => 'bi-arrow-repeat',
        'DOWNLOAD' => 'bi-download',
    ];

    foreach ($activityRows as $activityRow) {
        $action = (string) $activityRow['action'];
        $module = ucwords(strtolower(str_replace('_', ' ', (string) $activityRow['module'])));
        $age = max(0, (int) $activityRow['age_seconds']);

        if ($age < 60) {
            $timeLabel = $age . ' seconds ago';
        } elseif ($age < 3600) {
            $timeLabel = floor($age / 60) . ' minutes ago';
        } elseif ($age < 86400) {
            $timeLabel = floor($age / 3600) . ' hours ago';
        } else {
            $timeLabel = (string) $activityRow['created_label'];
        }

        $activities[] = [
            'icon' => $activityIcons[$action] ?? 'bi-activity',
            'title' => ucwords(strtolower(str_replace('_', ' ', $action))) . ' · ' . $module,
            'description' => 'A ' . strtolower($module) . ' action was recorded.',
            'time' => $timeLabel,
            'type' => $action === 'DELETE' ? 'danger' : ($action === 'CREATE' ? 'primary' : 'info'),
        ];
    }

    $dashboardDataAvailable = true;
} catch (Throwable $exception) {
    error_log('CMS dashboard query failed: ' . $exception->getMessage());
    $stats = [
        'total_caterers' => 0,
        'active_caterers' => 0,
        'upcoming_events' => 0,
        'events_created_this_month' => 0,
        'total_booking_value' => 0.0,
    ];
    $eventStats = ['total' => 0];
    $recentCaterers = [];
    $leadStatusSummary = [];
    $activities = [];
    $bookingValueSeries = array_fill_keys(array_keys($bookingValueSeries), 0.0);
}

$chartValues = array_values($bookingValueSeries);
$leadTotal = array_sum(array_map(static function (array $row): int {
    return (int) $row['total'];
}, $leadStatusSummary));
$activeRate = $stats['total_caterers'] > 0
    ? (int) round(($stats['active_caterers'] / $stats['total_caterers']) * 100)
    : 0;
$leadColors = ['#087f73', '#5276cc', '#ef9f27', '#16835d', '#536779', '#cf6b36', '#8b8b95', '#51545a'];

foreach ($leadStatusSummary as $index => &$leadStatus) {
    $leadStatus['label'] = ucwords(strtolower(str_replace('_', ' ', (string) $leadStatus['status'])));
    $leadStatus['color'] = $leadColors[$index % count($leadColors)];
    $leadStatus['total'] = (int) $leadStatus['total'];
}
unset($leadStatus);

function moneyFormat(float $amount): string
{
    if ($amount >= 10000000) {
        return '₹' . number_format($amount / 10000000, 2) . ' Cr';
    }

    if ($amount >= 100000) {
        return '₹' . number_format($amount / 100000, 2) . ' L';
    }

    return '₹' . number_format($amount);
}

?>
<!doctype html>

<html lang="en">

<?php renderAdminHead($pageTitle, $pageStylesheets); ?>


<body class="admin-body">


    <!-- =========================================================
     MOBILE OVERLAY
     ========================================================= -->

    <?php require __DIR__ . '/../components/sidebar.php'; ?>


    <!-- =========================================================
     MAIN APPLICATION
     ========================================================= -->

    <div class="admin-main">


        <!-- =====================================================
         TOPBAR
         ===================================================== -->

        <header class="admin-topbar">

            <div class="topbar-left">

                <button
                    type="button"
                    class="mobile-menu-button"
                    id="sidebarOpen">

                    <i class="bi bi-list"></i>

                </button>


                <div class="topbar-title">

                    <h1>
                        Dashboard
                    </h1>

                    <span>
                        UtsavDesk Platform Overview
                    </span>

                </div>

            </div>


            <div class="topbar-actions">


                <!-- Search -->

                <button
                    type="button"
                    class="topbar-action-btn d-none d-md-flex"
                    title="Search">

                    <i class="bi bi-search"></i>

                </button>


                <!-- Notifications -->

                <button
                    type="button"
                    class="topbar-action-btn notification-button"
                    title="Notifications">

                    <i class="bi bi-bell"></i>

                    <span class="notification-dot"></span>

                </button>


                <!-- Profile -->

                <div class="dropdown">

                    <button
                        type="button"
                        class="topbar-profile"
                        data-bs-toggle="dropdown">

                        <div class="topbar-avatar">
                            <?= htmlspecialchars(strtoupper(substr($admin['name'], 0, 2)), ENT_QUOTES, 'UTF-8') ?>
                        </div>

                        <div class="topbar-profile-info d-none d-md-block">

                            <strong>
                                <?= htmlspecialchars($admin['name']) ?>
                            </strong>

                            <span>
                                <?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $admin['role']))), ENT_QUOTES, 'UTF-8') ?>
                            </span>

                        </div>

                        <i class="bi bi-chevron-down"></i>

                    </button>


                    <ul class="dropdown-menu dropdown-menu-end">

                        <li>

                            <a
                                class="dropdown-item"
                                href="#">

                                <i class="bi bi-person me-2"></i>
                                My Profile

                            </a>

                        </li>

                        <li>

                            <a
                                class="dropdown-item"
                                href="../settings/">

                                <i class="bi bi-gear me-2"></i>
                                Settings

                            </a>

                        </li>

                        <li>
                            <hr class="dropdown-divider">
                        </li>

                        <li>

                            <form method="post" action="<?= htmlspecialchars(APP_BASE_URL . '/logout.php', ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="dropdown-item text-danger border-0 bg-transparent text-start w-100">

                                    <i class="bi bi-box-arrow-right me-2"></i>
                                    Sign Out

                                </button>
                            </form>

                        </li>

                    </ul>

                </div>

            </div>

        </header>


        <!-- =====================================================
         CONTENT
         ===================================================== -->

        <main class="admin-content">


            <!-- Welcome -->

            <section class="dashboard-heading">

                <div>

                    <h2>
                        Good morning, <?= htmlspecialchars(explode(' ', $admin['name'])[0], ENT_QUOTES, 'UTF-8') ?>
                    </h2>

                    <p>
                        <?= $dashboardDataAvailable ? 'Live UtsavDesk activity and booking overview.' : 'CMS dashboard data is temporarily unavailable.' ?>
                    </p>

                </div>


                <div class="dashboard-date">

                    <i class="bi bi-calendar3"></i>

                    <?= date('d M Y') ?>

                </div>

            </section>

            <?php if (!$dashboardDataAvailable): ?>
                <div class="alert alert-warning" role="status">
                    Dashboard metrics could not be refreshed from the CMS database.
                </div>
            <?php endif; ?>


            <!-- =================================================
             KPI CARDS
             ================================================= -->

            <?php if ($subscriptionSummary && in_array($_SESSION['PLATFORM_ADMIN']['ROLE'], ['SUPER_ADMIN','ADMIN','FINANCE','VIEWER'], true)): ?>
            <section class="dashboard-stat-grid mb-3">
                <div class="dashboard-stat-card p-3"><span>Active user subscriptions</span><h3><?= number_format((int)$subscriptionSummary['active']) ?></h3><a href="../subscriptions/">View subscriptions</a></div>
                <div class="dashboard-stat-card p-3"><span>Subscription receipts this month</span><h3>₹<?= number_format((float)$subscriptionSummary['month_received'],2) ?></h3><a href="../billing/">View billing</a></div>
                <div class="dashboard-stat-card p-3"><span>Expiring within 7 days</span><h3><?= number_format((int)$subscriptionSummary['expiring']) ?></h3><a href="../subscriptions/?status=ACTIVE">Review active terms</a></div>
            </section>
            <?php endif; ?>
            <section class="dashboard-stat-grid">


                <!-- Caterers -->

                <div class="dashboard-stat-card">

                    <div class="stat-card-top">

                        <div class="stat-icon stat-icon-primary">

                            <i class="bi bi-buildings"></i>

                        </div>


                    </div>


                    <div class="stat-card-value">

                        <?= number_format(
                            $stats['total_caterers']
                        ) ?>

                    </div>


                    <div class="stat-card-label">
                        Total Caterers
                    </div>


                    <div class="stat-card-footer">

                        Registered platform accounts

                    </div>

                </div>


                <!-- Active -->

                <div class="dashboard-stat-card">

                    <div class="stat-card-top">

                        <div class="stat-icon stat-icon-success">

                            <i class="bi bi-patch-check"></i>

                        </div>


                    </div>


                    <div class="stat-card-value">

                        <?= number_format(
                            $stats['active_caterers']
                        ) ?>

                    </div>


                    <div class="stat-card-label">
                        Active Caterers
                    </div>


                    <div class="stat-card-footer">

                        <?= $activeRate ?>%

                        platform activation

                    </div>

                </div>


                <!-- Events -->

                <div class="dashboard-stat-card">

                    <div class="stat-card-top">

                        <div class="stat-icon stat-icon-info">

                            <i class="bi bi-calendar2-event"></i>

                        </div>


                    </div>


                    <div class="stat-card-value">

                        <?= number_format(
                            $stats['upcoming_events']
                        ) ?>

                    </div>


                    <div class="stat-card-label">
                        Upcoming Events
                    </div>


                    <div class="stat-card-footer">

                        <?= number_format($stats['events_created_this_month']) ?> created this month

                    </div>

                </div>


                <!-- Revenue -->

                <div class="dashboard-stat-card">

                    <div class="stat-card-top">

                        <div class="stat-icon stat-icon-warning">

                            <i class="bi bi-currency-rupee"></i>

                        </div>


                    </div>


                    <div class="stat-card-value">

                        <?= moneyFormat(
                            $stats['total_booking_value']
                        ) ?>

                    </div>


                    <div class="stat-card-label">
                        Booked Contract Value
                    </div>


                    <div class="stat-card-footer">

                        Excludes cancelled events

                    </div>

                </div>

            </section>


            <!-- =================================================
             CHARTS
             ================================================= -->

            <section class="row g-4 mb-4">


                <!-- Revenue -->

                <div class="col-xl-8">

                    <div class="dashboard-panel h-100">

                        <div class="dashboard-panel-header">

                            <div>

                                <h3>Booking Value</h3>

                                <p>
                                    Contract value by event creation month
                                </p>

                            </div>


                            <span class="dashboard-select">Last 12 months</span>

                        </div>


                        <div class="chart-container">

                            <canvas id="revenueChart"></canvas>

                        </div>

                    </div>

                </div>


                <!-- Lead Pipeline -->

                <div class="col-xl-4">

                    <div class="dashboard-panel h-100">

                        <div class="dashboard-panel-header">

                            <div>

                                <h3>
                                    Lead Pipeline
                                </h3>

                                <p>
                                    Active customer enquiries by stage
                                </p>

                            </div>

                        </div>


                        <div class="subscription-chart-wrapper">

                            <div class="donut-container">

                                <canvas id="leadChart"></canvas>

                            </div>


                            <div class="subscription-total">

                                <strong>
                                    <?= number_format($leadTotal) ?>
                                </strong>

                                <span>
                                    Leads
                                </span>

                            </div>

                        </div>


                        <div class="subscription-legend">

                            <?php foreach ($leadStatusSummary as $leadStatus): ?>
                                <div>
                                    <span class="legend-dot" style="background-color: <?= htmlspecialchars($leadStatus['color'], ENT_QUOTES, 'UTF-8') ?>"></span>
                                    <span><?= htmlspecialchars($leadStatus['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <strong><?= number_format($leadStatus['total']) ?></strong>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($leadStatusSummary === []): ?>
                                <p class="text-muted small mb-0">No lead records available.</p>
                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </section>


            <!-- =================================================
             CATERERS + QUICK ACTIONS
             ================================================= -->

            <section class="row g-4 mb-4">


                <!-- Recent Caterers -->

                <div class="col-xl-8">

                    <div class="dashboard-panel">

                        <div class="dashboard-panel-header">

                            <div>

                                <h3>
                                    Recent Caterers
                                </h3>

                                <p>
                                    Recently registered businesses
                                </p>

                            </div>


                            <a
                                href="../caterers/"
                                class="panel-view-link">

                                View all

                                <i class="bi bi-arrow-right"></i>

                            </a>

                        </div>


                        <div class="table-responsive">

                            <table class="dashboard-table">

                                <thead>

                                    <tr>

                                        <th>Caterer</th>

                                        <th>Location</th>

                                        <th>Events</th>

                                        <th>Status</th>

                                        <th>Joined</th>

                                        <th></th>

                                    </tr>

                                </thead>


                                <tbody>

                                    <?php foreach ($recentCaterers as $caterer): ?>

                                        <tr>

                                            <td>

                                                <div class="table-business">

                                                    <div class="business-avatar">

                                                        <?= strtoupper(
                                                            substr(
                                                                $caterer['name'],
                                                                0,
                                                                1
                                                            )
                                                        ) ?>

                                                    </div>


                                                    <div>

                                                        <strong>

                                                            <?= htmlspecialchars(
                                                                $caterer['name']
                                                            ) ?>

                                                        </strong>

                                                        <span>

                                                            <?= htmlspecialchars(
                                                                $caterer['code']
                                                            ) ?>

                                                        </span>

                                                    </div>

                                                </div>

                                            </td>


                                            <td>

                                                <?= htmlspecialchars(
                                                    $caterer['city']
                                                ) ?>

                                            </td>


                                            <td><?= number_format((int) $caterer['event_count']) ?></td>


                                            <td>

                                                <span class="status-badge status-<?=
                                                                                    strtolower(
                                                                                        $caterer['status']
                                                                                    )
                                                                                    ?>">

                                                    <span></span>

                                                    <?= ucfirst(
                                                        strtolower(
                                                            $caterer['status']
                                                        )
                                                    ) ?>

                                                </span>

                                            </td>


                                            <td>

                                                <?= htmlspecialchars(
                                                    $caterer['date']
                                                ) ?>

                                            </td>


                                            <td>

                                                <a
                                                    href="../caterers/view.php?code=<?=
                                                                                    urlencode(
                                                                                        $caterer['code']
                                                                                    )
                                                                                    ?>"
                                                    class="table-action">

                                                    <i class="bi bi-chevron-right"></i>

                                                </a>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                    <?php if ($recentCaterers === []): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">No caterer records available.</td>
                                        </tr>
                                    <?php endif; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>


                <!-- Quick Actions -->

                <div class="col-xl-4">

                    <div class="dashboard-panel">

                        <div class="dashboard-panel-header">

                            <div>

                                <h3>
                                    Quick Actions
                                </h3>

                                <p>
                                    Common admin operations
                                </p>

                            </div>

                        </div>


                        <div class="quick-actions">


                            <a
                                href="../caterers/add.php"
                                class="quick-action">

                                <div class="quick-action-icon primary">

                                    <i class="bi bi-building-add"></i>

                                </div>

                                <div>

                                    <strong>
                                        Add Caterer
                                    </strong>

                                    <span>
                                        Register new business
                                    </span>

                                </div>

                                <i class="bi bi-chevron-right"></i>

                            </a>


                            <a
                                href="../subscriptions/"
                                class="quick-action">

                                <div class="quick-action-icon success">

                                    <i class="bi bi-stars"></i>

                                </div>

                                <div>

                                    <strong>
                                        Subscriptions
                                    </strong>

                                    <span>
                                        Manage active plans
                                    </span>

                                </div>

                                <i class="bi bi-chevron-right"></i>

                            </a>


                            <a
                                href="../support/"
                                class="quick-action">

                                <div class="quick-action-icon warning">

                                    <i class="bi bi-headset"></i>

                                </div>

                                <div>

                                    <strong>
                                        Support Tickets
                                    </strong>

                                    <span>
                                        Open the support queue
                                    </span>

                                </div>

                                <i class="bi bi-chevron-right"></i>

                            </a>


                            <a
                                href="../announcements/"
                                class="quick-action">

                                <div class="quick-action-icon info">

                                    <i class="bi bi-megaphone"></i>

                                </div>

                                <div>

                                    <strong>
                                        Announcement
                                    </strong>

                                    <span>
                                        Notify platform users
                                    </span>

                                </div>

                                <i class="bi bi-chevron-right"></i>

                            </a>

                        </div>

                    </div>

                </div>

            </section>


            <!-- =================================================
             ACTIVITY
             ================================================= -->

            <section class="row g-4">


                <div class="col-xl-7">

                    <div class="dashboard-panel">

                        <div class="dashboard-panel-header">

                            <div>

                                <h3>
                                    Recent Activity
                                </h3>

                                <p>
                                    Latest platform actions
                                </p>

                            </div>


                            <a
                                href="../activity/"
                                class="panel-view-link">

                                View logs

                                <i class="bi bi-arrow-right"></i>

                            </a>

                        </div>


                        <div class="activity-list">

                            <?php foreach ($activities as $activity): ?>

                                <div class="activity-item">


                                    <div class="activity-icon <?= $activity['type'] ?>">

                                        <i class="bi <?= $activity['icon'] ?>"></i>

                                    </div>


                                    <div class="activity-content">

                                        <strong>

                                            <?= htmlspecialchars(
                                                $activity['title']
                                            ) ?>

                                        </strong>

                                        <p>

                                            <?= htmlspecialchars(
                                                $activity['description']
                                            ) ?>

                                        </p>

                                    </div>


                                    <span class="activity-time">

                                        <?= htmlspecialchars(
                                            $activity['time']
                                        ) ?>

                                    </span>

                                </div>

                            <?php endforeach; ?>

                            <?php if ($activities === []): ?>
                                <div class="text-muted small py-3">No recent activity records.</div>
                            <?php endif; ?>

                        </div>

                    </div>

                </div>


                <!-- Platform Health -->

                <div class="col-xl-5">

                    <div class="dashboard-panel">

                        <div class="dashboard-panel-header">

                            <div>

                                <h3>
                                    Platform Health
                                </h3>

                                <p>
                                    Current system overview
                                </p>

                            </div>


                            <?php if ($dashboardDataAvailable): ?>
                                <span class="system-operational"><span></span>Connected</span>
                            <?php else: ?>
                                <span class="status-badge status-inactive">CMS unavailable</span>
                            <?php endif; ?>

                        </div>


                        <div class="health-list">


                            <div class="health-item">

                                <div>

                                    <i class="bi bi-database"></i>

                                    <span>
                                        CMS Database
                                    </span>

                                </div>

                                <strong class="<?= $dashboardDataAvailable ? 'health-good' : 'text-danger' ?>">
                                    <?= $dashboardDataAvailable ? 'Connected' : 'Unavailable' ?>
                                </strong>

                            </div>


                            <div class="health-item">

                                <div>

                                    <i class="bi bi-envelope"></i>

                                    <span>
                                        Caterer Records
                                    </span>

                                </div>

                                <strong class="health-good">
                                    <?= number_format($stats['total_caterers']) ?>
                                </strong>

                            </div>


                            <div class="health-item">

                                <div>

                                    <i class="bi bi-cloud"></i>

                                    <span>
                                        Event Records
                                    </span>

                                </div>

                                <strong class="health-good">
                                    <?= number_format((int) ($eventStats['total'] ?? 0)) ?>
                                </strong>

                            </div>


                            <div class="health-item">

                                <div>

                                    <i class="bi bi-shield-check"></i>

                                    <span>
                                        Recent Activity
                                    </span>

                                </div>

                                <strong class="health-good">
                                    <?= number_format(count($activities)) ?> loaded
                                </strong>

                            </div>

                        </div>

                    </div>

                </div>

            </section>


            <!-- Footer -->

            <footer class="dashboard-footer">

                <span>
                    © <?= date('Y') ?> UtsavDesk
                </span>

                <span>
                    Platform Administration
                </span>

            </footer>

        </main>

    </div>


    <!-- jQuery -->

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>


    <!-- Bootstrap -->

    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js">
    </script>


    <!-- Chart.js -->

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


    <!-- Central JS -->

    <script src="../assets/js/admin.js"></script>


    <script>
        const dashboardChartData = <?= json_encode([
                                        'bookingLabels' => $chartLabels,
                                        'bookingValues' => $chartValues,
                                        'leadLabels' => $leadTotal > 0 ? array_column($leadStatusSummary, 'label') : ['No leads'],
                                        'leadValues' => $leadTotal > 0 ? array_column($leadStatusSummary, 'total') : [1],
                                        'leadColors' => $leadTotal > 0 ? array_column($leadStatusSummary, 'color') : ['#dfe2e7'],
                                    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        document.addEventListener('DOMContentLoaded', function() {

            /*
            |--------------------------------------------------------------------------
            | Revenue Chart
            |--------------------------------------------------------------------------
            */

            const revenueCanvas = document.getElementById('revenueChart');

            if (revenueCanvas) {

                const revenueContext = revenueCanvas.getContext('2d');

                const gradient = revenueContext.createLinearGradient(
                    0,
                    0,
                    0,
                    300
                );

                gradient.addColorStop(
                    0,
                    'rgba(8,127,115,0.18)'
                );

                gradient.addColorStop(
                    1,
                    'rgba(8,127,115,0)'
                );


                new Chart(revenueCanvas, {

                    type: 'line',

                    data: {

                        labels: dashboardChartData.bookingLabels,

                        datasets: [{

                            label: 'Booking Value',

                            data: dashboardChartData.bookingValues,

                            borderColor: '#087f73',

                            backgroundColor: gradient,

                            borderWidth: 2.5,

                            pointRadius: 0,

                            pointHoverRadius: 5,

                            pointHoverBackgroundColor: '#087f73',

                            fill: true,

                            tension: 0.4

                        }]

                    },

                    options: {

                        responsive: true,

                        maintainAspectRatio: false,

                        interaction: {
                            intersect: false,
                            mode: 'index'
                        },

                        plugins: {

                            legend: {
                                display: false
                            },

                            tooltip: {

                                callbacks: {

                                    label: function(context) {

                                        return ' ₹' +
                                            context.raw +
                                            ' L';

                                    }

                                }

                            }

                        },

                        scales: {

                            x: {

                                grid: {
                                    display: false
                                },

                                border: {
                                    display: false
                                },

                                ticks: {
                                    color: '#8a8f98'
                                }

                            },

                            y: {

                                beginAtZero: true,

                                border: {
                                    display: false
                                },

                                grid: {
                                    color: '#f0f0f2'
                                },

                                ticks: {

                                    color: '#8a8f98',

                                    callback: function(value) {

                                        return '₹' + value + 'L';

                                    }

                                }

                            }

                        }

                    }

                });

            }


            /* Lead pipeline */

            const subscriptionCanvas =
                document.getElementById('leadChart');


            if (subscriptionCanvas) {

                new Chart(subscriptionCanvas, {

                    type: 'doughnut',

                    data: {

                        labels: dashboardChartData.leadLabels,

                        datasets: [{

                            data: dashboardChartData.leadValues,

                            backgroundColor: dashboardChartData.leadColors,

                            borderWidth: 0,

                            hoverOffset: 4

                        }]

                    },

                    options: {

                        responsive: true,

                        maintainAspectRatio: false,

                        cutout: '75%',

                        plugins: {

                            legend: {
                                display: false
                            }

                        }

                    }

                });

            }

        });
    </script>

</body>

</html>