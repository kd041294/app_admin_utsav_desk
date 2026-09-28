<?php
if (!defined('UTSAVDESK_ADMIN_HEADER_INCLUDED')) { http_response_code(403); exit; }
$metrics = $data['metrics'];
$moneyMetrics = ['Booking value', 'Payments collected', 'Refunds processed', 'Net collections', 'Approved expenses'];
$snapshotMetrics = ['Total caterers', 'Active caterers', 'Active users', 'Users logged in today', 'Upcoming events'];
?>
<p class="analytics-data-note">Period: <?= analyticsEscape($data['start']) ?> to <?= analyticsEscape($data['end']) ?>.
    Updated <?= analyticsEscape($data['updated']) ?> (CMS server time). Refreshes every 30 seconds.</p>
<section class="analytics-kpi-grid">
    <article class="analytics-kpi-card"><strong><?= number_format($data['platformBilling']['active']) ?></strong><span>Active subscriptions</span><small>Current snapshot · <a href="../subscriptions/">View subscriptions</a></small></article>
    <article class="analytics-kpi-card"><strong>INR <?= number_format($data['platformBilling']['received'],2) ?></strong><span>Subscription collections</span><small>Selected period · Non-voided manual payments</small></article>
<?php foreach ($metrics as $label => $value): ?>
    <article class="analytics-kpi-card">
        <div class="analytics-kpi-top"><div class="analytics-kpi-icon <?= in_array($label, $moneyMetrics, true) ? 'revenue' : 'caterer' ?>"><i class="bi <?= in_array($label, $moneyMetrics, true) ? 'bi-currency-exchange' : 'bi-bar-chart' ?>"></i></div></div>
        <strong><?= analyticsEscape(in_array($label, $moneyMetrics, true) ? ($data['currency'] === 'Mixed currencies' ? 'Unavailable' : $data['currency'] . ' ' . number_format((float) $value, 2)) : number_format((float) $value, $label === 'Conversion rate' ? 1 : 0) . ($label === 'Conversion rate' ? '%' : '')) ?></strong>
        <span><?= analyticsEscape($label) ?></span>
        <small><?= in_array($label, $snapshotMetrics, true) ? 'Current snapshot' : 'Selected period' ?></small>
    </article>
<?php endforeach; ?>
</section>
<section class="analytics-grid-main">
    <div class="analytics-panel"><div class="analytics-panel-header"><div><h3>Workspace Growth</h3><p>New caterers and currently active users by creation month</p></div></div><div class="analytics-chart"><canvas id="growthChart" aria-label="Monthly workspace growth" role="img"></canvas></div></div>
    <div class="analytics-panel"><div class="analytics-panel-header"><div><h3>Workspace Role Mix</h3><p>Currently active users</p></div></div><div class="analytics-chart"><canvas id="roleChart" aria-label="Active users by role" role="img"></canvas></div>
    <?php if (!$data['roles']): ?><p class="analytics-data-note">No active users.</p><?php endif; ?></div>
</section>
<section class="analytics-performance-grid">
<?php foreach (['leads' => 'Lead Performance', 'events' => 'Event Status', 'roles' => 'Active User Roles'] as $key => $title): ?>
    <div class="analytics-panel"><div class="analytics-panel-header"><div><h3><?= $title ?></h3><p><?= $key === 'roles' ? 'Current snapshot' : 'Records created in selected period' ?></p></div></div>
    <div class="analytics-performance-content">
    <?php if (!$data[$key]): ?><p>No records for this period.</p><?php endif; ?>
    <?php foreach ($data[$key] as $row): ?><div class="analytics-summary-row"><span><?= analyticsEscape(str_replace('_', ' ', $row['label'])) ?></span><strong><?= number_format((int) $row['total']) ?></strong></div><?php endforeach; ?>
    </div></div>
<?php endforeach; ?>
</section>
<section class="analytics-panel analytics-revenue-panel"><div class="analytics-panel-header"><div><h3>Collections &amp; Booking Value</h3><p>Successful payments by payment date and non-cancelled bookings by creation date. <?= analyticsEscape($data['currency']) ?>; no currency conversion.</p></div></div>
<?php if ($data['currency'] === 'Mixed currencies'): ?><p class="analytics-data-note">Financial totals and chart are unavailable across different currencies.</p><?php else: ?><div class="analytics-chart"><canvas id="revenueChart" aria-label="Monthly payments and booking value" role="img"></canvas></div><?php endif; ?>
</section>
<section class="analytics-panel analytics-top-caterers"><div class="analytics-panel-header"><div><h3>Caterer Performance</h3><p>Top 10 active caterers by booking value in the selected period</p></div><a href="../caterers/">View Caterers</a></div>
<div class="table-responsive"><table class="analytics-table"><thead><tr><th>Caterer</th><th>Leads</th><th>Events</th><th>Booking Value</th><th>Conversion</th></tr></thead><tbody>
<?php if (!$data['top']): ?><tr><td colspan="5">No active caterers.</td></tr><?php endif; ?>
<?php foreach ($data['top'] as $row): ?><tr><td><a href="../caterers/view.php?code=<?= rawurlencode($row['code']) ?>"><?= analyticsEscape($row['name']) ?></a><small class="d-block"><?= analyticsEscape($row['code']) ?></small></td><td><?= number_format((int) $row['leads']) ?></td><td><?= number_format((int) $row['events']) ?></td><td><?= $data['currency'] === 'Mixed currencies' ? 'Unavailable' : analyticsEscape($data['currency']) . ' ' . number_format((float) $row['booking_value'], 2) ?></td><td><?= $row['leads'] ? number_format(100 * $row['converted'] / $row['leads'], 1) : '0' ?>%</td></tr><?php endforeach; ?>
</tbody></table></div></section>
<p class="analytics-data-note">Conversion = converted leads ÷ leads created in the selected period. The collections chart shows customer payments to caterers; subscription collections are shown separately above. Approved expenses exclude pending and rejected entries; net collections subtract processed refunds and are not profit.</p>
<script type="application/json" id="analytics-chart-data"><?= json_encode(array_intersect_key($data, array_flip(['labels','series','roles','currency'])), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
