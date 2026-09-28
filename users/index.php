<?php

declare(strict_types=1);

require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../common/cms_db.php';

$admin = [
    'name' => (string) ($_SESSION['PLATFORM_ADMIN']['NAME'] ?? 'Platform Admin'),
    'email' => (string) ($_SESSION['PLATFORM_ADMIN']['EMAIL'] ?? ''),
    'role' => (string) ($_SESSION['PLATFORM_ADMIN']['ROLE'] ?? 'ADMIN'),
];
$users = [];
$caterers = [];
$summary = [
    'total_users' => 0,
    'active_users' => 0,
    'inactive_users' => 0,
    'verified_users' => 0,
    'logged_in_today' => 0,
];
$userLoadError = false;
$createUserErrors = [];
$createUserMessage = '';
$createUserValues = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'contact_number' => '',
    'role' => 'SUPER_ADMIN',
    'catering_id' => '',
];
$rolePermissionsForCreateUsers = [
    'SUPER_ADMIN' => ['SUPER_ADMIN', 'ADMIN', 'MANAGER', 'SALES_HEAD', 'SALES_ASSOCIATE'],
    'ADMIN' => ['SUPER_ADMIN'],
    'OPERATIONS' => ['MANAGER', 'SALES_HEAD', 'SALES_ASSOCIATE'],
];
$creatableRoles = $rolePermissionsForCreateUsers[$admin['role']] ?? [];
$createUserValues['role'] = $creatableRoles[0] ?? 'SUPER_ADMIN';
$canCreateUsers = $creatableRoles !== [];

try {
    $cms = cmsDatabaseConnection();
    $userTotals = $cms->query(
        'SELECT COUNT(*) AS total_users,
                COALESCE(SUM(CASE WHEN is_active = 1 AND status = 1 THEN 1 ELSE 0 END), 0) AS active_users,
                COALESCE(SUM(CASE WHEN is_email_verified = 1 THEN 1 ELSE 0 END), 0) AS verified_users,
                COALESCE(SUM(CASE WHEN last_login_at >= CURRENT_DATE THEN 1 ELSE 0 END), 0) AS logged_in_today
         FROM user_info'
    )->fetch();
    $summary['total_users'] = (int) $userTotals['total_users'];
    $summary['active_users'] = (int) $userTotals['active_users'];
    $summary['inactive_users'] = $summary['total_users'] - $summary['active_users'];
    $summary['verified_users'] = (int) $userTotals['verified_users'];
    $summary['logged_in_today'] = (int) $userTotals['logged_in_today'];

    $users = $cms->query(
        "SELECT u.id,
                u.user_code,
                TRIM(CONCAT(u.first_name, ' ', COALESCE(u.last_name, ''))) AS name,
                u.email,
                u.contact_number AS contact,
                COALESCE(c.catering_name, 'Unknown caterer') AS caterer,
                COALESCE(c.catering_code, '') AS caterer_code,
                u.role,
                CASE
                    WHEN EXISTS (SELECT 1 FROM user_subscription s WHERE s.user_id=u.id AND s.status='ACTIVE' AND s.starts_on<=CURRENT_DATE AND s.ends_on>CURRENT_DATE) THEN 'ACTIVE'
                    WHEN EXISTS (SELECT 1 FROM user_subscription s WHERE s.user_id=u.id AND s.status='ACTIVE' AND s.starts_on>CURRENT_DATE) THEN 'SCHEDULED'
                    WHEN EXISTS (SELECT 1 FROM user_subscription s WHERE s.user_id=u.id AND s.status='ACTIVE' AND s.ends_on<=CURRENT_DATE) THEN 'EXPIRED'
                    WHEN EXISTS (SELECT 1 FROM user_subscription s WHERE s.user_id=u.id AND s.status='CANCELLED') THEN 'CANCELLED'
                    ELSE 'NONE' END AS subscription_status,
                u.is_email_verified AS email_verified,
                u.is_contact_verified AS contact_verified,
                CASE WHEN u.is_active = 1 AND u.status = 1 THEN 'ACTIVE' ELSE 'INACTIVE' END AS status,
                COALESCE(DATE_FORMAT(u.last_login_at, '%d %b %Y'), 'Never') AS last_login,
                COALESCE(DATE_FORMAT(u.last_login_at, '%h:%i %p'), '') AS last_login_time,
                DATE_FORMAT(u.created_at, '%d %b %Y') AS created
         FROM user_info u
         LEFT JOIN catering_info c ON c.id = u.catering_id
         ORDER BY u.created_at DESC, u.id DESC"
    )->fetchAll();
    $caterers = $cms->query(
        "SELECT id, catering_code, catering_name
            FROM catering_info
            WHERE is_active = 1 AND status = 1 AND is_user_assign = 0
            ORDER BY catering_name, id"
    )->fetchAll();
    $userDataAvailable = true;
} catch (Throwable $exception) {
    error_log('CMS user listing query failed: ' . $exception->getMessage());
    $userLoadError = true;
    $userDataAvailable = false;
    $users = [];
    $caterers = [];
    $summary = [
        'total_users' => 0,
        'active_users' => 0,
        'inactive_users' => 0,
        'verified_users' => 0,
        'logged_in_today' => 0,
    ];
}

function generateUniqueUserCode(PDO $cms): string
{
    $check = $cms->prepare('SELECT 1 FROM user_info WHERE user_code = :user_code LIMIT 1');

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $userCode = 'USR' . strtoupper(bin2hex(random_bytes(8)));
        $check->execute(['user_code' => $userCode]);

        if (!$check->fetchColumn()) {
            return $userCode;
        }
    }

    throw new RuntimeException('A unique user code could not be generated.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
    if (!$canCreateUsers) {
        http_response_code(403);
        $createUserMessage = 'You are not authorized to create users.';
    } elseif (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $createUserMessage = 'Your session expired. Refresh this page and try again.';
    } else {
        foreach (array_keys($createUserValues) as $field) {
            $postedValue = $_POST[$field] ?? '';
            $createUserValues[$field] = is_string($postedValue) ? trim($postedValue) : '';
        }
        $createUserValues['email'] = strtolower($createUserValues['email']);

        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $passwordConfirmation = is_string($_POST['password_confirmation'] ?? null)
            ? $_POST['password_confirmation']
            : '';

        if ($createUserValues['first_name'] === '' || mb_strlen($createUserValues['first_name'], 'UTF-8') > 100) {
            $createUserErrors['first_name'] = 'Enter a first name up to 100 characters.';
        }
        if (mb_strlen($createUserValues['last_name'], 'UTF-8') > 100) {
            $createUserErrors['last_name'] = 'Last name cannot exceed 100 characters.';
        }
        if (!filter_var($createUserValues['email'], FILTER_VALIDATE_EMAIL) || strlen($createUserValues['email']) > 150) {
            $createUserErrors['email'] = 'Enter a valid email address up to 150 characters.';
        }
        if ($createUserValues['contact_number'] === '' || strlen($createUserValues['contact_number']) > 20) {
            $createUserErrors['contact_number'] = 'Enter a contact number up to 20 characters.';
        }
        if (!in_array($createUserValues['role'], $creatableRoles, true)) {
            $createUserErrors['role'] = 'Select a valid workspace role.';
        }
        if (!ctype_digit($createUserValues['catering_id']) || (int) $createUserValues['catering_id'] < 1) {
            $createUserErrors['catering_id'] = 'Select a catering account.';
        }
        if (
            strlen($password) < 12 || strlen($password) > 72
            || !preg_match('/[a-z]/', $password)
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/[0-9]/', $password)
            || !preg_match('/[^a-zA-Z0-9]/', $password)
        ) {
            $createUserErrors['password'] = 'Use 12-72 characters with uppercase, lowercase, a number, and a symbol.';
        }
        if ($password !== $passwordConfirmation) {
            $createUserErrors['password_confirmation'] = 'Passwords do not match.';
        }

        if ($createUserErrors === []) {
            try {
                $cms = cmsDatabaseConnection();
                $cms->beginTransaction();
                $catererCheck = $cms->prepare(
                    'SELECT id FROM catering_info WHERE id = :id AND is_active = 1 AND status = 1 AND is_user_assign = 0 LIMIT 1 FOR UPDATE'
                );
                $catererCheck->execute(['id' => (int) $createUserValues['catering_id']]);

                if (!$catererCheck->fetchColumn()) {
                    $cms->rollBack();
                    $createUserErrors['catering_id'] = 'That catering account is no longer active. Refresh and choose another.';
                } else {
                    $duplicateCheck = $cms->prepare(
                        'SELECT 1 FROM user_info WHERE email = :email OR contact_number = :contact_number LIMIT 1'
                    );
                    $duplicateCheck->execute([
                        'email' => $createUserValues['email'],
                        'contact_number' => $createUserValues['contact_number'],
                    ]);

                    if ($duplicateCheck->fetchColumn()) {
                        $cms->rollBack();
                        $createUserMessage = 'That email address or contact number is already assigned to a user.';
                    } else {
                        $insert = $cms->prepare(
                            'INSERT INTO user_info (
                                catering_id, user_code, first_name, last_name, email,
                                contact_number, password_hash, role, is_active,
                                is_email_verified, is_contact_verified, password_changed_at,
                                failed_login_attempts, status
                            ) VALUES (
                                :catering_id, :user_code, :first_name, :last_name, :email,
                                :contact_number, :password_hash, :role, 1,
                                0, 0, UTC_TIMESTAMP(), 0, 1
                            )'
                        );
                        $userCode = generateUniqueUserCode($cms);
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                        if ($passwordHash === false) {
                            throw new RuntimeException('The initial password could not be secured.');
                        }

                        $insert->execute([
                            'catering_id' => (int) $createUserValues['catering_id'],
                            'user_code' => $userCode,
                            'first_name' => $createUserValues['first_name'],
                            'last_name' => $createUserValues['last_name'] === '' ? null : $createUserValues['last_name'],
                            'email' => $createUserValues['email'],
                            'contact_number' => $createUserValues['contact_number'],
                            'password_hash' => $passwordHash,
                            'role' => $createUserValues['role'],
                        ]);

                        $assignCaterer = $cms->prepare(
                            'UPDATE catering_info SET is_user_assign = 1 WHERE id = :id AND is_user_assign = 0'
                        );
                        $assignCaterer->execute(['id' => (int) $createUserValues['catering_id']]);

                        if ($assignCaterer->rowCount() !== 1) {
                            throw new RuntimeException('Caterer assignment changed while creating the user.');
                        }

                        $cms->commit();

                        $_SESSION['USER_CREATE_SUCCESS'] = [
                            'user_code' => $userCode,
                            'name' => $createUserValues['first_name'] . ($createUserValues['last_name'] === '' ? '' : ' ' . $createUserValues['last_name']),
                        ];
                        header('Location: ' . APP_BASE_URL . '/users/', true, 303);
                        exit;
                    }
                }
            } catch (PDOException $exception) {
                if (isset($cms) && $cms instanceof PDO && $cms->inTransaction()) {
                    $cms->rollBack();
                }

                if ($exception->getCode() === '23000') {
                    $createUserMessage = 'The email, contact number, or generated user code is already in use. Please retry.';
                } else {
                    error_log('CMS user create failed: ' . $exception->getMessage());
                    $createUserMessage = 'The user could not be saved. Check the CMS database connection and try again.';
                }
            } catch (Throwable $exception) {
                if (isset($cms) && $cms instanceof PDO && $cms->inTransaction()) {
                    $cms->rollBack();
                }

                error_log('CMS user create failed: ' . $exception->getMessage());
                $createUserMessage = 'The user could not be saved. Please try again.';
            }
        }
    }
}

$createdUser = $_SESSION['USER_CREATE_SUCCESS'] ?? null;
unset($_SESSION['USER_CREATE_SUCCESS']);
$showAddUserModal = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'create_user'
    && ($createUserErrors !== [] || $createUserMessage !== '')
    && $canCreateUsers;

$availableRoles = array_values(array_unique(array_column($users, 'role')));
sort($availableRoles, SORT_NATURAL | SORT_FLAG_CASE);


function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));

    if (!$parts) {
        return 'U';
    }

    $result = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        $result .= strtoupper(substr($part, 0, 1));
    }

    return $result ?: 'U';
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
                        Users
                    </h1>

                    <span>
                        Caterer users and platform access
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

            <?php if ($createUserMessage !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <?= htmlspecialchars($createUserMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
            <?php if (is_array($createdUser)): ?>
                <div class="alert alert-success" role="status">
                    <i class="bi bi-check-circle me-1"></i>
                    Created <?= htmlspecialchars($createdUser['name'], ENT_QUOTES, 'UTF-8') ?>. User code: <strong><?= htmlspecialchars($createdUser['user_code'], ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
            <?php endif; ?>

            <?php if ($userLoadError): ?>
                <div class="alert alert-warning" role="status">
                    User data could not be loaded from the CMS database.
                </div>
            <?php endif; ?>


            <!-- PAGE HEADING -->

            <section class="users-heading">

                <div>

                    <h2>
                        User Management
                    </h2>

                    <p>
                        View and manage users across all registered caterers.
                    </p>

                </div>


                <div class="users-heading-actions">
                    <?php if ($canCreateUsers): ?>
                        <button type="button" class="btn btn-primary users-add-button" data-bs-toggle="modal" data-bs-target="#addUserModal">
                            <i class="bi bi-person-plus me-1"></i>Add User
                        </button>
                    <?php endif; ?>
                    <button type="button" class="users-export-button" id="usersExportButton">
                        <i class="bi bi-download"></i>
                        <span>Export Users</span>
                    </button>
                </div>

            </section>


            <!-- =================================================
             SUMMARY
             ================================================= -->

            <section class="users-summary-grid">


                <article class="users-summary-card">

                    <div class="users-summary-icon total">
                        <i class="bi bi-people"></i>
                    </div>

                    <div>

                        <span>
                            Total Users
                        </span>

                        <strong>
                            <?= number_format($summary['total_users']) ?>
                        </strong>

                        <small>
                            Across all caterers
                        </small>

                    </div>

                </article>


                <article class="users-summary-card">

                    <div class="users-summary-icon active">
                        <i class="bi bi-person-check"></i>
                    </div>

                    <div>

                        <span>
                            Active Users
                        </span>

                        <strong>
                            <?= number_format($summary['active_users']) ?>
                        </strong>

                        <small>
                            Currently active
                        </small>

                    </div>

                </article>


                <article class="users-summary-card">

                    <div class="users-summary-icon inactive">
                        <i class="bi bi-person-dash"></i>
                    </div>

                    <div>

                        <span>
                            Inactive
                        </span>

                        <strong>
                            <?= number_format($summary['inactive_users']) ?>
                        </strong>

                        <small>
                            Disabled accounts
                        </small>

                    </div>

                </article>


                <article class="users-summary-card">

                    <div class="users-summary-icon verified">
                        <i class="bi bi-patch-check"></i>
                    </div>

                    <div>

                        <span>
                            Verified
                        </span>

                        <strong>
                            <?= number_format($summary['verified_users']) ?>
                        </strong>

                        <small>
                            Email verified
                        </small>

                    </div>

                </article>


                <article class="users-summary-card">

                    <div class="users-summary-icon today">
                        <i class="bi bi-activity"></i>
                    </div>

                    <div>

                        <span>
                            Active Today
                        </span>

                        <strong>
                            <?= number_format($summary['logged_in_today']) ?>
                        </strong>

                        <small>
                            Logged in today
                        </small>

                    </div>

                </article>


            </section>


            <!-- =================================================
             USER TABLE
             ================================================= -->

            <section class="users-table-panel">


                <div class="users-table-header">

                    <div>

                        <h3>
                            Caterer Users
                        </h3>

                        <p>
                            Users registered under UtsavDesk caterer accounts.
                        </p>

                    </div>


                    <div class="users-table-count">

                        <i class="bi bi-people"></i>

                        <span id="usersHeaderCount"><?= number_format($summary['total_users']) ?></span>

                        users

                    </div>

                </div>


                <!-- FILTER BAR -->

                <div class="users-toolbar">


                    <div class="users-search">

                        <i class="bi bi-search"></i>

                        <input
                            type="search"
                            id="userSearch"
                            placeholder="Search name, email, contact, user code or caterer..."
                            autocomplete="off">

                    </div>


                    <div class="users-filters">


                        <select id="userRoleFilter">

                            <option value="">
                                All Roles
                            </option>

                            <?php foreach ($availableRoles as $role): ?>
                                <option value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $role))), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>

                        </select>


                        <select id="userStatusFilter">

                            <option value="">
                                All Status
                            </option>

                            <option value="ACTIVE">
                                Active
                            </option>

                            <option value="INACTIVE">
                                Inactive
                            </option>

                        </select>


                        <select id="userVerificationFilter">

                            <option value="">
                                Verification
                            </option>

                            <option value="VERIFIED">
                                Verified
                            </option>

                            <option value="UNVERIFIED">
                                Unverified
                            </option>

                        </select>


                        <button
                            type="button"
                            id="clearUserFilters"
                            title="Clear Filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>

                    </div>

                </div>


                <!-- TABLE -->

                <div class="table-responsive">

                    <table
                        class="users-table"
                        id="usersTable">

                        <thead>

                            <tr>

                                <th>User</th>

                                <th>Caterer</th>

                                <th>Role</th>

                                <th>Contact</th>

                                <th>Verification</th>

                                <th>Subscription</th>
                                <th>Last Login</th>

                                <th>Status</th>

                                <th></th>

                            </tr>

                        </thead>


                        <tbody>


                            <?php foreach ($users as $user): ?>
                                <?php $verification = (int) $user['email_verified'] === 1 ? 'VERIFIED' : 'UNVERIFIED'; ?>
                                <tr
                                    class="user-row"
                                    data-role="<?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-status="<?= htmlspecialchars($user['status'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-verification="<?= $verification ?>"
                                    data-search="<?= htmlspecialchars(mb_strtolower(implode(' ', [$user['name'], $user['email'], $user['contact'], $user['user_code'], $user['caterer'], $user['caterer_code']]), 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>">
                                    <td>
                                        <div class="users-person">
                                            <div class="users-avatar"><?= htmlspecialchars(initials($user['name']), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div>
                                                <button
                                                    type="button"
                                                    class="users-name-button view-user-button"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#userDetailsModal"
                                                    data-name="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-code="<?= htmlspecialchars($user['user_code'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-email="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-contact="<?= htmlspecialchars($user['contact'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-caterer="<?= htmlspecialchars($user['caterer'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-caterer-code="<?= htmlspecialchars($user['caterer_code'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-role="<?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-status="<?= htmlspecialchars($user['status'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-created="<?= htmlspecialchars($user['created'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-last-login="<?= htmlspecialchars(trim($user['last_login'] . ' ' . $user['last_login_time']), ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                                <span><?= htmlspecialchars($user['user_code'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="users-caterer">
                                            <a href="../caterers/"><?= htmlspecialchars($user['caterer'], ENT_QUOTES, 'UTF-8') ?></a>
                                            <span><?= htmlspecialchars($user['caterer_code'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="users-role role-<?= htmlspecialchars(strtolower($user['role']), ENT_QUOTES, 'UTF-8') ?>">
                                            <?php if ($user['role'] === 'SUPER_ADMIN'): ?>
                                                <i class="bi bi-shield-check"></i>
                                            <?php elseif ($user['role'] === 'ADMIN'): ?>
                                                <i class="bi bi-person-gear"></i>
                                            <?php elseif ($user['role'] === 'MANAGER'): ?>
                                                <i class="bi bi-briefcase"></i>
                                            <?php else: ?>
                                                <i class="bi bi-person"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $user['role']))), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td><span class="users-contact"><?= htmlspecialchars($user['contact'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <div class="users-verification">
                                            <span class="<?= $user['email_verified'] ? 'verified' : 'unverified' ?>" title="Email">
                                                <i class="bi bi-envelope"></i>
                                                <i class="bi <?= $user['email_verified'] ? 'bi-check-circle-fill' : 'bi-exclamation-circle' ?>"></i>
                                            </span>
                                            <span class="<?= $user['contact_verified'] ? 'verified' : 'unverified' ?>" title="Contact">
                                                <i class="bi bi-phone"></i>
                                                <i class="bi <?= $user['contact_verified'] ? 'bi-check-circle-fill' : 'bi-exclamation-circle' ?>"></i>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (in_array($admin['role'], ['SUPER_ADMIN','ADMIN','VIEWER'], true)): ?>
                                        <a href="../subscriptions/?user_id=<?= (int)$user['id'] ?>"><?= htmlspecialchars($user['subscription_status'], ENT_QUOTES, 'UTF-8') ?></a>
                                        <?php else: ?><?= htmlspecialchars($user['subscription_status'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                    </td>
                                    <td><div class="users-last-login">
                                            <strong><?= htmlspecialchars($user['last_login'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?php if ($user['last_login_time'] !== ''): ?>
                                                <span><?= htmlspecialchars($user['last_login_time'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="users-status status-<?= htmlspecialchars(strtolower($user['status']), ENT_QUOTES, 'UTF-8') ?>">
                                            <span></span><?= htmlspecialchars(ucfirst(strtolower($user['status'])), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="users-action-button view-user-button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#userDetailsModal"
                                            data-name="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-code="<?= htmlspecialchars($user['user_code'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-email="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-contact="<?= htmlspecialchars($user['contact'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-caterer="<?= htmlspecialchars($user['caterer'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-caterer-code="<?= htmlspecialchars($user['caterer_code'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-role="<?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-status="<?= htmlspecialchars($user['status'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-created="<?= htmlspecialchars($user['created'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-last-login="<?= htmlspecialchars(trim($user['last_login'] . ' ' . $user['last_login_time']), ENT_QUOTES, 'UTF-8') ?>"
                                            title="View user details" aria-label="View user details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>


                            <!-- EMPTY STATE -->

                            <tr
                                id="usersEmptyState"
                                class="d-none">

                                <td colspan="9">

                                    <div class="users-empty-state">

                                        <div class="users-empty-icon">
                                            <i class="bi bi-people"></i>
                                        </div>

                                        <h4>
                                            No users found
                                        </h4>

                                        <p>
                                            Try changing your search or filters.
                                        </p>

                                        <button
                                            type="button"
                                            id="clearEmptyUserFilters">
                                            Clear Filters
                                        </button>

                                    </div>

                                </td>

                            </tr>


                        </tbody>

                    </table>

                </div>


                <!-- PAGINATION -->

                <div class="users-pagination">

                    <span>
                        Showing <strong id="userRangeStart">0</strong> to
                        <strong id="userRangeEnd">0</strong> of
                        <strong id="userFilteredCount"><?= count($users) ?></strong> users

                    </span>


                    <div>

                        <button type="button" id="previousUserPage" aria-label="Previous page" disabled>
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <span id="userPageIndicator" aria-live="polite">Page 1 of 1</span>
                        <button type="button" id="nextUserPage" aria-label="Next page" disabled>
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
     USER DETAILS MODAL
     ========================================================= -->

    <div
        class="modal fade"
        id="userDetailsModal"
        tabindex="-1">

        <div class="modal-dialog modal-dialog-centered">

            <div class="modal-content users-modal">


                <div class="modal-header">

                    <div class="users-modal-user">

                        <div
                            class="users-modal-avatar"
                            id="modalUserAvatar">
                            U
                        </div>


                        <div>

                            <h5
                                class="modal-title"
                                id="modalUserName">
                                User
                            </h5>

                            <p id="modalUserCode">
                                —
                            </p>

                        </div>

                    </div>


                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"></button>

                </div>


                <div class="modal-body">


                    <div class="users-detail-grid">


                        <div class="users-detail-item">

                            <span>
                                Email
                            </span>

                            <strong id="modalUserEmail">
                                —
                            </strong>

                        </div>


                        <div class="users-detail-item">

                            <span>
                                Contact
                            </span>

                            <strong id="modalUserContact">
                                —
                            </strong>

                        </div>


                        <div class="users-detail-item">

                            <span>
                                Role
                            </span>

                            <strong id="modalUserRole">
                                —
                            </strong>

                        </div>


                        <div class="users-detail-item">

                            <span>
                                Status
                            </span>

                            <strong id="modalUserStatus">
                                —
                            </strong>

                        </div>


                        <div class="users-detail-item users-detail-full">

                            <span>
                                Caterer
                            </span>

                            <strong id="modalUserCaterer">
                                —
                            </strong>

                            <small id="modalUserCatererCode">
                                —
                            </small>

                        </div>


                        <div class="users-detail-item">

                            <span>
                                Account Created
                            </span>

                            <strong id="modalUserCreated">
                                —
                            </strong>

                        </div>


                        <div class="users-detail-item">

                            <span>
                                Last Login
                            </span>

                            <strong id="modalUserLastLogin">
                                —
                            </strong>

                        </div>


                    </div>


                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="users-modal-close"
                        data-bs-dismiss="modal">
                        Close
                    </button>


                </div>


            </div>

        </div>

    </div>


    <?php if ($canCreateUsers): ?>
        <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content users-modal">
                    <form method="post" action="<?= htmlspecialchars(APP_BASE_URL . '/users/', ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="create_user">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="addUserModalTitle"><i class="bi bi-person-plus text-primary me-2"></i>Add user</h5>
                                <p class="text-muted small mb-0">Create a workspace account for an unassigned active caterer.</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <?php if ($createUserMessage !== ''): ?>
                                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($createUserMessage, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <?php if ($createUserErrors !== []): ?>
                                <div class="alert alert-danger" role="alert">Please correct the highlighted fields.</div>
                            <?php endif; ?>
                            <?php if ($caterers === []): ?>
                                <div class="alert alert-warning" role="status">No active caterers are available. Create or activate a caterer first.</div>
                            <?php endif; ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="newUserFirstName"><i class="bi bi-person me-1 text-secondary"></i>First name</label>
                                    <input class="form-control<?= isset($createUserErrors['first_name']) ? ' is-invalid' : '' ?>" id="newUserFirstName" name="first_name" value="<?= htmlspecialchars($createUserValues['first_name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="100" required>
                                    <?php if (isset($createUserErrors['first_name'])): ?><div class="invalid-feedback"><?= htmlspecialchars($createUserErrors['first_name'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="newUserLastName"><i class="bi bi-person me-1 text-secondary"></i>Last name</label>
                                    <input class="form-control<?= isset($createUserErrors['last_name']) ? ' is-invalid' : '' ?>" id="newUserLastName" name="last_name" value="<?= htmlspecialchars($createUserValues['last_name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="100">
                                    <?php if (isset($createUserErrors['last_name'])): ?><div class="invalid-feedback"><?= htmlspecialchars($createUserErrors['last_name'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="newUserEmail"><i class="bi bi-envelope me-1 text-secondary"></i>Email</label>
                                    <input type="email" class="form-control<?= isset($createUserErrors['email']) ? ' is-invalid' : '' ?>" id="newUserEmail" name="email" value="<?= htmlspecialchars($createUserValues['email'], ENT_QUOTES, 'UTF-8') ?>" maxlength="150" autocomplete="email" required>
                                    <?php if (isset($createUserErrors['email'])): ?><div class="invalid-feedback"><?= htmlspecialchars($createUserErrors['email'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="newUserContact"><i class="bi bi-telephone me-1 text-secondary"></i>Contact number</label>
                                    <input type="tel" class="form-control<?= isset($createUserErrors['contact_number']) ? ' is-invalid' : '' ?>" id="newUserContact" name="contact_number" value="<?= htmlspecialchars($createUserValues['contact_number'], ENT_QUOTES, 'UTF-8') ?>" maxlength="20" autocomplete="tel" required>
                                    <?php if (isset($createUserErrors['contact_number'])): ?><div class="invalid-feedback"><?= htmlspecialchars($createUserErrors['contact_number'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="newUserCaterer"><i class="bi bi-building me-1 text-secondary"></i>Caterer</label>
                                    <select class="form-select<?= isset($createUserErrors['catering_id']) ? ' is-invalid' : '' ?>" id="newUserCaterer" name="catering_id" required>
                                        <option value="">Select an active caterer</option>
                                        <?php foreach ($caterers as $caterer): ?>
                                            <option value="<?= (int) $caterer['id'] ?>" <?= (string) $caterer['id'] === $createUserValues['catering_id'] ? 'selected' : '' ?>><?= htmlspecialchars($caterer['catering_name'] . ' · ' . $caterer['catering_code'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($createUserErrors['catering_id'])): ?><div class="invalid-feedback"><?= htmlspecialchars($createUserErrors['catering_id'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="newUserRole"><i class="bi bi-shield-lock me-1 text-secondary"></i>Workspace role</label>
                                    <select class="form-select<?= isset($createUserErrors['role']) ? ' is-invalid' : '' ?>" id="newUserRole" name="role" required>
                                        <?php foreach ($creatableRoles as $role): ?>
                                            <option value="<?= $role ?>" <?= $createUserValues['role'] === $role ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $role))), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($createUserErrors['role'])): ?><div class="invalid-feedback"><?= htmlspecialchars($createUserErrors['role'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="newUserPassword"><i class="bi bi-key me-1 text-secondary"></i>Initial password</label>
                                    <input type="password" class="form-control<?= isset($createUserErrors['password']) ? ' is-invalid' : '' ?>" id="newUserPassword" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
                                    <div class="form-text">12-72 characters; include uppercase, lowercase, a number, and a symbol.</div>
                                    <?php if (isset($createUserErrors['password'])): ?><div class="invalid-feedback d-block"><?= htmlspecialchars($createUserErrors['password'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="newUserPasswordConfirmation"><i class="bi bi-check2-shield me-1 text-secondary"></i>Confirm password</label>
                                    <input type="password" class="form-control<?= isset($createUserErrors['password_confirmation']) ? ' is-invalid' : '' ?>" id="newUserPasswordConfirmation" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required>
                                    <?php if (isset($createUserErrors['password_confirmation'])): ?><div class="invalid-feedback"><?= htmlspecialchars($createUserErrors['password_confirmation'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" <?= $caterers === [] ? 'disabled' : '' ?>>
                                <i class="bi bi-person-plus me-1"></i>Create user
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js">
    </script>

    <script src="../assets/js/admin.js"></script>


    <script>
        $(function() {
            <?php if ($showAddUserModal): ?>
                bootstrap.Modal.getOrCreateInstance(document.getElementById('addUserModal')).show();
            <?php endif; ?>

            const pageSize = 10;
            let currentPage = 1;
            let filteredUserRows = [];

            /* =====================================================
               USER FILTER
               ===================================================== */

            function filterUsers() {

                const search =
                    $.trim(
                        $('#userSearch').val()
                    ).toLowerCase();


                const role =
                    $('#userRoleFilter').val();


                const status =
                    $('#userStatusFilter').val();


                const verification =
                    $('#userVerificationFilter').val();


                filteredUserRows = $('.user-row').get().filter(function(row) {
                    const $row = $(row);
                    return (!search || String($row.data('search') || '').includes(search)) &&
                        (!role || String($row.data('role') || '') === role) &&
                        (!status || String($row.data('status') || '') === status) &&
                        (!verification || String($row.data('verification') || '') === verification);
                });
                currentPage = 1;
                renderUserPage();
            }

            function renderUserPage() {
                const total = filteredUserRows.length;
                const pageCount = Math.max(1, Math.ceil(total / pageSize));
                currentPage = Math.min(currentPage, pageCount);
                const start = total === 0 ? 0 : (currentPage - 1) * pageSize;

                $('.user-row').hide();
                $(filteredUserRows.slice(start, start + pageSize)).show();
                $('#usersHeaderCount, #userFilteredCount').text(total);
                $('#userRangeStart').text(total === 0 ? 0 : start + 1);
                $('#userRangeEnd').text(Math.min(start + pageSize, total));
                $('#userPageIndicator').text('Page ' + currentPage + ' of ' + pageCount);
                $('#previousUserPage').prop('disabled', currentPage <= 1);
                $('#nextUserPage').prop('disabled', currentPage >= pageCount);
                $('#usersEmptyState').toggleClass('d-none', total !== 0);
            }


            $('#userSearch').on(
                'input',
                filterUsers
            );


            $(
                '#userRoleFilter,' +
                '#userStatusFilter,' +
                '#userVerificationFilter'
            ).on(
                'change',
                filterUsers
            );


            /* =====================================================
               CLEAR FILTERS
               ===================================================== */

            function clearUserFilters() {

                $('#userSearch').val('');

                $('#userRoleFilter').val('');

                $('#userStatusFilter').val('');

                $('#userVerificationFilter').val('');

                filterUsers();

            }


            $('#clearUserFilters').on(
                'click',
                clearUserFilters
            );


            $('#clearEmptyUserFilters').on(
                'click',
                clearUserFilters
            );

            $('#previousUserPage').on('click', function() {
                if (currentPage > 1) {
                    currentPage--;
                    renderUserPage();
                }
            });

            $('#nextUserPage').on('click', function() {
                if (currentPage * pageSize < filteredUserRows.length) {
                    currentPage++;
                    renderUserPage();
                }
            });

            $('#usersExportButton').on('click', function() {
                const headers = $('#usersTable thead th').toArray().slice(0, 7).map(function(cell) {
                    return cell.innerText.trim();
                });
                const csvRows = [headers].concat(filteredUserRows.map(function(row) {
                    return Array.from(row.cells).slice(0, 7).map(function(cell) {
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
                download.download = 'utsavdesk-users-' + new Date().toISOString().slice(0, 10) + '.csv';
                download.click();
                window.setTimeout(function() {
                    URL.revokeObjectURL(url);
                }, 1000);
            });


            /* =====================================================
               USER DETAILS MODAL
               ===================================================== */

            $('.view-user-button').on(
                'click',
                function() {

                    const $button = $(this);

                    const name =
                        String(
                            $button.data('name') || ''
                        );

                    const words =
                        name
                        .trim()
                        .split(/\s+/)
                        .slice(0, 2);

                    const initials =
                        words
                        .map(function(word) {
                            return word.charAt(0).toUpperCase();
                        })
                        .join('');


                    $('#modalUserAvatar').text(
                        initials || 'U'
                    );


                    $('#modalUserName').text(
                        name
                    );


                    $('#modalUserCode').text(
                        $button.data('code')
                    );


                    $('#modalUserEmail').text(
                        $button.data('email')
                    );


                    $('#modalUserContact').text(
                        $button.data('contact')
                    );


                    $('#modalUserRole').text(
                        $button.data('role')
                    );


                    $('#modalUserStatus').text(
                        $button.data('status')
                    );


                    $('#modalUserCaterer').text(
                        $button.data('caterer')
                    );


                    $('#modalUserCatererCode').text(
                        $button.data('caterer-code')
                    );


                    $('#modalUserCreated').text(
                        $button.data('created')
                    );


                    $('#modalUserLastLogin').text(
                        $button.data('last-login')
                    );

                }
            );

            filterUsers();


        });
    </script>


</body>

</html>
