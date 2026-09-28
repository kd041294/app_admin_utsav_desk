<?php

declare(strict_types=1);
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../common/billing.php';
require_once __DIR__ . '/../common/functions.php';
$error = '';
$loadError = false;
$rows = [];
$users = [];
$totals = [];
$count = 0;
$canWrite = billingCanWrite();
$values = ['request_key' => bin2hex(random_bytes(16)), 'user_id' => '', 'payment_method' => 'UPI', 'payment_reference' => '', 'payer_name' => '', 'notes' => ''];
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$search = is_string($_GET['q'] ?? null) ? substr(trim($_GET['q']), 0, 100) : '';
$status = in_array($_GET['status'] ?? '', ['PAID', 'VOID'], true) ? $_GET['status'] : '';
$from = is_string($_GET['from'] ?? null) ? $_GET['from'] : '';
$to = is_string($_GET['to'] ?? null) ? $_GET['to'] : '';
$userFilter = max(0, (int)filter_var($_GET['user_id'] ?? 0, FILTER_VALIDATE_INT));
$page = max(1, (int)filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT));
$flash = $_SESSION['BILLING_FLASH'] ?? '';
unset($_SESSION['BILLING_FLASH']);
try {
    $db = cmsDatabaseConnection();
    $clock = new DateTimeImmutable((string)$db->query('SELECT NOW()')->fetchColumn());
    $values['paid_at'] = $clock->format('Y-m-d\TH:i');
    $values['start_date'] = $clock->format('Y-m-d');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!$canWrite) {
            http_response_code(403);
            throw new InvalidArgumentException('Your role has read-only billing access.');
        }
        $token = $_POST['csrf_token'] ?? null;
        if (!is_string($token) || !verifyCsrfToken($token)) {
            http_response_code(419);
            throw new InvalidArgumentException('Your session token expired. Reload and retry.');
        }
        if ($action === 'record') {
            foreach ($values as $key => $value) {
                if (is_string($_POST[$key] ?? null)) {
                    $values[$key] = $_POST[$key];
                }
            }
            if (($_POST['confirmed'] ?? '') !== '1') {
                throw new InvalidArgumentException('Confirm that the payment has actually been received and verified.');
            }
            $id = billingRecordPayment($db, $_POST, $_SESSION['PLATFORM_ADMIN']);
            $_SESSION['BILLING_FLASH'] = 'Payment recorded successfully. The linked user subscription has been created.';
            udSetToast('success', 'Payment recorded', 'Payment recorded successfully. The linked user subscription has been created.');
            header('Location: ' . APP_BASE_URL . '/billing/?receipt=' . $id, true, 303);
            exit;
        } elseif ($action === 'void') {
            billingVoidPayment($db, (int)filter_var($_POST['payment_id'] ?? 0, FILTER_VALIDATE_INT), billingText($_POST, 'reason', 1000), $_SESSION['PLATFORM_ADMIN']);
            $_SESSION['BILLING_FLASH'] = 'Payment voided and its subscription cancelled. No money was refunded.';
            udSetToast('warning', 'Payment voided', 'Payment voided and its subscription cancelled. No money was refunded.');
            header('Location: ' . APP_BASE_URL . '/billing/', true, 303);
            exit;
        } else {
            throw new InvalidArgumentException('Unsupported billing action.');
        }
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Billing write/load failed: ' . $exception->getMessage());
    $error = 'Billing is temporarily unavailable. Please retry.';
    $loadError = true;
}
try {
    if ($loadError) {
        throw new RuntimeException('Connection unavailable');
    }
    $totals = billingSummary($db);
    $receiptId = max(0, (int)filter_var($_GET['receipt'] ?? 0, FILTER_VALIDATE_INT));
    if ($receiptId) {
        $stmt = $db->prepare('SELECT p.*, CONCAT(u.first_name, " ", COALESCE(u.last_name,"")) AS name,u.user_code,u.email,c.catering_name,s.starts_on,s.ends_on,' . billingStatusSql() . ' AS subscription_status FROM user_subscription_payment p JOIN user_info u ON u.id=p.user_id JOIN catering_info c ON c.id=u.catering_id JOIN user_subscription s ON s.payment_id=p.id WHERE p.id=?');
        $stmt->execute([$receiptId]);
        $receipt = $stmt->fetch();
        if (!$receipt) {
            http_response_code(404);
            throw new InvalidArgumentException('Receipt not found.');
        }
        $stmt = $db->prepare('SELECT * FROM user_subscription_audit WHERE payment_id=? ORDER BY id');
        $stmt->execute([$receiptId]);
        $audit = $stmt->fetchAll();
        require __DIR__ . '/../components/billing-shell.php';
        if ($flash) {
            echo '<div class="alert alert-success">' . billingEscape($flash) . '</div>';
        }
        require __DIR__ . '/receipt.php';
        require __DIR__ . '/../components/billing-footer.php';
        exit;
    }
    $users = $db->query("SELECT u.id,CONCAT(u.first_name,' ',COALESCE(u.last_name,'')) AS name,u.user_code,u.email,c.catering_name FROM user_info u JOIN catering_info c ON c.id=u.catering_id WHERE u.is_active=1 AND u.status=1 AND c.is_active=1 AND c.status=1 ORDER BY c.catering_name,u.first_name,u.id")->fetchAll();
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(p.receipt_number LIKE ? OR p.payment_reference LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name," ",COALESCE(u.last_name,"")) LIKE ? OR c.catering_name LIKE ?)';
        for ($i = 0; $i < 5; $i++) {
            $params[] = '%' . $search . '%';
        }
    }
    if ($status) {
        $where[] = 'p.status=?';
        $params[] = $status;
    }
    if ($userFilter) {
        $where[] = 'p.user_id=?';
        $params[] = $userFilter;
    }
    foreach (['from' => $from, 'to' => $to] as $key => $date) {
        if ($date === '') {
            continue;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Choose a valid date filter.');
        }
        $where[] = $key === 'from' ? 'p.paid_at >= ?' : 'p.paid_at < ?';
        $params[] = $key === 'from' ? $date : $parsed->modify('+1 day')->format('Y-m-d');
    }
    if ($from && $to && $from > $to) {
        throw new InvalidArgumentException('The start date must be on or before the end date.');
    }
    $join = ' FROM user_subscription_payment p JOIN user_info u ON u.id=p.user_id JOIN catering_info c ON c.id=u.catering_id JOIN user_subscription s ON s.payment_id=p.id';
    $condition = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $stmt = $db->prepare('SELECT COUNT(*)' . $join . $condition);
    $stmt->execute($params);
    $count = (int)$stmt->fetchColumn();
    $page = min($page, max(1, (int)ceil($count / 25)));
    $sql = 'SELECT p.*,CONCAT(u.first_name," ",COALESCE(u.last_name,"")) AS name,u.email,c.catering_name,s.starts_on,s.ends_on,' . billingStatusSql() . ' AS subscription_status' . $join . $condition . ' ORDER BY p.paid_at DESC,p.id DESC';
    if (($_GET['export'] ?? '') === 'csv') {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="subscription-payments.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Receipt', 'User', 'Email', 'Caterer', 'Plan', 'Amount INR', 'Method', 'Reference', 'Payment date', 'Payment status', 'Subscription starts', 'Ends (exclusive)', 'Subscription status']);
        while ($row = $stmt->fetch()) {
            $csv = array_map(static function ($v) {
                return is_string($v) && preg_match('/^[=+@\-\t\r\n]/', $v) ? "'" . $v : $v;
            }, [$row['receipt_number'], $row['name'], $row['email'], $row['catering_name'], $row['plan_name'], $row['amount'], $row['payment_method'], $row['payment_reference'], $row['paid_at'], $row['status'], $row['starts_on'], $row['ends_on'], $row['subscription_status']]);
            fputcsv($out, $csv);
        }
        fclose($out);
        exit;
    }
    $stmt = $db->prepare($sql . ' LIMIT 25 OFFSET ' . (($page - 1) * 25));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Billing read failed: ' . $exception->getMessage());
    $loadError = true;
    $error = 'Billing data could not be loaded. Please retry.';
}
require __DIR__ . '/../components/billing-shell.php';
?>
<section class="billing-heading">
    <div>
        <h2>Billing &amp; Payments</h2>
        <p>Record verified manual payments and manage user subscription terms.</p>
    </div>
    <div class="billing-heading-actions"><a class="billing-secondary-button" href="../subscriptions/">Subscriptions</a><?php if ($canWrite && !$loadError): ?><button class="ud-btn ud-btn-primary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">Record Payment</button><?php endif; ?></div>
</section>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= billingEscape($error) ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-success" role="status"><?= billingEscape($flash) ?></div><?php endif; ?>
<?php if (!$loadError): ?>
    <section class="billing-summary-grid">
        <?php foreach (['month_received' => 'Received this month', 'total_received' => 'Total received', 'payments' => 'Paid transactions', 'active' => 'Active subscribers', 'expiring' => 'Expiring in 7 days'] as $key => $label): ?><article class="billing-summary-card"><span><?= $label ?></span>
                <h3 class="mt-2"><?= in_array($key, ['month_received', 'total_received'], true) ? '₹' . number_format((float)$totals[$key], 2) : number_format((int)$totals[$key]) ?></h3><small>All users · <?= in_array($key, ['active', 'expiring'], true) ? 'Current terms' : 'Excludes voided payments' ?></small>
            </article><?php endforeach; ?>
    </section>
    <form method="get" class="billing-live-filters mb-3">
        <label>
            Search
            <input class="form-control" name="q" value="<?= billingEscape($search) ?>" placeholder="User, caterer, receipt or reference">
        </label>
        <label>
            Status
            <select name="status" class="form-select">
                <option value="">All payments</option>
                <option value="PAID" <?= $status === 'PAID' ? 'selected' : '' ?>>PAID</option>
                <option value="VOID" <?= $status === 'VOID' ? 'selected' : '' ?>>VOID</option>
            </select>
        </label>
        <label>
            Paid from
            <input class="form-control" type="date" name="from" value="<?= billingEscape($from) ?>">
        </label>
        <label>
            Paid through
            <input class="form-control" type="date" name="to" value="<?= billingEscape($to) ?>">
        </label>
        <?php if ($userFilter): ?><input type="hidden" name="user_id" value="<?= $userFilter ?>"><?php endif; ?>
        <div class="d-flex gap-2 align-items-end">
            <button class="ud-btn ud-btn-primary">Filter</button>
            <a class="ud-btn btn btn-outline-secondary" href="./">Reset</a>
            <button class="ud-btn btn btn-outline-secondary" name="export" value="csv">Export CSV</button>
        </div>
    </form>
    <section class="billing-panel p-3">
        <div class="d-flex justify-content-between mb-3">
            <h3 class="h6">Payment history · <?= $count ?> results</h3><span class="text-muted small">CMS server time · Refreshes every 30s while idle</span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle billing-live-table">
                <thead>
                    <tr>
                        <th>Receipt / date</th>
                        <th>User / caterer</th>
                        <th>Plan / term</th>
                        <th>Amount</th>
                        <th>Method / reference</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?><tr>
                            <td colspan="7" class="text-center py-5">No payments match these filters. Record a verified payment to create a subscription.</td>
                        </tr><?php endif; ?>
                    <?php foreach ($rows as $row): ?><tr>
                            <td><a href="?receipt=<?= $row['id'] ?>"><?= billingEscape($row['receipt_number']) ?></a><small class="d-block"><?= billingEscape($row['paid_at']) ?></small></td>
                            <td><?= billingEscape($row['name']) ?><small class="d-block"><?= billingEscape($row['email']) ?></small><small class="d-block"><?= billingEscape($row['catering_name']) ?></small></td>
                            <td><?= billingEscape($row['plan_name']) ?><small class="d-block"><?= billingEscape($row['starts_on']) ?> → <?= billingEscape((new DateTimeImmutable($row['ends_on']))->modify('-1 day')->format('Y-m-d')) ?></small><small><?= billingEscape($row['subscription_status']) ?></small></td>
                            <td class="text-nowrap">₹<?= number_format((float)$row['amount'], 2) ?></td>
                            <td><?= billingEscape($row['payment_method']) ?><small class="d-block"><?= billingEscape($row['payment_reference'] ?: 'Cash') ?></small></td>
                            <td><span class="badge <?= $row['status'] === 'PAID' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= billingEscape($row['status']) ?></span></td>
                            <td><a class="btn btn-sm btn-outline-secondary" href="?receipt=<?= $row['id'] ?>">Receipt</a><?php if ($canWrite && $row['status'] === 'PAID'): ?> <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#voidPaymentModal" data-payment-id="<?= $row['id'] ?>" data-receipt="<?= billingEscape($row['receipt_number']) ?>">Void</button><?php endif; ?></td>
                        </tr><?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <nav class="d-flex gap-3 justify-content-end" aria-label="Payment pagination"><?php if ($page > 1): ?><a href="?<?= billingEscape(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= max(1, (int)ceil($count / 25)) ?></span><?php if ($page * 25 < $count): ?><a href="?<?= billingEscape(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Next</a><?php endif; ?></nav>
    </section>
<?php endif; ?>
<?php if ($canWrite && !$loadError) {
    require __DIR__ . '/modals.php';
} ?>
<?php require __DIR__ . '/../components/billing-footer.php'; ?>