<?php
declare(strict_types=1);
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../common/analytics.php';
function analyticsEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$admin = ['name' => $_SESSION['PLATFORM_ADMIN']['NAME'], 'email' => $_SESSION['PLATFORM_ADMIN']['EMAIL'], 'role' => $_SESSION['PLATFORM_ADMIN']['ROLE']];
$period = filter_input(INPUT_GET, 'period', FILTER_VALIDATE_INT);
$period = in_array($period, [1, 3, 6, 12], true) ? $period : 12;
$format = isset($_GET['format']) && is_string($_GET['format']) ? $_GET['format'] : '';
$data = null;
try { $data = cmsAnalytics(cmsDatabaseConnection(), $period); }
catch (Throwable $error) { error_log('CMS analytics failed: ' . $error->getMessage()); http_response_code(503); }
if ($format === 'csv') {
    if (!$data) { header('Content-Type: text/plain; charset=UTF-8'); exit('CMS analytics is unavailable. Please retry.'); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="analytics-' . $data['start'] . '-to-' . $data['end'] . '.csv"');
    $out = fopen('php://output', 'w');
    $write = static function (array $row) use ($out): void {
        fputcsv($out, array_map(static function ($value) {
            return is_string($value) && preg_match('/^[=+@\-\t\r\n]/', $value) ? "'" . $value : $value;
        }, $row));
    };
    $write(['Period start', $data['start'], 'Period end', $data['end'], 'Updated (CMS time)', $data['updated']]);
    $write(['Currency', $data['currency']]);
    $write(['Active subscriptions', $data['platformBilling']['active'], 'Current snapshot']);
    $write(['Subscription collections INR', $data['platformBilling']['received'], 'Selected period']);
    $write(['Metric', 'Value', 'Scope']);
    foreach ($data['metrics'] as $label => $value) {
        $financial = in_array($label, ['Booking value','Payments collected','Refunds processed','Net collections','Approved expenses'], true);
        $write([$label, $financial && $data['currency'] === 'Mixed currencies' ? 'Unavailable' : $value, in_array($label, ['Total caterers','Active caterers','Active users','Users logged in today','Upcoming events'], true) ? 'Current snapshot' : 'Selected period']);
    }
    $write([]); $write(['Caterer', 'Code', 'Leads', 'Events', 'Booking value', 'Conversion %']);
    foreach ($data['top'] as $row) { $write([$row['name'], $row['code'], $row['leads'], $row['events'], $data['currency'] === 'Mixed currencies' ? 'Unavailable' : $row['booking_value'], $row['leads'] ? round(100 * $row['converted'] / $row['leads'], 1) : 0]); }
    fclose($out); exit;
}
if ($format === 'fragment') {
    header('Content-Type: text/html; charset=UTF-8');
    if (!$data) { exit('CMS analytics is unavailable. Please retry.'); }
    require __DIR__ . '/content.php'; exit;
}
require __DIR__ . '/shell.php';
?>
<main class="admin-content">
    <section class="analytics-heading"><div><h2>Platform Analytics</h2><p>Live CMS growth, collections, events and usage.</p></div>
        <form method="get" class="analytics-heading-actions" id="analytics-controls">
            <label class="visually-hidden" for="analyticsPeriod">Reporting period</label>
            <select name="period" id="analyticsPeriod"><?php foreach ([12 => 'Last 12 Months', 6 => 'Last 6 Months', 3 => 'Last 3 Months', 1 => 'This Month'] as $value => $label): ?><option value="<?= $value ?>" <?= $period === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
            <button class="analytics-export" type="submit">Apply</button>
            <button class="analytics-export" type="submit" name="format" value="csv"><i class="bi bi-download"></i> Export Report</button>
        </form>
    </section>
    <p id="analytics-status" role="status" class="analytics-data-note"><?= $data ? '' : 'CMS analytics is unavailable. Retrying automatically; you can also reload this page.' ?></p>
    <div id="analytics-content"><?php if ($data) { require __DIR__ . '/content.php'; } ?></div>
    <footer class="dashboard-footer"><span>© <?= date('Y') ?> UtsavDesk</span><span>Platform Administration</span></footer>
</main></div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
<script src="../assets/js/admin.js"></script>
<script src="../assets/js/analytics.js"></script>
</body></html>
