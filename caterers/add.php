<?php

declare(strict_types=1);

require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../common/cms_db.php';

$admin = $_SESSION['PLATFORM_ADMIN'];
$values = [
    'catering_code' => '',
    'catering_name' => '',
    'business_tagline' => '',
    'email' => '',
    'primary_contact' => '',
    'secondary_contact' => '',
    'whatsapp_number' => '',
    'address' => '',
    'city' => '',
    'state' => '',
    'pincode' => '',
    'country' => 'India',
    'gst_number' => '',
    'website' => '',
    'facebook_url' => '',
    'instagram_url' => '',
    'currency' => 'INR',
    'timezone' => 'Asia/Kolkata',
    'is_active' => 1,
    'status' => 1,
    'fassai_number' => '',
];
$errors = [];
$formError = '';

function nextCateringCode(PDO $cms): string
{
    $codeCheck = $cms->prepare('SELECT 1 FROM catering_info WHERE catering_code = :code LIMIT 1');
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $candidate = 'CAT';

        for ($character = 0; $character < 7; $character++) {
            $candidate .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $codeCheck->execute(['code' => $candidate]);

        if (!$codeCheck->fetchColumn()) {
            return $candidate;
        }
    }

    throw new RuntimeException('A unique catering code could not be generated.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'generate_code') {
    header('Content-Type: application/json; charset=utf-8');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Your form session expired. Refresh this page and try again.']);
        exit;
    }

    try {
        echo json_encode(['success' => true, 'code' => nextCateringCode(cmsDatabaseConnection())]);
    } catch (Throwable $exception) {
        error_log('CMS catering code generation failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'A unique code could not be generated. Try again.']);
    }

    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $formError = 'Your form session expired. Refresh this page and try again.';
    } else {
        foreach ($values as $field => $default) {
            if (in_array($field, ['is_active', 'status'], true)) {
                $values[$field] = isset($_POST[$field]) ? 1 : 0;
                continue;
            }

            $postedValue = $_POST[$field] ?? '';
            $values[$field] = is_string($postedValue) ? trim($postedValue) : '';
        }

        $values['catering_code'] = strtoupper($values['catering_code']);
        $values['currency'] = strtoupper($values['currency']);

        if (!preg_match('/^CAT[A-Z0-9]{7}$/', $values['catering_code'])) {
            $errors['catering_code'] = 'Code must start with CAT and contain exactly 7 letters or digits after it.';
        }

        if ($values['catering_name'] === '' || mb_strlen($values['catering_name'], 'UTF-8') > 200) {
            $errors['catering_name'] = 'Enter a business name up to 200 characters.';
        }

        $lengthLimits = [
            'business_tagline' => 255,
            'email' => 150,
            'primary_contact' => 20,
            'secondary_contact' => 20,
            'whatsapp_number' => 20,
            'city' => 100,
            'state' => 100,
            'pincode' => 10,
            'country' => 100,
            'gst_number' => 30,
            'website' => 255,
            'facebook_url' => 255,
            'instagram_url' => 255,
            'currency' => 10,
            'timezone' => 100,
            'fassai_number' => 30,
        ];

        foreach ($lengthLimits as $field => $limit) {
            if (mb_strlen($values[$field], 'UTF-8') > $limit) {
                $errors[$field] = 'Maximum length is ' . $limit . ' characters.';
            }
        }

        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        foreach (['website', 'facebook_url', 'instagram_url'] as $urlField) {
            if ($values[$urlField] !== '' && (
                filter_var($values[$urlField], FILTER_VALIDATE_URL) === false
                || !in_array(strtolower((string) parse_url($values[$urlField], PHP_URL_SCHEME)), ['http', 'https'], true)
            )) {
                $errors[$urlField] = 'Enter a valid URL starting with http:// or https://.';
            }
        }

        if (!preg_match('/^[A-Z]{3,10}$/', $values['currency'])) {
            $errors['currency'] = 'Use a valid currency code, such as INR.';
        }

        if ($values['country'] === '') {
            $errors['country'] = 'Country is required.';
        }

        try {
            new DateTimeZone($values['timezone']);
        } catch (Throwable $exception) {
            $errors['timezone'] = 'Enter a valid time zone, such as Asia/Kolkata.';
        }

        if ($errors === []) {
            try {
                $cms = cmsDatabaseConnection();
                $codeCheck = $cms->prepare('SELECT 1 FROM catering_info WHERE catering_code = :code LIMIT 1');
                $codeCheck->execute(['code' => $values['catering_code']]);

                if ($codeCheck->fetchColumn()) {
                    $errors['catering_code'] = 'This catering code is already in use.';
                } else {
                    $insert = $cms->prepare(
                        'INSERT INTO catering_info (
                            catering_code, catering_name, business_tagline, email,
                            primary_contact, secondary_contact, whatsapp_number,
                            address, city, state, pincode, country, gst_number,
                            website, facebook_url, instagram_url, currency, timezone,
                            is_active, status, fassai_number
                        ) VALUES (
                            :catering_code, :catering_name, :business_tagline, :email,
                            :primary_contact, :secondary_contact, :whatsapp_number,
                            :address, :city, :state, :pincode, :country, :gst_number,
                            :website, :facebook_url, :instagram_url, :currency, :timezone,
                            :is_active, :status, :fassai_number
                        )'
                    );
                    $nullableFields = [
                        'business_tagline',
                        'email',
                        'primary_contact',
                        'secondary_contact',
                        'whatsapp_number',
                        'address',
                        'city',
                        'state',
                        'pincode',
                        'gst_number',
                        'website',
                        'facebook_url',
                        'instagram_url',
                        'fassai_number',
                    ];
                    $params = $values;

                    foreach ($nullableFields as $field) {
                        $params[$field] = $params[$field] === '' ? null : $params[$field];
                    }

                    $insert->execute($params);
                    header('Location: ' . APP_BASE_URL . '/caterers/?created=1', true, 303);
                    exit;
                }
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $errors['catering_code'] = 'This catering code is already in use.';
                } else {
                    error_log('CMS caterer create failed: ' . $exception->getMessage());
                    $formError = 'The caterer could not be saved. Check the CMS database connection and try again.';
                }
            } catch (Throwable $exception) {
                error_log('CMS caterer create failed: ' . $exception->getMessage());
                $formError = 'The caterer could not be saved. Check the CMS database connection and try again.';
            }
        }
    }
}

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$fieldError = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? '<div class="invalid-feedback">' . htmlspecialchars($errors[$field], ENT_QUOTES, 'UTF-8') . '</div>' : '';
};
?>
<!doctype html>
<html lang="en">
<?php renderAdminHead($pageTitle, $pageStylesheets); ?>

<body class="admin-body">
    <?php require __DIR__ . '/../components/sidebar.php'; ?>

    <div class="admin-main">
        <header class="admin-topbar">
            <div class="topbar-left">
                <button type="button" class="mobile-menu-button" id="sidebarOpen" aria-label="Open navigation">
                    <i class="bi bi-list"></i>
                </button>
                <div class="topbar-title">
                    <h1>Add Caterer</h1>
                    <span>Create a catering business profile</span>
                </div>
            </div>
            <div class="topbar-actions">
                <div class="dropdown">
                    <button type="button" class="topbar-profile" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="topbar-avatar"><?= $escape(strtoupper(substr($admin['NAME'], 0, 2))) ?></div>
                        <div class="topbar-profile-info d-none d-md-block">
                            <strong><?= $escape($admin['NAME']) ?></strong>
                            <span><?= $escape(ucwords(strtolower(str_replace('_', ' ', $admin['ROLE'])))) ?></span>
                        </div>
                        <i class="bi bi-chevron-down"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= $escape(APP_BASE_URL . '/settings/') ?>"><i class="bi bi-gear me-2"></i>Settings</a></li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li>
                            <form method="post" action="<?= $escape(APP_BASE_URL . '/logout.php') ?>">
                                <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                <button type="submit" class="dropdown-item text-danger border-0 bg-transparent text-start w-100">
                                    <i class="bi bi-box-arrow-right me-2"></i>Sign Out
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="admin-content">
            <nav class="caterer-add-breadcrumb" aria-label="Breadcrumb">
                <a href="<?= $escape(APP_BASE_URL . '/caterers/') ?>">Caterers</a>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <span>Add Caterer</span>
            </nav>

            <section class="caterer-add-heading">
                <div>
                    <h2>Add a caterer</h2>
                    <p>Enter the business details stored in the catering profile.</p>
                </div>
                <a class="btn btn-outline-secondary" href="<?= $escape(APP_BASE_URL . '/caterers/') ?>">
                    <i class="bi bi-arrow-left me-1"></i>Back to caterers
                </a>
            </section>

            <?php if ($formError !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= $escape($formError) ?></div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-circle me-1"></i>Please review the highlighted fields and try again.
                </div>
            <?php endif; ?>

            <form method="post" action="" class="caterer-add-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">

                <section class="caterer-add-section" aria-labelledby="businessDetailsHeading">
                    <div class="caterer-add-section-heading">
                        <span class="caterer-add-icon"><i class="bi bi-building"></i></span>
                        <div>
                            <h3 id="businessDetailsHeading">Business details</h3>
                            <p>Identity and registration details for this catering business.</p>
                        </div>
                    </div>
                    <div class="caterer-add-fields">
                        <div class="caterer-add-field">
                            <label for="catering_code">Catering code <span class="required-mark">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                                <input class="form-control<?= isset($errors['catering_code']) ? ' is-invalid' : '' ?>" id="catering_code" name="catering_code" value="<?= $escape($values['catering_code']) ?>" maxlength="10" placeholder="CAT1A2B3C4" required autocomplete="off" aria-describedby="catering_code_error">
                                <button class="btn btn-outline-secondary caterer-generate-code" type="button" id="generateCateringCode" title="Find the next unused catering code">
                                    <i class="bi bi-arrow-repeat me-1"></i>Generate
                                </button>
                                <?= $fieldError('catering_code') ?>
                            </div>
                            <div class="form-text" id="catering_code_error">CAT followed by 7 letters or digits. Generated codes are checked against existing records.</div>
                            <div class="form-text" id="cateringCodeStatus" aria-live="polite"></div>
                        </div>
                        <div class="caterer-add-field">
                            <label for="catering_name">Business name <span class="required-mark">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-shop"></i></span>
                                <input class="form-control<?= isset($errors['catering_name']) ? ' is-invalid' : '' ?>" id="catering_name" name="catering_name" value="<?= $escape($values['catering_name']) ?>" maxlength="200" placeholder="Business name" required>
                                <?= $fieldError('catering_name') ?>
                            </div>
                        </div>
                        <div class="caterer-add-field caterer-add-field-wide">
                            <label for="business_tagline">Business tagline</label>
                            <input class="form-control<?= isset($errors['business_tagline']) ? ' is-invalid' : '' ?>" id="business_tagline" name="business_tagline" value="<?= $escape($values['business_tagline']) ?>" maxlength="255" placeholder="A short description of the business">
                            <?= $fieldError('business_tagline') ?>
                        </div>
                        <div class="caterer-add-field">
                            <label for="gst_number">GST number</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-receipt"></i></span>
                                <input class="form-control<?= isset($errors['gst_number']) ? ' is-invalid' : '' ?>" id="gst_number" name="gst_number" value="<?= $escape($values['gst_number']) ?>" maxlength="30" autocomplete="off">
                                <?= $fieldError('gst_number') ?>
                            </div>
                        </div>
                        <div class="caterer-add-field">
                            <label for="fassai_number">FSSAI number</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-patch-check"></i></span>
                                <input class="form-control<?= isset($errors['fassai_number']) ? ' is-invalid' : '' ?>" id="fassai_number" name="fassai_number" value="<?= $escape($values['fassai_number']) ?>" maxlength="30" autocomplete="off">
                                <?= $fieldError('fassai_number') ?>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="caterer-add-section" aria-labelledby="contactDetailsHeading">
                    <div class="caterer-add-section-heading">
                        <span class="caterer-add-icon contact"><i class="bi bi-person-lines-fill"></i></span>
                        <div>
                            <h3 id="contactDetailsHeading">Contact details</h3>
                            <p>Business email and phone numbers.</p>
                        </div>
                    </div>
                    <div class="caterer-add-fields">
                        <div class="caterer-add-field">
                            <label for="email">Email</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>" id="email" name="email" value="<?= $escape($values['email']) ?>" maxlength="150" autocomplete="email" placeholder="business@example.com">
                                <?= $fieldError('email') ?>
                            </div>
                        </div>
                        <div class="caterer-add-field">
                            <label for="primary_contact">Primary contact</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                <input type="tel" class="form-control<?= isset($errors['primary_contact']) ? ' is-invalid' : '' ?>" id="primary_contact" name="primary_contact" value="<?= $escape($values['primary_contact']) ?>" maxlength="20" autocomplete="tel">
                                <?= $fieldError('primary_contact') ?>
                            </div>
                        </div>
                        <div class="caterer-add-field">
                            <label for="secondary_contact">Secondary contact</label>
                            <input type="tel" class="form-control<?= isset($errors['secondary_contact']) ? ' is-invalid' : '' ?>" id="secondary_contact" name="secondary_contact" value="<?= $escape($values['secondary_contact']) ?>" maxlength="20">
                            <?= $fieldError('secondary_contact') ?>
                        </div>
                        <div class="caterer-add-field">
                            <label for="whatsapp_number">WhatsApp number</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-whatsapp"></i></span>
                                <input type="tel" class="form-control<?= isset($errors['whatsapp_number']) ? ' is-invalid' : '' ?>" id="whatsapp_number" name="whatsapp_number" value="<?= $escape($values['whatsapp_number']) ?>" maxlength="20">
                                <?= $fieldError('whatsapp_number') ?>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="caterer-add-section" aria-labelledby="addressHeading">
                    <div class="caterer-add-section-heading">
                        <span class="caterer-add-icon location"><i class="bi bi-geo-alt"></i></span>
                        <div>
                            <h3 id="addressHeading">Business address</h3>
                            <p>Location used for the caterer profile.</p>
                        </div>
                    </div>
                    <div class="caterer-add-fields">
                        <div class="caterer-add-field caterer-add-field-wide">
                            <label for="address">Address</label>
                            <textarea class="form-control<?= isset($errors['address']) ? ' is-invalid' : '' ?>" id="address" name="address" rows="3" placeholder="Street, building, locality"><?= $escape($values['address']) ?></textarea>
                            <?= $fieldError('address') ?>
                        </div>
                        <div class="caterer-add-field">
                            <label for="city">City</label>
                            <input class="form-control<?= isset($errors['city']) ? ' is-invalid' : '' ?>" id="city" name="city" value="<?= $escape($values['city']) ?>" maxlength="100">
                            <?= $fieldError('city') ?>
                        </div>
                        <div class="caterer-add-field">
                            <label for="state">State</label>
                            <input class="form-control<?= isset($errors['state']) ? ' is-invalid' : '' ?>" id="state" name="state" value="<?= $escape($values['state']) ?>" maxlength="100">
                            <?= $fieldError('state') ?>
                        </div>
                        <div class="caterer-add-field">
                            <label for="pincode">Pincode</label>
                            <input class="form-control<?= isset($errors['pincode']) ? ' is-invalid' : '' ?>" id="pincode" name="pincode" value="<?= $escape($values['pincode']) ?>" maxlength="10" inputmode="numeric">
                            <?= $fieldError('pincode') ?>
                        </div>
                        <div class="caterer-add-field">
                            <label for="country">Country <span class="required-mark">*</span></label>
                            <input class="form-control<?= isset($errors['country']) ? ' is-invalid' : '' ?>" id="country" name="country" value="<?= $escape($values['country']) ?>" maxlength="100" required>
                            <?= $fieldError('country') ?>
                        </div>
                    </div>
                </section>

                <section class="caterer-add-section" aria-labelledby="onlineDetailsHeading">
                    <div class="caterer-add-section-heading">
                        <span class="caterer-add-icon online"><i class="bi bi-globe2"></i></span>
                        <div>
                            <h3 id="onlineDetailsHeading">Online presence</h3>
                            <p>Optional website and social profile links.</p>
                        </div>
                    </div>
                    <div class="caterer-add-fields">
                        <div class="caterer-add-field">
                            <label for="website">Website</label>
                            <input type="url" class="form-control<?= isset($errors['website']) ? ' is-invalid' : '' ?>" id="website" name="website" value="<?= $escape($values['website']) ?>" maxlength="255" placeholder="https://">
                            <?= $fieldError('website') ?>
                        </div>
                        <div class="caterer-add-field">
                            <label for="facebook_url"><i class="bi bi-facebook me-1"></i>Facebook URL</label>
                            <input type="url" class="form-control<?= isset($errors['facebook_url']) ? ' is-invalid' : '' ?>" id="facebook_url" name="facebook_url" value="<?= $escape($values['facebook_url']) ?>" maxlength="255" placeholder="https://">
                            <?= $fieldError('facebook_url') ?>
                        </div>
                        <div class="caterer-add-field">
                            <label for="instagram_url"><i class="bi bi-instagram me-1"></i>Instagram URL</label>
                            <input type="url" class="form-control<?= isset($errors['instagram_url']) ? ' is-invalid' : '' ?>" id="instagram_url" name="instagram_url" value="<?= $escape($values['instagram_url']) ?>" maxlength="255" placeholder="https://">
                            <?= $fieldError('instagram_url') ?>
                        </div>
                    </div>
                </section>

                <section class="caterer-add-section" aria-labelledby="preferencesHeading">
                    <div class="caterer-add-section-heading">
                        <span class="caterer-add-icon preferences"><i class="bi bi-sliders"></i></span>
                        <div>
                            <h3 id="preferencesHeading">Regional settings</h3>
                            <p>Defaults used for this caterer’s workspace.</p>
                        </div>
                    </div>
                    <div class="caterer-add-fields">
                        <div class="caterer-add-field">
                            <label for="currency">Currency <span class="required-mark">*</span></label>
                            <input class="form-control<?= isset($errors['currency']) ? ' is-invalid' : '' ?>" id="currency" name="currency" value="<?= $escape($values['currency']) ?>" maxlength="10" required>
                            <?= $fieldError('currency') ?>
                        </div>
                        <div class="caterer-add-field">
                            <label for="timezone">Time zone <span class="required-mark">*</span></label>
                            <input class="form-control<?= isset($errors['timezone']) ? ' is-invalid' : '' ?>" id="timezone" name="timezone" value="<?= $escape($values['timezone']) ?>" maxlength="100" required>
                            <?= $fieldError('timezone') ?>
                        </div>
                    </div>
                </section>

                <section class="caterer-add-section caterer-add-status-section" aria-labelledby="statusHeading">
                    <div class="caterer-add-section-heading">
                        <span class="caterer-add-icon status"><i class="bi bi-shield-check"></i></span>
                        <div>
                            <h3 id="statusHeading">Account status</h3>
                            <p>Initial status flags from the catering profile.</p>
                        </div>
                    </div>
                    <div class="caterer-add-status-fields">
                        <label class="caterer-add-switch" for="is_active">
                            <span><i class="bi bi-person-check"></i><span><strong>Enabled</strong><small>Allow this caterer account to operate.</small></span></span>
                            <input type="checkbox" id="is_active" name="is_active" value="1" <?= $values['is_active'] ? 'checked' : '' ?>>
                        </label>
                        <label class="caterer-add-switch" for="status">
                            <span><i class="bi bi-toggle-on"></i><span><strong>Record active</strong><small>Include this record in active CMS listings.</small></span></span>
                            <input type="checkbox" id="status" name="status" value="1" <?= $values['status'] ? 'checked' : '' ?>>
                        </label>
                    </div>
                </section>

                <div class="caterer-add-actions">
                    <a class="btn btn-outline-secondary" href="<?= $escape(APP_BASE_URL . '/caterers/') ?>">Cancel</a>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check2-circle me-1"></i>Create caterer
                    </button>
                </div>
            </form>

            <footer class="dashboard-footer">
                <span>© <?= date('Y') ?> UtsavDesk</span>
                <span>Platform Administration</span>
            </footer>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const codeInput = document.getElementById('catering_code');
            const generateCodeButton = document.getElementById('generateCateringCode');
            const codeStatus = document.getElementById('cateringCodeStatus');
            const form = document.querySelector('.caterer-add-form');

            generateCodeButton.addEventListener('click', async function() {
                generateCodeButton.disabled = true;
                codeStatus.className = 'form-text';
                codeStatus.textContent = 'Checking existing catering codes…';

                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                        },
                        body: new URLSearchParams({
                            action: 'generate_code',
                            csrf_token: form.querySelector('[name="csrf_token"]').value
                        })
                    });
                    const result = await response.json();

                    if (!response.ok || !result.success || !result.code) {
                        throw new Error(result.message || 'A code could not be generated.');
                    }

                    codeInput.value = result.code;
                    codeInput.classList.remove('is-invalid');
                    codeStatus.classList.add('text-success');
                    codeStatus.textContent = result.code + ' is available. It will be checked again when saved.';
                } catch (error) {
                    codeStatus.classList.add('text-danger');
                    codeStatus.textContent = error.message || 'A code could not be generated. Try again.';
                } finally {
                    generateCodeButton.disabled = false;
                }
            });

            const sidebar = document.getElementById('adminSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const openButton = document.getElementById('sidebarOpen');
            const closeButton = document.getElementById('sidebarClose');

            function setSidebarOpen(isOpen) {
                sidebar.classList.toggle('sidebar-open', isOpen);
                overlay.classList.toggle('active', isOpen);
            }

            openButton.addEventListener('click', function() {
                setSidebarOpen(true);
            });
            closeButton.addEventListener('click', function() {
                setSidebarOpen(false);
            });
            overlay.addEventListener('click', function() {
                setSidebarOpen(false);
            });
        });
    </script>
</body>

</html>