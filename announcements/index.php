<?php

declare(strict_types=1);
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../common/cms_db.php';
require_once __DIR__ . '/../common/notifications.php';

$admin = $_SESSION['PLATFORM_ADMIN'] ?? ['NAME' => 'System Admin', 'ROLE' => 'SUPER_ADMIN'];
$adminRole = (string) ($admin['ROLE'] ?? 'ADMIN');
if (!in_array($adminRole, ['SUPER_ADMIN', 'ADMIN'], true)) {
    http_response_code(403);
    require __DIR__ . '/../unauthorized/index.php';
    exit;
}

$cms = null;
$summary = [
    'total' => 0,
    'published' => 0,
    'scheduled' => 0,
    'draft' => 0,
    'expired' => 0,
];
$notifications = [];

try {
    $cms = cmsDatabaseConnection();
    userNotificationEnsureTable($cms);

    $summary['total'] = (int) $cms->query('SELECT COUNT(*) FROM user_announcement')->fetchColumn();
    $summary['published'] = (int) $cms->query("SELECT COUNT(*) FROM user_announcement WHERE status = 'PUBLISHED'")->fetchColumn();
    $summary['scheduled'] = (int) $cms->query("SELECT COUNT(*) FROM user_announcement WHERE status = 'SCHEDULED'")->fetchColumn();
    $summary['draft'] = (int) $cms->query("SELECT COUNT(*) FROM user_announcement WHERE status = 'DRAFT'")->fetchColumn();
    $summary['expired'] = (int) $cms->query("SELECT COUNT(*) FROM user_announcement WHERE status = 'EXPIRED'")->fetchColumn();

    $notifications = $cms->query(
        'SELECT * FROM user_announcement ORDER BY created_at DESC LIMIT 25'
    )->fetchAll();
} catch (Throwable $exception) {
    error_log('Announcement load failed: ' . $exception->getMessage());
}

function announcementIcon(string $type): string
{
    switch (strtoupper(trim($type))) {
        case 'MAINTENANCE':
            return 'bi-tools';
        case 'FEATURE':
            return 'bi-stars';
        case 'BILLING':
            return 'bi-credit-card';
        case 'SECURITY':
            return 'bi-shield-check';
        case 'UPDATE':
            return 'bi-arrow-repeat';
        default:
            return 'bi-megaphone';
    }
}
?>
<!doctype html>
<html lang="en">
<?php renderAdminHead($pageTitle ?? 'Announcements', $pageStylesheets ?? []); ?>

<body class="admin-body">
    <?php require __DIR__ . '/../components/sidebar.php'; ?>

    <div class="admin-main">
        <header class="admin-topbar">
            <div class="topbar-left">
                <button type="button" class="mobile-menu-button" id="sidebarOpen"><i class="bi bi-list"></i></button>
                <div class="topbar-title">
                    <h1>Announcements</h1>
                    <span>Platform communication center</span>
                </div>
            </div>

            <div class="topbar-actions">
                <button type="button" class="topbar-action-btn d-none d-md-flex"><i class="bi bi-search"></i></button>
                <button type="button" class="topbar-action-btn notification-button"><i class="bi bi-bell"></i><span class="notification-dot"></span></button>

                <div class="dropdown">
                    <button type="button" class="topbar-profile" data-bs-toggle="dropdown">
                        <div class="topbar-avatar"><?= htmlspecialchars(substr((string) ($admin['NAME'] ?? 'SA'), 0, 2), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="topbar-profile-info d-none d-md-block">
                            <strong><?= htmlspecialchars((string) ($admin['NAME'] ?? 'System Admin'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars($adminRole, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <i class="bi bi-chevron-down"></i>
                    </button>

                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i>My Profile</a></li>
                        <li><a class="dropdown-item" href="../settings/"><i class="bi bi-gear me-2"></i>Settings</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
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
            <section class="announcement-heading">
                <div>
                    <h2>Platform Announcements</h2>
                    <p>Create and manage application notifications shown to users.</p>
                </div>

                <button type="button" class="ud-btn ud-btn-primary announcement-create-btn" data-bs-toggle="modal" data-bs-target="#announcementModal">
                    <i class="bi bi-plus-lg"></i>
                    <span>New Announcement</span>
                </button>
            </section>

            <section class="announcement-summary-grid">
                <article class="announcement-summary-card" data-summary-status="">
                    <div class="announcement-summary-icon total"><i class="bi bi-megaphone"></i></div>
                    <div>
                        <span>Total</span>
                        <strong><?= (int) $summary['total'] ?></strong>
                        <small>All notifications</small>
                    </div>
                </article>

                <article class="announcement-summary-card clickable" data-summary-status="PUBLISHED">
                    <div class="announcement-summary-icon published"><i class="bi bi-broadcast"></i></div>
                    <div>
                        <span>Published</span>
                        <strong><?= (int) $summary['published'] ?></strong>
                        <small>Currently published</small>
                    </div>
                </article>

                <article class="announcement-summary-card clickable" data-summary-status="SCHEDULED">
                    <div class="announcement-summary-icon scheduled"><i class="bi bi-clock"></i></div>
                    <div>
                        <span>Scheduled</span>
                        <strong><?= (int) $summary['scheduled'] ?></strong>
                        <small>Waiting to publish</small>
                    </div>
                </article>

                <article class="announcement-summary-card clickable" data-summary-status="DRAFT">
                    <div class="announcement-summary-icon draft"><i class="bi bi-file-earmark-text"></i></div>
                    <div>
                        <span>Draft</span>
                        <strong><?= (int) $summary['draft'] ?></strong>
                        <small>Not published</small>
                    </div>
                </article>

                <article class="announcement-summary-card clickable" data-summary-status="EXPIRED">
                    <div class="announcement-summary-icon expired"><i class="bi bi-calendar-x"></i></div>
                    <div>
                        <span>Expired</span>
                        <strong><?= (int) $summary['expired'] ?></strong>
                        <small>No longer visible</small>
                    </div>
                </article>
            </section>

            <section class="announcement-panel">
                <div class="announcement-tabs">
                    <button type="button" class="announcement-tab active" data-status="">All</button>
                    <button type="button" class="announcement-tab" data-status="PUBLISHED">Published</button>
                    <button type="button" class="announcement-tab" data-status="SCHEDULED">Scheduled</button>
                    <button type="button" class="announcement-tab" data-status="DRAFT">Drafts</button>
                    <button type="button" class="announcement-tab" data-status="EXPIRED">Expired</button>
                </div>

                <div class="announcement-toolbar">
                    <div class="announcement-search">
                        <i class="bi bi-search"></i>
                        <input type="search" id="announcementSearch" placeholder="Search notifications..." autocomplete="off">
                    </div>

                    <div class="announcement-filters">
                        <select id="announcementTypeFilter">
                            <option value="">All Types</option>
                            <option value="MAINTENANCE">Maintenance</option>
                            <option value="FEATURE">New Feature</option>
                            <option value="UPDATE">Platform Update</option>
                            <option value="BILLING">Billing</option>
                            <option value="SECURITY">Security</option>
                        </select>

                        <select id="announcementPriorityFilter">
                            <option value="">All Priority</option>
                            <option value="LOW">Low</option>
                            <option value="NORMAL">Normal</option>
                            <option value="HIGH">High</option>
                        </select>

                        <button type="button" id="clearAnnouncementFilters" title="Clear filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                    </div>
                </div>

                <div class="announcement-list">
                    <?php foreach ($notifications as $notification): ?>
                        <?php
                        $notificationStatus = strtoupper((string) ($notification['status'] ?? 'DRAFT'));
                        $notificationType = strtoupper((string) ($notification['type'] ?? 'MAINTENANCE'));
                        $notificationPriority = strtoupper((string) ($notification['priority'] ?? 'NORMAL'));
                        $publishDate = (string) ($notification['publish_at'] ?? '');
                        $expiresDate = (string) ($notification['expires_at'] ?? '');
                        $views = (int) ($notification['views'] ?? 0);
                        $totalRecipients = max(1, (int) ($notification['total_recipients'] ?? 0));
                        $reach = $totalRecipients > 0 ? (int) round(($views / $totalRecipients) * 100) : 0;
                        ?>

                        <article class="announcement-item"
                            data-status="<?= htmlspecialchars($notificationStatus, ENT_QUOTES, 'UTF-8') ?>"
                            data-type="<?= htmlspecialchars($notificationType, ENT_QUOTES, 'UTF-8') ?>"
                            data-priority="<?= htmlspecialchars($notificationPriority, ENT_QUOTES, 'UTF-8') ?>"
                            data-search="<?= htmlspecialchars(strtolower((string) ($notification['notification_code'] ?? '') . ' ' . ($notification['title'] ?? '') . ' ' . ($notification['message'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="announcement-type-icon type-<?= strtolower($notificationType) ?>">
                                <i class="bi <?= announcementIcon($notificationType) ?>"></i>
                            </div>

                            <div class="announcement-item-content">
                                <div class="announcement-item-top">
                                    <div class="announcement-badges">
                                        <span class="announcement-type-badge type-<?= strtolower($notificationType) ?>">
                                            <?= htmlspecialchars(userNotificationTypeLabel($notificationType), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php if ($notificationPriority === 'HIGH'): ?>
                                            <span class="announcement-priority-high"><i class="bi bi-exclamation-circle"></i>Important</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="announcement-status status-<?= strtolower($notificationStatus) ?>"><?= htmlspecialchars(userNotificationStatusLabel($notificationStatus), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>

                                <h3><?= htmlspecialchars((string) ($notification['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                                <p><?= htmlspecialchars((string) ($notification['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>

                                <div class="announcement-meta">
                                    <span><i class="bi bi-signal"></i><?= htmlspecialchars(userNotificationPriorityLabel($notificationPriority), ENT_QUOTES, 'UTF-8') ?> priority</span>
                                    <span><i class="bi bi-calendar3"></i><?= htmlspecialchars($publishDate !== '' ? $publishDate : 'Not scheduled', ENT_QUOTES, 'UTF-8') ?></span>
                                    <span><i class="bi bi-person"></i><?= htmlspecialchars((string) ($notification['created_by_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>

                            <div class="announcement-performance">
                                <?php if ($notificationStatus === 'PUBLISHED' || $notificationStatus === 'EXPIRED'): ?>
                                    <span>Viewed</span>
                                    <strong><?= number_format($views) ?><small>/ <?= number_format($totalRecipients) ?></small></strong>
                                    <div class="announcement-view-progress"><span style="width: <?= $reach ?>%"></span></div>
                                    <small><?= $reach ?>% reach</small>
                                <?php elseif ($notificationStatus === 'SCHEDULED'): ?>
                                    <div class="announcement-scheduled-info"><i class="bi bi-clock"></i><span>Scheduled</span></div>
                                <?php else: ?>
                                    <div class="announcement-draft-info"><i class="bi bi-pencil"></i><span>Draft</span></div>
                                <?php endif; ?>
                            </div>

                            <div class="dropdown announcement-actions">
                                <button type="button" class="announcement-action-btn" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <button type="button" class="dropdown-item view-announcement"
                                            data-bs-toggle="modal"
                                            data-bs-target="#viewAnnouncementModal"
                                            data-code="<?= htmlspecialchars((string) ($notification['notification_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-title="<?= htmlspecialchars((string) ($notification['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-message="<?= htmlspecialchars((string) ($notification['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-type="<?= htmlspecialchars(userNotificationTypeLabel($notificationType), ENT_QUOTES, 'UTF-8') ?>"
                                            data-status="<?= htmlspecialchars(userNotificationStatusLabel($notificationStatus), ENT_QUOTES, 'UTF-8') ?>"
                                            data-priority="<?= htmlspecialchars(userNotificationPriorityLabel($notificationPriority), ENT_QUOTES, 'UTF-8') ?>"
                                            data-publish="<?= htmlspecialchars($publishDate !== '' ? $publishDate : 'Not scheduled', ENT_QUOTES, 'UTF-8') ?>"
                                            data-expire="<?= htmlspecialchars($expiresDate !== '' ? $expiresDate : 'No expiry', ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-eye"></i>View
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item edit-announcement"
                                            data-id="<?= (int) ($notification['id'] ?? 0) ?>"
                                            data-code="<?= htmlspecialchars((string) ($notification['notification_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-title="<?= htmlspecialchars((string) ($notification['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-message="<?= htmlspecialchars((string) ($notification['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-type="<?= htmlspecialchars($notificationType, ENT_QUOTES, 'UTF-8') ?>"
                                            data-priority="<?= htmlspecialchars($notificationPriority, ENT_QUOTES, 'UTF-8') ?>"
                                            data-status="<?= htmlspecialchars($notificationStatus, ENT_QUOTES, 'UTF-8') ?>"
                                            data-publish-type="<?= htmlspecialchars(strtoupper((string) ($notification['publish_type'] ?? 'NOW')), ENT_QUOTES, 'UTF-8') ?>"
                                            data-publish-at="<?= htmlspecialchars((string) ($notification['publish_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-expire-at="<?= htmlspecialchars((string) ($notification['expires_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-ack="<?= (int) ($notification['acknowledgement_required'] ?? 0) ?>"
                                            data-email="<?= (int) ($notification['email_notification'] ?? 0) ?>">
                                            <i class="bi bi-pencil"></i>Edit
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item delete-announcement" data-id="<?= (int) ($notification['id'] ?? 0) ?>">
                                            <i class="bi bi-trash"></i>Delete
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </article>
                    <?php endforeach; ?>

                    <div class="announcement-empty d-none" id="announcementEmpty">
                        <div><i class="bi bi-megaphone"></i></div>
                        <h4>No notifications found</h4>
                        <p>Try changing your filters or create a new announcement.</p>
                        <button type="button" id="clearAnnouncementEmpty">Clear Filters</button>
                    </div>
                </div>

                <div class="announcement-pagination">
                    <span>Showing <strong id="visibleAnnouncementCount"><?= count($notifications) ?></strong> of <strong><?= (int) $summary['total'] ?></strong> notifications</span>
                    <div>
                        <button disabled><i class="bi bi-chevron-left"></i></button>
                        <button class="active">1</button>
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

    <div class="modal fade" id="announcementModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content announcement-modal">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="announcementModalTitle">Create Announcement</h5>
                        <p id="announcementModalSubtitle">Send a system-wide notification to app users.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" id="announcementId" value="">

                    <div class="announcement-form-group">
                        <label for="announcementTitle">Announcement Title</label>
                        <input id="announcementTitle" type="text" maxlength="180" placeholder="Enter announcement title">
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="announcement-form-group">
                                <label for="announcementType">Announcement Type</label>
                                <select id="announcementType">
                                    <option value="MAINTENANCE">Maintenance</option>
                                    <option value="FEATURE">New Feature</option>
                                    <option value="UPDATE">Platform Update</option>
                                    <option value="BILLING">Billing</option>
                                    <option value="SECURITY">Security</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="announcement-form-group">
                                <label for="announcementPriority">Priority</label>
                                <select id="announcementPriority">
                                    <option value="LOW">Low</option>
                                    <option value="NORMAL" selected>Normal</option>
                                    <option value="HIGH">High</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="announcement-form-group">
                        <label for="announcementMessage">Message</label>
                        <textarea id="announcementMessage" rows="6" maxlength="5000" placeholder="Write the announcement message..."></textarea>
                        <small>Maximum 5,000 characters</small>
                    </div>

                    <div class="announcement-form-section">
                        <h6>Publishing</h6>
                        <div class="announcement-publish-options">
                            <label>
                                <input type="radio" name="publish_type" value="NOW" checked>
                                <span><strong>Publish Now</strong><small>Visible to users immediately.</small></span>
                            </label>
                            <label>
                                <input type="radio" name="publish_type" value="SCHEDULE">
                                <span><strong>Schedule</strong><small>Publish automatically on a selected date.</small></span>
                            </label>
                        </div>
                        <div class="announcement-schedule-fields d-none" id="scheduleFields">
                            <div>
                                <label for="publishAt">Publish Date & Time</label>
                                <input id="publishAt" type="datetime-local">
                            </div>
                            <div>
                                <label for="expireAt">Expiry Date & Time</label>
                                <input id="expireAt" type="datetime-local">
                            </div>
                        </div>
                    </div>

                    <div class="announcement-form-section">
                        <h6>Delivery</h6>
                        <label class="announcement-switch-row">
                            <span><strong>Require acknowledgement</strong><small>Ask users to confirm they read this notice.</small></span>
                            <input id="requireAcknowledgement" class="form-check-input" type="checkbox">
                        </label>
                        <label class="announcement-switch-row">
                            <span><strong>Send email notification</strong><small>Also notify users by email.</small></span>
                            <input id="sendEmailNotification" class="form-check-input" type="checkbox">
                        </label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" id="announcementDraftBtn" class="announcement-draft-btn"><i class="bi bi-file-earmark"></i>Save Draft</button>
                    <div>
                        <button type="button" class="announcement-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" id="announcementSaveBtn" class="ud-btn ud-btn-primary"><i class="bi bi-send"></i>Publish</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="viewAnnouncementModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content announcement-view-modal">
                <div class="modal-header">
                    <div>
                        <span class="announcement-view-code" id="viewAnnouncementCode">NT-00000</span>
                        <h5 class="modal-title" id="viewAnnouncementTitle">Announcement</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="announcement-preview">
                        <div class="announcement-preview-icon"><i class="bi bi-megaphone"></i></div>
                        <div>
                            <span>Announcement</span>
                            <h3 id="previewAnnouncementTitle">—</h3>
                            <p id="viewAnnouncementMessage">—</p>
                        </div>
                    </div>

                    <div class="announcement-view-grid">
                        <div><span>Type</span><strong id="viewAnnouncementType">—</strong></div>
                        <div><span>Status</span><strong id="viewAnnouncementStatus">—</strong></div>
                        <div><span>Priority</span><strong id="viewAnnouncementPriority">—</strong></div>
                        <div><span>Publish Date</span><strong id="viewAnnouncementPublish">—</strong></div>
                        <div class="wide"><span>Expiry</span><strong id="viewAnnouncementExpire">—</strong></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="announcement-cancel-btn" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>
    <script>
        $(function() {
            let activeStatus = '';
            let editingAnnouncementId = null;

            function setAnnouncementModalMode(isEdit, titleText) {
                editingAnnouncementId = isEdit ? $('#announcementId').val() || null : null;
                $('#announcementModalTitle').text(isEdit ? 'Edit Announcement' : 'Create Announcement');
                $('#announcementModalSubtitle').text(isEdit ? 'Update this platform notification.' : 'Send a system-wide notification to app users.');
                $('#announcementSaveBtn').text(isEdit ? 'Update' : 'Publish');
                $('#announcementDraftBtn').toggle(!isEdit);
                if (titleText) {
                    $('#announcementModalTitle').text(titleText);
                }
            }

            function clearAnnouncementForm() {
                $('#announcementId').val('');
                $('#announcementTitle').val('');
                $('#announcementMessage').val('');
                $('#announcementType').val('MAINTENANCE');
                $('#announcementPriority').val('NORMAL');
                $('#requireAcknowledgement').prop('checked', false);
                $('#sendEmailNotification').prop('checked', false);
                $('input[name="publish_type"]').prop('checked', false).filter('[value="NOW"]').prop('checked', true);
                $('#publishAt').val('');
                $('#expireAt').val('');
                $('#scheduleFields').addClass('d-none');
                setAnnouncementModalMode(false, 'Create Announcement');
            }

            function filterAnnouncements() {
                const search = $.trim($('#announcementSearch').val()).toLowerCase();
                const type = $('#announcementTypeFilter').val();
                const priority = $('#announcementPriorityFilter').val();
                let visible = 0;

                $('.announcement-item').each(function() {
                    const $item = $(this);
                    const matchesSearch = !search || String($item.data('search')).includes(search);
                    const matchesStatus = !activeStatus || String($item.data('status')) === activeStatus;
                    const matchesType = !type || String($item.data('type')) === type;
                    const matchesPriority = !priority || String($item.data('priority')) === priority;
                    const show = matchesSearch && matchesStatus && matchesType && matchesPriority;
                    $item.toggle(show);
                    if (show) visible++;
                });

                $('#visibleAnnouncementCount').text(visible);
                $('#announcementEmpty').toggleClass('d-none', visible !== 0);
            }

            $('#announcementSearch').on('input', filterAnnouncements);
            $('#announcementTypeFilter, #announcementPriorityFilter').on('change', filterAnnouncements);

            $('.announcement-tab').on('click', function() {
                $('.announcement-tab').removeClass('active');
                $(this).addClass('active');
                activeStatus = String($(this).data('status') || '');
                filterAnnouncements();
            });

            $('[data-summary-status]').on('click', function() {
                activeStatus = String($(this).data('summary-status') || '');
                $('.announcement-tab').removeClass('active');
                $('.announcement-tab').each(function() {
                    if (String($(this).data('status') || '') === activeStatus) {
                        $(this).addClass('active');
                    }
                });
                filterAnnouncements();
            });

            function clearAnnouncementFilters() {
                activeStatus = '';
                $('#announcementSearch').val('');
                $('#announcementTypeFilter').val('');
                $('#announcementPriorityFilter').val('');
                $('.announcement-tab').removeClass('active').first().addClass('active');
                filterAnnouncements();
            }

            $('#clearAnnouncementFilters, #clearAnnouncementEmpty').on('click', clearAnnouncementFilters);
            $('.announcement-create-btn').on('click', clearAnnouncementForm);
            $('#announcementModal').on('hidden.bs.modal', clearAnnouncementForm);

            $('input[name="publish_type"]').on('change', function() {
                $('#scheduleFields').toggleClass('d-none', $(this).val() !== 'SCHEDULE');
            });

            function saveAnnouncement(status) {
                const publishType = $('input[name="publish_type"]:checked').val() || 'NOW';
                const resolvedStatus = status === 'DRAFT' ? 'DRAFT' : (publishType === 'SCHEDULE' ? 'SCHEDULED' : 'PUBLISHED');

                const payload = {
                    id: $('#announcementId').val() || null,
                    title: $.trim($('#announcementTitle').val()),
                    message: $.trim($('#announcementMessage').val()),
                    type: $('#announcementType').val(),
                    priority: $('#announcementPriority').val(),
                    status: resolvedStatus,
                    publish_type: publishType,
                    banner_enabled: 1,
                    acknowledgement_required: $('#requireAcknowledgement').is(':checked') ? 1 : 0,
                    email_notification: $('#sendEmailNotification').is(':checked') ? 1 : 0,
                    publish_at: publishType === 'SCHEDULE' ? $('#publishAt').val() || '' : '',
                    expires_at: publishType === 'SCHEDULE' ? $('#expireAt').val() || '' : '',
                    target_scope: 'ALL_USERS',
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                };

                if (!payload.title || !payload.message) {
                    window.alert('Title and message are required.');
                    return;
                }

                if (payload.title.length > 180 || payload.message.length > 5000) {
                    window.alert('Title or message is too long.');
                    return;
                }

                if (payload.status === 'SCHEDULED' && !payload.publish_at) {
                    window.alert('Please select a publish date and time for scheduled notifications.');
                    return;
                }

                const endpoint = payload.id ? '<?= htmlspecialchars(APP_BASE_URL . '/api/announcements/update.php', ENT_QUOTES, 'UTF-8') ?>' : '<?= htmlspecialchars(APP_BASE_URL . '/api/announcements/create.php', ENT_QUOTES, 'UTF-8') ?>';

                $.ajax({
                    url: endpoint,
                    method: 'POST',
                    contentType: 'application/json; charset=utf-8',
                    dataType: 'json',
                    headers: {
                        'X-CSRF-TOKEN': payload.csrf_token
                    },
                    data: JSON.stringify(payload),
                    success: function(response) {
                        if (!response || !response.success) {
                            window.alert((response && response.message) ? response.message : 'Unable to save notification.');
                            return;
                        }
                        $('#announcementModal').modal('hide');
                        window.location.reload();
                    },
                    error: function(xhr) {
                        const response = xhr.responseJSON || {};
                        window.alert(response.message || 'Unable to save the notification right now.');
                    }
                });
            }

            $('#announcementSaveBtn').on('click', function() {
                saveAnnouncement('PUBLISHED');
            });

            $('#announcementDraftBtn').on('click', function() {
                saveAnnouncement('DRAFT');
            });

            $('.edit-announcement').on('click', function() {
                const $btn = $(this);
                $('#announcementId').val($btn.data('id'));
                $('#announcementTitle').val($btn.data('title') || '');
                $('#announcementMessage').val($btn.data('message') || '');
                $('#announcementType').val($btn.data('type') || 'MAINTENANCE');
                $('#announcementPriority').val($btn.data('priority') || 'NORMAL');
                $('#requireAcknowledgement').prop('checked', Number($btn.data('ack')) === 1);
                $('#sendEmailNotification').prop('checked', Number($btn.data('email')) === 1);
                const publishType = String($btn.data('publishType') || 'NOW').toUpperCase();
                const publishAt = $btn.data('publishAt') || '';
                const expireAt = $btn.data('expireAt') || '';

                $('input[name="publish_type"]').prop('checked', false);
                const $selectedPublishType = $('input[name="publish_type"][value="' + publishType + '"]');
                if ($selectedPublishType.length) {
                    $selectedPublishType.prop('checked', true);
                } else {
                    $('input[name="publish_type"][value="NOW"]').prop('checked', true);
                }

                $('#publishAt').val(publishType === 'SCHEDULE' ? publishAt : '');
                $('#expireAt').val(publishType === 'SCHEDULE' ? expireAt : '');
                $('#scheduleFields').toggleClass('d-none', publishType !== 'SCHEDULE');
                setAnnouncementModalMode(true, 'Edit Announcement');
                $('#announcementModal').modal('show');
            });

            $('.delete-announcement').on('click', function() {
                const announcementId = Number($(this).data('id'));
                if (!announcementId) {
                    window.alert('Unable to find the announcement to delete.');
                    return;
                }

                if (!window.confirm('Delete this announcement? This action cannot be undone.')) {
                    return;
                }

                $.ajax({
                    url: '<?= htmlspecialchars(APP_BASE_URL . '/api/announcements/delete.php', ENT_QUOTES, 'UTF-8') ?>',
                    method: 'POST',
                    contentType: 'application/json; charset=utf-8',
                    dataType: 'json',
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    data: JSON.stringify({
                        id: announcementId,
                        csrf_token: $('meta[name="csrf-token"]').attr('content')
                    }),
                    success: function(response) {
                        if (!response || !response.success) {
                            window.alert((response && response.message) ? response.message : 'Unable to delete this announcement.');
                            return;
                        }
                        window.location.reload();
                    },
                    error: function(xhr) {
                        const response = xhr.responseJSON || {};
                        window.alert(response.message || 'Unable to delete the announcement right now.');
                    }
                });
            });

            $('.view-announcement').on('click', function() {
                $('#viewAnnouncementCode').text($(this).data('code') || 'NT-00000');
                $('#viewAnnouncementTitle').text($(this).data('title') || 'Announcement');
                $('#previewAnnouncementTitle').text($(this).data('title') || 'Announcement');
                $('#viewAnnouncementMessage').text($(this).data('message') || '—');
                $('#viewAnnouncementType').text($(this).data('type') || '—');
                $('#viewAnnouncementStatus').text($(this).data('status') || '—');
                $('#viewAnnouncementPriority').text($(this).data('priority') || '—');
                $('#viewAnnouncementPublish').text($(this).data('publish') || '—');
                $('#viewAnnouncementExpire').text($(this).data('expire') || '—');
            });
        });
    </script>
</body>

</html>