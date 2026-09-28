<?php

declare(strict_types=1);

require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../common/cms_db.php';

$admin = [
    'name' => (string) ($_SESSION['PLATFORM_ADMIN']['NAME'] ?? 'Platform Admin'),
    'email' => (string) ($_SESSION['PLATFORM_ADMIN']['EMAIL'] ?? ''),
    'role' => (string) ($_SESSION['PLATFORM_ADMIN']['ROLE'] ?? 'ADMIN'),
];
$caterers = [];
$summary = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'events' => 0,
];
$catererDataAvailable = false;
$catererLoadError = false;

try {
    $cms = cmsDatabaseConnection();
    $statusTotals = $cms->query(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN is_active = 1 AND status = 1 THEN 1 ELSE 0 END), 0) AS active
         FROM catering_info'
    )->fetch();
    $summary['total'] = (int) $statusTotals['total'];
    $summary['active'] = (int) $statusTotals['active'];
    $summary['inactive'] = $summary['total'] - $summary['active'];
    $summary['events'] = (int) $cms->query(
        'SELECT COUNT(*) FROM event_details WHERE is_active = 1 AND status = 1'
    )->fetchColumn();

    $caterers = $cms->query(
        "SELECT c.id,
                c.catering_code AS code,
                c.catering_name AS name,
                COALESCE(c.business_tagline, '') AS tagline,
                COALESCE(c.email, '') AS email,
                COALESCE(c.primary_contact, '') AS contact,
                COALESCE(c.city, '') AS city,
                COALESCE(c.state, '') AS state,
                CASE WHEN c.is_active = 1 AND c.status = 1 THEN 'ACTIVE' ELSE 'INACTIVE' END AS status,
                DATE_FORMAT(c.created_at, '%d %b %Y') AS joined,
                c.created_at AS created_sort,
                COALESCE(u.total, 0) AS users,
                COALESCE(e.total, 0) AS events,
                COALESCE(q.total, 0) AS leads
         FROM catering_info c
         LEFT JOIN (
             SELECT catering_id, COUNT(*) AS total
             FROM user_info
             WHERE is_active = 1 AND status = 1
             GROUP BY catering_id
         ) u ON u.catering_id = c.id
         LEFT JOIN (
             SELECT catering_id, COUNT(*) AS total
             FROM event_details
             WHERE is_active = 1 AND status = 1
             GROUP BY catering_id
         ) e ON e.catering_id = c.id
         LEFT JOIN (
             SELECT catering_id, COUNT(*) AS total
             FROM customer_query
             WHERE is_active = 1 AND status = 1
             GROUP BY catering_id
         ) q ON q.catering_id = c.id
         ORDER BY c.created_at DESC, c.id DESC"
    )->fetchAll();

    $catererDataAvailable = true;
} catch (Throwable $exception) {
    error_log('CMS caterer listing query failed: ' . $exception->getMessage());
    $catererLoadError = true;
    $caterers = [];
    $summary = ['total' => 0, 'active' => 0, 'inactive' => 0, 'events' => 0];
}

$cities = array_values(array_unique(array_filter(array_map(static function (array $caterer): string {
    return trim((string) $caterer['city']);
}, $caterers))));
natcasesort($cities);
$cities = array_values($cities);

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
     MAIN
     ========================================================= -->

    <div class="admin-main">


        <!-- TOPBAR -->

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
                        Caterers
                    </h1>

                    <span>
                        Manage UtsavDesk businesses
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
                            <a class="dropdown-item" href="#">
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
         PAGE CONTENT
         ===================================================== -->

        <main class="admin-content">

            <?php if (($_GET['created'] ?? '') === '1'): ?>
                <div class="alert alert-success caterers-created-alert" role="status">
                    <i class="bi bi-check-circle me-1"></i>Caterer created successfully.
                </div>
            <?php endif; ?>


            <!-- PAGE HEADER -->

            <section class="page-heading">

                <div>

                    <h2>
                        Caterer Management
                    </h2>

                    <p>
                        Manage businesses registered on the UtsavDesk platform.
                    </p>

                </div>


                <a
                    href="./add.php"
                    class="ud-btn ud-btn-primary caterer-add-button">

                    <i class="bi bi-plus-lg"></i>

                    <span>
                        Add Caterer
                    </span>

                </a>

            </section>

            <?php if ($catererLoadError): ?>
                <div class="alert alert-warning" role="status">
                    Caterer data could not be loaded from the CMS database. Please try again later.
                </div>
            <?php endif; ?>


            <!-- =================================================
             SUMMARY CARDS
             ================================================= -->

            <section class="caterer-summary-grid">


                <button
                    type="button"
                    class="caterer-summary-card active"
                    data-status="ALL">

                    <div class="caterer-summary-icon all">
                        <i class="bi bi-buildings"></i>
                    </div>

                    <div>

                        <span>
                            Total Caterers
                        </span>

                        <strong>
                            <?= number_format($summary['total']) ?>
                        </strong>

                    </div>

                </button>


                <button
                    type="button"
                    class="caterer-summary-card"
                    data-status="ACTIVE">

                    <div class="caterer-summary-icon active">
                        <i class="bi bi-check-circle"></i>
                    </div>

                    <div>

                        <span>
                            Active
                        </span>

                        <strong>
                            <?= number_format($summary['active']) ?>
                        </strong>

                    </div>

                </button>


                <button
                    type="button"
                    class="caterer-summary-card"
                    data-status="INACTIVE">

                    <div class="caterer-summary-icon inactive">
                        <i class="bi bi-pause-circle"></i>
                    </div>

                    <div>

                        <span>
                            Inactive
                        </span>

                        <strong>
                            <?= number_format($summary['inactive']) ?>
                        </strong>

                    </div>

                </button>


                <div class="caterer-summary-card">
                    <div class="caterer-summary-icon all">
                        <i class="bi bi-calendar2-event"></i>
                    </div>
                    <div>
                        <span>Events</span>
                        <strong><?= number_format($summary['events']) ?></strong>
                    </div>
                </div>

            </section>


            <!-- =================================================
             MAIN TABLE PANEL
             ================================================= -->

            <section class="dashboard-panel caterer-list-panel">


                <!-- FILTER BAR -->

                <div class="caterer-toolbar">


                    <div class="caterer-search">

                        <i class="bi bi-search"></i>

                        <input
                            type="search"
                            id="catererSearch"
                            placeholder="Search caterer, code, email, phone or location..."
                            autocomplete="off">

                    </div>


                    <div class="caterer-filters">


                        <select id="statusFilter" class="caterer-filter-select" aria-label="Filter by account status">
                            <option value="">All Statuses</option>
                            <option value="ACTIVE">Active</option>
                            <option value="INACTIVE">Inactive</option>
                        </select>


                        <select
                            id="cityFilter"
                            class="caterer-filter-select">

                            <option value="">
                                All Locations
                            </option>

                            <?php foreach ($cities as $city): ?>
                                <option value="<?= htmlspecialchars($city, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($city, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>

                        </select>


                        <button
                            type="button"
                            id="clearCatererFilters"
                            class="caterer-filter-button"
                            title="Clear filters">

                            <i class="bi bi-arrow-counterclockwise"></i>

                        </button>


                        <button
                            type="button"
                            class="caterer-filter-button"
                            id="exportCaterers"
                            title="Export">

                            <i class="bi bi-download"></i>

                        </button>

                    </div>

                </div>


                <!-- RESULTS INFO -->

                <div class="caterer-result-bar">

                    <span>

                        Showing

                        <strong id="visibleCatererCount">
                            <?= count($caterers) ?>
                        </strong>

                        caterers

                    </span>


                    <div>

                        <span>
                            Sort by:
                        </span>

                        <select id="catererSort">

                            <option value="newest">
                                Newest
                            </option>

                            <option value="name">
                                Name
                            </option>

                            <option value="events">
                                Events
                            </option>

                        </select>

                    </div>

                </div>


                <!-- TABLE -->

                <div class="table-responsive">

                    <table
                        class="dashboard-table caterer-table"
                        id="catererTable">

                        <thead>

                            <tr>

                                <th>Caterer</th>

                                <th>Contact</th>

                                <th>Location</th>

                                <th class="text-center">Leads</th>

                                <th class="text-center">
                                    Users
                                </th>

                                <th class="text-center">
                                    Events
                                </th>

                                <th>Status</th>

                                <th>Joined</th>

                                <th></th>

                            </tr>

                        </thead>


                        <tbody>


                            <?php foreach ($caterers as $caterer): ?>

                                <tr
                                    class="caterer-row"
                                    data-status="<?= htmlspecialchars($caterer['status'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-city="<?= htmlspecialchars($caterer['city'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-events="<?= (int) $caterer['events'] ?>"
                                    data-leads="<?= (int) $caterer['leads'] ?>"
                                    data-created="<?= htmlspecialchars($caterer['created_sort'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-name="<?= htmlspecialchars(mb_strtolower($caterer['name'], 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>"
                                    data-search="<?= htmlspecialchars(mb_strtolower(implode(' ', [$caterer['code'], $caterer['name'], $caterer['tagline'], $caterer['email'], $caterer['contact'], $caterer['city'], $caterer['state']]), 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>">


                                    <!-- BUSINESS -->

                                    <td>

                                        <div class="caterer-business-cell">

                                            <div class="caterer-avatar">

                                                <?= htmlspecialchars(mb_strtoupper(mb_substr($caterer['name'], 0, 1, 'UTF-8'), 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>

                                            </div>


                                            <div>

                                                <a
                                                    href="./view.php?id=<?= (int)$caterer['id'] ?>"
                                                    class="caterer-name">

                                                    <?= htmlspecialchars(
                                                        $caterer['name']
                                                    ) ?>

                                                </a>


                                                <span class="caterer-code">

                                                    <?= htmlspecialchars($caterer['code'], ENT_QUOTES, 'UTF-8') ?>
                                                    <?php if ($caterer['tagline'] !== ''): ?>
                                                        · <?= htmlspecialchars($caterer['tagline'], ENT_QUOTES, 'UTF-8') ?>
                                                    <?php endif; ?>

                                                </span>

                                            </div>

                                        </div>

                                    </td>


                                    <!-- CONTACT -->

                                    <td>

                                        <div class="caterer-contact-cell">

                                            <span>

                                                <?= htmlspecialchars($caterer['email'] !== '' ? $caterer['email'] : 'Email unavailable', ENT_QUOTES, 'UTF-8') ?>

                                            </span>

                                            <small>

                                                <?= htmlspecialchars($caterer['contact'] !== '' ? $caterer['contact'] : 'Contact unavailable', ENT_QUOTES, 'UTF-8') ?>

                                            </small>

                                        </div>

                                    </td>


                                    <!-- LOCATION -->

                                    <td>

                                        <div class="caterer-location">

                                            <i class="bi bi-geo-alt"></i>

                                            <span>

                                                <?= htmlspecialchars($caterer['city'] !== '' ? $caterer['city'] : 'Location unavailable', ENT_QUOTES, 'UTF-8') ?>

                                            </span>

                                        </div>

                                    </td>


                                    <!-- LEADS -->

                                    <td>

                                        <span class="caterer-number"><?= number_format((int) $caterer['leads']) ?></span>

                                    </td>


                                    <!-- USERS -->

                                    <td class="text-center">

                                        <span class="caterer-number">

                                            <?= (int)$caterer['users'] ?>

                                        </span>

                                    </td>


                                    <!-- EVENTS -->

                                    <td class="text-center">

                                        <span class="caterer-number">

                                            <?= (int)$caterer['events'] ?>

                                        </span>

                                    </td>


                                    <!-- STATUS -->

                                    <td>

                                        <span class="status-badge status-<?=
                                                                            strtolower($caterer['status'])
                                                                            ?>">

                                            <span></span>

                                            <?= ucfirst(
                                                strtolower(
                                                    $caterer['status']
                                                )
                                            ) ?>

                                        </span>

                                    </td>


                                    <!-- DATE -->

                                    <td>

                                        <span class="caterer-joined-date">

                                            <?= htmlspecialchars(
                                                $caterer['joined']
                                            ) ?>

                                        </span>

                                    </td>


                                    <!-- ACTIONS -->

                                    <td>

                                        <div class="dropdown">

                                            <button
                                                type="button"
                                                class="caterer-action-menu"
                                                data-bs-toggle="dropdown"
                                                aria-expanded="false">

                                                <i class="bi bi-three-dots-vertical"></i>

                                            </button>


                                            <ul class="dropdown-menu dropdown-menu-end caterer-dropdown">


                                                <li>

                                                    <a
                                                        class="dropdown-item"
                                                        href="./view.php?id=<?= (int)$caterer['id'] ?>">

                                                        <i class="bi bi-eye"></i>

                                                        View Details

                                                    </a>

                                                </li>


                                                <li>

                                                    <a
                                                        class="dropdown-item"
                                                        href="./edit.php?id=<?= (int)$caterer['id'] ?>">

                                                        <i class="bi bi-pencil"></i>

                                                        Edit Caterer

                                                    </a>

                                                </li>


                                                <li>

                                                    <a
                                                        class="dropdown-item"
                                                        href="../users/?caterer=<?= urlencode($caterer['code']) ?>">

                                                        <i class="bi bi-people"></i>

                                                        View Users

                                                    </a>

                                                </li>


                                                <li>

                                                    <a
                                                        class="dropdown-item"
                                                        href="../activity/?caterer=<?= urlencode($caterer['code']) ?>">

                                                        <i class="bi bi-clock-history"></i>

                                                        Activity Logs

                                                    </a>

                                                </li>


                                                <li>
                                                    <hr class="dropdown-divider">
                                                </li>


                                            </ul>

                                        </div>

                                    </td>


                                </tr>

                            <?php endforeach; ?>


                            <!-- EMPTY STATE -->

                            <tr
                                id="catererEmptyState"
                                class="d-none">

                                <td colspan="9">

                                    <div class="caterer-empty-state">

                                        <div>

                                            <i class="bi bi-search"></i>

                                        </div>

                                        <h4>
                                            No caterers found
                                        </h4>

                                        <p>
                                            Try changing your search or filters.
                                        </p>

                                        <button
                                            type="button"
                                            id="emptyClearFilters">
                                            Clear Filters
                                        </button>

                                    </div>

                                </td>

                            </tr>


                        </tbody>

                    </table>

                </div>


                <!-- PAGINATION -->

                <div class="caterer-pagination">

                    <div>

                        Showing

                        <strong id="catererRangeStart">0</strong>

                        to

                        <strong id="catererRangeEnd">0</strong>

                        of

                        <strong id="catererFilteredTotal"><?= count($caterers) ?></strong>

                        caterers

                    </div>


                    <nav>

                        <button type="button" id="previousCatererPage" aria-label="Previous page">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <span id="catererPageIndicator" aria-live="polite">Page 1 of 1</span>
                        <button type="button" id="nextCatererPage" aria-label="Next page">
                            <i class="bi bi-chevron-right"></i>
                        </button>

                    </nav>

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


    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js">
    </script>

    <script src="../assets/js/admin.js"></script>


    <script>
        $(function() {

            const pageSize = 10;
            let currentPage = 1;
            let filteredRows = [];


            /* =====================================================
               FILTER CATERERS
               ===================================================== */

            function filterCaterers() {
                const search = $.trim(
                    $('#catererSearch').val()
                ).toLowerCase();
                const status = $('#statusFilter').val();
                const city = $('#cityFilter').val();
                filteredRows = $('.caterer-row').get().filter(function(row) {
                    const $row = $(row);
                    const rowSearch = String($row.data('search') || '').toLowerCase();
                    const rowStatus = String($row.data('status') || '');
                    const rowCity = String($row.data('city') || '');

                    return (!search || rowSearch.includes(search)) &&
                        (!status || rowStatus === status) &&
                        (!city || rowCity === city);
                });

                currentPage = 1;
                renderCatererPage();
            }

            function renderCatererPage() {
                const total = filteredRows.length;
                const pageCount = Math.max(1, Math.ceil(total / pageSize));
                currentPage = Math.min(currentPage, pageCount);
                const firstIndex = total === 0 ? 0 : (currentPage - 1) * pageSize;
                const pageRows = filteredRows.slice(firstIndex, firstIndex + pageSize);

                $('.caterer-row').hide();
                $(pageRows).show();
                $('#visibleCatererCount, #catererFilteredTotal').text(total);
                $('#catererRangeStart').text(total === 0 ? 0 : firstIndex + 1);
                $('#catererRangeEnd').text(Math.min(firstIndex + pageSize, total));
                $('#catererPageIndicator').text('Page ' + currentPage + ' of ' + pageCount);
                $('#previousCatererPage').prop('disabled', currentPage <= 1);
                $('#nextCatererPage').prop('disabled', currentPage >= pageCount);
                $('#catererEmptyState').toggleClass('d-none', total !== 0);
            }


            /* =====================================================
               SEARCH
               ===================================================== */

            $('#catererSearch').on(
                'input',
                filterCaterers
            );


            /* =====================================================
               FILTER DROPDOWNS
               ===================================================== */

            $('#statusFilter, #cityFilter').on(
                'change',
                function() {
                    const status = $('#statusFilter').val() || 'ALL';
                    $('.caterer-summary-card[data-status]').removeClass('active');
                    $('.caterer-summary-card[data-status="' + status + '"]').addClass('active');
                    filterCaterers();
                }
            );


            /* =====================================================
               STATUS CARDS
               ===================================================== */

            $('.caterer-summary-card[data-status]').on(
                'click',
                function() {
                    const status = String($(this).data('status'));
                    $('#statusFilter').val(status === 'ALL' ? '' : status);
                    $('.caterer-summary-card[data-status]').removeClass('active');
                    $(this).addClass('active');
                    filterCaterers();

                }
            );


            /* =====================================================
               CLEAR FILTERS
               ===================================================== */

            function clearFilters() {

                $('#catererSearch').val('');
                $('#statusFilter').val('');
                $('#cityFilter').val('');

                $('.caterer-summary-card[data-status]').removeClass('active');
                $('.caterer-summary-card[data-status="ALL"]').addClass('active');
                filterCaterers();

            }


            $('#clearCatererFilters').on(
                'click',
                clearFilters
            );


            $('#emptyClearFilters').on(
                'click',
                clearFilters
            );


            /* =====================================================
               SORTING
               ===================================================== */

            $('#catererSort').on(
                'change',
                function() {

                    const sort = $(this).val();

                    const $tbody =
                        $('#catererTable tbody');


                    const rows =
                        $('.caterer-row')
                        .get();


                    rows.sort(function(a, b) {

                        if (sort === 'name') {

                            return String(
                                $(a).data('name')
                            ).localeCompare(
                                String(
                                    $(b).data('name')
                                )
                            );

                        }


                        if (sort === 'events') {

                            return (
                                Number($(b).data('events')) -
                                Number($(a).data('events'))
                            );

                        }

                        if (sort === 'newest') {
                            return String($(b).data('created')).localeCompare(String($(a).data('created')));
                        }

                        return String($(a).data('name')).localeCompare(String($(b).data('name')));

                    });


                    $.each(rows, function(_, row) {

                        $tbody.append(row);

                    });


                    $tbody.append(
                        $('#catererEmptyState')
                    );

                    filterCaterers();

                }
            );


            $('#previousCatererPage').on('click', function() {
                if (currentPage > 1) {
                    currentPage--;
                    renderCatererPage();
                }
            });

            $('#nextCatererPage').on('click', function() {
                if (currentPage * pageSize < filteredRows.length) {
                    currentPage++;
                    renderCatererPage();
                }
            });

            $('#exportCaterers').on('click', function() {
                const headers = $('#catererTable thead th').toArray().slice(0, 8).map(function(cell) {
                    return cell.innerText.trim();
                });
                const csvRows = [headers].concat(filteredRows.map(function(row) {
                    return Array.from(row.cells).slice(0, 8).map(function(cell) {
                        return '"' + cell.innerText.trim().replace(/"/g, '""') + '"';
                    });
                }));
                const csv = '\uFEFF' + csvRows.map(function(row) {
                    return row.join(',');
                }).join('\r\n');
                const url = URL.createObjectURL(new Blob([csv], {
                    type: 'text/csv;charset=utf-8'
                }));
                const download = document.createElement('a');
                download.href = url;
                download.download = 'utsavdesk-caterers-' + new Date().toISOString().slice(0, 10) + '.csv';
                download.click();
                window.setTimeout(function() {
                    URL.revokeObjectURL(url);
                }, 1000);
            });

            filterCaterers();

        });
    </script>

</body>

</html>