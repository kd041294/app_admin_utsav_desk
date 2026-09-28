<?php if (!defined('UTSAVDESK_ADMIN_HEADER_INCLUDED')) { http_response_code(404); exit; } ?>
<section class="billing-panel p-4 billing-receipt">
<div class="d-flex justify-content-between"><div><h2>Payment receipt</h2><p><?= billingEscape($receipt['receipt_number']) ?></p></div><strong><?= billingEscape($receipt['status']) ?></strong></div>
<p><?= billingEscape(platformSettingsRuntime()['platform_name']) ?> • Manual subscription payment • INR</p><hr>
<dl class="row">
<?php foreach (['User'=>$receipt['name'] . ' (' . $receipt['user_code'] . ')','Email'=>$receipt['email'],'Caterer'=>$receipt['catering_name'],'Payer'=>$receipt['payer_name'],'Plan'=>$receipt['plan_name'],'List price'=>'INR ' . number_format((float)$receipt['list_amount'],2),'Discount'=>'INR ' . number_format((float)$receipt['discount_amount'],2),'Amount received'=>'INR ' . number_format((float)$receipt['amount'],2),'Payment method'=>$receipt['payment_method'],'External reference'=>$receipt['payment_reference'] ?: 'Cash payment','Payment date'=>$receipt['paid_at'],'Subscription starts'=>platformSettingsDate($receipt['starts_on']),'Subscription through'=>platformSettingsDate((new DateTimeImmutable($receipt['ends_on']))->modify('-1 day')->format('Y-m-d')),'Subscription status'=>$receipt['subscription_status'],'Recorded by'=>$receipt['recorded_by_name'],'Recorded at'=>$receipt['created_at'],'Notes'=>$receipt['notes'] ?: '—'] as $label=>$value): ?>
<dt class="col-sm-4"><?= billingEscape($label) ?></dt><dd class="col-sm-8"><?= nl2br(billingEscape($value)) ?></dd>
<?php endforeach; ?></dl>
<?php if ($receipt['status'] === 'VOID'): ?><div class="alert alert-warning">Voided <?= billingEscape($receipt['voided_at']) ?>: <?= billingEscape($receipt['void_reason']) ?>. This record does not evidence a refund.</div><?php endif; ?>
<p class="small">Billing enquiries: <a href="mailto:<?= billingEscape(platformSettingsRuntime()['billing_email']) ?>"><?= billingEscape(platformSettingsRuntime()['billing_email']) ?></a> · Support: <a href="mailto:<?= billingEscape(platformSettingsRuntime()['support_email']) ?>"><?= billingEscape(platformSettingsRuntime()['support_email']) ?></a></p>
<p class="text-muted">Receipt for a manually verified payment. This is not a tax invoice.</p>
<h3 class="h6">Audit history</h3><ul><?php foreach ($audit as $entry): ?><li><?= billingEscape($entry['created_at'] . ' · ' . $entry['admin_name'] . ' · ' . $entry['action'] . ' · ' . $entry['description']) ?></li><?php endforeach; ?></ul>
<div class="d-flex gap-2 d-print-none"><button type="button" class="btn btn-primary" id="printReceipt">Print / Save PDF</button><a class="btn btn-outline-secondary" href="./">Back to billing</a></div>
</section>
