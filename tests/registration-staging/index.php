<?php

declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self' https://cdn.jsdelivr.net; script-src 'self'; img-src 'self'; font-src https://cdn.jsdelivr.net; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
$https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$https && !$local) {
    http_response_code(403);
    exit('HTTPS is required for registration.');
}
if ($https) header('Strict-Transport-Security: max-age=31536000');
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    http_response_code(413);
    exit('Request too large.');
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('utsav_registration');
session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
require __DIR__ . '/private/bootstrap.php';
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$errors = [];
$values = [
    'first_name' => '',
    'last_name' => '',
    'display_name' => '',
    'email' => '',
    'contact_number' => '',
    'whatsapp_number' => '',
    'language' => 'en',
    'theme' => 'SYSTEM',
];
$stage = 'access';
$registrationStep = 'profile';
$available = false;
$invitesAvailable = false;
$ready = true;
$successCode = $_SESSION['success_code'] ?? null;
$successRole = $_SESSION['success_role'] ?? 'SUPER_ADMIN';
unset($_SESSION['success_code'], $_SESSION['success_role']);
$registrationType = $_SESSION['registration_type'] ?? 'super_admin';
$registrationRole = $registrationType === 'invite' ? (string)($_SESSION['invite_role'] ?? 'ADMIN') : 'SUPER_ADMIN';
$inviteEmail = $registrationType === 'invite' ? (string)($_SESSION['invite_email'] ?? '') : '';
try {
    $config = configuration();
    $db = database($config);
    $available = bootstrapAvailable($db);
    $invitesAvailable = registrationInvitesAvailable($db);
    if ((int)($_SESSION['grant_expires'] ?? 0) <= time()) {
        unset($_SESSION['grant_expires'], $_SESSION['registration_type'], $_SESSION['invite_id'], $_SESSION['invite_email'], $_SESSION['invite_role']);
        $registrationType = 'super_admin';
        $registrationRole = 'SUPER_ADMIN';
        $inviteEmail = '';
    }
    $stage = isset($_SESSION['grant_expires']) ? 'register' : 'access';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], input('csrf'))) {
            http_response_code(400);
            throw new DomainException('Your form expired. Refresh the page and try again.');
        }
        $action = input('action');
        if ($action === 'register') $registrationStep = input('form_step') === 'security' ? 'security' : 'profile';
        if ($action === 'cancel') {
            unset($_SESSION['grant_expires'], $_SESSION['registration_type'], $_SESSION['invite_id'], $_SESSION['invite_email'], $_SESSION['invite_role']);
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            header('Location: index.php', true, 303);
            exit;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        // Trust the connected peer only, never a client-supplied forwarding header.
        throttle($db, hash_hmac('sha256', 'ip:' . $ip, $config['app_secret']), 10, 900);
        throttle($db, hash_hmac('sha256', 'global', $config['app_secret']), 100, 900);
        if ($action === 'access') {
            unset($_SESSION['grant_expires'], $_SESSION['registration_type'], $_SESSION['invite_id'], $_SESSION['invite_email'], $_SESSION['invite_role']);
            $code = strtoupper(trim((string)input('access_code')));
            $valid = preg_match('/^[A-Z0-9]{16}$/D', $code) === 1;
            $matches = hash_equals($config['super_code_hash'], hash_hmac('sha256', $code, $config['app_secret']));
            if ($valid && $matches && $available) {
                session_regenerate_id(true);
                $_SESSION['grant_expires'] = time() + 600;
                $_SESSION['registration_type'] = 'super_admin';
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                header('Location: index.php', true, 303);
                exit;
            }
            if ($valid) {
                $invite = findRegistrationInvite($db, $code, $config['app_secret']);
                if ($invite) {
                    session_regenerate_id(true);
                    $_SESSION['grant_expires'] = time() + 600;
                    $_SESSION['registration_type'] = 'invite';
                    $_SESSION['invite_id'] = (int)$invite['id'];
                    $_SESSION['invite_email'] = $invite['email'];
                    $_SESSION['invite_role'] = $invite['role'];
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                    header('Location: index.php', true, 303);
                    exit;
                }
            }
            throw new DomainException('The access code is invalid or no longer available.');
        }
        if ($action !== 'register' || !isset($_SESSION['grant_expires'])) throw new DomainException('Verify your access code before registering.');
        foreach ($values as $key => $_) $values[$key] = trim(input($key));
        $registrationType = $_SESSION['registration_type'] ?? 'super_admin';
        if ($registrationType === 'invite') {
            $values['email'] = (string)($_SESSION['invite_email'] ?? '');
            $inviteEmail = $values['email'];
            $registrationRole = (string)($_SESSION['invite_role'] ?? 'ADMIN');
        } else {
            $values['email'] = strtolower($values['email']);
        }
        $data = $values + ['password' => input('password'), 'confirm_password' => input('confirm_password')];
        $errors = validateRegistration($data);
        if (!$errors) {
            if ($registrationType === 'invite') {
                $registered = registerInvitedAdmin($db, (int)($_SESSION['invite_id'] ?? 0), $inviteEmail, $data);
                $adminCode = $registered['admin_code'];
                $registrationRole = $registered['role'];
            } else {
                $adminCode = registerSuperAdmin($db, $data);
                $registrationRole = 'SUPER_ADMIN';
            }
            $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'success_code' => $adminCode, 'success_role' => $registrationRole];
            session_regenerate_id(true);
            header('Location: index.php', true, 303);
            exit;
        }
    }
} catch (DomainException $e) {
    $errors[] = $e->getMessage();
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        $errors[] = 'Registration could not be completed. Check your details or contact the application owner.';
    } else {
        http_response_code(503);
        $ready = false;
        $errors[] = 'Registration is temporarily unavailable. Please contact the application owner.';
        error_log('Utsav registration database failure; SQLSTATE ' . $e->getCode());
    }
} catch (Throwable $e) {
    http_response_code(503);
    $ready = false;
    $errors[] = 'Registration is not ready. Ask the application owner to run the local setup.';
    error_log('Utsav registration configuration failure: ' . get_class($e));
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#123d32">
    <title>Admin registration | Utsav Desk</title>
    <link rel="icon" type="image/png" href="assets/images/logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/registration.css">
    <script src="assets/registration.js?v=20260928-format" defer></script>
</head>

<body>
    <main class="shell">
        <aside class="intro">
            <a class="brand" href="index.php" aria-label="Utsav Desk admin registration"><img src="assets/images/logo.png" alt="Utsav Desk"></a>
            <div class="intro-copy">
                <p class="eyebrow">ADMIN WORKSPACE</p>
                <h1>Set the stage<br>for every celebration.</h1>
                <p class="lead">A considered workspace begins with the right people and the right details.</p>
                <ol class="steps">
                    <li><span>01</span>
                        <p>Verify your access<small>Your private code opens the door.</small></p>
                    </li>
                    <li><span>02</span>
                        <p>Build your profile<small>Set up your administrator account.</small></p>
                    </li>
                </ol>
            </div>
            <p class="aside-footer">UTSAV DESK <span>/</span> ADMINISTRATION</p>
        </aside>
        <section class="panel">
            <div class="panel-inner">
                <?php if ($successCode): ?>
                    <div class="badge">REGISTRATION COMPLETE</div>
                    <h2>Welcome to Utsav Desk.</h2>
                    <p class="muted">Your <?= escape($successRole) ?> account has been created.</p>
                    <div class="notice success"><strong>Account created</strong>
                        <p>Admin ID: <b><?= escape($successCode) ?></b></p>
                        <p>Your access code has been permanently retired.</p>
                    </div>
                    <p class="muted">Use this account in your Utsav Desk admin portal once its sign-in is connected.</p>
                <?php else: ?>
                    <div class="panel-heading">
                        <div class="badge">INVITATION ONLY</div>
                        <p class="eyebrow">STEP <?= $stage === 'register' ? '02 / 02' : '01 / 02' ?></p>
                        <h2><?= $stage === 'register' ? 'Create your account' : 'Your access starts here' ?></h2>
                        <p class="muted"><?= $stage === 'register' ? 'Complete your ' . escape($registrationRole) . ' profile and preferences.' : 'Enter your one-time super admin or invitation code.' ?></p>
                    </div>
                    <?php if ($errors): ?><div class="notice error" role="alert"><?php foreach ($errors as $error): ?><p><?= escape($error) ?></p><?php endforeach; ?></div><?php endif; ?>
                    <?php if ($ready && $stage === 'access' && !$available && !$invitesAvailable): ?>
                        <div class="notice"><strong>Registration is closed.</strong>
                            <p>There are no active registration codes at this time.</p>
                        </div>
                    <?php elseif ($ready && $stage === 'access'): ?>
                        <div class="flow-content">
                            <form class="access-form" method="post" action="index.php"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="access"><label for="access_code">Access or invitation code</label>
                                <div class="input-wrap"><span class="input-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span><input class="code with-icon" id="access_code" name="access_code" type="password" inputmode="text" autocapitalize="characters" spellcheck="false" title="Enter the complete 16-character access code (letters and numbers)." pattern="[A-Za-z0-9]{16}" minlength="16" maxlength="16" autocomplete="off" placeholder="Enter 16-character code" required autofocus aria-describedby="code-help"><button class="visibility-toggle" type="button" data-password-toggle data-visibility-label="access code" aria-label="Show access code" aria-controls="access_code" title="Show access code"><i class="bi bi-eye" aria-hidden="true"></i></button></div><small id="code-help">Use the one-time code provided by your application owner.</small><button class="primary" type="submit">Verify code <span aria-hidden="true">→</span></button>
                            </form>
                            <div class="security-note"><span aria-hidden="true"><i class="bi bi-shield-check"></i></span>
                                <p>Private by design<small>Your code is checked securely and never appears in a link.</small></p>
                            </div>
                        </div>
                    <?php elseif ($ready): ?>
                        <div class="verified"><span>Access verified</span><strong><?= escape($registrationRole) ?></strong></div>
                        <p class="form-help">Complete registration within 10 minutes.</p>
                        <ol class="progress" aria-label="Registration steps">
                            <li data-progress-step="profile"><span>1</span>Profile</li>
                            <li data-progress-step="security"><span>2</span>Preferences &amp; security</li>
                        </ol>
                        <form class="registration-form" method="post" action="index.php" data-registration-form data-start-step="<?= escape($registrationStep) ?>">
                            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="register"><input type="hidden" name="form_step" value="<?= escape($registrationStep) ?>">
                            <section class="form-step" data-registration-step="profile" aria-labelledby="profile-title" <?= $registrationStep === 'profile' ? '' : ' hidden' ?>>
                                <h3 id="profile-title">Your profile</h3>
                                <div class="form-grid profile-grid">
                                    <div class="field"><label for="first_name">First name</label>
                                        <div class="input-wrap"><span class="input-icon" aria-hidden="true"><i class="bi bi-person"></i></span><input class="with-icon" id="first_name" name="first_name" maxlength="100" autocomplete="given-name" value="<?= escape($values['first_name']) ?>" required data-step-required="true"></div>
                                    </div>
                                    <div class="field"><label for="last_name">Last name <em>optional</em></label>
                                        <div class="input-wrap"><span class="input-icon" aria-hidden="true"><i class="bi bi-person"></i></span><input class="with-icon" id="last_name" name="last_name" maxlength="100" autocomplete="family-name" value="<?= escape($values['last_name']) ?>"></div>
                                    </div>
                                    <div class="field"><label for="display_name">Display name <em>optional</em></label>
                                        <div class="input-wrap"><span class="input-icon" aria-hidden="true"><i class="bi bi-person-badge"></i></span><input class="with-icon" id="display_name" name="display_name" maxlength="150" autocomplete="nickname" value="<?= escape($values['display_name']) ?>" placeholder="Defaults to your name"></div>
                                    </div>
                                    <?php if ($registrationType === 'invite'): ?>
                                        <div class="field"><label for="email">Invited email</label>
                                            <div class="input-wrap"><span class="input-icon" aria-hidden="true"><i class="bi bi-envelope"></i></span><input class="with-icon" id="email" name="email" type="email" maxlength="190" value="<?= escape($inviteEmail) ?>" readonly required data-step-required="true"></div>
                                        </div>
                                    <?php else: ?>
                                        <div class="field"><label for="email">Email address</label>
                                            <div class="input-wrap"><span class="input-icon" aria-hidden="true"><i class="bi bi-envelope"></i></span><input class="with-icon" id="email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= escape($values['email']) ?>" placeholder="you@example.com" required data-step-required="true"></div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="field"><label for="contact_number">Phone number <em>optional</em></label>
                                        <div class="input-wrap"><span class="input-icon" aria-hidden="true"><i class="bi bi-telephone"></i></span><input class="with-icon" id="contact_number" name="contact_number" type="tel" title="Use 7–15 digits, optionally starting with +, for example +919876543210." pattern="\+?[0-9]{7,15}" maxlength="16" autocomplete="tel" value="<?= escape($values['contact_number']) ?>" placeholder="+919876543210"></div>
                                    </div>
                                    <div class="field"><label for="whatsapp_number">WhatsApp number <em>optional</em></label>
                                        <div class="input-wrap"><span class="input-icon" aria-hidden="true"><i class="bi bi-whatsapp"></i></span><input class="with-icon" id="whatsapp_number" name="whatsapp_number" type="tel" title="Use 7–15 digits, optionally starting with +, for example +919876543210." pattern="\+?[0-9]{7,15}" maxlength="16" autocomplete="tel" value="<?= escape($values['whatsapp_number']) ?>" placeholder="+919876543210"></div>
                                    </div>
                                </div><button class="primary" type="button" data-next-step>Continue to preferences <span aria-hidden="true">→</span></button>
                            </section>
                            <section class="form-step" data-registration-step="security" aria-labelledby="security-title" <?= $registrationStep === 'security' ? '' : ' hidden' ?>>
                                <h3 id="security-title">Preferences &amp; security</h3>
                                <div class="form-grid security-grid">
                                    <div class="field"><label for="language">Language</label>
                                        <div class="input-wrap"><span class="input-icon" aria-hidden="true"><i class="bi bi-translate"></i></span><select class="with-icon" id="language" name="language" required data-step-required="true"><?php foreach (['en' => 'English', 'hi' => 'Hindi', 'bn' => 'Bengali', 'gu' => 'Gujarati', 'mr' => 'Marathi', 'ta' => 'Tamil', 'te' => 'Telugu'] as $language => $languageLabel): ?><option value="<?= escape($language) ?>" <?= $values['language'] === $language ? ' selected' : '' ?>><?= escape($languageLabel) ?></option><?php endforeach; ?></select></div>
                                    </div>
                                    <fieldset class="field field-wide theme-field">
                                        <legend>Appearance</legend>
                                        <div class="theme-options"><?php foreach (['SYSTEM' => ['System', 'bi-circle-half'], 'LIGHT' => ['Light', 'bi-sun'], 'DARK' => ['Dark', 'bi-moon']] as $theme => [$themeLabel, $themeIcon]): ?><label class="theme-option"><input type="radio" name="theme" value="<?= escape($theme) ?>" <?= $values['theme'] === $theme ? ' checked' : '' ?> required data-step-required="true"><i class="bi <?= escape($themeIcon) ?>" aria-hidden="true"></i><span><?= escape($themeLabel) ?></span></label><?php endforeach; ?></div>
                                    </fieldset>
                                    <div class="field"><label for="password">Password</label>
                                        <div class="input-wrap"><span class="input-icon" aria-hidden="true"><i class="bi bi-lock"></i></span><input class="with-icon with-visibility" id="password" name="password" type="password" minlength="15" maxlength="72" autocomplete="new-password" required data-step-required="true" aria-describedby="password-help"><button class="visibility-toggle" type="button" data-password-toggle data-visibility-label="password" aria-label="Show password" aria-controls="password" title="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button></div><small id="password-help">15+ characters; maximum 72 bytes.</small>
                                    </div>
                                    <div class="field"><label for="confirm_password">Confirm password</label>
                                        <div class="input-wrap"><span class="input-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span><input class="with-icon with-visibility" id="confirm_password" name="confirm_password" type="password" minlength="15" maxlength="72" autocomplete="new-password" required data-step-required="true"><button class="visibility-toggle" type="button" data-password-toggle data-visibility-label="confirm password" aria-label="Show confirm password" aria-controls="confirm_password" title="Show confirm password"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
                                    </div>
                                </div>
                                <div class="step-actions"><button class="back" type="button" data-previous-step>Back to profile</button><button class="primary" type="submit">Create <?= escape($registrationRole) ?> account <span aria-hidden="true">→</span></button></div>
                            </section>
                        </form>
                        <form class="cancel-form" method="post" action="index.php"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="cancel"><button class="back" type="submit">Use another access code</button></form>
                <?php endif;
                endif; ?>
                <footer>Utsav Desk <span>/</span> Admin registration</footer>
            </div>
        </section>
    </main>
</body>

</html>