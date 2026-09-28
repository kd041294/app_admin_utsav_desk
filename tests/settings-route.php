<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../common/settings.php';
$case=$argv[1]??'render';
$db=databaseConnection();
foreach(['platform_settings','activity_log','admin_user','admin_registration_limits'] as $table){
    $ddl=$db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    $ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);
    $ddl=preg_replace('/^\s*CONSTRAINT .*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',"\n)",$ddl);$db->exec($ddl);
}
$role=in_array($case,['readonly','forbidden_save'],true)?'ADMIN':($case==='forbidden_view'?'VIEWER':'SUPER_ADMIN');
$stmt=$db->prepare("INSERT INTO admin_user(id,admin_code,first_name,display_name,email,role) VALUES (991,'TEST-SETTINGS','Test','Test Admin','test@example.test',?)");$stmt->execute([$role]);
session_set_save_handler(new class implements SessionHandlerInterface {
    public function open($path,$name):bool{return true;} public function close():bool{return true;}
    public function read($id):string{return '';} public function write($id,$data):bool{return true;}
    public function destroy($id):bool{return true;} public function gc($max):int{return 0;}
},true);
session_start(['use_cookies'=>0,'cache_limiter'=>'']);
$_SESSION=['PLATFORM_ADMIN'=>['ID'=>991,'IS_LOGGED_IN'=>true],'CSRF_TOKEN'=>'settings-test-token'];
$_SERVER['SCRIPT_NAME']='/admin_utsav_desk/settings/index.php';$_SERVER['REQUEST_METHOD']='GET';
if($case==='anonymous')unset($_SESSION['PLATFORM_ADMIN']);
$settings=platformSettingsDefaultValues();
if(in_array($case,['auth_lock','auth_success'],true)){
    $stmt=$db->prepare('UPDATE admin_user SET password_hash=?,failed_login_attempts=2 WHERE id=991');$stmt->execute([password_hash('test-password',PASSWORD_DEFAULT)]);
    $db->exec("INSERT INTO platform_settings(setting_key,setting_type,setting_value) VALUES ('login_lock_attempts','int','3'),('login_lock_minutes','int','2')");
    $_SERVER['REQUEST_METHOD']='POST';$_POST=['email'=>'test@example.test','password'=>$case==='auth_success'?'test-password':'incorrect','csrf_token'=>'settings-test-token'];
}
if(in_array($case,['save','bad_input','bad_csrf','forbidden_save','smtp_empty','smtp_rate','array_token'],true)){
    $_SERVER['REQUEST_METHOD']='POST';
    foreach($settings as $key=>$value)$_POST[$key]=is_bool($value)?($value?'1':'0'):(string)$value;
    $_POST['platform_name']='Route Test';$_POST['smtp_password']='';$_POST['action']='save';$_POST['csrf_token']='settings-test-token';
    if($case==='bad_input')$_POST['support_email']='invalid';
    if($case==='bad_csrf')$_POST['csrf_token']='invalid';
    if($case==='array_token')$_POST['csrf_token']=[];
    if(in_array($case,['smtp_empty','smtp_rate'],true))$_POST['action']='test_smtp';
    if($case==='smtp_rate')$_SESSION['SMTP_TEST_AT']=time();
}
ob_start();
register_shutdown_function(static function()use($case,$db){
    $html=ob_get_clean();$code=http_response_code()?:200;$saved=platformSettingsLoad($db);
    $assert=static function(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,'FAIL: '.$message."\n");exit(1);}};
    if($case==='save'){$assert($code===303 && $saved['platform_name']==='Route Test','Save redirects and persists');$assert((int)$db->query('SELECT COUNT(*) FROM activity_log')->fetchColumn()===1,'Save audit');}
    elseif($case==='auth_lock'){
        $row=$db->query('SELECT failed_login_attempts,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),locked_until) AS lock_seconds FROM admin_user WHERE id=991')->fetch();
        $assert($code===401 && (int)$row['failed_login_attempts']===3 && (int)$row['lock_seconds']>=115 && (int)$row['lock_seconds']<=120,'Configured third failure locks for two minutes');
        $assert((int)$db->query("SELECT COUNT(*) FROM activity_log WHERE action='LOGIN_FAILED'")->fetchColumn()===1,'Failure writes security alert');
    }
    elseif($case==='auth_success'){
        $assert($code===200 && (int)$db->query('SELECT failed_login_attempts FROM admin_user WHERE id=991')->fetchColumn()===0,'Successful login resets failures');
    }
    elseif($case==='anonymous')$assert($code===302,'Anonymous login redirect');
    elseif($case==='forbidden_view')$assert($code===403,'Viewer denied settings');
    elseif(in_array($case,['bad_csrf','array_token'],true))$assert($code===419,'CSRF rejection');
    elseif($case==='forbidden_save')$assert($code===403,'Admin cannot save');
    else{
        $assert($code===200,'Page renders');
        $assert(strpos($html,'data-setting-section="maintenance"')===false,'Maintenance absent');
        foreach(['general','subscription','security','email','notifications','features','system'] as $section)$assert(strpos($html,'data-setting-section="'.$section.'"')!==false,'Section '.$section.' renders');
        if($case==='readonly')$assert(strpos($html,'<fieldset disabled')!==false,'Admin form read-only');
        if($case==='bad_input')$assert(strpos($html,'Enter a valid support email')!==false,'Field validation displayed');
        if($case==='smtp_empty')$assert(strpos($html,'Save an SMTP host')!==false,'Missing SMTP host handled');
        if($case==='smtp_rate')$assert(strpos($html,'Wait 30 seconds')!==false,'SMTP rate limit');
    }
    if($case!=='save')$assert($saved['settings_revision']===0,'Rejected/read request did not write settings');
    echo 'PASS: settings route '.$case."\n";
});
if(in_array($case,['auth_lock','auth_success'],true)){require __DIR__.'/../api/auth/login.php';}
else{require __DIR__.'/../settings/index.php';}
