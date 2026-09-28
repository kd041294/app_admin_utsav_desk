<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../common/analytics.php';
function checkAnalytics(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$db = cmsDatabaseConnection();
foreach ([1, 3, 6, 12] as $months) {
    $data = cmsAnalytics($db, $months);
    checkAnalytics(count($data['labels']) === $months, 'Month bucket count');
    foreach ($data['series'] as $series) { checkAnalytics(count($series) === $months, 'Series bucket count'); }
    checkAnalytics(abs(array_sum($data['series']['payments']) - $data['metrics']['Payments collected']) < 0.01, 'Payment chart reconciles with KPI');
    checkAnalytics(abs(array_sum($data['series']['bookings']) - $data['metrics']['Booking value']) < 0.01, 'Bookings chart reconciles with KPI');
    checkAnalytics($data['metrics']['Conversion rate'] >= 0 && $data['metrics']['Conversion rate'] <= 100, 'Conversion bounds');
    checkAnalytics(array_sum(array_column($data['events'], 'total')) === $data['metrics']['Events created'], 'Event statuses reconcile');
    checkAnalytics(array_sum(array_column($data['leads'], 'total')) === $data['metrics']['Leads'], 'Lead statuses reconcile');
    checkAnalytics(array_sum(array_column($data['roles'], 'total')) === $data['metrics']['Active users'], 'Role totals reconcile');
    checkAnalytics(!$db->inTransaction(), 'Snapshot transaction released');
    echo "PASS: $months month snapshot\n";
}
try { cmsAnalytics($db, 2); throw new RuntimeException('Invalid period accepted'); }
catch (InvalidArgumentException $expected) { echo "PASS: invalid period rejected\n"; }
define('UTSAVDESK_ADMIN_HEADER_INCLUDED', true);
function analyticsEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
ob_start(); require __DIR__ . '/../analytics/content.php'; $html = ob_get_clean();
checkAnalytics(strpos($html, 'analytics-chart-data') !== false, 'Live view contains chart payload');
$data['roles'] = $data['leads'] = $data['events'] = $data['top'] = [];
ob_start(); require __DIR__ . '/../analytics/content.php'; $html = ob_get_clean();
checkAnalytics(strpos($html, 'No records for this period.') !== false, 'Empty state renders');
echo "PASS: live and empty view rendering\n";
