<?php
declare(strict_types=1);
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../common/settings.php';
require_once __DIR__ . '/../common/cms_db.php';
$admin=$_SESSION['PLATFORM_ADMIN'];$adminName=$admin['NAME'];$adminRole=$admin['ROLE'];
$canEditSettings=$adminRole==='SUPER_ADMIN';$error='';$loadError=false;$loaded=false;
$message=$_SESSION['SETTINGS_FLASH']??'';unset($_SESSION['SETTINGS_FLASH']);
$settings=platformSettingsDefaultValues();$system=[];
function settingsEscape($value): string {return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
try {
    $db=databaseConnection();$settings=platformSettingsLoad($db);$loaded=true;
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        if(!$canEditSettings){http_response_code(403);throw new InvalidArgumentException('Only Super Admin can update or test settings.');}
        $token=$_POST['csrf_token']??null;
        if(!is_string($token)||!verifyCsrfToken($token)){http_response_code(419);throw new InvalidArgumentException('Your security token expired. Reload before trying again.');}
        $action=$_POST['action']??'';
        if($action==='save'){
            $changed=platformSettingsSave($db,$_POST,(int)$admin['ID']);
            $_SESSION['SETTINGS_FLASH']=$changed?'Settings saved successfully.':'No settings were changed.';
            header('Location: '.APP_BASE_URL.'/settings/',true,303);exit;
        }elseif($action==='test_smtp'){
            if(time()-(int)($_SESSION['SMTP_TEST_AT']??0)<30)throw new InvalidArgumentException('Wait 30 seconds before testing SMTP again.');
            $_SESSION['SMTP_TEST_AT']=time();
            platformSettingsTestSmtp($settings);
            $_SESSION['SETTINGS_FLASH']='Encrypted SMTP connection succeeded'.($settings['smtp_username']!==''?' and credentials were accepted.':'.').' No email was sent.';
            header('Location: '.APP_BASE_URL.'/settings/#email',true,303);exit;
        }else{throw new InvalidArgumentException('Unsupported settings action.');}
    }
}catch(InvalidArgumentException $exception){
    $error=$exception->getMessage();
    if(($_POST['action']??'')==='save' && $canEditSettings){foreach($settings as $key=>$value){if($key==='smtp_password')continue;if(is_string($_POST[$key]??null))$settings[$key]=$_POST[$key];elseif(platformSettingsTypeForKey($key)==='bool')$settings[$key]=false;}}
}catch(Throwable $exception){error_log('Settings failed: '.$exception->getMessage());$error=($_POST['action']??'')==='test_smtp' && get_class($exception)===RuntimeException::class?$exception->getMessage():'Settings could not be loaded or saved. Please retry.';$loadError=!$loaded;}
try{$system['Admin database']='Connected · '.databaseConnection()->query('SELECT VERSION()')->fetchColumn();}catch(Throwable $exception){$system['Admin database']='Unavailable';}
try{$system['CMS database']=cmsDatabaseConnection()->query('SELECT 1')->fetchColumn()?'Connected':'Unavailable';}catch(Throwable $exception){$system['CMS database']='Unavailable';}
$system+=['Environment'=>APP_ENV,'PHP version'=>PHP_VERSION,'Admin session'=>session_name(),'Server time'=>date('Y-m-d H:i:s T'),'Display time'=>(new DateTimeImmutable())->setTimezone(new DateTimeZone(in_array($settings['timezone'],DateTimeZone::listIdentifiers(),true)?$settings['timezone']:'Asia/Kolkata'))->format('Y-m-d H:i:s T'),'Display timezone'=>$settings['timezone'],'Settings revision'=>$settings['settings_revision'],'Email delivery'=>'SMTP configuration only; connection test sends no email'];
$sections=['general'=>['General','bi-sliders'],'subscription'=>['Subscription','bi-stars'],'security'=>['Security','bi-shield-lock'],'email'=>['Email / SMTP','bi-envelope'],'notifications'=>['Notifications','bi-bell'],'features'=>['Platform Features','bi-grid'],'system'=>['System','bi-gear']];
require __DIR__.'/shell.php';
?>
<main class="admin-content">
<section class="settings-heading"><div><h2>Platform Settings</h2><p>Manage admin configuration, security and communication settings.</p></div><div class="settings-heading-actions"><button type="reset" form="settingsForm" class="settings-reset-button" <?= !$canEditSettings||$loadError?'disabled':'' ?>>Reset unsaved</button><button type="submit" form="settingsForm" id="saveAllSettings" class="ud-btn ud-btn-primary" <?= !$canEditSettings||$loadError?'disabled':'' ?>>Save Changes</button></div></section>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= settingsEscape($error) ?></div><?php endif; ?>
<?php if($message): ?><div class="alert alert-success" role="status"><?= settingsEscape($message) ?></div><?php endif; ?>
<?php if(!$canEditSettings): ?><div class="alert alert-info">Read-only access. Only Super Admin can change settings. All sections remain available to view.</div><?php endif; ?>
<p class="small text-muted" id="settingsDirty" role="status">Changes apply after saving.</p>
<div class="settings-layout"><aside class="settings-navigation" aria-label="Settings sections"><div class="settings-nav-title">Configuration</div><?php foreach($sections as $key=>$section): ?><a href="#<?= $key ?>" class="settings-nav-item <?= $key==='general'?'active':'' ?>" data-setting-target="<?= $key ?>"><span class="settings-nav-icon"><i class="bi <?= $section[1] ?>"></i></span><span><strong><?= $section[0] ?></strong></span></a><?php endforeach; ?></aside>
<div class="settings-content"><form method="post" id="settingsForm"><input type="hidden" name="csrf_token" value="<?= settingsEscape(csrfToken()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="settings_revision" value="<?= (int)$settings['settings_revision'] ?>">
<fieldset <?= !$canEditSettings||$loadError?'disabled':'' ?> class="settings-fieldset">
<section class="settings-section active" data-setting-section="general" id="general"><div class="settings-section-heading"><h3>General</h3></div><div class="settings-card"><div class="settings-form-grid">
<?php foreach(['platform_name'=>['Platform name','text',100],'support_email'=>['Support email','email',190],'billing_email'=>['Billing email','email',190]] as $key=>$field): ?><div class="settings-form-group"><label for="<?= $key ?>"><?= $field[0] ?></label><input id="<?= $key ?>" name="<?= $key ?>" type="<?= $field[1] ?>" maxlength="<?= $field[2] ?>" value="<?= settingsEscape($settings[$key]) ?>" required></div><?php endforeach; ?>
<div class="settings-form-group"><label for="timezone">Display timezone</label><select id="timezone" name="timezone"><?php foreach(DateTimeZone::listIdentifiers() as $zone): ?><option <?= $settings['timezone']===$zone?'selected':'' ?>><?= settingsEscape($zone) ?></option><?php endforeach; ?></select><small>Used for the system display clock. The selected date format applies to receipt calendar dates. CMS storage and reporting boundaries retain CMS server time.</small></div>
<div class="settings-form-group"><label for="date_format">Date format</label><select name="date_format" id="date_format"><?php foreach(['d M Y','d/m/Y','Y-m-d'] as $format): ?><option value="<?= $format ?>" <?= $settings['date_format']===$format?'selected':'' ?>><?= date($format) ?></option><?php endforeach; ?></select></div>
<div class="settings-form-group"><label>Billing currency</label><input value="INR · Indian Rupee" readonly><small>The annual plan is priced in INR.</small></div>
</div></div></section>
<section class="settings-section" data-setting-section="subscription" id="subscription"><div class="settings-section-heading"><h3>Subscription &amp; Billing</h3></div><div class="settings-card"><h4>Utsav Annual · All Features</h4><p><del>₹24,999</del> <strong class="fs-3">₹9,999 / year</strong></p><p>₹15,000 discount. Full manual payment creates one annual user subscription. Renewals extend the last term. No free plan, trial or automatic gateway collection is enabled by this admin workflow.</p><a href="../billing/" class="btn btn-outline-primary">Manage payments</a> <a href="../subscriptions/" class="btn btn-outline-secondary">View subscriptions</a></div></section>
<section class="settings-section" data-setting-section="security" id="security"><div class="settings-section-heading"><h3>Admin Security</h3></div><div class="settings-card"><div class="settings-form-grid">
<?php foreach(['session_timeout'=>['Idle session timeout (minutes)',5,240],'login_lock_attempts'=>['Failed attempts before lock',3,20],'login_lock_minutes'=>['Temporary lock duration (minutes)',1,1440]] as $key=>$field): ?><div class="settings-form-group"><label for="<?= $key ?>"><?= $field[0] ?></label><input type="number" step="1" required name="<?= $key ?>" id="<?= $key ?>" min="<?= $field[1] ?>" max="<?= $field[2] ?>" value="<?= settingsEscape($settings[$key]) ?>"></div><?php endforeach; ?></div><p class="small text-muted mt-3">Applies to platform admin sessions and new failed-login locks. Existing locks retain their expiry. IP rate limiting remains enabled. The current admin login uses a password; there is no OTP workflow to configure here.</p></div></section>
<section class="settings-section" data-setting-section="email" id="email"><div class="settings-section-heading"><h3>Email / SMTP</h3></div><div class="settings-card">
<?php if(platformSettingsSmtpIsDemo($settings)): ?><div class="alert alert-info">Demo SMTP configuration. Replace these placeholders with your provider's hostname and credentials to enable connection testing.</div><?php endif; ?>
<div class="settings-form-grid">
<div class="settings-form-group"><label for="smtp_host">SMTP hostname</label><input id="smtp_host" name="smtp_host" maxlength="253" placeholder="smtp.example.com" value="<?= settingsEscape($settings['smtp_host']) ?>"></div>
<div class="settings-form-group"><label for="smtp_port">Port</label><input type="number" id="smtp_port" name="smtp_port" min="1" max="65535" required value="<?= settingsEscape($settings['smtp_port']) ?>"></div>
<div class="settings-form-group"><label for="smtp_username">Username</label><input id="smtp_username" name="smtp_username" maxlength="190" autocomplete="off" value="<?= settingsEscape($settings['smtp_username']) ?>"></div>
<div class="settings-form-group"><label for="smtp_encryption">Encryption</label><select name="smtp_encryption" id="smtp_encryption"><?php foreach(['TLS'=>'STARTTLS','SSL'=>'Implicit TLS / SSL'] as $key=>$label): ?><option value="<?= $key ?>" <?= $settings['smtp_encryption']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<div class="settings-form-group full"><label for="smtp_password">Replace SMTP password</label><input id="smtp_password" type="password" name="smtp_password" maxlength="1024" autocomplete="new-password" value=""><small><?= $settings['smtp_password']!==''?'A credential is saved. Leave blank to keep it.':'No credential saved.' ?> Passwords are encrypted and never returned to this form.</small><label class="mt-2"><input type="checkbox" name="clear_smtp_password" value="1" class="settings-inline-check"> Remove saved password</label></div>
</div><p class="small text-muted">Save changes before testing. Testing connects securely and authenticates using the saved configuration; it sends no email. Automated email delivery is not enabled by this page.</p><button type="submit" name="action" value="test_smtp" form="smtpTestForm" class="btn btn-outline-primary" <?= platformSettingsSmtpIsDemo($settings)?'disabled':'' ?>>Test saved SMTP connection</button></div></section>
<section class="settings-section" data-setting-section="notifications" id="notifications"><div class="settings-section-heading"><h3>Admin Notifications</h3></div><div class="settings-card"><p>Choose which live alerts appear in the shared sidebar for authorized admins.</p>
<?php foreach(['billing_notifications'=>['Billing alerts','Show annual terms expiring within seven days.'],'security_notifications'=>['Security alerts','Show recent security failures and warnings from the admin activity log.']] as $key=>$labels): ?><label class="settings-toggle-row"><span><strong><?= $labels[0] ?></strong><small><?= $labels[1] ?></small></span><input type="checkbox" class="form-check-input" name="<?= $key ?>" value="1" <?= !empty($settings[$key])?'checked':'' ?>></label><?php endforeach; ?></div></section>
<section class="settings-section" data-setting-section="features" id="features"><div class="settings-section-heading"><h3>Platform Features</h3></div><div class="settings-card"><p>The annual subscription includes all Utsav app features. This admin does not apply individual feature restrictions.</p><ul><li>Enquiries, quotations and events</li><li>Payments, inventory and expenses</li><li>Staff management and reports</li></ul><p class="small text-muted">App-side entitlement enforcement and public caterer-registration rules belong to the separate Utsav app and registration services. They are not controlled by this settings page.</p><a href="../users/">Manage users</a> · <a href="../caterers/">Manage caterers</a></div></section>
</fieldset></form>
<section class="settings-section" data-setting-section="system" id="system"><div class="settings-section-heading"><h3>System Status</h3></div><div class="settings-card"><dl class="row"><?php foreach($system as $label=>$value): ?><dt class="col-sm-4"><?= settingsEscape($label) ?></dt><dd class="col-sm-8"><?= settingsEscape($value) ?></dd><?php endforeach; ?></dl><p>Settings are read from the database on each request. There is no persistent configuration cache to clear.</p><a href="./#system" class="btn btn-outline-secondary" id="refreshSystem">Refresh status</a> <a href="../activity/?type=SETTINGS" class="btn btn-outline-secondary">View activity logs</a></div></section>
</div></div>
<form method="post" id="smtpTestForm"><input type="hidden" name="csrf_token" value="<?= settingsEscape(csrfToken()) ?>"></form>
<footer class="dashboard-footer"><span>© <?= date('Y') ?> <?= settingsEscape($settings['platform_name']) ?></span><span>Platform Administration</span></footer>
</main></div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script><script src="../assets/js/admin.js"></script><script src="../assets/js/settings.js"></script>
</body></html>
