<?php
declare(strict_types=1);
require_once __DIR__.'/../components/header.php';
require_once __DIR__.'/../common/access_codes.php';
require_once __DIR__.'/../common/billing.php';
$admin=$_SESSION['PLATFORM_ADMIN'];$error='';$rows=[];
$flash=$_SESSION['ACCESS_CODE_NOTICE']??'';unset($_SESSION['ACCESS_CODE_NOTICE']);
$values=['email'=>'','role'=>'VIEWER','expires_days'=>'7'];
$search=is_string($_GET['q']??null)?substr(trim($_GET['q']),0,190):'';
$registrationUrl=appConfigValue('ADMIN_REGISTRATION_URL',APP_ENV==='local'?'http://localhost:8383/admin_registration_utsav_desk/':'');
if(!filter_var($registrationUrl,FILTER_VALIDATE_URL))$registrationUrl='';
try {
    $db=databaseConnection();accessCodeAuthorize($admin);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        $token=$_POST['csrf_token']??null;if(!is_string($token)||!verifyCsrfToken($token)){http_response_code(419);throw new DomainException('Your security token expired. Reload and retry.');}
        foreach($values as $key=>$value)if(is_string($_POST[$key]??null))$values[$key]=$_POST[$key];
        if(($_POST['action']??'')==='approve'){
            accessCodeRateLimit($db,hash('sha256','invite-approve:'.$admin['ID']));
            accessCodeApprove($db,$values['email'],$values['role'],(int)filter_var($values['expires_days'],FILTER_VALIDATE_INT),$admin);
            $_SESSION['ACCESS_CODE_NOTICE']='Registration approved. Copy the 16-character code below and share it privately with the approved person.';
        }elseif(($_POST['action']??'')==='revoke'){
            accessCodeRevoke($db,(int)filter_var($_POST['invite_id']??0,FILTER_VALIDATE_INT),$admin);$_SESSION['ACCESS_CODE_NOTICE']='Approval revoked. The code can no longer complete registration.';
        }else{throw new DomainException('Unsupported action.');}
        header('Location: '.APP_BASE_URL.'/access-codes/',true,303);exit;
    }
}catch(DomainException|InvalidArgumentException $exception){$error=$exception->getMessage();}
catch(Throwable $exception){error_log('Access codes failed: '.$exception->getMessage());$error='Access codes could not be updated. Please retry.';}
try {
    $q=$db->prepare("SELECT i.*,a.display_name AS creator,CASE WHEN i.revoked_at IS NOT NULL THEN 'REVOKED' WHEN i.used_at IS NOT NULL THEN 'USED' WHEN i.expires_at<=UTC_TIMESTAMP() THEN 'EXPIRED' ELSE 'ACTIVE' END AS display_status FROM admin_registration_invites i LEFT JOIN admin_user a ON a.id=i.created_by WHERE i.email LIKE ? ORDER BY i.id DESC LIMIT 100");$q->execute(['%'.$search.'%']);$rows=$q->fetchAll();
}catch(Throwable $exception){error_log('Invitation list failed: '.$exception->getMessage());$error='Invitation history is temporarily unavailable.';}
$pageSubtitle='Approve admin registration and manage access codes';
require __DIR__.'/../components/billing-shell.php';
?>
<section class="billing-heading"><div><h2>Admin Registration Approvals</h2><p>Approve a person and generate their single-use, 16-character registration code.</p></div><?php if($registrationUrl): ?><a class="btn btn-outline-primary" href="<?= billingEscape($registrationUrl) ?>" target="_blank" rel="noopener noreferrer">Open registration portal <i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?></section>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= billingEscape($error) ?></div><?php endif; ?>
<?php if($flash): ?><div class="alert alert-success" role="status"><?= billingEscape($flash) ?></div><?php endif; ?>
<div class="row g-4 mb-4"><div class="col-lg-5"><section class="ud-card p-4"><h3 class="h5">Approve registration</h3><form method="post" id="approvalForm"><input type="hidden" name="csrf_token" value="<?= billingEscape(csrfToken()) ?>"><input type="hidden" name="action" value="approve">
<label class="form-label mt-3" for="approvalEmail">Approved email address</label><input class="form-control" type="email" id="approvalEmail" name="email" required maxlength="190" value="<?= billingEscape($values['email']) ?>">
<label class="form-label mt-3" for="approvalRole">Assigned role</label><select class="form-select" id="approvalRole" name="role"><?php foreach(['VIEWER','SUPPORT','OPERATIONS','FINANCE','ADMIN'] as $role): ?><option <?= $values['role']===$role?'selected':'' ?>><?= $role ?></option><?php endforeach; ?></select>
<label class="form-label mt-3" for="approvalDays">Expires after (days)</label><input class="form-control" type="number" min="1" max="90" id="approvalDays" name="expires_days" required value="<?= billingEscape($values['expires_days']) ?>">
<p class="small text-muted mt-3">Approving the same email again replaces its previous unused code. The email and role cannot be changed by the person registering.</p><button class="btn btn-primary w-100">Approve &amp; Generate Code</button></form></section></div>
<div class="col-lg-7"><section class="ud-card p-4 h-100"><h3 class="h5">How onboarding works</h3><ol class="lh-lg"><li>Approve the person's email and choose their role.</li><li>Copy their code from the table below and share it privately.</li><li>They open the registration portal and verify the code.</li><li>They complete their profile and password within 10 minutes.</li><li>The account becomes active with the approved role; the code is consumed.</li></ol><p class="text-muted small">Codes remain visible here to Admin and Super Admin while usable. Expired, revoked and used approvals cannot grant another account. No email is sent automatically.</p><?php if(!$registrationUrl): ?><div class="alert alert-info">Set ADMIN_REGISTRATION_URL in the server environment to show the registration portal link.</div><?php endif; ?></section></div></div>
<section class="billing-panel p-3"><form class="d-flex gap-2 mb-3" method="get"><label class="visually-hidden" for="codeSearch">Search approved email</label><input id="codeSearch" class="form-control" name="q" value="<?= billingEscape($search) ?>" placeholder="Search approved email"><button class="btn btn-outline-primary">Search</button><a class="btn btn-outline-secondary" href="./">Reset</a></form><p class="small text-muted">Latest 100 matching approvals · All timestamps are UTC</p><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Approved person</th><th>Access code</th><th>Status</th><th>Expires / used</th><th>Approved by</th><th></th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="6" class="text-center py-4">No registration approvals found.</td></tr><?php endif; ?>
<?php foreach($rows as $row): $code='';if($row['display_status']==='ACTIVE' && $row['token_encrypted']){try{$code=platformSettingsDecrypt($row['token_encrypted']);}catch(Throwable $exception){error_log('Invitation code decryption unavailable.');}} ?>
<tr><td><?= billingEscape($row['email']) ?><small class="d-block"><?= billingEscape($row['role']) ?></small></td><td><?php if($code!==''): ?><code class="text-nowrap" id="invite-code-<?= $row['id'] ?>"><?= billingEscape($code) ?></code> <button type="button" class="btn btn-sm btn-outline-secondary copy-access-code" data-code-id="invite-code-<?= $row['id'] ?>">Copy</button><?php elseif($row['display_status']==='ACTIVE'): ?><small>Legacy code unavailable. Reapprove to issue a new code.</small><?php else: ?><small>Not usable</small><?php endif; ?></td><td><span class="badge <?= $row['display_status']==='ACTIVE'?'text-bg-success':'text-bg-secondary' ?>"><?= billingEscape($row['display_status']) ?></span></td><td><?= billingEscape($row['expires_at']) ?><small class="d-block"><?= $row['used_at']?'Used: '.billingEscape($row['used_at']):($row['revoked_at']?'Revoked: '.billingEscape($row['revoked_at']):'') ?></small></td><td><?= billingEscape($row['creator']?:'Legacy / system') ?></td><td><?php if($row['display_status']==='ACTIVE'): ?><form method="post" class="revoke-approval"><input type="hidden" name="csrf_token" value="<?= billingEscape(csrfToken()) ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="invite_id" value="<?= $row['id'] ?>"><button class="btn btn-sm btn-outline-danger">Revoke</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><p id="copyStatus" class="small" role="status"></p></section>
<script src="../assets/js/access-codes.js"></script>
<?php require __DIR__.'/../components/billing-footer.php'; ?>
