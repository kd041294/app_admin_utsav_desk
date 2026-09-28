<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../common/billing.php';
function billingCheck(bool $ok,string $message): void { if(!$ok){throw new RuntimeException($message);} echo 'PASS: '.$message."\n"; }
function billingReject(callable $fn,string $message): void { try{$fn();}catch(InvalidArgumentException $error){billingCheck(true,$message);return;}throw new RuntimeException($message); }
$db=cmsDatabaseConnection();
// Connection-local temporary tables shadow the live billing tables. All writes disappear on disconnect.
foreach(['user_subscription_payment','user_subscription','user_subscription_audit'] as $table){
    $ddl = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);
    $ddl = preg_replace('/^\s*CONSTRAINT .*\n/m','',$ddl);
    $ddl = preg_replace('/,\s*\)/',"\n)",$ddl);
    $db->exec($ddl);
}
$user=(int)$db->query('SELECT u.id FROM user_info u JOIN catering_info c ON c.id=u.catering_id WHERE u.is_active=1 AND u.status=1 AND c.is_active=1 AND c.status=1 LIMIT 1')->fetchColumn();
if(!$user){throw new RuntimeException('A live active user is needed for read-only eligibility validation.');}
$now=new DateTimeImmutable((string)$db->query('SELECT NOW()')->fetchColumn());
$input=['user_id'=>(string)$user,'payer_name'=>'Billing test payer','payment_method'=>'UPI','payment_reference'=>'TEST-'.bin2hex(random_bytes(8)),'paid_at'=>$now->modify('-1 minute')->format('Y-m-d\TH:i'),'start_date'=>$now->format('Y-m-d'),'request_key'=>bin2hex(random_bytes(16)),'notes'=>'Temporary table test','amount'=>'1.00','plan_name'=>'Tampered','duration_days'=>'1'];
$admin=['ID'=>1,'NAME'=>'Test admin'];
$id=billingRecordPayment($db,$input,$admin);
$payment=$db->query('SELECT * FROM user_subscription_payment')->fetch();
$subscription=$db->query('SELECT * FROM user_subscription')->fetch();
billingCheck($payment['amount']==='9999.00' && $payment['list_amount']==='24999.00' && $payment['discount_amount']==='15000.00','Fixed annual pricing ignores client tampering');
billingCheck((int)$subscription['all_features']===1 && $subscription['ends_on']===billingAnnualEnd($input['start_date']),'Payment creates one annual full-feature subscription');
billingCheck(billingRecordPayment($db,$input,$admin)===$id && (int)$db->query('SELECT COUNT(*) FROM user_subscription')->fetchColumn()===1,'Repeat request is idempotent');
$changed=$input;$changed['notes']='Different';billingReject(static function()use($db,$changed,$admin){billingRecordPayment($db,$changed,$admin);},'Same key cannot change payment details');
$duplicate=$input;$duplicate['request_key']=bin2hex(random_bytes(16));billingReject(static function()use($db,$duplicate,$admin){billingRecordPayment($db,$duplicate,$admin);},'Duplicate external payment reference rejected');
$renew=$input;$renew['request_key']=bin2hex(random_bytes(16));$renew['payment_reference'].='-renew';$second=billingRecordPayment($db,$renew,$admin);
$renewal=$db->query('SELECT * FROM user_subscription ORDER BY id DESC LIMIT 1')->fetch();
billingCheck($renewal['starts_on']===$subscription['ends_on'],'Renewal starts after existing term without overlap');
billingCheck(billingAnnualEnd('2028-02-29')==='2029-02-28' && billingAnnualEnd('2027-03-01')==='2028-03-01','Calendar-year leap handling');
$bad=$input;$bad['paid_at']=$now->modify('+1 day')->format('Y-m-d\TH:i');billingReject(static function()use($bad,$now){billingInput($bad,$now);},'Future payment rejected');
$bad=$input;$bad['payment_reference']='';billingReject(static function()use($bad,$now){billingInput($bad,$now);},'Non-cash reference required');
$bad=$input;$bad['start_date']='2026-02-30';billingReject(static function()use($bad,$now){billingInput($bad,$now);},'Invalid calendar date rejected');
$before=(int)$db->query('SELECT COUNT(*) FROM user_subscription_payment')->fetchColumn();
$db->exec('ALTER TABLE user_subscription_audit CHANGE description unavailable_description VARCHAR(2000) NOT NULL');
$failed=$renew;$failed['request_key']=bin2hex(random_bytes(16));$failed['payment_reference'].='-rollback';
try { billingRecordPayment($db,$failed,$admin); throw new RuntimeException('Expected audit insert to fail'); }
catch(PDOException $expected) { billingCheck((int)$db->query('SELECT COUNT(*) FROM user_subscription_payment')->fetchColumn()===$before && (int)$db->query('SELECT COUNT(*) FROM user_subscription')->fetchColumn()===$before,'Audit failure rolls back payment and subscription'); }
$db->exec('ALTER TABLE user_subscription_audit CHANGE unavailable_description description VARCHAR(2000) NOT NULL');
billingVoidPayment($db,$id,'Incorrect test record',$admin);billingVoidPayment($db,$id,'Repeated correction',$admin);
billingCheck($db->query('SELECT status FROM user_subscription WHERE payment_id='.$id)->fetchColumn()==='CANCELLED','Voiding cancels linked entitlement');
billingCheck((int)$db->query("SELECT COUNT(*) FROM user_subscription_audit WHERE action='PAYMENT_VOIDED'")->fetchColumn()===1,'Repeated void does not duplicate audit');
$summary=billingSummary($db);billingCheck((float)$summary['total_received']===9999.0,'Revenue excludes voided payments');
foreach(['SUPER_ADMIN'=>true,'ADMIN'=>true,'FINANCE'=>true,'VIEWER'=>false,'SUPPORT'=>false,'OPERATIONS'=>false] as $role=>$expected){$_SESSION['PLATFORM_ADMIN']['ROLE']=$role;billingCheck(billingCanWrite()===$expected,'Write policy for '.$role);}
echo "All billing tests passed; no live payment rows were written.\n";
