<?php

declare(strict_types=1);

require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/activity.php';

$admin = [
    'id' => (int) ($_SESSION['PLATFORM_ADMIN']['ID'] ?? 0),
    'name' => (string) ($_SESSION['PLATFORM_ADMIN']['NAME'] ?? 'Platform Admin'),
    'email' => (string) ($_SESSION['PLATFORM_ADMIN']['EMAIL'] ?? ''),
    'role' => (string) ($_SESSION['PLATFORM_ADMIN']['ROLE'] ?? 'ADMIN'),
];

$summary = [
    'today' => 0,
    'admin_actions' => 0,
    'security' => 0,
    'failed_logins' => 0,
    'caterer_actions' => 0,
];

$activities = [];
$activityError = '';

$typeFilter = in_array(strtoupper((string) ($_GET['type'] ?? '')), adminActivityTypes(), true)
    ? strtoupper((string) $_GET['type'])
    : '';
$actionFilter = in_array(strtoupper((string) ($_GET['action'] ?? '')), adminActivityActions(), true)
    ? strtoupper((string) $_GET['action'])
    : '';
$severityFilter = in_array(strtoupper((string) ($_GET['severity'] ?? '')), adminActivitySeverities(), true)
    ? strtoupper((string) $_GET['severity'])
    : '';
$searchFilter = is_string($_GET['q'] ?? null) ? trim((string) $_GET['q']) : '';
$dateFilter = in_array((string) ($_GET['date'] ?? ''), ['today', '7', '30'], true)
    ? (string) $_GET['date']
    : '';

try {
    $db = databaseConnection();
    $summary = adminActivitySummary($db);
    $activities = adminActivityList($db, [
        'type' => $typeFilter,
        'action' => $actionFilter,
        'severity' => $severityFilter,
        'search' => $searchFilter,
        'date_window' => $dateFilter,
    ]);
} catch (Throwable $exception) {
    error_log('Activity page failed: ' . $exception->getMessage());
    $activityError = 'Activity data is temporarily unavailable. Please retry.';
}

function initials(string $name): string
{
    if ($name === 'System') {
        return 'SY';
    }

    $parts = preg_split('/\s+/', trim($name));
    $initials = '';

    foreach (array_slice($parts ?: [], 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials ?: 'UD';
}

function actionLabel(string $action): string
{
    return ucwords(strtolower(str_replace('_', ' ', $action)));
}
?>
<!doctype html>

<html lang="en">

<?php renderAdminHead($pageTitle, $pageStylesheets); ?>

<body class="admin-body">

    <?php require __DIR__ . '/../components/sidebar.php'; ?>

    <div class="admin-main">

        <header class="admin-topbar">

            <div class="topbar-left">
                <button type="button" class="mobile-menu-button" id="sidebarOpen">
                    <i class="bi bi-list"></i>
                </button>

                <div class="topbar-title">
                    <h1>Activity Logs</h1>
                    <span>Platform audit trail and security activity</span>
                </div>
            </div>

            <div class="topbar-actions">
                <button type="button" class="topbar-action-btn d-none d-md-flex">
                    <i class="bi bi-search"></i>
                </button>

                <button type="button" class="topbar-action-btn notification-button">
                    <i class="bi bi-bell"></i>
                    <span class="notification-dot"></span>
                </button>

                <div class="dropdown">
                    <button type="button" class="topbar-profile" data-bs-toggle="dropdown">
                        <div class="topbar-avatar"><?= htmlspecialchars(initials($admin['name'])) ?></div>

                        <div class="topbar-profile-info d-none d-md-block">
                            <strong><?= htmlspecialchars($admin['name']) ?></strong>
                            <span><?= htmlspecialchars(strtoupper($admin['role'])) ?></span>
                        </div>

                        <i class="bi bi-chevron-down"></i>
                    </button>

                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item" href="#">
                                <i class="bi bi-person me-2"></i>My Profile
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="../settings/">
                                <i class="bi bi-gear me-2"></i>Settings
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= htmlspecialchars(APP_BASE_URL . '/logout.php', ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="dropdown-item text-danger border-0 bg-transparent text-start w-100">
                                    <i class="bi bi-box-arrow-right me-2"></i>Sign Out
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>

        </header>

        <main class="admin-content">

            <section class="activity-heading">
                <div>
                    <h2>Platform Activity</h2>
                    <p>Review administrative actions, security events and system changes.</p>
                </div>

                <div class="activity-heading-actions">
                    <button type="button" class="activity-export-button">
                        <i class="bi bi-download"></i>
                        <span>Export Logs</span>
                    </button>
                </div>
            </section>

            <section class="activity-summary-grid">
                <article class="activity-summary-card">
                    <div class="activity-summary-icon today"><i class="bi bi-activity"></i></div>
                    <div>
                        <span>Activity Today</span>
                        <strong><?= number_format($summary['today']) ?></strong>
                        <small>All platform actions</small>
                    </div>
                </article>

                <article class="activity-summary-card activity-filter-card" data-type-card="ADMIN">
                    <div class="activity-summary-icon admin"><i class="bi bi-person-gear"></i></div>
                    <div>
                        <span>Admin Actions</span>
                        <strong><?= number_format($summary['admin_actions']) ?></strong>
                        <small>Today</small>
                    </div>
                </article>

                <article class="activity-summary-card activity-filter-card" data-type-card="SECURITY">
                    <div class="activity-summary-icon security"><i class="bi bi-shield-lock"></i></div>
                    <div>
                        <span>Security Events</span>
                        <strong><?= number_format($summary['security']) ?></strong>
                        <small>Last 24 hours</small>
                    </div>
                </article>

                <article class="activity-summary-card activity-filter-card" data-action-card="LOGIN_FAILED">
                    <div class="activity-summary-icon failed"><i class="bi bi-exclamation-triangle"></i></div>
                    <div>
                        <span>Failed Logins</span>
                        <strong><?= number_format($summary['failed_logins']) ?></strong>
                        <small>Last 24 hours</small>
                    </div>
                </article>

                <article class="activity-summary-card activity-filter-card" data-type-card="CATERER">
                    <div class="activity-summary-icon caterer"><i class="bi bi-buildings"></i></div>
                    <div>
                        <span>Caterer Actions</span>
                        <strong><?= number_format($summary['caterer_actions']) ?></strong>
                        <small>Platform management</small>
                    </div>
                </article>
            </section>

            <section class="activity-panel">
                <div class="activity-tabs">
                    <button type="button" class="activity-tab active" data-log-type="">All Activity</button>
                    <button type="button" class="activity-tab" data-log-type="SECURITY">Security</button>
                    <button type="button" class="activity-tab" data-log-type="CATERER">Caterers</button>
                    <button type="button" class="activity-tab" data-log-type="SUBSCRIPTION">Subscriptions</button>
                    <button type="button" class="activity-tab" data-log-type="BILLING">Billing</button>
                    <button type="button" class="activity-tab" data-log-type="SUPPORT">Support</button>
                </div>

                <div class="activity-toolbar">
                    <div class="activity-search">
                        <i class="bi bi-search"></i>
                        <input type="search" id="activitySearch" placeholder="Search actor, caterer, action, IP..." autocomplete="off">
                    </div>

                    <div class="activity-filters">
                        <select id="activityActionFilter">
                            <option value="">All Actions</option>
                            <option value="CREATE">Create</option>
                            <option value="UPDATE">Update</option>
                            <option value="LOGIN">Login</option>
                            <option value="LOGIN_FAILED">Failed Login</option>
                            <option value="PAYMENT">Payment</option>
                            <option value="STATUS_CHANGE">Status Change</option>
                            <option value="SUSPEND">Suspend</option>
                        </select>

                        <select id="activitySeverityFilter">
                            <option value="">All Severity</option>
                            <option value="INFO">Information</option>
                            <option value="SUCCESS">Success</option>
                            <option value="WARNING">Warning</option>
                            <option value="DANGER">Critical</option>
                        </select>

                        <select id="activityDateFilter">
                            <option value="">All Dates</option>
                            <option value="today">Today</option>
                            <option value="7">Last 7 Days</option>
                            <option value="30">Last 30 Days</option>
                        </select>

                        <button type="button" id="clearActivityFilters" title="Clear filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                    </div>
                </div>

                <?php if ($activityError !== ''): ?>
                    <div class="alert alert-warning mt-3 mb-0"><?= htmlspecialchars($activityError) ?></div>
                <?php endif; ?>

                <div class="activity-list">
                    <?php foreach ($activities as $activity): ?>
                        <?php
                        $activityType = strtoupper((string) ($activity['type'] ?? 'ADMIN'));
                        $activityAction = strtoupper((string) ($activity['action'] ?? 'VIEW'));
                        $activitySeverity = strtoupper((string) ($activity['severity'] ?? 'INFO'));
                        $activityActor = trim((string) ($activity['actor_name'] ?? 'System'));
                        $activityTarget = trim((string) ($activity['subject_name'] ?? 'System'));
                        $activityCreatedAt = (string) ($activity['created_at'] ?? date('Y-m-d H:i:s'));
                        $activityDate = date('d M Y', strtotime($activityCreatedAt));
                        $activityTime = date('h:i A', strtotime($activityCreatedAt));
                        $activityRelative = adminActivityRelativeLabel($activityCreatedAt);

                        switch ($activityType) {
                            case 'SECURITY':
                                $icon = 'bi-shield-lock';
                                break;
                            case 'CATERER':
                                $icon = 'bi-buildings';
                                break;
                            case 'SUBSCRIPTION':
                                $icon = 'bi-stars';
                                break;
                            case 'BILLING':
                                $icon = 'bi-credit-card';
                                break;
                            case 'SUPPORT':
                                $icon = 'bi-headset';
                                break;
                            case 'ADMIN':
                                $icon = 'bi-person-gear';
                                break;
                            case 'SETTINGS':
                                $icon = 'bi-gear';
                                break;
                            case 'ANNOUNCEMENT':
                                $icon = 'bi-megaphone';
                                break;
                            default:
                                $icon = 'bi-activity';
                                break;
                        }
                        ?>

                        <article class="activity-item"
                            data-type="<?= htmlspecialchars($activityType) ?>"
                            data-action="<?= htmlspecialchars($activityAction) ?>"
                            data-severity="<?= htmlspecialchars($activitySeverity) ?>"
                            data-search="<?= htmlspecialchars(strtolower((string) ($activity['log_code'] ?? '') . ' ' . $activityActor . ' ' . ($activity['description'] ?? '') . ' ' . $activityTarget . ' ' . ($activity['ip_address'] ?? '') . ' ' . $activityType . ' ' . $activityAction)) ?>">

                            <div class="activity-item-icon severity-<?= strtolower($activitySeverity) ?>">
                                <i class="bi <?= $icon ?>"></i>
                            </div>

                            <div class="activity-item-main">
                                <div class="activity-item-heading">
                                    <div>
                                        <span class="activity-module"><?= htmlspecialchars(ucfirst(strtolower($activityType))) ?></span>
                                        <span class="activity-action action-<?= strtolower(str_replace('_', '-', $activityAction)) ?>"><?= htmlspecialchars(actionLabel($activityAction)) ?></span>
                                        <?php if ($activitySeverity === 'WARNING' || $activitySeverity === 'DANGER'): ?>
                                            <span class="activity-severity severity-label-<?= strtolower($activitySeverity) ?>"><?= htmlspecialchars($activitySeverity) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <time><?= htmlspecialchars($activityRelative) ?></time>
                                </div>

                                <p><?= htmlspecialchars((string) ($activity['description'] ?? '')) ?></p>

                                <div class="activity-meta">
                                    <span>
                                        <i class="bi bi-person"></i>
                                        <?= htmlspecialchars($activityActor) ?>
                                        <?php if (!empty($activity['actor_role'])): ?>
                                            <small><?= htmlspecialchars((string) $activity['actor_role']) ?></small>
                                        <?php endif; ?>
                                    </span>

                                    <span>
                                        <i class="bi bi-bullseye"></i>
                                        <?= htmlspecialchars($activityTarget ?: 'System') ?>
                                    </span>

                                    <span>
                                        <i class="bi bi-globe2"></i>
                                        <?= htmlspecialchars((string) ($activity['ip_address'] ?? '—')) ?>
                                    </span>

                                    <span>
                                        <i class="bi bi-laptop"></i>
                                        <?= htmlspecialchars((string) ($activity['device'] ?? 'Web Browser')) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="activity-date">
                                <strong><?= htmlspecialchars($activityDate) ?></strong>
                                <span><?= htmlspecialchars($activityTime) ?></span>
                                <small><?= htmlspecialchars((string) ($activity['log_code'] ?? '')) ?></small>
                            </div>

                            <button type="button" class="activity-view-button" data-bs-toggle="modal" data-bs-target="#activityDetailModal" data-code="<?= htmlspecialchars((string) ($activity['log_code'] ?? '')) ?>" data-type="<?= htmlspecialchars($activityType) ?>" data-module="<?= htmlspecialchars((string) ($activity['module'] ?? $activityType)) ?>" data-action="<?= htmlspecialchars(actionLabel($activityAction)) ?>" data-actor="<?= htmlspecialchars($activityActor) ?>" data-role="<?= htmlspecialchars((string) ($activity['actor_role'] ?? 'ADMIN')) ?>" data-description="<?= htmlspecialchars((string) ($activity['description'] ?? '')) ?>" data-target="<?= htmlspecialchars($activityTarget ?: 'System') ?>" data-target-code="<?= htmlspecialchars((string) ($activity['subject_id'] ?? '')) ?>" data-ip="<?= htmlspecialchars((string) ($activity['ip_address'] ?? '—')) ?>" data-device="<?= htmlspecialchars((string) ($activity['device'] ?? 'Web Browser')) ?>" data-date="<?= htmlspecialchars($activityDate . ' · ' . $activityTime) ?>" data-severity="<?= htmlspecialchars($activitySeverity) ?>">
                                <i class="bi bi-chevron-right"></i>
                            </button>
                        </article>
                    <?php endforeach; ?>

                    <div class="activity-empty d-none" id="activityEmpty">
                        <div><i class="bi bi-clock-history"></i></div>
                        <h4>No activity found</h4>
                        <p>Try changing your search or filter options.</p>
                        <button type="button" id="activityEmptyClear">Clear Filters</button>
                    </div>
                </div>

                <div class="activity-pagination">
                    <span>
                        Showing <strong id="visibleActivityCount"><?= count($activities) ?></strong> of <strong><?= count($activities) ?></strong> activity records
                    </span>

                    <div>
                        <button disabled><i class="bi bi-chevron-left"></i></button>
                        <button class="active">1</button>
                        <button>2</button>
                        <button>3</button>
                        <span>...</span>
                        <button>25</button>
                        <button><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>
            </section>

            <footer class="dashboard-footer">
                <span>© <?= date('Y') ?> UtsavDesk</span>
                <span>Platform Administration</span>
            </footer>
        </main>
    </div>

    <div class="modal fade" id="activityDetailModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content activity-detail-modal">
                <div class="modal-header">
                    <div>
                        <span class="activity-modal-code" id="modalLogCode">LOG-000000</span>
                        <h5 class="modal-title">Activity Details</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="activity-detail-description">
                        <div class="activity-detail-large-icon" id="modalSeverityIcon"><i class="bi bi-activity"></i></div>
                        <div>
                            <span>Event</span>
                            <p id="modalDescription">—</p>
                        </div>
                    </div>

                    <div class="activity-detail-grid">
                        <div><span>Type</span><strong id="modalType">—</strong></div>
                        <div><span>Module</span><strong id="modalModule">—</strong></div>
                        <div><span>Action</span><strong id="modalAction">—</strong></div>
                        <div><span>Severity</span><strong id="modalSeverity">—</strong></div>
                        <div><span>Performed By</span><strong id="modalActor">—</strong></div>
                        <div><span>Admin Role</span><strong id="modalRole">—</strong></div>
                        <div><span>Target</span><strong id="modalTarget">—</strong></div>
                        <div><span>Target ID</span><strong id="modalTargetCode">—</strong></div>
                        <div><span>IP Address</span><strong id="modalIp">—</strong></div>
                        <div><span>Device</span><strong id="modalDevice">—</strong></div>
                        <div class="activity-detail-wide"><span>Date &amp; Time</span><strong id="modalDate">—</strong></div>
                    </div>

                    <div class="activity-change-preview">
                        <div class="activity-change-heading">
                            <div><i class="bi bi-code-square"></i>Change Data</div>
                            <span>Audit payload</span>
                        </div>

                        <div class="activity-json">
                            <pre>{
    "previous_value": "STARTER",
    "new_value": "PRO",
    "changed_by": "platform_admin",
    "source": "utsavdesk-admin"
}</pre>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="activity-modal-close" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>

    <script>
        $(function () {
            let activeType = '';
            let activeAction = '';

            function filterActivity() {
                const search = $.trim($('#activitySearch').val()).toLowerCase();
                const action = activeAction || $('#activityActionFilter').val();
                const severity = $('#activitySeverityFilter').val();
                let visible = 0;

                $('.activity-item').each(function () {
                    const $item = $(this);
                    const searchData = String($item.data('search') || '');
                    const type = String($item.data('type') || '');
                    const itemAction = String($item.data('action') || '');
                    const itemSeverity = String($item.data('severity') || '');

                    const matchesSearch = !search || searchData.includes(search);
                    const matchesType = !activeType || type === activeType;
                    const matchesAction = !action || itemAction === action;
                    const matchesSeverity = !severity || itemSeverity === severity;
                    const show = matchesSearch && matchesType && matchesAction && matchesSeverity;

                    $item.toggle(show);
                    if (show) {
                        visible++;
                    }
                });

                $('#visibleActivityCount').text(visible);
                $('#activityEmpty').toggleClass('d-none', visible !== 0);
            }

            $('#activitySearch').on('input', filterActivity);
            $('#activityActionFilter, #activitySeverityFilter, #activityDateFilter').on('change', filterActivity);

            $('.activity-tab').on('click', function () {
                $('.activity-tab').removeClass('active');
                $(this).addClass('active');
                activeType = String($(this).data('log-type') || '');
                activeAction = '';
                $('#activityActionFilter').val('');
                filterActivity();
            });

            $('[data-type-card]').on('click', function () {
                activeType = String($(this).data('type-card') || '');
                activeAction = '';
                $('.activity-tab').removeClass('active');
                $('.activity-tab').each(function () {
                    if (String($(this).data('log-type')) === activeType) {
                        $(this).addClass('active');
                    }
                });
                filterActivity();
            });

            $('[data-action-card]').on('click', function () {
                activeType = '';
                activeAction = String($(this).data('action-card') || '');
                $('#activityActionFilter').val(activeAction);
                $('.activity-tab').removeClass('active').first().addClass('active');
                filterActivity();
            });

            function clearActivityFilters() {
                activeType = '';
                activeAction = '';
                $('#activitySearch').val('');
                $('#activityActionFilter').val('');
                $('#activitySeverityFilter').val('');
                $('#activityDateFilter').val('');
                $('.activity-tab').removeClass('active').first().addClass('active');
                filterActivity();
            }

            $('#clearActivityFilters').on('click', clearActivityFilters);
            $('#activityEmptyClear').on('click', clearActivityFilters);

            $('.activity-view-button').on('click', function () {
                const $button = $(this);
                $('#modalLogCode').text($button.data('code'));
                $('#modalType').text($button.data('type'));
                $('#modalModule').text($button.data('module'));
                $('#modalAction').text($button.data('action'));
                $('#modalActor').text($button.data('actor'));
                $('#modalRole').text($button.data('role'));
                $('#modalDescription').text($button.data('description'));
                $('#modalTarget').text($button.data('target'));
                $('#modalTargetCode').text($button.data('target-code') || '—');
                $('#modalIp').text($button.data('ip'));
                $('#modalDevice').text($button.data('device'));
                $('#modalDate').text($button.data('date'));
                $('#modalSeverity').text($button.data('severity'));

                const severity = String($button.data('severity')).toLowerCase();
                $('#modalSeverityIcon').removeClass('info success warning danger').addClass(severity);
            });
        });
    </script>

</body>

</html>
