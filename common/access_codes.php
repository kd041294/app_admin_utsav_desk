<?php
declare(strict_types=1);
require_once __DIR__ . '/settings.php';
function accessCodeGenerate(): string {
    $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';$code='';
    for($i=0;$i<16;$i++)$code.=$alphabet[random_int(0,strlen($alphabet)-1)];
    return $code;
}
function accessCodeIsValidFormat(string $code): bool {return preg_match('/^[A-Z0-9]{16}$/D',strtoupper(trim($code)))===1;}
function accessCodeHash(string $code): string {return hash('sha256',strtoupper(trim($code)));}
function accessCodeRateLimit(PDO $db,string $key,int $maxAttempts=8,int $windowSeconds=900): void {
    $now=time();$reset=$now-$windowSeconds;
    $q=$db->prepare('INSERT INTO admin_registration_limits(bucket_key,attempts,window_start) VALUES (?,1,?) ON DUPLICATE KEY UPDATE attempts=IF(window_start<?,1,attempts+1),window_start=IF(window_start<?,?,window_start)');
    $q->execute([$key,$now,$reset,$reset,$now]);
    $q=$db->prepare('SELECT attempts FROM admin_registration_limits WHERE bucket_key=?');$q->execute([$key]);
    if((int)$q->fetchColumn()>$maxAttempts)throw new DomainException('Too many requests. Try again in 15 minutes.');
}
function accessCodeAuthorize(array $admin): void {
    if((int)($admin['ID']??0)<1 || !in_array($admin['ROLE']??'',['SUPER_ADMIN','ADMIN'],true))throw new DomainException('You cannot manage admin registration approvals.');
}
function accessCodeApprove(PDO $db,string $email,string $role,int $days,array $admin): string {
    accessCodeAuthorize($admin);$email=strtolower(trim($email));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)throw new InvalidArgumentException('Enter a valid email address for the approved person.');
    if(!in_array($role,['ADMIN','OPERATIONS','SUPPORT','FINANCE','VIEWER'],true))throw new InvalidArgumentException('Choose a permitted admin role.');
    if($days<1||$days>90)throw new InvalidArgumentException('Expiry must be between 1 and 90 days.');
    $code=accessCodeGenerate();$encrypted=platformSettingsEncrypt($code);
    $db->beginTransaction();
    try {
        // A stable per-email row serializes approvals by different issuing admins.
        $key=hash('sha256','invite-email:'.$email);
        $q=$db->prepare('INSERT IGNORE INTO admin_registration_limits(bucket_key,attempts,window_start) VALUES (?,0,?)');$q->execute([$key,time()]);
        $q=$db->prepare('SELECT bucket_key FROM admin_registration_limits WHERE bucket_key=? FOR UPDATE');$q->execute([$key]);$q->fetch();
        $q=$db->prepare('SELECT id FROM admin_user WHERE email=? LIMIT 1');$q->execute([$email]);
        if($q->fetch())throw new DomainException('An admin account already exists for this email. Manage that account instead.');
        $q=$db->prepare('UPDATE admin_registration_invites SET revoked_at=UTC_TIMESTAMP(),revoked_by=? WHERE email=? AND used_at IS NULL AND revoked_at IS NULL');$q->execute([$admin['ID'],$email]);
        $q=$db->prepare('INSERT INTO admin_registration_invites(token_hash,token_encrypted,email,role,expires_at,created_by,created_at) VALUES (?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? DAY),?,UTC_TIMESTAMP())');
        $q->execute([accessCodeHash($code),$encrypted,$email,$role,$days,$admin['ID']]);$id=(int)$db->lastInsertId();
        adminActivityInsert($db,['log_code'=>'INV-'.bin2hex(random_bytes(10)),'admin_id'=>$admin['ID'],'actor_name'=>$admin['NAME']??'Admin','actor_role'=>$admin['ROLE'],'type'=>'ADMIN','module'=>'ACCESS_CODES','action'=>'CREATE','subject_id'=>$id,'subject_type'=>'registration_invite','subject_name'=>$email,'description'=>'Approved admin registration with role '.$role.'. Any earlier unused approval for this email was revoked.','metadata'=>['role'=>$role,'expiry_days'=>$days],'severity'=>'SUCCESS']);
        $db->commit();return $code;
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function accessCodeRevoke(PDO $db,int $id,array $admin): void {
    accessCodeAuthorize($admin);if($id<1)throw new InvalidArgumentException('Select an invitation.');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT * FROM admin_registration_invites WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch();
        if(!$row||$row['used_at']!==null||$row['revoked_at']!==null)throw new DomainException('This approval is unavailable, already used or revoked.');
        $q=$db->prepare('UPDATE admin_registration_invites SET revoked_at=UTC_TIMESTAMP(),revoked_by=? WHERE id=?');$q->execute([$admin['ID'],$id]);
        adminActivityInsert($db,['log_code'=>'INV-'.bin2hex(random_bytes(10)),'admin_id'=>$admin['ID'],'actor_name'=>$admin['NAME']??'Admin','actor_role'=>$admin['ROLE'],'type'=>'ADMIN','module'=>'ACCESS_CODES','action'=>'STATUS_CHANGE','subject_id'=>$id,'subject_type'=>'registration_invite','subject_name'=>$row['email'],'description'=>'Revoked unused registration approval.','severity'=>'WARNING']);
        $db->commit();
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
