<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/activity.php';

function platformSettingsDefaultValues(): array {
    return ['platform_name'=>'UtsavDesk','support_email'=>'support@utsavdesk.com','billing_email'=>'billing@utsavdesk.com','timezone'=>'Asia/Kolkata','currency'=>'INR','date_format'=>'d M Y','session_timeout'=>30,'login_lock_attempts'=>5,'login_lock_minutes'=>15,'smtp_host'=>'','smtp_port'=>587,'smtp_username'=>'','smtp_encryption'=>'TLS','smtp_password'=>'','billing_notifications'=>true,'security_notifications'=>true,'settings_revision'=>0];
}
function platformSettingsTypeForKey(string $key): string {
    if(in_array($key,['session_timeout','login_lock_attempts','login_lock_minutes','smtp_port','settings_revision'],true))return 'int';
    if(in_array($key,['billing_notifications','security_notifications'],true))return 'bool';
    return 'string';
}
function platformSettingsLoad(PDO $db): array {
    $values=platformSettingsDefaultValues();
    foreach($db->query('SELECT setting_key,setting_value FROM platform_settings') as $row){
        $key=$row['setting_key'];if(!array_key_exists($key,$values))continue;
        $type=platformSettingsTypeForKey($key);
        $values[$key]=$type==='int'?(int)$row['setting_value']:($type==='bool'?$row['setting_value']==='1':(string)$row['setting_value']);
    }
    return $values;
}
function platformSettingsRuntime(): array {
    static $values=null;
    if($values===null){try{$values=platformSettingsLoad(databaseConnection());}catch(Throwable $error){error_log('Platform settings unavailable.');$values=platformSettingsDefaultValues();}}
    return $values;
}
function platformSettingsKey(): string {
    $key=base64_decode((string)appConfigValue('SETTINGS_ENCRYPTION_KEY',''),true);
    if($key===false || strlen($key)!==32)throw new RuntimeException('Settings encryption key is unavailable.');
    return $key;
}
function platformSettingsEncrypt(string $secret): string {
    if($secret==='')return '';
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($secret,'aes-256-gcm',platformSettingsKey(),OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false)throw new RuntimeException('Credential encryption failed.');
    return 'enc:v1:'.base64_encode($iv.$tag.$cipher);
}
function platformSettingsDecrypt(string $stored): string {
    if($stored==='')return '';
    if(strpos($stored,'enc:v1:')!==0)throw new RuntimeException('SMTP credential requires encryption migration.');
    $raw=base64_decode(substr($stored,7),true);
    if($raw===false || strlen($raw)<29)throw new RuntimeException('Invalid encrypted SMTP credential.');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',platformSettingsKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
    if($plain===false)throw new RuntimeException('SMTP credential could not be decrypted.');
    return $plain;
}
function platformSettingsValidate(array $input,array $current): array {
    $values=$current;
    foreach(['platform_name'=>100,'support_email'=>190,'billing_email'=>190,'timezone'=>100,'date_format'=>20,'smtp_host'=>253,'smtp_username'=>190,'smtp_encryption'=>10] as $key=>$max){
        if(!isset($input[$key]) || !is_string($input[$key]) || mb_strlen($input[$key])>$max)throw new InvalidArgumentException('Invalid '.str_replace('_',' ',$key).'.');
        $values[$key]=trim($input[$key]);
    }
    if($values['platform_name']==='')throw new InvalidArgumentException('Platform name is required.');
    foreach(['support_email','billing_email'] as $key){if(!filter_var($values[$key],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid '.str_replace('_',' ',$key).'.');}
    if(!in_array($values['timezone'],DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('Choose a valid timezone.');
    if(!in_array($values['date_format'],['d M Y','d/m/Y','Y-m-d'],true))throw new InvalidArgumentException('Choose a supported date format.');
    foreach(['session_timeout'=>[5,240],'login_lock_attempts'=>[3,20],'login_lock_minutes'=>[1,1440],'smtp_port'=>[1,65535]] as $key=>$bounds){
        $value=filter_var($input[$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>$bounds[0],'max_range'=>$bounds[1]]]);
        if($value===false)throw new InvalidArgumentException(str_replace('_',' ',$key).' must be between '.$bounds[0].' and '.$bounds[1].'.');
        $values[$key]=$value;
    }
    foreach(['billing_notifications','security_notifications'] as $key){
        if(isset($input[$key]) && !in_array($input[$key],['0','1'],true))throw new InvalidArgumentException('Invalid switch value.');
        $values[$key]=($input[$key]??'0')==='1';
    }
    if(!in_array($values['smtp_encryption'],['TLS','SSL'],true))throw new InvalidArgumentException('SMTP requires TLS or SSL encryption.');
    if($values['smtp_host']!=='' && (!filter_var($values['smtp_host'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) || ctype_digit($values['smtp_host'])))throw new InvalidArgumentException('SMTP host must be a valid hostname, not a number, URL, path or port.');
    if(preg_match('/[\r\n]/',$values['smtp_username']))throw new InvalidArgumentException('Invalid SMTP username.');
    $password=$input['smtp_password']??'';
    if(!is_string($password)||strlen($password)>1024)throw new InvalidArgumentException('SMTP password is too long.');
    if(($input['clear_smtp_password']??'')==='1'){$values['smtp_password']='';}
    elseif($password!==''){$values['smtp_password']=platformSettingsEncrypt($password);}
    $values['currency']='INR';
    return $values;
}
function platformSettingsSave(PDO $db,array $input,int $adminId=0): bool {
    $expected=filter_var($input['settings_revision']??null,FILTER_VALIDATE_INT);
    if($expected===false || $expected<0)throw new InvalidArgumentException('Reload the settings page before saving.');
    $db->beginTransaction();
    try {
        $db->exec("INSERT INTO platform_settings(setting_key,setting_type,setting_value) VALUES ('settings_revision','int','0') ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)");
        $revision=(int)$db->query("SELECT setting_value FROM platform_settings WHERE setting_key='settings_revision' FOR UPDATE")->fetchColumn();
        if($revision!==$expected)throw new InvalidArgumentException('Another admin changed settings. Reload to get the latest values before saving.');
        $current=platformSettingsLoad($db);$values=platformSettingsValidate($input,$current);$changed=[];
        foreach($values as $key=>$value){if($key!=='settings_revision' && $value!==$current[$key])$changed[]=$key;}
        if(!$changed){$db->commit();return false;}
        $values['settings_revision']=$revision+1;
        $statement=$db->prepare('INSERT INTO platform_settings(setting_key,setting_type,setting_value,updated_by,updated_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE setting_type=VALUES(setting_type),setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=NOW()');
        foreach(array_merge($changed,['settings_revision']) as $key){$value=$values[$key];$statement->execute([$key,platformSettingsTypeForKey($key),is_bool($value)?($value?'1':'0'):(string)$value,$adminId?:null]);}
        $actor=$_SESSION['PLATFORM_ADMIN']??[];
        adminActivityInsert($db,['log_code'=>'SET-'.bin2hex(random_bytes(10)),'admin_id'=>$adminId,'actor_name'=>$actor['NAME']??'Platform admin','actor_role'=>$actor['ROLE']??'SUPER_ADMIN','type'=>'SETTINGS','module'=>'SETTINGS','action'=>'UPDATE','subject_type'=>'platform_settings','subject_name'=>'Platform Settings','description'=>'Updated platform settings: '.implode(', ',$changed),'severity'=>'SUCCESS','metadata'=>['updated_keys'=>$changed]]);
        $db->commit();return true;
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function platformSettingsDate(string $value): string {
    $settings=platformSettingsRuntime();
    try{return (new DateTimeImmutable($value))->format($settings['date_format']);}catch(Throwable $error){return $value;}
}

/** Establish and authenticate an encrypted SMTP connection without sending mail. */
function platformSettingsSmtpIsDemo(array $values): bool {
    return (bool)preg_match('/\.(test|example|invalid)$/i',(string)($values['smtp_host']??''));
}
function platformSettingsTestSmtp(array $values): void {
    if($values['smtp_host']==='')throw new InvalidArgumentException('Save an SMTP host before testing.');
    if(platformSettingsSmtpIsDemo($values))throw new InvalidArgumentException('Dummy SMTP configuration is saved. Enter your real SMTP hostname and credentials before testing.');
    $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$values['smtp_host']]]);
    $scheme=$values['smtp_encryption']==='SSL'?'ssl':'tcp';
    $socket=@stream_socket_client($scheme.'://'.$values['smtp_host'].':'.$values['smtp_port'],$code,$message,8,STREAM_CLIENT_CONNECT,$context);
    if(!$socket){
        if(strpos($message,'getaddrinfo')!==false || strpos($message,'php_network_getaddresses')!==false)throw new RuntimeException('The saved SMTP hostname could not be resolved. Correct the hostname, save settings and test again.');
        throw new RuntimeException('Could not connect to the configured SMTP server. Check host, port and encryption.');
    }
    stream_set_timeout($socket,3);
    $deadline=microtime(true)+20;
    $read=static function(array $expected)use($socket,$deadline):void{
        for($i=0;$i<30;$i++){
            if(microtime(true)>$deadline)throw new RuntimeException('SMTP connection test timed out.');
            $line=fgets($socket,2048);if($line===false)throw new RuntimeException('SMTP server timed out or disconnected.');
            if(strlen($line)>3 && $line[3]===' ') { if(!in_array((int)substr($line,0,3),$expected,true))throw new RuntimeException('SMTP server rejected the connection or authentication (code '.(int)substr($line,0,3).').');return; }
        }
        throw new RuntimeException('Unexpected SMTP server response.');
    };
    $command=static function(string $text,array $expected)use($socket,$read):void{if(fwrite($socket,$text."\r\n")===false)throw new RuntimeException('SMTP connection closed.');$read($expected);};
    try {
        $read([220]);$command('EHLO utsavdesk.local',[250]);
        if($values['smtp_encryption']==='TLS'){$command('STARTTLS',[220]);if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('SMTP TLS negotiation failed.');$command('EHLO utsavdesk.local',[250]);}
        if($values['smtp_username']!==''){$command('AUTH LOGIN',[334]);$command(base64_encode($values['smtp_username']),[334]);$command(base64_encode(platformSettingsDecrypt($values['smtp_password'])),[235]);}
        $command('QUIT',[221]);
    }finally{fclose($socket);}
}
