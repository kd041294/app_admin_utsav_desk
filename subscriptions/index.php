<?php
declare(strict_types=1);
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../common/billing.php';
$error=''; $rows=[]; $total=0; $summary=[];
$search=is_string($_GET['q'] ?? null)?substr(trim($_GET['q']),0,100):'';
$status=in_array($_GET['status'] ?? '',['ACTIVE','SCHEDULED','EXPIRED','CANCELLED'],true)?$_GET['status']:'';
$userId=max(0,(int)filter_var($_GET['user_id'] ?? 0,FILTER_VALIDATE_INT));
$page=max(1,(int)filter_var($_GET['page'] ?? 1,FILTER_VALIDATE_INT));
try {
    $db=cmsDatabaseConnection(); $summary=billingSummary($db);
    $conditions=[];$params=[];
    if($status){$conditions[]=billingStatusSql().'=?';$params[]=$status;}
    if($userId){$conditions[]='s.user_id=?';$params[]=$userId;}
    if($search!==''){$conditions[]='(CONCAT(u.first_name," ",COALESCE(u.last_name,"")) LIKE ? OR u.email LIKE ? OR c.catering_name LIKE ?)';$params=array_merge($params,array_fill(0,3,'%'.$search.'%'));}
    $join=' FROM user_subscription s JOIN user_info u ON u.id=s.user_id JOIN catering_info c ON c.id=u.catering_id JOIN user_subscription_payment p ON p.id=s.payment_id';
    $where=$conditions?' WHERE '.implode(' AND ',$conditions):'';
    $stmt=$db->prepare('SELECT COUNT(*)'.$join.$where);$stmt->execute($params);$total=(int)$stmt->fetchColumn();
    $page=min($page,max(1,(int)ceil($total/25)));
    $stmt=$db->prepare('SELECT s.*,CONCAT(u.first_name," ",COALESCE(u.last_name,"")) AS name,u.email,c.catering_name,p.receipt_number,p.amount,'.billingStatusSql().' AS display_status'.$join.$where.' ORDER BY s.created_at DESC,s.id DESC LIMIT 25 OFFSET '.(($page-1)*25));
    $stmt->execute($params);$rows=$stmt->fetchAll();
} catch(Throwable $exception){error_log('Subscriptions failed: '.$exception->getMessage());$error='Subscriptions are temporarily unavailable. Please retry.';}
require __DIR__.'/../components/billing-shell.php';
?>
<section class="billing-heading"><div><h2>User Subscriptions</h2><p>Live subscription terms created by verified manual payments.</p></div><a class="ud-btn ud-btn-primary" href="../billing/">Go to Billing</a></section>
<?php if($error): ?><div class="alert alert-danger"><?= billingEscape($error) ?></div><?php else: ?>
<section class="billing-panel p-4 mb-3"><div class="d-flex flex-wrap gap-4 justify-content-between"><div><h3 class="h5">Utsav Annual · All Features</h3><p class="mb-0">One year of access to all Utsav app features. Renewal extends the user's last subscription term.</p></div><div><del>₹24,999</del> <strong class="fs-3">₹9,999</strong><div>₹15,000 discount · one calendar year</div></div></div></section>
<section class="billing-summary-grid"><article class="billing-summary-card"><span>Active subscribers</span><h3><?= (int)$summary['active'] ?></h3></article><article class="billing-summary-card"><span>Expiring within 7 days</span><h3><?= (int)$summary['expiring'] ?></h3></article><article class="billing-summary-card"><span>Paid transactions</span><h3><?= (int)$summary['payments'] ?></h3></article><article class="billing-summary-card"><span>Received this month</span><h3>₹<?= number_format((float)$summary['month_received'],2) ?></h3></article><article class="billing-summary-card"><span>Annual plan</span><h3>All features</h3></article></section>
<form method="get" class="billing-live-filters mb-3"><label>Search<input class="form-control" name="q" value="<?= billingEscape($search) ?>" placeholder="User, email or caterer"></label><label>Status<select name="status" class="form-select"><option value="">All terms</option><?php foreach(['ACTIVE','SCHEDULED','EXPIRED','CANCELLED'] as $option): ?><option <?= $status===$option?'selected':'' ?>><?= $option ?></option><?php endforeach; ?></select></label><?php if($userId): ?><input type="hidden" name="user_id" value="<?= $userId ?>"><?php endif; ?><button class="btn btn-primary">Filter</button><a href="./" class="btn btn-outline-secondary">Reset</a></form>
<section class="billing-panel p-3"><p><?= $total ?> matching terms · Dates are inclusive below · Refreshes every 30 seconds while idle</p><div class="table-responsive"><table class="table align-middle billing-live-table"><thead><tr><th>User</th><th>Caterer</th><th>Plan</th><th>Term</th><th>Status</th><th>Payment</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="6" class="text-center py-5">No subscriptions match. Record a payment in Billing to create one.</td></tr><?php endif; ?>
<?php foreach($rows as $row): ?><tr><td><?= billingEscape($row['name']) ?><small class="d-block"><?= billingEscape($row['email']) ?></small></td><td><?= billingEscape($row['catering_name']) ?></td><td><?= billingEscape($row['plan_name']) ?><small class="d-block"><?= $row['all_features']?'All features included':'' ?></small></td><td><?= billingEscape($row['starts_on']) ?> → <?= billingEscape((new DateTimeImmutable($row['ends_on']))->modify('-1 day')->format('Y-m-d')) ?></td><td><span class="badge <?= $row['display_status']==='ACTIVE'?'text-bg-success':'text-bg-secondary' ?>"><?= billingEscape($row['display_status']) ?></span></td><td><a href="../billing/?receipt=<?= $row['payment_id'] ?>"><?= billingEscape($row['receipt_number']) ?></a><small class="d-block">₹<?= number_format((float)$row['amount'],2) ?></small></td></tr><?php endforeach; ?>
</tbody></table></div><nav class="d-flex gap-3 justify-content-end" aria-label="Subscription pagination"><?php if($page>1): ?><a href="?<?= billingEscape(http_build_query(array_merge($_GET,['page'=>$page-1]))) ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= max(1,(int)ceil($total/25)) ?></span><?php if($page*25<$total): ?><a href="?<?= billingEscape(http_build_query(array_merge($_GET,['page'=>$page+1]))) ?>">Next</a><?php endif; ?></nav></section>
<?php endif; ?>
<?php require __DIR__.'/../components/billing-footer.php'; ?>
