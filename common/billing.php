<?php
declare(strict_types=1);
require_once __DIR__ . '/cms_db.php';

function billingEscape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function billingCanWrite(): bool { return in_array($_SESSION['PLATFORM_ADMIN']['ROLE'] ?? '', ['SUPER_ADMIN','ADMIN','FINANCE'], true); }
function billingAnnualEnd(string $start): string {
    $date = new DateTimeImmutable($start);
    $target = $date->modify('first day of this month')->modify('+1 year');
    return $target->setDate((int)$target->format('Y'),(int)$target->format('m'),min((int)$date->format('d'),(int)$target->format('t')))->format('Y-m-d');
}
function billingText(array $input, string $key, int $max, bool $required = true): string {
    $value = $input[$key] ?? '';
    if (!is_string($value)) { throw new InvalidArgumentException('Invalid ' . str_replace('_', ' ', $key) . '.'); }
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $max) { throw new InvalidArgumentException('Please provide a valid ' . str_replace('_', ' ', $key) . ' (maximum ' . $max . ' characters).'); }
    return $value;
}
function billingInput(array $input, DateTimeImmutable $now): array {
    $data = [];
    foreach (['payer_name'=>150,'payment_method'=>30,'paid_at'=>19,'start_date'=>10,'request_key'=>32] as $key=>$limit) { $data[$key] = billingText($input,$key,$limit); }
    $data['notes'] = billingText($input,'notes',2000,false);
    $data['payment_reference'] = billingText($input,'payment_reference',150,false);
    $data['user_id'] = filter_var($input['user_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$data['user_id']) { throw new InvalidArgumentException('Select a valid user.'); }
    $data['plan_name'] = 'Utsav Annual — All Features';
    $data['amount'] = '9999.00';
    if (!in_array($data['payment_method'],['CASH','UPI','BANK_TRANSFER','CHEQUE','CARD','OTHER'],true)) { throw new InvalidArgumentException('Choose a valid payment method.'); }
    if ($data['payment_method'] !== 'CASH' && $data['payment_reference'] === '') { throw new InvalidArgumentException('A bank, UPI, cheque or external receipt reference is required for non-cash payments.'); }
    if (!preg_match('/^[a-f0-9]{32}$/D',$data['request_key'])) { throw new InvalidArgumentException('Invalid payment form. Reload and retry.'); }
    $paid = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $data['paid_at']);
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $data['start_date']);
    if (!$paid || $paid->format('Y-m-d\TH:i') !== $data['paid_at'] || $paid > $now || $paid->format('Y') < '2000') { throw new InvalidArgumentException('Payment date must be valid and cannot be in the future (CMS server time).'); }
    if (!$start || $start->format('Y-m-d') !== $data['start_date'] || $start->format('Y') < '2000' || $start->format('Y') > '2100') { throw new InvalidArgumentException('Enter a valid subscription start date between 2000 and 2100.'); }
    $data['paid_at'] = $paid->format('Y-m-d H:i:s');
    return $data;
}

/** Atomically records a verified payment and grants one non-overlapping subscription. */
function billingRecordPayment(PDO $db, array $input, array $admin): int {
    $now = new DateTimeImmutable((string)$db->query('SELECT NOW()')->fetchColumn());
    $data = billingInput($input,$now);
    $hash = hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
    $db->beginTransaction();
    try {
        // Serialize subscriptions for this user, including requests with different keys.
        $statement = $db->prepare('SELECT u.id FROM user_info u JOIN catering_info c ON c.id=u.catering_id WHERE u.id=? AND u.is_active=1 AND u.status=1 AND c.is_active=1 AND c.status=1 FOR UPDATE');
        $statement->execute([$data['user_id']]);
        if (!$statement->fetch()) { throw new InvalidArgumentException('The user or their caterer is inactive or unavailable.'); }
        $statement = $db->prepare('SELECT id,payload_hash FROM user_subscription_payment WHERE request_key=?');
        $statement->execute([$data['request_key']]);
        $existing = $statement->fetch();
        if ($existing) {
            if (!hash_equals($existing['payload_hash'],$hash)) { throw new InvalidArgumentException('This form was already used for another payment. Reload before recording a new payment.'); }
            $db->commit(); return (int)$existing['id'];
        }
        $statement = $db->prepare("SELECT MAX(ends_on) FROM user_subscription WHERE user_id=? AND status='ACTIVE' AND ends_on > ?");
        $statement->execute([$data['user_id'],$data['start_date']]);
        $lastEnd = $statement->fetchColumn();
        $starts = $lastEnd ?: $data['start_date'];
        $ends = billingAnnualEnd($starts);
        $data['duration_days'] = (int)(new DateTimeImmutable($starts))->diff(new DateTimeImmutable($ends))->days;
        $statement = $db->prepare('INSERT INTO user_subscription_payment (receipt_number,user_id,plan_name,amount,payment_method,payment_reference,paid_at,payer_name,duration_days,requested_start,notes,request_key,payload_hash,recorded_by,recorded_by_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute(['SUB-' . $now->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6))),$data['user_id'],$data['plan_name'],$data['amount'],$data['payment_method'],$data['payment_reference'] ?: null,$data['paid_at'],$data['payer_name'],$data['duration_days'],$data['start_date'],$data['notes'],$data['request_key'],$hash,$admin['ID'],$admin['NAME']]);
        $id = (int)$db->lastInsertId();
        $statement = $db->prepare('INSERT INTO user_subscription (user_id,payment_id,plan_name,starts_on,ends_on) VALUES (?,?,?,?,?)');
        $statement->execute([$data['user_id'],$id,$data['plan_name'],$starts,$ends]);
        $statement = $db->prepare("INSERT INTO user_subscription_audit (payment_id,admin_id,admin_name,action,description) VALUES (?,?,?,'PAYMENT_RECORDED',?)");
        $statement->execute([$id,$admin['ID'],$admin['NAME'],'Verified manual payment; subscription from ' . $starts . ' to ' . $ends . ' (exclusive).']);
        $db->commit(); return $id;
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        if ($error instanceof PDOException && $error->getCode() === '23000') { throw new InvalidArgumentException('This payment reference or request has already been recorded. Refresh the transaction list before retrying.'); }
        throw $error;
    }
}

function billingVoidPayment(PDO $db, int $id, string $reason, array $admin): void {
    if ($id < 1 || strlen(trim($reason)) < 5 || mb_strlen($reason) > 1000) { throw new InvalidArgumentException('Select a payment and provide a correction reason (5–1000 characters).'); }
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT user_id FROM user_subscription_payment WHERE id=?'); $statement->execute([$id]);
        $user = $statement->fetchColumn();
        if (!$user) { throw new InvalidArgumentException('Payment not found.'); }
        $statement = $db->prepare('SELECT id FROM user_info WHERE id=? FOR UPDATE'); $statement->execute([$user]);
        $statement = $db->prepare("UPDATE user_subscription_payment SET status='VOID',voided_at=NOW(),voided_by=?,void_reason=? WHERE id=? AND status='PAID'");
        $statement->execute([$admin['ID'],trim($reason),$id]);
        if ($statement->rowCount()) {
            $statement = $db->prepare("UPDATE user_subscription SET status='CANCELLED',cancelled_at=NOW() WHERE payment_id=?"); $statement->execute([$id]);
            $statement = $db->prepare("INSERT INTO user_subscription_audit (payment_id,admin_id,admin_name,action,description) VALUES (?,?,?,'PAYMENT_VOIDED',?)"); $statement->execute([$id,$admin['ID'],$admin['NAME'],trim($reason)]);
        }
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
}

function billingSummary(PDO $db): array {
    return $db->query("SELECT p.*,s.* FROM
        (SELECT COALESCE(SUM(CASE WHEN paid_at >= DATE_FORMAT(CURRENT_DATE,'%Y-%m-01') AND paid_at <= NOW() THEN amount ELSE 0 END),0) AS month_received,
                COALESCE(SUM(amount),0) AS total_received, COUNT(*) AS payments
         FROM user_subscription_payment WHERE status='PAID') p
        CROSS JOIN (SELECT COUNT(DISTINCT user_id) AS active,
                COALESCE(SUM(ends_on <= CURRENT_DATE + INTERVAL 7 DAY),0) AS expiring
         FROM user_subscription WHERE status='ACTIVE' AND starts_on <= CURRENT_DATE AND ends_on > CURRENT_DATE) s")->fetch();
}

function billingStatusSql(string $alias = 's'): string {
    return "CASE WHEN $alias.status='CANCELLED' THEN 'CANCELLED' WHEN $alias.starts_on>CURRENT_DATE THEN 'SCHEDULED' WHEN $alias.ends_on<=CURRENT_DATE THEN 'EXPIRED' ELSE 'ACTIVE' END";
}
