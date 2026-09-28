<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../common/settings.php';
function settingsCheck(bool $valid,string $message):void{if(!$valid)throw new RuntimeException($message);echo 'PASS: '.$message."\n";}
function settingsReject(callable $fn,string $message):void{try{$fn();}catch(InvalidArgumentException $error){settingsCheck(true,$message);return;}throw new RuntimeException($message);}
function settingsPayload(array $values):array{foreach($values as $key=>$value)$values[$key]=is_bool($value)?($value?'1':'0'):(string)$value;$values['smtp_password']='';return $values;}
$db=databaseConnection();
foreach(['platform_settings','activity_log'] as $table){
    $ddl=$db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    $ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);
    $ddl=preg_replace('/^\s*CONSTRAINT .*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',"\n)",$ddl);$db->exec($ddl);
}
$current=platformSettingsLoad($db);$input=settingsPayload($current);
settingsCheck(!array_filter(array_keys($current),static function($key){return strpos($key,'maintenance')===0;}),'Maintenance absent from loaded defaults');
settingsReject(static function(){platformSettingsTestSmtp(['smtp_host'=>'smtp.example.test']);},'Dummy SMTP cannot attempt delivery or authenticate');
settingsCheck(platformSettingsSave($db,$input,0)===false,'No-change save produces no audit');
$input['platform_name']='Settings test';$input['smtp_password']='test-only-secret';$input['smtp_host']='smtp.example.test';$input['smtp_username']='test@example.test';
settingsCheck(platformSettingsSave($db,$input,0),'Validated settings persist');
$saved=platformSettingsLoad($db);settingsCheck($saved['platform_name']==='Settings test' && $saved['settings_revision']===1,'Name and revision saved');
settingsCheck(strpos($saved['smtp_password'],'enc:v1:')===0 && platformSettingsDecrypt($saved['smtp_password'])==='test-only-secret','SMTP credential encrypted and decrypts correctly');
$audit=(string)$db->query('SELECT metadata FROM activity_log LIMIT 1')->fetchColumn();settingsCheck(strpos($audit,'test-only-secret')===false && strpos($audit,'smtp_password')!==false,'Audit records keys without secrets');
settingsReject(static function()use($db,$input){platformSettingsSave($db,$input,0);},'Stale revision rejected');
$input=settingsPayload($saved);$input['platform_name']='Another test';platformSettingsSave($db,$input,0);
$saved=platformSettingsLoad($db);settingsCheck(platformSettingsDecrypt($saved['smtp_password'])==='test-only-secret','Blank password preserves stored credential');
$input=settingsPayload($saved);$input['clear_smtp_password']='1';platformSettingsSave($db,$input,0);$saved=platformSettingsLoad($db);settingsCheck($saved['smtp_password']==='','Explicit credential removal');
foreach(['support_email'=>'invalid','timezone'=>'Mars/City','date_format'=>'invalid','session_timeout'=>'0','login_lock_attempts'=>'999','smtp_host'=>'https://host/path','smtp_port'=>'70000','smtp_encryption'=>'NONE','platform_name'=>['bad']] as $key=>$bad){$input=settingsPayload($saved);$input[$key]=$bad;settingsReject(static function()use($db,$input){platformSettingsSave($db,$input,0);},'Invalid '.$key.' rejected');}
settingsCheck(platformSettingsLoad($db)['settings_revision']===$saved['settings_revision'],'Validation failures leave persisted settings unchanged');
$input=settingsPayload($saved);$input['smtp_host']='1234';settingsReject(static function()use($db,$input){platformSettingsSave($db,$input,0);},'Numeric-only SMTP hostname rejected');
$input=settingsPayload($saved);$input['billing_notifications']='0';$input['security_notifications']='0';$input['maintenance_enabled']='1';platformSettingsSave($db,$input,0);$saved=platformSettingsLoad($db);
settingsCheck(!$saved['billing_notifications'] && !$saved['security_notifications'],'Notification switches persist off');
settingsCheck((int)$db->query("SELECT COUNT(*) FROM platform_settings WHERE setting_key LIKE 'maintenance%'")->fetchColumn()===0,'Old forms cannot recreate maintenance keys');
$secret=platformSettingsEncrypt('integrity-test');$raw=base64_decode(substr($secret,7));$raw[28]=chr(ord($raw[28])^1);
try{platformSettingsDecrypt('enc:v1:'.base64_encode($raw));throw new LogicException('Tampered credential accepted');}catch(RuntimeException $expected){settingsCheck(true,'Tampered ciphertext rejected');}
$db->exec('ALTER TABLE activity_log CHANGE description unavailable_description TEXT');
$input=settingsPayload($saved);$input['platform_name']='Must roll back';
try{platformSettingsSave($db,$input,0);throw new RuntimeException('Expected audit failure');}catch(PDOException $expected){settingsCheck(platformSettingsLoad($db)['platform_name']===$saved['platform_name'] && !$db->inTransaction(),'Audit failure rolls back configuration');}
$query=$db->prepare('SELECT DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE)');$query->execute([15]);settingsCheck((bool)$query->fetchColumn(),'Login lock interval supports native prepared statements');
echo "Settings tests passed using temporary tables; live settings unchanged.\n";
