<?php

declare(strict_types=1);

require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../common/cms_db.php';

$admin = $_SESSION['PLATFORM_ADMIN'] ?? ['NAME' => 'Platform Admin', 'ROLE' => 'ADMIN'];
$catererId = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
$catererId = $catererId === false ? 0 : (int) $catererId;

$caterer = null;
$queries = [];
$quotations = [];
$events = [];
$quotationMenusById = [];
$eventMenusById = [];

try {
    $cms = cmsDatabaseConnection();

    $caterer = $cms->prepare('SELECT * FROM catering_info WHERE id = :id LIMIT 1');
    $caterer->execute(['id' => $catererId]);
    $caterer = $caterer->fetch();

    if ($caterer) {
        $queries = $cms->prepare(
            'SELECT * FROM customer_query WHERE catering_id = :id AND is_active = 1 AND status = 1 ORDER BY created_at DESC'
        );
        $queries->execute(['id' => $catererId]);
        $queries = $queries->fetchAll();

        $quotations = $cms->prepare(
            'SELECT * FROM customer_quotation WHERE catering_id = :id AND is_active = 1 AND status = 1 ORDER BY created_at DESC'
        );
        $quotations->execute(['id' => $catererId]);
        $quotations = $quotations->fetchAll();

        $events = $cms->prepare(
            'SELECT * FROM event_details WHERE catering_id = :id AND is_active = 1 AND status = 1 ORDER BY event_date DESC, created_at DESC'
        );
        $events->execute(['id' => $catererId]);
        $events = $events->fetchAll();

        if ($quotations) {
            $quotationIds = array_map(static fn($row) => (int) ($row['id'] ?? 0), $quotations);
            $quotationIds = array_values(array_filter($quotationIds, static fn($id) => $id > 0));

            if ($quotationIds) {
                $quotationPlaceholders = [];
                foreach ($quotationIds as $index => $quotationId) {
                    $quotationPlaceholders[] = ':qid' . $index;
                }

                $quotationMenuSql = 'SELECT * FROM customer_quotation_menu WHERE catering_id = :catering_id AND quotation_id IN (' . implode(',', $quotationPlaceholders) . ') AND is_active = 1 AND status = 1 ORDER BY sort_order ASC, created_at ASC';
                $quotationMenuStmt = $cms->prepare($quotationMenuSql);
                $quotationMenuStmt->bindValue(':catering_id', $catererId, PDO::PARAM_INT);

                foreach ($quotationIds as $index => $quotationId) {
                    $quotationMenuStmt->bindValue(':qid' . $index, (int) $quotationId, PDO::PARAM_INT);
                }

                $quotationMenuStmt->execute();

                foreach ($quotationMenuStmt->fetchAll() as $menuRow) {
                    $quotationMenusById[(int) ($menuRow['quotation_id'] ?? 0)][] = $menuRow;
                }
            }
        }

        if ($events) {
            $eventIds = array_map(static fn($row) => (int) ($row['id'] ?? 0), $events);
            $eventIds = array_values(array_filter($eventIds, static fn($id) => $id > 0));

            if ($eventIds) {
                $eventPlaceholders = [];
                foreach ($eventIds as $index => $eventId) {
                    $eventPlaceholders[] = ':eid' . $index;
                }

                $eventMenuSql = 'SELECT * FROM event_menu WHERE catering_id = :catering_id AND event_id IN (' . implode(',', $eventPlaceholders) . ') AND is_active = 1 AND status = 1 ORDER BY sort_order ASC, created_at ASC';
                $eventMenuStmt = $cms->prepare($eventMenuSql);
                $eventMenuStmt->bindValue(':catering_id', $catererId, PDO::PARAM_INT);

                foreach ($eventIds as $index => $eventId) {
                    $eventMenuStmt->bindValue(':eid' . $index, (int) $eventId, PDO::PARAM_INT);
                }

                $eventMenuStmt->execute();

                foreach ($eventMenuStmt->fetchAll() as $menuRow) {
                    $eventMenusById[(int) ($menuRow['event_id'] ?? 0)][] = $menuRow;
                }
            }
        }
    }
} catch (Throwable $exception) {
    error_log('Caterer detail load failed: ' . $exception->getMessage());
    $caterer = null;
    $queries = [];
    $quotations = [];
    $events = [];
    $quotationMenusById = [];
    $eventMenusById = [];
}

function formatMoney($value): string
{
    $float = (float) $value;

    return '₹' . number_format($float, 2, '.', ',');
}

function formatDate($value): string
{
    $date = trim((string) $value);

    if ($date === '' || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '—';
    }

    $time = strtotime($date);

    return $time === false ? $date : date('d M Y', $time);
}

function formatDateTime($value): string
{
    $date = trim((string) $value);

    if ($date === '' || $date === '0000-00-00 00:00:00') {
        return '—';
    }

    $time = strtotime($date);

    return $time === false ? $date : date('d M Y, H:i', $time);
}
?>
<!doctype html>
<html lang="en">
<?php renderAdminHead($pageTitle ?? 'Caterer Details', $pageStylesheets ?? []); ?>

<body class="admin-body">
    <?php require __DIR__ . '/../components/sidebar.php'; ?>

    <div class="admin-main">
        <header class="admin-topbar">
            <div class="topbar-left">
                <button type="button" class="mobile-menu-button" id="sidebarOpen"><i class="bi bi-list"></i></button>
                <div class="topbar-title">
                    <h1>Caterer Details</h1>
                    <span>Business profile and activity</span>
                </div>
            </div>

            <div class="topbar-actions">
                <a href="./index.php" class="btn btn-sm btn-outline-secondary me-2"><i class="bi bi-arrow-left"></i> Back</a>
                <div class="dropdown">
                    <button type="button" class="topbar-profile" data-bs-toggle="dropdown">
                        <div class="topbar-avatar"><?= htmlspecialchars(strtoupper(substr((string) ($admin['NAME'] ?? 'AD'), 0, 2)), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="topbar-profile-info d-none d-md-block">
                            <strong><?= htmlspecialchars((string) ($admin['NAME'] ?? 'Platform Admin'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars((string) ($admin['ROLE'] ?? 'ADMIN'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <i class="bi bi-chevron-down"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="../settings/"><i class="bi bi-gear me-2"></i>Settings</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li>
                            <form method="post" action="<?= htmlspecialchars(APP_BASE_URL . '/logout.php', ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="dropdown-item text-danger border-0 bg-transparent text-start w-100"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="admin-content">
            <?php if (!$caterer): ?>
                <div class="alert alert-warning shadow-sm">
                    <h4 class="mb-2">Caterer not found</h4>
                    <p class="mb-0">The requested caterer could not be found or is no longer active in the CMS.</p>
                </div>
            <?php else: ?>
                <section class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                    <div>
                        <div class="text-uppercase small text-secondary mb-1">Caterer profile</div>
                        <h2 class="mb-1"><?= htmlspecialchars((string) ($caterer['catering_name'] ?? 'Untitled Caterer'), ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="text-muted mb-0"><?= htmlspecialchars((string) ($caterer['business_tagline'] ?? 'Business profile'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="d-flex gap-2">
                        <span class="badge rounded-pill <?= ((int) ($caterer['is_active'] ?? 0) === 1 && (int) ($caterer['status'] ?? 0) === 1) ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?> px-3 py-2">
                            <?= ((int) ($caterer['is_active'] ?? 0) === 1 && (int) ($caterer['status'] ?? 0) === 1) ? 'Active' : 'Inactive' ?>
                        </span>
                        <span class="badge rounded-pill bg-primary-subtle text-primary px-3 py-2">#<?= htmlspecialchars((string) ($caterer['catering_code'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </section>

                <section class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-muted small">Enquiries</span>
                                    <i class="bi bi-chat-left-text text-primary"></i>
                                </div>
                                <h3 class="mb-0"><?= count($queries) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-muted small">Quotations</span>
                                    <i class="bi bi-receipt text-warning"></i>
                                </div>
                                <h3 class="mb-0"><?= count($quotations) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-muted small">Events</span>
                                    <i class="bi bi-calendar-event text-success"></i>
                                </div>
                                <h3 class="mb-0"><?= count($events) ?></h3>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <div class="row g-4">
                            <div class="col-lg-8">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width:60px;height:60px; font-size:1.3rem; font-weight:700;">
                                        <?= htmlspecialchars(strtoupper(substr((string) ($caterer['catering_name'] ?? 'C'), 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div>
                                        <h4 class="mb-1"><?= htmlspecialchars((string) ($caterer['catering_name'] ?? 'Untitled Caterer'), ENT_QUOTES, 'UTF-8') ?></h4>
                                        <div class="text-muted"><?= htmlspecialchars((string) ($caterer['email'] ?? 'No email provided'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="text-lg-end">
                                    <div class="text-muted small">Location</div>
                                    <strong><?= htmlspecialchars((string) (($caterer['city'] ?? '') . (!empty($caterer['state']) ? ', ' . $caterer['state'] : '')), ENT_QUOTES, 'UTF-8') ?: 'Location not provided' ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="mb-5">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="mb-0">Customer enquiries</h3>
                        <span class="badge bg-light text-dark rounded-pill px-3 py-2"><?= count($queries) ?> total</span>
                    </div>

                    <?php if ($queries): ?>
                        <div class="table-responsive">
                            <table class="table table-striped align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Query</th>
                                        <th>Customer</th>
                                        <th>Event</th>
                                        <th>Status</th>
                                        <th>Contact</th>
                                        <th>Budget</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($queries as $query): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars((string) ($query['query_code'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></strong></td>
                                            <td>
                                                <div class="fw-semibold"><?= htmlspecialchars((string) ($query['customer_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8') ?></div>
                                                <small class="text-muted"><?= htmlspecialchars((string) ($query['email'] ?? 'No email'), ENT_QUOTES, 'UTF-8') ?></small>
                                            </td>
                                            <td>
                                                <div><?= htmlspecialchars((string) ($query['event_type'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                                                <small class="text-muted"><?= formatDate((string) ($query['event_date'] ?? '')) ?></small>
                                            </td>
                                            <td><span class="badge bg-info-subtle text-info"><?= htmlspecialchars((string) ($query['query_status'] ?? 'NEW'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td><?= htmlspecialchars((string) ($query['contact_number'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= formatMoney((float) ($query['budget'] ?? 0)) ?></td>
                                            <td><?= formatDateTime((string) ($query['created_at'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-light border">No enquiries are available for this caterer.</div>
                    <?php endif; ?>
                </section>

                <section class="mb-5">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="mb-0">Customer quotations</h3>
                        <span class="badge bg-light text-dark rounded-pill px-3 py-2"><?= count($quotations) ?> total</span>
                    </div>

                    <?php if ($quotations): ?>
                        <?php foreach ($quotations as $quotation): ?>
                            <?php $quotationMenus = $quotationMenusById[(int) ($quotation['id'] ?? 0)] ?? []; ?>
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-body">
                                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
                                        <div>
                                            <div class="small text-uppercase text-muted">Quotation</div>
                                            <h5 class="mb-1"><?= htmlspecialchars((string) ($quotation['quotation_code'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></h5>
                                            <div class="text-muted"><?= htmlspecialchars((string) ($quotation['customer_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($quotation['email'] ?? 'No email'), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <span class="badge bg-warning-subtle text-warning px-3 py-2"><?= htmlspecialchars((string) ($quotation['quotation_status'] ?? 'DRAFT'), ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="badge bg-light text-dark px-3 py-2">Valid: <?= formatDate((string) ($quotation['valid_until'] ?? '')) ?></span>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-3">
                                            <div class="small text-muted">Event type</div><strong><?= htmlspecialchars((string) ($quotation['event_type'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Event date</div><strong><?= formatDate((string) ($quotation['event_date'] ?? '')) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Location</div><strong><?= htmlspecialchars((string) ($quotation['event_location'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Guests</div><strong><?= (int) ($quotation['total_guests'] ?? 0) ?></strong>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-3">
                                            <div class="small text-muted">Food amount</div><strong><?= formatMoney((float) ($quotation['food_amount'] ?? 0)) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Additional charge</div><strong><?= formatMoney((float) ($quotation['additional_charge'] ?? 0)) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Tax</div><strong><?= formatMoney((float) ($quotation['tax_amount'] ?? 0)) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Grand total</div><strong><?= formatMoney((float) ($quotation['grand_total'] ?? 0)) ?></strong>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-3">
                                            <div class="small text-muted">Advance</div><strong><?= formatMoney((float) ($quotation['advance_amount'] ?? 0)) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Balance</div><strong><?= formatMoney((float) ($quotation['balance_amount'] ?? 0)) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Revision</div><strong><?= (int) ($quotation['revision_no'] ?? 0) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Created</div><strong><?= formatDateTime((string) ($quotation['created_at'] ?? '')) ?></strong>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <div class="small text-muted">Customer note</div>
                                            <div><?= nl2br(htmlspecialchars((string) ($quotation['customer_note'] ?? '—'), ENT_QUOTES, 'UTF-8')) ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="small text-muted">Internal note</div>
                                            <div><?= nl2br(htmlspecialchars((string) ($quotation['internal_note'] ?? '—'), ENT_QUOTES, 'UTF-8')) ?></div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <div class="small text-uppercase text-muted mb-2">Menu details</div>
                                        <?php if ($quotationMenus): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered align-middle mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Type</th>
                                                            <th>Title</th>
                                                            <th>Menu content</th>
                                                            <th>Guests</th>
                                                            <th>Per head</th>
                                                            <th>Section</th>
                                                            <th>Included</th>
                                                            <th>Remarks</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($quotationMenus as $menuItem): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars((string) ($menuItem['menu_type'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><?= htmlspecialchars((string) ($menuItem['menu_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><?= nl2br(htmlspecialchars((string) ($menuItem['menu_content'] ?? '—'), ENT_QUOTES, 'UTF-8')) ?></td>
                                                                <td><?= (int) ($menuItem['guest_count'] ?? 0) ?></td>
                                                                <td><?= formatMoney((float) ($menuItem['price_per_head'] ?? 0)) ?></td>
                                                                <td><?= formatMoney((float) ($menuItem['section_amount'] ?? 0)) ?></td>
                                                                <td><?= ((int) ($menuItem['is_included'] ?? 0) === 1) ? 'Yes' : 'No' ?></td>
                                                                <td><?= nl2br(htmlspecialchars((string) ($menuItem['remarks'] ?? '—'), ENT_QUOTES, 'UTF-8')) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-light border mb-0">No menu items have been created for this quotation.</div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="small text-muted">Terms & conditions</div>
                                    <div class="mt-1"><?= nl2br(htmlspecialchars((string) ($quotation['terms_conditions'] ?? '—'), ENT_QUOTES, 'UTF-8')) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert alert-light border">No quotations are available for this caterer.</div>
                    <?php endif; ?>
                </section>

                <section class="mb-5">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="mb-0">Event details</h3>
                        <span class="badge bg-light text-dark rounded-pill px-3 py-2"><?= count($events) ?> total</span>
                    </div>

                    <?php if ($events): ?>
                        <?php foreach ($events as $event): ?>
                            <?php $eventMenus = $eventMenusById[(int) ($event['id'] ?? 0)] ?? []; ?>
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-body">
                                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
                                        <div>
                                            <div class="small text-uppercase text-muted">Event</div>
                                            <h5 class="mb-1"><?= htmlspecialchars((string) ($event['event_code'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></h5>
                                            <div class="text-muted"><?= htmlspecialchars((string) ($event['event_name'] ?? 'Untitled Event'), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <span class="badge bg-success-subtle text-success px-3 py-2"><?= htmlspecialchars((string) ($event['event_status'] ?? 'UPCOMING'), ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="badge bg-light text-dark px-3 py-2"><?= htmlspecialchars((string) ($event['payment_status'] ?? 'PENDING'), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-3">
                                            <div class="small text-muted">Customer</div><strong><?= htmlspecialchars((string) ($event['customer_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8') ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Email</div><strong><?= htmlspecialchars((string) ($event['email'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Contact</div><strong><?= htmlspecialchars((string) ($event['contact_number'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Event type</div><strong><?= htmlspecialchars((string) ($event['event_type'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-3">
                                            <div class="small text-muted">Date</div><strong><?= formatDate((string) ($event['event_date'] ?? '')) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Time</div><strong><?= htmlspecialchars((string) ($event['event_start_time'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) ($event['event_end_time'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Location</div><strong><?= htmlspecialchars((string) ($event['event_location'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Guests</div><strong><?= (int) ($event['total_guests'] ?? 0) ?></strong>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-3">
                                            <div class="small text-muted">Food amount</div><strong><?= formatMoney((float) ($event['food_amount'] ?? 0)) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Additional charge</div><strong><?= formatMoney((float) ($event['additional_charge'] ?? 0)) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Tax</div><strong><?= formatMoney((float) ($event['tax_amount'] ?? 0)) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Contract amount</div><strong><?= formatMoney((float) ($event['contract_amount'] ?? 0)) ?></strong>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-3">
                                            <div class="small text-muted">Advance</div><strong><?= formatMoney((float) ($event['advance_amount'] ?? 0)) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Received</div><strong><?= formatMoney((float) ($event['received_amount'] ?? 0)) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Balance</div><strong><?= formatMoney((float) ($event['balance_amount'] ?? 0)) ?></strong>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Menu status</div><strong><?= htmlspecialchars((string) ($event['menu_status'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <div class="small text-muted">Special instruction</div>
                                            <div><?= nl2br(htmlspecialchars((string) ($event['special_instruction'] ?? '—'), ENT_QUOTES, 'UTF-8')) ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="small text-muted">Internal note</div>
                                            <div><?= nl2br(htmlspecialchars((string) ($event['internal_note'] ?? '—'), ENT_QUOTES, 'UTF-8')) ?></div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <div class="small text-uppercase text-muted mb-2">Event menu</div>
                                        <?php if ($eventMenus): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered align-middle mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Type</th>
                                                            <th>Title</th>
                                                            <th>Menu content</th>
                                                            <th>Guests</th>
                                                            <th>Finalized</th>
                                                            <th>Remarks</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($eventMenus as $menuItem): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars((string) ($menuItem['menu_type'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><?= htmlspecialchars((string) ($menuItem['menu_title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><?= nl2br(htmlspecialchars((string) ($menuItem['menu_content'] ?? '—'), ENT_QUOTES, 'UTF-8')) ?></td>
                                                                <td><?= (int) ($menuItem['guest_count'] ?? 0) ?></td>
                                                                <td><?= ((int) ($menuItem['is_finalized'] ?? 0) === 1) ? 'Yes' : 'No' ?></td>
                                                                <td><?= nl2br(htmlspecialchars((string) ($menuItem['remarks'] ?? '—'), ENT_QUOTES, 'UTF-8')) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-light border mb-0">No menu items have been created for this event.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert alert-light border">No events are available for this caterer.</div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>

</html>