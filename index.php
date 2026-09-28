<?php

declare(strict_types=1);

require_once __DIR__ . '/common/auth.php';

sendSecurityHeaders();
startSecureSession();
csrfToken();

if (!empty($_SESSION['PLATFORM_ADMIN']['IS_LOGGED_IN'])) {
    header('Location: ./dashboard/');
    exit;
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <meta
        name="robots"
        content="noindex,nofollow">

    <title>Platform Admin Login | UtsavDesk</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(APP_BASE_URL . '/assets/images/logo.png', ENT_QUOTES, 'UTF-8') ?>">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <link
        rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">

    <style>
        :root {
            --primary: #087f73;
            --primary-dark: #065e56;
            --primary-soft: #e7f5f1;
            --secondary: #4599c8;

            --text: #18181b;
            --text-secondary: #52525b;
            --text-muted: #8b8b95;

            --border: #e6e6ea;
            --background: #f3f6fa;
            --surface: #ffffff;

            --success: #16835d;
            --danger: #c73545;

            --shadow:
                0 25px 70px rgba(22, 22, 30, .10);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: "Inter", sans-serif;
        }

        button,
        input {
            font-family: inherit;
        }


        /* =====================================================
           PAGE
           ===================================================== */

        .auth-page {
            min-height: 100vh;
            display: grid;
            grid-template-columns: minmax(420px, 1fr) minmax(500px, .92fr);
        }


        /* =====================================================
           LEFT
           ===================================================== */

        .auth-visual {
            position: relative;
            min-height: 100vh;
            padding: 42px 52px;
            overflow: hidden;
            background:
                radial-gradient(circle at 18% 15%,
                    rgba(18, 180, 224, .20),
                    transparent 28%),
                radial-gradient(circle at 85% 80%,
                    rgba(255, 255, 255, .10),
                    transparent 28%),
                linear-gradient(145deg,
                    #102235 0%,
                    #183e51 45%,
                    #087f73 100%);
            color: #fff;
            display: flex;
            flex-direction: column;
        }

        .auth-visual::before,
        .auth-visual::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, .09);
        }

        .auth-visual::before {
            width: 480px;
            height: 480px;
            right: -210px;
            top: -160px;
        }

        .auth-visual::after {
            width: 340px;
            height: 340px;
            left: -170px;
            bottom: -130px;
        }


        /* BRAND */

        .auth-brand {
            position: relative;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: #fff;
            text-decoration: none;
        }

        .auth-brand-logo {
            width: 45px;
            height: 45px;
            border: 1px solid rgba(255, 255, 255, .20);
            border-radius: 13px;
            background: rgba(255, 255, 255, .12);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .auth-brand-name {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -.6px;
        }

        .auth-brand-name span {
            font-weight: 400;
        }

        .auth-brand-label {
            margin-top: 1px;
            color: rgba(255, 255, 255, .62);
            font-size: 10px;
        }


        /* HERO */

        .auth-hero {
            position: relative;
            z-index: 2;
            max-width: 600px;
            margin: auto 0;
            padding: 70px 0;
        }

        .auth-eyebrow {
            width: fit-content;
            margin-bottom: 22px;
            padding: 7px 11px;
            border: 1px solid rgba(255, 255, 255, .15);
            border-radius: 100px;
            background: rgba(255, 255, 255, .08);
            color: rgba(255, 255, 255, .84);
            font-size: 10px;
            font-weight: 600;
        }

        .auth-hero h1 {
            max-width: 540px;
            margin: 0;
            font-size: clamp(36px, 4vw, 58px);
            font-weight: 750;
            line-height: 1.08;
            letter-spacing: -2.4px;
        }

        .auth-hero>p {
            max-width: 510px;
            margin: 20px 0 0;
            color: rgba(255, 255, 255, .67);
            font-size: 14px;
            line-height: 1.8;
        }


        /* FEATURES */

        .auth-features {
            margin-top: 40px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .auth-feature {
            padding: 15px;
            border: 1px solid rgba(255, 255, 255, .10);
            border-radius: 13px;
            background: rgba(255, 255, 255, .07);
            backdrop-filter: blur(10px);
        }

        .auth-feature-icon {
            width: 32px;
            height: 32px;
            margin-bottom: 11px;
            border-radius: 8px;
            background: rgba(255, 255, 255, .12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .auth-feature strong {
            display: block;
            margin-bottom: 4px;
            font-size: 11px;
        }

        .auth-feature span {
            color: rgba(255, 255, 255, .55);
            font-size: 9px;
            line-height: 1.5;
        }

        .auth-visual-footer {
            position: relative;
            z-index: 2;
            color: rgba(255, 255, 255, .45);
            font-size: 9px;
        }


        /* =====================================================
           RIGHT
           ===================================================== */

        .auth-form-section {
            min-height: 100vh;
            padding: 35px;
            background:
                radial-gradient(circle at 90% 10%,
                    rgba(160, 16, 61, .035),
                    transparent 25%),
                #fff;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .auth-form-container {
            width: 100%;
            max-width: 420px;
        }

        .auth-mobile-brand {
            display: none;
        }

        .auth-security-label {
            width: fit-content;
            margin-bottom: 17px;
            padding: 6px 9px;
            border-radius: 7px;
            background: #f3f4f6;
            color: var(--text-secondary);
            font-size: 9px;
            font-weight: 600;
        }

        .auth-security-label i {
            margin-right: 4px;
            color: var(--success);
        }

        .auth-form-header {
            margin-bottom: 29px;
        }

        .auth-form-header h2 {
            margin: 0 0 8px;
            font-size: 29px;
            font-weight: 750;
            letter-spacing: -1px;
        }

        .auth-form-header p {
            margin: 0;
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.65;
        }


        /* =====================================================
           ALERT
           ===================================================== */

        .auth-alert {
            display: none;
            margin-bottom: 17px;
            padding: 11px 12px;
            border-radius: 9px;
            font-size: 10px;
        }

        .auth-alert.error {
            border: 1px solid #f2cbd0;
            background: #fff3f4;
            color: var(--danger);
        }

        .auth-alert.success {
            border: 1px solid #cce8dd;
            background: #f1fbf7;
            color: var(--success);
        }

        .auth-alert.show {
            display: flex;
            align-items: center;
            gap: 7px;
        }


        /* =====================================================
           FORM
           ===================================================== */

        .auth-field {
            margin-bottom: 17px;
        }

        .auth-field label {
            display: block;
            margin-bottom: 7px;
            color: var(--text-secondary);
            font-size: 10px;
            font-weight: 650;
        }

        .auth-field-wrap {
            position: relative;
        }

        .auth-field-icon {
            position: absolute;
            top: 50%;
            left: 14px;
            transform: translateY(-50%);
            color: #9a9aa3;
            font-size: 14px;
        }

        .auth-field input {
            width: 100%;
            height: 49px;
            padding: 0 14px 0 42px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            outline: none;
            color: var(--text);
            font-size: 12px;
            transition: .18s ease;
        }

        .auth-field input::placeholder {
            color: #b1b1b8;
        }

        .auth-field input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(160, 16, 61, .06);
        }

        .auth-field input.is-invalid {
            border-color: var(--danger);
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            width: 30px;
            height: 30px;
            padding: 0;
            border: 0;
            background: transparent;
            transform: translateY(-50%);
            color: #8d8d96;
        }

        .password-field input {
            padding-right: 45px;
        }


        /* OPTIONS */

        .auth-options {
            margin: -2px 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .auth-check {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--text-secondary);
            font-size: 9px;
            cursor: pointer;
        }

        .auth-check input {
            width: 14px;
            height: 14px;
            accent-color: var(--primary);
        }

        .auth-link-button {
            padding: 0;
            border: 0;
            background: transparent;
            color: var(--primary);
            font-size: 9px;
            font-weight: 650;
        }


        /* BUTTON */

        .auth-submit {
            width: 100%;
            height: 49px;
            border: 0;
            border-radius: 10px;
            background: var(--primary);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            box-shadow: 0 8px 20px rgba(160, 16, 61, .18);
            transition: .18s ease;
        }

        .auth-submit:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .auth-submit:disabled {
            opacity: .7;
            transform: none;
        }


        /* =====================================================
           OTP
           ===================================================== */

        .otp-section {
            display: none;
        }

        .otp-section.show {
            display: block;
        }

        .login-section.hide {
            display: none;
        }

        .otp-email {
            margin: -14px 0 22px;
            padding: 10px 12px;
            border-radius: 8px;
            background: #f3f6fa;
            color: var(--text-secondary);
            font-size: 9px;
        }

        .otp-email strong {
            color: var(--text);
        }

        .otp-boxes {
            margin: 7px 0 20px;
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 8px;
        }

        .otp-input {
            width: 100%;
            height: 51px;
            border: 1px solid var(--border);
            border-radius: 9px;
            outline: none;
            text-align: center;
            font-size: 18px;
            font-weight: 700;
        }

        .otp-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(160, 16, 61, .05);
        }

        .otp-options {
            margin-top: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 9px;
        }

        .otp-options span {
            color: var(--text-muted);
        }


        /* =====================================================
           FOOTER
           ===================================================== */

        .auth-form-footer {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid #f0f0f2;
            color: var(--text-muted);
            font-size: 9px;
            line-height: 1.6;
            text-align: center;
        }

        .auth-form-footer i {
            margin-right: 3px;
            color: var(--success);
        }


        /* =====================================================
           RESPONSIVE
           ===================================================== */

        @media (max-width: 991.98px) {

            .auth-page {
                display: block;
            }

            .auth-visual {
                display: none;
            }

            .auth-form-section {
                padding: 28px 20px;
            }

            .auth-mobile-brand {
                margin-bottom: 45px;
                display: flex;
                align-items: center;
                gap: 10px;
                text-decoration: none;
            }

            .auth-mobile-logo {
                width: 40px;
                height: 40px;
                border-radius: 11px;
                background: var(--primary);
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 14px;
                font-weight: 800;
            }

            .auth-mobile-brand strong {
                display: block;
                color: var(--text);
                font-size: 17px;
            }

            .auth-mobile-brand strong span {
                font-weight: 400;
            }

            .auth-mobile-brand small {
                display: block;
                color: var(--text-muted);
                font-size: 8px;
            }

        }


        @media (max-width: 575.98px) {

            .auth-form-section {
                align-items: flex-start;
                padding: 22px 18px;
            }

            .auth-mobile-brand {
                margin-bottom: 38px;
            }

            .auth-form-header h2 {
                font-size: 25px;
            }

            .otp-boxes {
                gap: 5px;
            }

            .otp-input {
                height: 47px;
            }

        }
    </style>
    <link rel="stylesheet" href="assets/css/theme.css?v=20260928">

</head>

<body>

    <div class="auth-page">


        <!-- =====================================================
         LEFT
         ===================================================== -->

        <section class="auth-visual">

            <a href="./" class="auth-brand">

                <div class="auth-brand-logo">
                    UD
                </div>

                <div>

                    <div class="auth-brand-name">
                        Utsav<span>Desk</span>
                    </div>

                    <div class="auth-brand-label">
                        Platform Administration
                    </div>

                </div>

            </a>


            <div class="auth-hero">

                <div class="auth-eyebrow">
                    <i class="bi bi-shield-check me-1"></i>
                    UtsavDesk Administration
                </div>

                <h1>
                    Manage the entire platform from one place.
                </h1>

                <p>
                    Monitor caterers, subscriptions, billing, users,
                    support requests and platform activity through the
                    UtsavDesk administration console.
                </p>


                <div class="auth-features">

                    <div class="auth-feature">

                        <div class="auth-feature-icon">
                            <i class="bi bi-buildings"></i>
                        </div>

                        <strong>
                            Caterers
                        </strong>

                        <span>
                            Manage tenant accounts and platform access.
                        </span>

                    </div>


                    <div class="auth-feature">

                        <div class="auth-feature-icon">
                            <i class="bi bi-credit-card"></i>
                        </div>

                        <strong>
                            Billing
                        </strong>

                        <span>
                            Track subscriptions and platform revenue.
                        </span>

                    </div>


                    <div class="auth-feature">

                        <div class="auth-feature-icon">
                            <i class="bi bi-shield-lock"></i>
                        </div>

                        <strong>
                            Security
                        </strong>

                        <span>
                            Audit administrator and platform activity.
                        </span>

                    </div>

                </div>

            </div>


            <div class="auth-visual-footer">
                © <?= date('Y') ?> UtsavDesk · Platform Administration
            </div>

        </section>


        <!-- =====================================================
         RIGHT
         ===================================================== -->

        <section class="auth-form-section">

            <div class="auth-form-container">


                <!-- MOBILE BRAND -->

                <a href="./" class="auth-mobile-brand">

                    <div class="auth-mobile-logo">
                        UD
                    </div>

                    <div>

                        <strong>
                            Utsav<span>Desk</span>
                        </strong>

                        <small>
                            Platform Administration
                        </small>

                    </div>

                </a>


                <!-- =================================================
                 LOGIN
                 ================================================= -->

                <div
                    class="login-section"
                    id="loginSection">

                    <div class="auth-security-label">
                        <i class="bi bi-shield-check"></i>
                        Secure Admin Access
                    </div>


                    <div class="auth-form-header">

                        <h2>
                            Welcome back
                        </h2>

                        <p>
                            Sign in with your UtsavDesk platform administrator account.
                        </p>

                    </div>


                    <div
                        class="auth-alert error"
                        id="loginError"
                        role="alert"
                        aria-live="polite">
                        <i class="bi bi-exclamation-circle"></i>

                        <span></span>
                    </div>


                    <form id="loginForm" method="post" action="./api/auth/login.php">


                        <div class="auth-field">

                            <label for="email">
                                Email Address
                            </label>

                            <div class="auth-field-wrap">

                                <i class="bi bi-envelope auth-field-icon"></i>

                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    placeholder="admin@utsavdesk.com"
                                    autocomplete="email"
                                    required>

                            </div>

                        </div>


                        <div class="auth-field">

                            <label for="password">
                                Password
                            </label>

                            <div class="auth-field-wrap password-field">

                                <i class="bi bi-lock auth-field-icon"></i>

                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    placeholder="Enter your password"
                                    autocomplete="current-password"
                                    required>

                                <button
                                    type="button"
                                    class="password-toggle"
                                    id="passwordToggle">
                                    <i class="bi bi-eye"></i>
                                </button>

                            </div>

                        </div>


                        <button
                            type="submit"
                            class="auth-submit"
                            id="loginButton">

                            Sign In

                            <i class="bi bi-arrow-right ms-1"></i>

                        </button>

                    </form>

                </div>


                <!-- =================================================
                 OTP
                 ================================================= -->

                <div
                    class="otp-section"
                    id="otpSection">

                    <div class="auth-security-label">
                        <i class="bi bi-shield-lock"></i>
                        Identity Verification
                    </div>


                    <div class="auth-form-header">

                        <h2>
                            Verify your login
                        </h2>

                        <p>
                            Enter the six-digit security code sent to your registered email.
                        </p>

                    </div>


                    <div class="otp-email">

                        Verification code sent to

                        <strong id="otpEmail">
                            admin@utsavdesk.com
                        </strong>

                    </div>


                    <div
                        class="auth-alert error"
                        id="otpError">
                        <i class="bi bi-exclamation-circle"></i>

                        <span></span>
                    </div>


                    <form id="otpForm">

                        <label
                            style="
                            display:block;
                            margin-bottom:7px;
                            font-size:10px;
                            font-weight:650;
                            color:#52525b;
                        ">
                            Security Code
                        </label>


                        <div class="otp-boxes">

                            <?php for ($i = 0; $i < 6; $i++): ?>

                                <input
                                    type="text"
                                    class="otp-input"
                                    maxlength="1"
                                    inputmode="numeric"
                                    autocomplete="one-time-code">

                            <?php endfor; ?>

                        </div>


                        <button
                            type="submit"
                            class="auth-submit"
                            id="verifyButton">
                            Verify & Continue
                        </button>


                        <div class="otp-options">

                            <button
                                type="button"
                                class="auth-link-button"
                                id="changeAccount">
                                <i class="bi bi-arrow-left"></i>
                                Change account
                            </button>


                            <span>

                                Resend in

                                <strong id="otpTimer">
                                    30s
                                </strong>

                            </span>

                        </div>

                    </form>

                </div>


                <div class="auth-form-footer">

                    <i class="bi bi-lock-fill"></i>

                    Restricted to authorized UtsavDesk platform administrators.

                </div>

            </div>

        </section>

    </div>


    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <script>
        $(function() {

            let otpTimerInterval = null;


            /* =====================================================
               PASSWORD
               ===================================================== */

            $('#passwordToggle').on('click', function() {

                const $password = $('#password');

                const show =
                    $password.attr('type') === 'password';

                $password.attr(
                    'type',
                    show ? 'text' : 'password'
                );

                $(this)
                    .find('i')
                    .toggleClass('bi-eye', !show)
                    .toggleClass('bi-eye-slash', show);

            });


            /* =====================================================
               LOGIN
               ===================================================== */

            $('#loginForm').on('submit', function(event) {

                event.preventDefault();

                hideError('#loginError');

                const email =
                    $.trim($('#email').val());

                const password =
                    $('#password').val();

                if (!email) {

                    showError(
                        '#loginError',
                        'Please enter your email address.'
                    );

                    $('#email').focus();

                    return;
                }

                if (!isValidEmail(email)) {

                    showError(
                        '#loginError',
                        'Please enter a valid email address.'
                    );

                    $('#email').focus();

                    return;
                }

                if (!password) {

                    showError(
                        '#loginError',
                        'Please enter your password.'
                    );

                    $('#password').focus();

                    return;
                }


                const $button =
                    $('#loginButton');

                const original =
                    $button.html();

                $button
                    .prop('disabled', true)
                    .html(
                        '<span class="spinner-border spinner-border-sm me-1"></span> Signing in...'
                    );


                $.ajax({
                    url: './api/auth/login.php',
                    method: 'POST',
                    contentType: 'application/json',
                    dataType: 'json',
                    headers: {
                        'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content')
                    },
                    data: JSON.stringify({
                        email: email,
                        password: password
                    })
                }).done(function(response) {
                    window.location.assign(response.redirect || './dashboard/');
                }).fail(function(xhr) {
                    const response = xhr.responseJSON || {};
                    showError('#loginError', response.message || 'Sign-in is temporarily unavailable.');
                }).always(function() {
                    $button.prop('disabled', false).html(original);
                });

            });


            /* =====================================================
               OTP INPUT
               ===================================================== */

            $('.otp-input').on('input', function() {

                this.value =
                    this.value.replace(/\D/g, '');

                if (
                    this.value &&
                    $(this).next('.otp-input').length
                ) {

                    $(this)
                        .next('.otp-input')
                        .focus();

                }

            });


            $('.otp-input').on('keydown', function(event) {

                if (
                    event.key === 'Backspace' &&
                    !this.value &&
                    $(this).prev('.otp-input').length
                ) {

                    $(this)
                        .prev('.otp-input')
                        .focus();

                }

            });


            $('.otp-input').on('paste', function(event) {

                event.preventDefault();

                const paste =
                    (
                        event.originalEvent.clipboardData ||
                        window.clipboardData
                    )
                    .getData('text')
                    .replace(/\D/g, '')
                    .slice(0, 6);

                $('.otp-input').each(function(index) {

                    $(this).val(
                        paste[index] || ''
                    );

                });

                $('.otp-input')
                    .eq(
                        Math.min(
                            paste.length,
                            5
                        )
                    )
                    .focus();

            });


            /* =====================================================
               VERIFY
               ===================================================== */

            $('#otpForm').on('submit', function(event) {

                event.preventDefault();
                showError('#otpError', 'Email verification is not configured. Sign in with your email and password.');

            });


            /* =====================================================
               CHANGE ACCOUNT
               ===================================================== */

            $('#changeAccount').on('click', function() {

                clearInterval(otpTimerInterval);

                $('.otp-input').val('');

                $('#otpSection')
                    .removeClass('show');

                $('#loginSection')
                    .removeClass('hide');

                $('#password').val('');

                $('#email').focus();

            });


            /* =====================================================
               TIMER
               ===================================================== */

            function startOtpTimer() {

                clearInterval(otpTimerInterval);

                let seconds = 30;

                $('#otpTimer').text('30s');

                otpTimerInterval =
                    setInterval(function() {

                        seconds--;

                        if (seconds <= 0) {

                            clearInterval(
                                otpTimerInterval
                            );

                            $('#otpTimer')
                                .html(
                                    '<button type="button" class="auth-link-button" id="resendOtp">Resend code</button>'
                                );

                            return;
                        }

                        $('#otpTimer').text(
                            seconds + 's'
                        );

                    }, 1000);

            }


            $(document).on(
                'click',
                '#resendOtp',
                function() {

                    $('.otp-input').val('');

                    startOtpTimer();

                }
            );


            /* =====================================================
               HELPERS
               ===================================================== */

            function isValidEmail(email) {

                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/
                    .test(email);

            }


            function showError(selector, message) {

                $(selector)
                    .addClass('show')
                    .find('span')
                    .text(message);

            }


            function hideError(selector) {

                $(selector)
                    .removeClass('show')
                    .find('span')
                    .text('');

            }

        });
    </script>

</body>

</html>