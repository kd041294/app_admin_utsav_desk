<?php

declare(strict_types=1);

require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../common/cms_db.php';
require_once __DIR__ . '/../common/support.php';
require_once __DIR__ . '/../common/functions.php';

$admin = [
    'id' => (int) ($_SESSION['PLATFORM_ADMIN']['ID'] ?? 0),
    'name' => (string) ($_SESSION['PLATFORM_ADMIN']['NAME'] ?? 'Platform Admin'),
    'email' => (string) ($_SESSION['PLATFORM_ADMIN']['EMAIL'] ?? ''),
    'role' => (string) ($_SESSION['PLATFORM_ADMIN']['ROLE'] ?? 'ADMIN'),
];

$summary = [
    'open' => 0,
    'in_progress' => 0,
    'urgent' => 0,
    'resolved' => 0,
    'avg_response' => 0,
];

$statusFilter = in_array((string) ($_GET['status'] ?? ''), supportTicketStatuses(), true)
    ? (string) $_GET['status']
    : '';
$priorityFilter = in_array((string) ($_GET['priority'] ?? ''), supportTicketPriorities(), true)
    ? (string) $_GET['priority']
    : '';
$searchFilter = is_string($_GET['q'] ?? null) ? trim((string) $_GET['q']) : '';

$successMessage = $_SESSION['SUPPORT_FLASH'] ?? '';
unset($_SESSION['SUPPORT_FLASH']);
$error = '';
$tickets = [];
$caterers = [];

try {
    $cms = cmsDatabaseConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!verifyCsrfToken($token)) {
            throw new InvalidArgumentException('Your session has expired. Please refresh and retry.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_ticket') {
            $ticket = supportCreateTicket($cms, $_POST, $admin);
            $_SESSION['SUPPORT_FLASH'] = 'Support ticket ' . $ticket['ticket_code'] . ' was created successfully.';
            udSetToast('success', 'Ticket created', 'Support ticket ' . $ticket['ticket_code'] . ' was created successfully.');
            header('Location: ' . APP_BASE_URL . '/support/', true, 303);
            exit;
        }

        if ($action === 'update_ticket') {
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            if ($ticketId < 1) {
                throw new InvalidArgumentException('Select a valid support ticket.');
            }

            supportUpdateTicket($cms, $ticketId, $_POST);
            $_SESSION['SUPPORT_FLASH'] = 'Ticket updated successfully.';
            udSetToast('success', 'Ticket updated', 'Ticket updated successfully.');
            header('Location: ' . APP_BASE_URL . '/support/', true, 303);
            exit;
        }

        if ($action === 'reply_ticket') {
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            if ($ticketId < 1) {
                throw new InvalidArgumentException('Select a valid support ticket.');
            }

            supportAddMessage($cms, $ticketId, $_POST, $admin);
            $_SESSION['SUPPORT_FLASH'] = 'Your support message was saved successfully.';
            udSetToast('info', 'Reply saved', 'Your support message was saved successfully.');
            header('Location: ' . APP_BASE_URL . '/support/', true, 303);
            exit;
        }

        throw new InvalidArgumentException('Unsupported support action.');
    }

    $summary = supportSummary($cms);
    $tickets = supportListTickets($cms, [
        'status' => $statusFilter,
        'priority' => $priorityFilter,
        'search' => $searchFilter,
    ]);

    $caterers = $cms->query(
        'SELECT id, catering_code, catering_name
         FROM catering_info
         WHERE is_active = 1 AND status = 1
         ORDER BY catering_name ASC'
    )->fetchAll();
} catch (Throwable $exception) {
    error_log('Support page failed: ' . $exception->getMessage());
    $error = 'Support data is temporarily unavailable. Please retry.';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $value = '';

    foreach (array_slice($parts ?: [], 0, 2) as $part) {
        $value .= strtoupper(substr($part, 0, 1));
    }

    return $value ?: 'UD';
}

function supportCreatedDateLabel(string $dateValue): array
{
    try {
        $date = new DateTimeImmutable($dateValue);
        return [$date->format('d M Y'), $date->format('h:i A')];
    } catch (Throwable $exception) {
        return ['—', '—'];
    }
}

function supportAgeLabel(array $ticket): string
{
    $updatedAt = (string) ($ticket['updated_at'] ?? $ticket['created_at'] ?? '');

    if ($updatedAt === '') {
        return 'Just now';
    }

    try {
        $updated = new DateTimeImmutable($updatedAt);
        $diff = $updated->diff(new DateTimeImmutable());

        if ($diff->days === 0 && $diff->h === 0) {
            return $diff->i . ' min ago';
        }

        if ($diff->days === 0) {
            return $diff->h . ' hrs ago';
        }

        if ($diff->days === 1) {
            return '1 day ago';
        }

        return $diff->days . ' days ago';
    } catch (Throwable $exception) {
        return 'Just now';
    }
}

?>
<!doctype html>

<html lang="en">

<?php renderAdminHead($pageTitle, $pageStylesheets); ?>


<body class="admin-body">


    <?php require __DIR__ . '/../components/sidebar.php'; ?>


    <!-- =========================================================
     MAIN
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
                        Support
                    </h1>

                    <span>
                        Platform support and caterer assistance
                    </span>

                </div>

            </div>


            <div class="topbar-actions">


                <button
                    type="button"
                    class="topbar-action-btn d-none d-md-flex">
                    <i class="bi bi-search"></i>
                </button>


                <button
                    type="button"
                    class="topbar-action-btn notification-button">

                    <i class="bi bi-bell"></i>

                    <span class="notification-dot"></span>

                </button>


                <div class="dropdown">

                    <button
                        type="button"
                        class="topbar-profile"
                        data-bs-toggle="dropdown">

                        <div class="topbar-avatar">
                            KJ
                        </div>


                        <div class="topbar-profile-info d-none d-md-block">

                            <strong>
                                <?= htmlspecialchars($admin['name']) ?>
                            </strong>

                            <span>
                                Super Admin
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


            <!-- =================================================
             PAGE HEADER
             ================================================= -->

            <section class="support-heading">


                <div>

                    <h2>
                        Support Center
                    </h2>

                    <p>
                        Review and manage support requests from UtsavDesk caterers.
                    </p>

                </div>


                <button
                    type="button"
                    class="ud-btn ud-btn-primary support-create-button"
                    data-bs-toggle="modal"
                    data-bs-target="#createTicketModal">

                    <i class="bi bi-plus-lg"></i>

                    <span>
                        Create Ticket
                    </span>

                </button>


            </section>


            <!-- =================================================
             SUMMARY
             ================================================= -->

            <section class="support-summary-grid">


                <article
                    class="support-summary-card clickable"
                    data-status-card="OPEN">

                    <div class="support-summary-icon open">
                        <i class="bi bi-inbox"></i>
                    </div>


                    <div>

                        <span>
                            Open Tickets
                        </span>

                        <strong>
                            <?= number_format((int) $summary['open']) ?>
                        </strong>

                        <small>
                            Awaiting action
                        </small>

                    </div>

                </article>


                <article
                    class="support-summary-card clickable"
                    data-status-card="IN_PROGRESS">

                    <div class="support-summary-icon progress">
                        <i class="bi bi-hourglass-split"></i>
                    </div>


                    <div>

                        <span>
                            In Progress
                        </span>

                        <strong>
                            <?= number_format((int) $summary['in_progress']) ?>
                        </strong>

                        <small>
                            Currently assigned
                        </small>

                    </div>

                </article>


                <article
                    class="support-summary-card clickable"
                    data-priority-card="URGENT">

                    <div class="support-summary-icon urgent">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>


                    <div>

                        <span>
                            Urgent
                        </span>

                        <strong>
                            <?= number_format((int) $summary['urgent']) ?>
                        </strong>

                        <small>
                            Needs attention
                        </small>

                    </div>

                </article>


                <article
                    class="support-summary-card clickable"
                    data-status-card="RESOLVED">

                    <div class="support-summary-icon resolved">
                        <i class="bi bi-check2-circle"></i>
                    </div>


                    <div>

                        <span>
                            Resolved
                        </span>

                        <strong>
                            <?= number_format((int) $summary['resolved']) ?>
                        </strong>

                        <small>
                            This month
                        </small>

                    </div>

                </article>


                <article class="support-summary-card">

                    <div class="support-summary-icon response">
                        <i class="bi bi-stopwatch"></i>
                    </div>


                    <div>

                        <span>
                            Avg. Response
                        </span>

                        <strong class="response-time">
                            <?= htmlspecialchars(
                                number_format((int) $summary['avg_response']) . ' min'
                            ) ?>
                        </strong>

                        <small>
                            First response time
                        </small>

                    </div>

                </article>


            </section>


            <!-- =================================================
             TICKET PANEL
             ================================================= -->

            <section class="support-panel">


                <!-- TABS -->

                <div class="support-tabs">

                    <button
                        type="button"
                        class="support-tab active"
                        data-ticket-status="">

                        All Tickets

                        <span><?= count($tickets) ?></span>

                    </button>


                    <button
                        type="button"
                        class="support-tab"
                        data-ticket-status="OPEN">

                        Open

                        <span><?= number_format((int) $summary['open']) ?></span>

                    </button>


                    <button
                        type="button"
                        class="support-tab"
                        data-ticket-status="IN_PROGRESS">

                        In Progress

                        <span><?= number_format((int) $summary['in_progress']) ?></span>

                    </button>


                    <button
                        type="button"
                        class="support-tab"
                        data-ticket-status="RESOLVED">

                        Resolved

                        <span><?= number_format((int) $summary['resolved']) ?></span>

                    </button>


                    <button
                        type="button"
                        class="support-tab"
                        data-ticket-status="CLOSED">

                        Closed

                        <span><?= number_format(max(0, count($tickets) - (int) $summary['open'] - (int) $summary['in_progress'] - (int) $summary['resolved'])) ?></span>

                    </button>

                </div>


                <!-- TOOLBAR -->

                <div class="support-toolbar">


                    <div class="support-search">

                        <i class="bi bi-search"></i>

                        <input
                            type="search"
                            id="supportSearch"
                            placeholder="Search ticket, caterer, subject..."
                            autocomplete="off">

                    </div>


                    <div class="support-filters">


                        <select id="supportPriorityFilter">

                            <option value="">
                                All Priority
                            </option>

                            <option value="URGENT">
                                Urgent
                            </option>

                            <option value="HIGH">
                                High
                            </option>

                            <option value="MEDIUM">
                                Medium
                            </option>

                            <option value="LOW">
                                Low
                            </option>

                        </select>


                        <select id="supportCategoryFilter">

                            <option value="">
                                All Categories
                            </option>

                            <option value="TECHNICAL">
                                Technical
                            </option>

                            <option value="BILLING">
                                Billing
                            </option>

                            <option value="ACCOUNT">
                                Account
                            </option>

                            <option value="GENERAL">
                                General
                            </option>

                        </select>


                        <button
                            type="button"
                            id="clearSupportFilters"
                            title="Clear filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>


                    </div>


                </div>


                <!-- =================================================
                 TABLE
                 ================================================= -->

                <div class="table-responsive">

                    <table class="support-table">

                        <thead>

                            <tr>

                                <th>Ticket</th>

                                <th>Caterer</th>

                                <th>Category</th>

                                <th>Priority</th>

                                <th>Status</th>

                                <th>Assigned To</th>

                                <th>Created</th>

                                <th>Age</th>

                                <th></th>

                            </tr>

                        </thead>


                        <tbody>


                            <?php if ($tickets === []): ?>
                                <tr id="supportEmptyState">
                                    <td colspan="9">
                                        <div class="support-empty">
                                            <div><i class="bi bi-inbox"></i></div>
                                            <h4>No support tickets found</h4>
                                            <p>Try changing your search or filters.</p>
                                            <button type="button" id="clearEmptySupport">Clear Filters</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tickets as $ticket): ?>
                                    <?php
                                    $ticketStatus = strtoupper((string) ($ticket['status'] ?? 'OPEN'));
                                    $ticketPriority = strtoupper((string) ($ticket['priority'] ?? 'MEDIUM'));
                                    $ticketCategory = strtoupper((string) ($ticket['category'] ?? 'GENERAL'));
                                    $catererName = (string) ($ticket['catering_name'] ?? 'Unknown Caterer');
                                    $catererCode = (string) ($ticket['catering_code'] ?? '');
                                    $requesterName = (string) ($ticket['requester_name'] ?? 'Caterer User');
                                    $requesterEmail = (string) ($ticket['requester_email'] ?? '');
                                    $createdDate = supportCreatedDateLabel((string) ($ticket['created_at'] ?? ''));
                                    $ageLabel = supportAgeLabel($ticket);
                                    ?>
                                    <tr class="support-ticket-row"
                                        data-status="<?= htmlspecialchars($ticketStatus, ENT_QUOTES, 'UTF-8') ?>"
                                        data-priority="<?= htmlspecialchars($ticketPriority, ENT_QUOTES, 'UTF-8') ?>"
                                        data-category="<?= htmlspecialchars($ticketCategory, ENT_QUOTES, 'UTF-8') ?>"
                                        data-search="<?= htmlspecialchars(strtolower((string) $ticket['ticket_code'] . ' ' . (string) $ticket['subject'] . ' ' . $catererName . ' ' . $catererCode . ' ' . $requesterName . ' ' . $requesterEmail), ENT_QUOTES, 'UTF-8') ?>">
                                        <td>
                                            <div class="support-ticket-info">
                                                <button type="button" class="support-ticket-title view-ticket" data-bs-toggle="modal" data-bs-target="#ticketModal"
                                                    data-ticket-id="<?= (int) $ticket['id'] ?>"
                                                    data-code="<?= htmlspecialchars((string) $ticket['ticket_code'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-subject="<?= htmlspecialchars((string) $ticket['subject'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-description="<?= htmlspecialchars((string) $ticket['description'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-caterer="<?= htmlspecialchars($catererName, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-caterer-code="<?= htmlspecialchars($catererCode, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-requester="<?= htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-email="<?= htmlspecialchars($requesterEmail, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-category="<?= htmlspecialchars($ticketCategory, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-priority="<?= htmlspecialchars($ticketPriority, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-status="<?= htmlspecialchars($ticketStatus, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-assigned="<?= htmlspecialchars((string) ($ticket['assigned_to'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-created="<?= htmlspecialchars($createdDate[0] . ' · ' . $createdDate[1], ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars((string) $ticket['subject'], ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                                <span><?= htmlspecialchars((string) $ticket['ticket_code'], ENT_QUOTES, 'UTF-8') ?> · Updated <?= htmlspecialchars($ageLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="support-caterer">
                                                <div class="support-caterer-avatar"><?= htmlspecialchars(initials($catererName), ENT_QUOTES, 'UTF-8') ?></div>
                                                <div>
                                                    <?php if ($catererCode !== ''): ?>
                                                        <a href="../caterers/view.php?code=<?= urlencode($catererCode) ?>"><?= htmlspecialchars($catererName, ENT_QUOTES, 'UTF-8') ?></a>
                                                    <?php else: ?>
                                                        <span><?= htmlspecialchars($catererName, ENT_QUOTES, 'UTF-8') ?></span>
                                                    <?php endif; ?>
                                                    <span><?= htmlspecialchars($catererCode, ENT_QUOTES, 'UTF-8') ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="support-category <?= 'category-' . strtolower($ticketCategory) ?>">
                                                <i class="bi <?= supportTicketCategoryIcon($ticketCategory) ?>"></i>
                                                <?= htmlspecialchars(ucfirst(strtolower($ticketCategory)), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="support-priority <?= 'priority-' . strtolower($ticketPriority) ?>">
                                                <i></i>
                                                <?= htmlspecialchars(ucfirst(strtolower($ticketPriority)), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="support-status <?= 'status-' . strtolower(str_replace('_', '-', $ticketStatus)) ?>"><?= htmlspecialchars(supportDisplayStatus($ticketStatus), ENT_QUOTES, 'UTF-8') ?></span>
                                        </td>
                                        <td>
                                            <div class="support-assigned">
                                                <?php $assigned = trim((string) ($ticket['assigned_to'] ?? 'Unassigned')); ?>
                                                <?php if ($assigned === '' || strtolower($assigned) === 'unassigned'): ?>
                                                    <span class="unassigned">Unassigned</span>
                                                <?php else: ?>
                                                    <span class="support-assignee-avatar"><?= htmlspecialchars(initials($assigned), ENT_QUOTES, 'UTF-8') ?></span>
                                                    <strong><?= htmlspecialchars($assigned, ENT_QUOTES, 'UTF-8') ?></strong>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="support-date">
                                                <strong><?= htmlspecialchars($createdDate[0], ENT_QUOTES, 'UTF-8') ?></strong>
                                                <span><?= htmlspecialchars($createdDate[1], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="support-age <?= $ticketPriority === 'URGENT' ? 'urgent' : '' ?>"><?= htmlspecialchars($ageLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                        </td>
                                        <td>
                                            <div class="dropdown">
                                                <button type="button" class="support-action-button" data-bs-toggle="dropdown">
                                                    <i class="bi bi-three-dots-vertical"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end support-action-menu">
                                                    <li>
                                                        <button type="button" class="dropdown-item view-ticket"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#ticketModal"
                                                            data-ticket-id="<?= (int) $ticket['id'] ?>"
                                                            data-code="<?= htmlspecialchars((string) $ticket['ticket_code'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-subject="<?= htmlspecialchars((string) $ticket['subject'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-description="<?= htmlspecialchars((string) $ticket['description'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-caterer="<?= htmlspecialchars($catererName, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-caterer-code="<?= htmlspecialchars($catererCode, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-requester="<?= htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-email="<?= htmlspecialchars($requesterEmail, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-category="<?= htmlspecialchars($ticketCategory, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-priority="<?= htmlspecialchars($ticketPriority, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-status="<?= htmlspecialchars($ticketStatus, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-assigned="<?= htmlspecialchars((string) ($ticket['assigned_to'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-created="<?= htmlspecialchars($createdDate[0] . ' · ' . $createdDate[1], ENT_QUOTES, 'UTF-8') ?>">
                                                            <i class="bi bi-eye"></i> View Ticket
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <button type="button" class="dropdown-item">
                                                            <i class="bi bi-person-check"></i> Assign Ticket
                                                        </button>
                                                    </li>
                                                    <?php if (!in_array($ticketStatus, ['RESOLVED', 'CLOSED'], true)): ?>
                                                        <li>
                                                            <button type="button" class="dropdown-item resolve-ticket" data-ticket-id="<?= (int) $ticket['id'] ?>" data-status="RESOLVED">
                                                                <i class="bi bi-check2-circle"></i> Mark Resolved
                                                            </button>
                                                        </li>
                                                    <?php endif; ?>
                                                    <li>
                                                        <hr class="dropdown-divider">
                                                    </li>
                                                    <?php if ($catererCode !== ''): ?>
                                                        <li>
                                                            <a href="../caterers/view.php?code=<?= urlencode($catererCode) ?>" class="dropdown-item">
                                                                <i class="bi bi-building"></i> View Caterer
                                                            </a>
                                                        </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>


                            <!-- EMPTY STATE -->

                            <tr
                                id="supportEmptyState"
                                class="d-none">

                                <td colspan="9">

                                    <div class="support-empty">

                                        <div>
                                            <i class="bi bi-inbox"></i>
                                        </div>

                                        <h4>
                                            No support tickets found
                                        </h4>

                                        <p>
                                            Try changing your search or filters.
                                        </p>

                                        <button
                                            type="button"
                                            id="clearEmptySupport">
                                            Clear Filters
                                        </button>

                                    </div>

                                </td>

                            </tr>


                        </tbody>

                    </table>

                </div>


                <!-- PAGINATION -->

                <div class="support-pagination">


                    <span>

                        Showing

                        <strong>
                            <?= count($tickets) ?>
                        </strong>

                        of

                        <strong>
                            108
                        </strong>

                        tickets

                    </span>


                    <div>

                        <button disabled>
                            <i class="bi bi-chevron-left"></i>
                        </button>

                        <button class="active">
                            1
                        </button>

                        <button>
                            2
                        </button>

                        <button>
                            3
                        </button>

                        <span>...</span>

                        <button>
                            16
                        </button>

                        <button>
                            <i class="bi bi-chevron-right"></i>
                        </button>

                    </div>


                </div>


            </section>


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


    <!-- =========================================================
     TICKET DETAIL MODAL
     ========================================================= -->

    <div
        class="modal fade"
        id="ticketModal"
        tabindex="-1">

        <div
            class="modal-dialog modal-dialog-centered modal-xl">

            <div class="modal-content support-ticket-modal">


                <!-- HEADER -->

                <div class="modal-header support-ticket-modal-header">


                    <div>

                        <div class="support-modal-code">
                            <span id="ticketModalCode">
                                SUP-000000
                            </span>

                            <span
                                class="support-status status-open"
                                id="ticketModalStatus">
                                Open
                            </span>
                        </div>


                        <h5
                            class="modal-title"
                            id="ticketModalSubject">
                            Support Ticket
                        </h5>

                    </div>


                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"></button>


                </div>


                <!-- BODY -->

                <div class="modal-body support-ticket-modal-body">


                    <div class="support-ticket-layout">


                        <!-- CONVERSATION -->

                        <div class="support-conversation">


                            <!-- ORIGINAL MESSAGE -->

                            <div class="support-message customer-message">


                                <div class="support-message-avatar">
                                    AG
                                </div>


                                <div class="support-message-content">


                                    <div class="support-message-meta">

                                        <div>

                                            <strong id="ticketRequester">
                                                Caterer User
                                            </strong>

                                            <span>
                                                Customer
                                            </span>

                                        </div>


                                        <time id="ticketCreated">
                                            —
                                        </time>

                                    </div>


                                    <p id="ticketDescription">
                                        —
                                    </p>


                                </div>


                            </div>


                            <!-- SUPPORT RESPONSE -->

                            <div class="support-message admin-message">


                                <div class="support-message-avatar">
                                    UD
                                </div>


                                <div class="support-message-content">


                                    <div class="support-message-meta">

                                        <div>

                                            <strong>
                                                UtsavDesk Support
                                            </strong>

                                            <span class="admin-label">
                                                Support
                                            </span>

                                        </div>


                                        <time>
                                            25 min ago
                                        </time>

                                    </div>


                                    <p>
                                        Thank you for reporting this issue.
                                        We are reviewing the account and related
                                        platform activity. We will update you once
                                        the investigation is complete.
                                    </p>


                                </div>


                            </div>


                            <!-- REPLY -->

                            <div class="support-reply-box">

                                <div class="support-reply-tabs">

                                    <button
                                        type="button"
                                        class="active"
                                        data-reply-type="reply">
                                        <i class="bi bi-reply"></i>
                                        Reply
                                    </button>


                                    <button
                                        type="button"
                                        data-reply-type="note">
                                        <i class="bi bi-lock"></i>
                                        Internal Note
                                    </button>

                                </div>

                                <form method="post" id="replyTicketForm">
                                    <input type="hidden" name="action" value="reply_ticket">
                                    <input type="hidden" name="ticket_id" id="replyTicketId" value="0">
                                    <input type="hidden" name="reply_type" id="replyTypeInput" value="reply">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

                                    <textarea
                                        id="ticketReply"
                                        name="message"
                                        rows="5"
                                        placeholder="Write your response..."></textarea>


                                    <div class="support-reply-actions">

                                        <div>

                                            <button
                                                type="button"
                                                title="Attach file">
                                                <i class="bi bi-paperclip"></i>
                                            </button>

                                        </div>


                                        <button
                                            type="submit"
                                            class="ud-btn ud-btn-primary"
                                            id="sendTicketReply">
                                            Send Reply

                                            <i class="bi bi-send"></i>
                                        </button>


                                    </div>
                                </form>

                            </div>


                        </div>


                        <!-- TICKET INFO -->

                        <aside class="support-ticket-details">


                            <div class="support-detail-section">


                                <h6>
                                    Ticket Details
                                </h6>


                                <div class="support-detail-row">

                                    <span>
                                        Priority
                                    </span>

                                    <strong id="ticketPriority">
                                        —
                                    </strong>

                                </div>


                                <div class="support-detail-row">

                                    <span>
                                        Category
                                    </span>

                                    <strong id="ticketCategory">
                                        —
                                    </strong>

                                </div>


                                <div class="support-detail-row">

                                    <span>
                                        Assigned To
                                    </span>

                                    <select id="ticketAssigned">

                                        <option>
                                            Unassigned
                                        </option>

                                        <option>
                                            Kundan Jha
                                        </option>

                                        <option>
                                            Support Team
                                        </option>

                                        <option>
                                            Finance Team
                                        </option>

                                    </select>

                                </div>


                                <div class="support-detail-row">

                                    <span>
                                        Status
                                    </span>

                                    <select id="ticketStatusSelect">

                                        <option value="OPEN">
                                            Open
                                        </option>

                                        <option value="IN_PROGRESS">
                                            In Progress
                                        </option>

                                        <option value="RESOLVED">
                                            Resolved
                                        </option>

                                        <option value="CLOSED">
                                            Closed
                                        </option>

                                    </select>

                                </div>


                            </div>


                            <!-- CATERER -->

                            <div class="support-detail-section">


                                <h6>
                                    Caterer
                                </h6>


                                <div class="support-modal-caterer">


                                    <div id="ticketCatererAvatar">
                                        RB
                                    </div>


                                    <span>

                                        <strong id="ticketCaterer">
                                            —
                                        </strong>

                                        <small id="ticketCatererCode">
                                            —
                                        </small>

                                    </span>


                                </div>


                                <a
                                    href="#"
                                    class="support-view-caterer"
                                    id="ticketCatererLink">

                                    View Caterer Profile

                                    <i class="bi bi-arrow-right"></i>

                                </a>


                            </div>


                            <!-- REQUESTER -->

                            <div class="support-detail-section">


                                <h6>
                                    Requester
                                </h6>


                                <div class="support-requester-info">

                                    <div>

                                        <i class="bi bi-person"></i>

                                        <span id="ticketRequesterSide">
                                            —
                                        </span>

                                    </div>


                                    <div>

                                        <i class="bi bi-envelope"></i>

                                        <span id="ticketEmail">
                                            —
                                        </span>

                                    </div>

                                </div>


                            </div>


                            <!-- ACTION -->

                            <div class="support-detail-section">

                                <button
                                    type="button"
                                    class="support-resolve-button"
                                    id="resolveTicketButton">

                                    <i class="bi bi-check2-circle"></i>

                                    Mark as Resolved

                                </button>

                            </div>


                        </aside>


                    </div>


                </div>


            </div>

        </div>

    </div>


    <!-- =========================================================
     CREATE TICKET MODAL
     ========================================================= -->

    <div
        class="modal fade"
        id="createTicketModal"
        tabindex="-1">

        <div class="modal-dialog modal-dialog-centered">

            <div class="modal-content support-create-modal">

                <form method="post" id="createTicketForm">
                    <input type="hidden" name="action" value="create_ticket">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

                    <div class="modal-header">

                        <div>

                            <h5 class="modal-title">
                                Create Support Ticket
                            </h5>

                            <p>
                                Create a ticket on behalf of a caterer.
                            </p>

                        </div>


                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>

                    </div>


                    <div class="modal-body">

                        <div class="support-form-group">
                            <label>Caterer</label>
                            <select name="caterer_id" required>
                                <option value="">Select caterer</option>
                                <?php foreach ($caterers as $caterer): ?>
                                    <option value="<?= (int) $caterer['id'] ?>"><?= htmlspecialchars((string) $caterer['catering_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string) $caterer['catering_code'], ENT_QUOTES, 'UTF-8') ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="support-form-group">
                            <label>Requester Name</label>
                            <input type="text" name="requester_name" placeholder="Enter requester name" required>
                        </div>

                        <div class="support-form-group">
                            <label>Requester Email</label>
                            <input type="email" name="requester_email" placeholder="requester@caterer.com" required>
                        </div>

                        <div class="support-form-group">
                            <label>Subject</label>
                            <input type="text" name="subject" placeholder="Enter ticket subject" required>
                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">
                                <div class="support-form-group">
                                    <label>Category</label>
                                    <select name="category" required>
                                        <?php foreach (supportTicketCategories() as $category): ?>
                                            <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= $category === 'GENERAL' ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst(strtolower($category)), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="support-form-group">
                                    <label>Priority</label>
                                    <select name="priority" required>
                                        <?php foreach (supportTicketPriorities() as $priority): ?>
                                            <option value="<?= htmlspecialchars($priority, ENT_QUOTES, 'UTF-8') ?>" <?= $priority === 'MEDIUM' ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst(strtolower($priority)), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="support-form-group">
                            <label>Assigned To</label>
                            <select name="assigned_to">
                                <option value="Unassigned">Unassigned</option>
                                <option value="Support Team">Support Team</option>
                                <option value="Finance Team">Finance Team</option>
                                <option value="Kundan Jha">Kundan Jha</option>
                            </select>
                        </div>

                        <div class="support-form-group">
                            <label>Description</label>
                            <textarea name="description" rows="5" placeholder="Describe the support request..." required></textarea>
                        </div>

                    </div>


                    <div class="modal-footer">

                        <button
                            type="button"
                            class="support-modal-cancel"
                            data-bs-dismiss="modal">
                            Cancel
                        </button>


                        <button
                            type="submit"
                            class="ud-btn ud-btn-primary support-modal-save">

                            <i class="bi bi-plus-lg"></i>

                            Create Ticket

                        </button>

                    </div>
                </form>

            </div>

        </div>

    </div>


    <!-- =========================================================
     JS
     ========================================================= -->

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js">
    </script>

    <script src="../assets/js/admin.js"></script>


    <script>
        $(function() {


            let activeStatus = '';


            /* =====================================================
               FILTER
               ===================================================== */

            function filterTickets() {

                const search =
                    $.trim(
                        $('#supportSearch').val()
                    ).toLowerCase();


                const priority =
                    $('#supportPriorityFilter').val();


                const category =
                    $('#supportCategoryFilter').val();


                let visible = 0;


                $('.support-ticket-row').each(function() {

                    const $row = $(this);


                    const matchesSearch = !search ||
                        String(
                            $row.data('search')
                        ).includes(search);


                    const matchesStatus = !activeStatus ||
                        String(
                            $row.data('status')
                        ) === activeStatus;


                    const matchesPriority = !priority ||
                        String(
                            $row.data('priority')
                        ) === priority;


                    const matchesCategory = !category ||
                        String(
                            $row.data('category')
                        ) === category;


                    const show =
                        matchesSearch &&
                        matchesStatus &&
                        matchesPriority &&
                        matchesCategory;


                    $row.toggle(show);


                    if (show) {
                        visible++;
                    }

                });


                $('#supportEmptyState').toggleClass(
                    'd-none',
                    visible !== 0
                );

            }


            $('#supportSearch').on(
                'input',
                filterTickets
            );


            $(
                '#supportPriorityFilter,' +
                '#supportCategoryFilter'
            ).on(
                'change',
                filterTickets
            );


            /* =====================================================
               STATUS TABS
               ===================================================== */

            $('.support-tab').on(
                'click',
                function() {

                    $('.support-tab')
                        .removeClass('active');


                    $(this)
                        .addClass('active');


                    activeStatus =
                        String(
                            $(this).data(
                                'ticket-status'
                            ) || ''
                        );


                    filterTickets();

                }
            );


            /* =====================================================
               SUMMARY CARD FILTER
               ===================================================== */

            $('[data-status-card]').on(
                'click',
                function() {

                    activeStatus =
                        String(
                            $(this).data(
                                'status-card'
                            )
                        );


                    $('.support-tab')
                        .removeClass('active');


                    $('.support-tab').each(function() {

                        if (
                            String(
                                $(this).data(
                                    'ticket-status'
                                )
                            ) === activeStatus
                        ) {

                            $(this)
                                .addClass('active');

                        }

                    });


                    filterTickets();

                }
            );


            $('[data-priority-card]').on(
                'click',
                function() {

                    $('#supportPriorityFilter').val(
                        $(this).data(
                            'priority-card'
                        )
                    );


                    filterTickets();

                }
            );


            /* =====================================================
               CLEAR
               ===================================================== */

            function clearSupportFilters() {

                $('#supportSearch').val('');

                $('#supportPriorityFilter').val('');

                $('#supportCategoryFilter').val('');

                activeStatus = '';


                $('.support-tab')
                    .removeClass('active');


                $('.support-tab')
                    .first()
                    .addClass('active');


                filterTickets();

            }


            $('#clearSupportFilters').on(
                'click',
                clearSupportFilters
            );


            $('#clearEmptySupport').on(
                'click',
                clearSupportFilters
            );


            /* =====================================================
               TICKET MODAL
               ===================================================== */

            $('.view-ticket').on(
                'click',
                function() {

                    const $button =
                        $(this);


                    const caterer =
                        String(
                            $button.data(
                                'caterer'
                            ) || ''
                        );


                    const catererInitials =
                        caterer
                        .split(/\s+/)
                        .slice(0, 2)
                        .map(function(item) {

                            return item
                                .charAt(0)
                                .toUpperCase();

                        })
                        .join('');


                    const status =
                        String(
                            $button.data(
                                'status'
                            ) || ''
                        );


                    $('#ticketModalCode').text(
                        $button.data('code')
                    );


                    $('#ticketModalSubject').text(
                        $button.data('subject')
                    );


                    $('#ticketDescription').text(
                        $button.data('description')
                    );


                    $('#ticketRequester').text(
                        $button.data('requester')
                    );


                    $('#ticketRequesterSide').text(
                        $button.data('requester')
                    );


                    $('#ticketEmail').text(
                        $button.data('email')
                    );


                    $('#ticketCreated').text(
                        $button.data('created')
                    );


                    $('#ticketPriority').text(
                        $button.data('priority')
                    );


                    $('#ticketCategory').text(
                        $button.data('category')
                    );


                    $('#ticketCaterer').text(
                        caterer
                    );


                    $('#ticketCatererCode').text(
                        $button.data(
                            'caterer-code'
                        )
                    );


                    $('#ticketCatererAvatar').text(
                        catererInitials || 'C'
                    );


                    $('#ticketAssigned').val(
                        $button.data('assigned')
                    );

                    $('#replyTicketId').val(
                        $button.data('ticket-id') || 0
                    );


                    $('#ticketStatusSelect').val(
                        status
                    );


                    $('#ticketCatererLink').attr(
                        'href',
                        '../caterers/view.php?code=' +
                        encodeURIComponent(
                            $button.data(
                                'caterer-code'
                            )
                        )
                    );


                    const $status =
                        $('#ticketModalStatus');


                    $status
                        .removeClass(
                            'status-open ' +
                            'status-in-progress ' +
                            'status-resolved ' +
                            'status-closed'
                        );


                    $status.addClass(
                        'status-' +
                        status
                        .toLowerCase()
                        .replaceAll('_', '-')
                    );


                    $status.text(
                        status
                        .toLowerCase()
                        .replaceAll('_', ' ')
                        .replace(
                            /\b\w/g,
                            function(letter) {

                                return letter
                                    .toUpperCase();

                            }
                        )
                    );

                }
            );


            /* =====================================================
               REPLY / INTERNAL NOTE
               ===================================================== */

            $('.support-reply-tabs button').on(
                'click',
                function() {

                    $('.support-reply-tabs button')
                        .removeClass('active');


                    $(this)
                        .addClass('active');


                    const type =
                        $(this).data(
                            'reply-type'
                        );

                    $('#replyTypeInput').val(type);

                    if (type === 'note') {

                        $('#ticketReply').attr(
                            'placeholder',
                            'Write an internal note. The caterer will not see this...'
                        );


                        $('#sendTicketReply').html(
                            'Add Note ' +
                            '<i class="bi bi-lock"></i>'
                        );

                    } else {

                        $('#ticketReply').attr(
                            'placeholder',
                            'Write your response...'
                        );


                        $('#sendTicketReply').html(
                            'Send Reply ' +
                            '<i class="bi bi-send"></i>'
                        );

                    }

                }
            );

            $('#ticketStatusSelect').on('change', function() {
                const ticketId = $('#replyTicketId').val();
                const status = $(this).val();

                if (!ticketId || ticketId === '0') {
                    return;
                }

                const form = document.createElement('form');
                form.method = 'post';
                form.action = window.location.href;

                const action = document.createElement('input');
                action.name = 'action';
                action.value = 'update_ticket';

                const idInput = document.createElement('input');
                idInput.name = 'ticket_id';
                idInput.value = ticketId;

                const statusInput = document.createElement('input');
                statusInput.name = 'status';
                statusInput.value = status;

                const csrfInput = document.createElement('input');
                csrfInput.name = 'csrf_token';
                csrfInput.value = $('meta[name="csrf-token"]').attr('content') || '';

                form.appendChild(action);
                form.appendChild(idInput);
                form.appendChild(statusInput);
                form.appendChild(csrfInput);
                document.body.appendChild(form);
                form.submit();
            });

            $('#resolveTicketButton').on('click', function() {
                const ticketId = $('#replyTicketId').val();
                if (!ticketId || ticketId === '0') {
                    return;
                }

                const form = document.createElement('form');
                form.method = 'post';
                form.action = window.location.href;

                const action = document.createElement('input');
                action.name = 'action';
                action.value = 'update_ticket';

                const idInput = document.createElement('input');
                idInput.name = 'ticket_id';
                idInput.value = ticketId;

                const statusInput = document.createElement('input');
                statusInput.name = 'status';
                statusInput.value = 'RESOLVED';

                const csrfInput = document.createElement('input');
                csrfInput.name = 'csrf_token';
                csrfInput.value = $('meta[name="csrf-token"]').attr('content') || '';

                form.appendChild(action);
                form.appendChild(idInput);
                form.appendChild(statusInput);
                form.appendChild(csrfInput);
                document.body.appendChild(form);
                form.submit();
            });


        });
    </script>


</body>

</html>