<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../common/access_codes.php';
require_once __DIR__.'/../../admin_registration_utsav_desk/private/bootstrap.php';
function inviteCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS: '.$message."\n";}
function inviteReject(callable $fn,string $message):void{try{$fn();}catch(DomainException|InvalidArgumentException $expected){inviteCheck(true,$message);return;}throw new RuntimeException($message);}
$db=databaseConnection();
foreach(['admin_registration_invites','admin_registration_limits','activity_log','admin_user','ud_registration_bootstrap'] as $table){$ddl=$db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];$ddl=str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^\s*CONSTRAINT .*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',"\n)",$ddl);$db->exec($ddl);}
$admin=['ID'=>991,'ROLE'=>'ADMIN','NAME'=>'Test issuer'];
$values=['first_name'=>'Test','last_name'=>'Invitee','display_name'=>'Test Invitee','email'=>'tampered@example.test','contact_number'=>'','whatsapp_number'=>'','language'=>'en','theme'=>'SYSTEM','password'=>'temporary-test-password-strong','confirm_password'=>'temporary-test-password-strong'];
$code=accessCodeApprove($db,'approved@example.test','VIEWER',7,$admin);
inviteCheck(strlen($code)===16 && accessCodeIsValidFormat($code),'16-character secure code generated');
$row=$db->query('SELECT * FROM admin_registration_invites')->fetch();
inviteCheck(platformSettingsDecrypt($row['token_encrypted'])===$code && $row['token_hash']!==$code,'Code recoverable only from encrypted storage');
$invite=findRegistrationInvite($db,strtolower($code),'unrelated-legacy-secret');
inviteCheck($invite && $invite['email']==='approved@example.test','Admin and registration app share verification independently of legacy secrets');
inviteReject(static function()use($db,$invite,$values){registerInvitedAdmin($db,(int)$invite['id'],'wrong@example.test',$values);},'Session email mismatch denied');
$result=registerInvitedAdmin($db,(int)$invite['id'],$invite['email'],$values);
$account=$db->query('SELECT * FROM admin_user')->fetch();
inviteCheck($account['email']==='approved@example.test' && $account['role']==='VIEWER' && $account['account_status']==='ACTIVE','Created account uses approved email and role');
inviteCheck(password_verify($values['password'],$account['password_hash']),'Registered password works with admin login verification');
inviteCheck(findRegistrationInvite($db,$code,'legacy')===null,'Used code cannot be verified again');
inviteReject(static function()use($db,$invite,$values){registerInvitedAdmin($db,(int)$invite['id'],$invite['email'],$values);},'Double submission cannot create second account');
inviteReject(static function()use($db,$admin){accessCodeApprove($db,'approved@example.test','ADMIN',7,$admin);},'Existing account cannot be invited again');
$code=accessCodeApprove($db,'revoke@example.test','SUPPORT',7,$admin);$invite=findRegistrationInvite($db,$code,'legacy');accessCodeRevoke($db,(int)$invite['id'],$admin);
inviteCheck(findRegistrationInvite($db,$code,'legacy')===null,'Revoked code rejected');
inviteReject(static function()use($db,$invite,$values){registerInvitedAdmin($db,(int)$invite['id'],$invite['email'],$values);},'Revocation blocks an already-started registration');
$first=accessCodeApprove($db,'renew@example.test','FINANCE',7,$admin);$second=accessCodeApprove($db,'renew@example.test','OPERATIONS',7,$admin);
inviteCheck(findRegistrationInvite($db,$first,'legacy')===null && findRegistrationInvite($db,$second,'legacy')['role']==='OPERATIONS','Reapproval revokes prior code and applies new role');
$q=$db->prepare('UPDATE admin_registration_invites SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE token_hash=?');$q->execute([accessCodeHash($second)]);
inviteCheck(findRegistrationInvite($db,$second,'legacy')===null,'Expired code rejected');
inviteReject(static function()use($db,$admin){accessCodeApprove($db,'bad@example.test','SUPER_ADMIN',7,$admin);},'Invitations cannot create a super admin');
inviteReject(static function()use($db){accessCodeApprove($db,'bad@example.test','ADMIN',7,['ID'=>1,'ROLE'=>'VIEWER']);},'Unauthorized issuer rejected');
inviteCheck((int)$db->query('SELECT COUNT(*) FROM ud_registration_bootstrap')->fetchColumn()===0,'Bootstrap data never modified');
$audit=implode(' ',array_column($db->query('SELECT metadata FROM activity_log')->fetchAll(),'metadata'));inviteCheck(strpos($audit,$code)===false,'Audit does not expose raw access codes');
echo "Invitation flow tests passed in temporary tables; no live accounts created.\n";
