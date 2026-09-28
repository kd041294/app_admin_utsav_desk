<?php

declare(strict_types=1);

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Unauthorized | UtsavDesk</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <style>
        :root {
            --primary: #087f73;
            --primary-dark: #065e56;
            --primary-soft: rgba(8, 127, 115, 0.10);
            --secondary: #4599c8;
            --danger: #c73545;
            --danger-soft: #fff1f3;
            --text: #18181b;
            --text-secondary: #52525b;
            --muted: #8d8d95;
            --border: rgba(16, 34, 53, 0.08);
            --background: #f3f6fa;
            --surface: rgba(255, 255, 255, 0.94);
            --shadow: 0 25px 70px rgba(15, 23, 42, 0.20);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100%;
            font-family: "Inter", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(69, 153, 200, 0.28), transparent 26%),
                radial-gradient(circle at bottom right, rgba(8, 127, 115, 0.18), transparent 22%),
                linear-gradient(145deg, #102235 0%, #183e51 45%, #087f73 100%);
            color: var(--text);
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 18px;
        }

        .unauthorized-shell {
            position: relative;
            width: min(100%, 700px);
            padding: 32px 28px 28px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 30px;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(16px);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .unauthorized-shell::before,
        .unauthorized-shell::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .unauthorized-shell::before {
            width: 430px;
            height: 430px;
            right: -160px;
            top: -200px;
        }

        .unauthorized-shell::after {
            width: 300px;
            height: 300px;
            left: -120px;
            bottom: -160px;
        }

        .brand {
            position: relative;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 14px;
            color: #fff;
            text-decoration: none;
        }

        .brand-badge {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.22);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.14), rgba(255, 255, 255, 0.05));
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: inset 0 0 20px rgba(255, 255, 255, 0.06);
        }

        .brand-badge img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .brand-name {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.7px;
        }

        .brand-name span {
            font-weight: 400;
        }

        .card {
            position: relative;
            z-index: 2;
            margin-top: 28px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 26px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.97), rgba(255, 255, 255, 0.92));
            padding: 34px 28px 26px;
            text-align: center;
            box-shadow: 0 18px 44px rgba(15, 23, 42, 0.12);
        }

        .alert-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(199, 53, 69, 0.12), rgba(255, 255, 255, 1));
            color: var(--danger);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            box-shadow: inset 0 0 0 12px rgba(199, 53, 69, 0.05), 0 12px 28px rgba(199, 53, 69, 0.12);
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: linear-gradient(135deg, #f8fafc, #eef2f7);
            border: 1px solid rgba(148, 163, 184, 0.2);
            color: var(--text-secondary);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            margin-bottom: 18px;
        }

        .eyebrow::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.12);
        }

        h1 {
            margin: 0;
            font-size: clamp(32px, 4vw, 48px);
            line-height: 1.08;
            letter-spacing: -1.7px;
            color: var(--text);
        }

        .subtitle {
            margin: 16px auto 0;
            max-width: 490px;
            font-size: 16px;
            line-height: 1.8;
            color: var(--text-secondary);
        }

        .actions {
            margin-top: 30px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .btn-primary,
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 170px;
            padding: 13px 20px;
            border-radius: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }

        .btn-primary {
            border: none;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            box-shadow: 0 14px 26px rgba(8, 127, 115, 0.22);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            color: #fff;
            box-shadow: 0 18px 28px rgba(8, 127, 115, 0.28);
        }

        .btn-secondary {
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.9);
            color: var(--text);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
        }

        .btn-secondary:hover {
            transform: translateY(-1px);
            border-color: rgba(15, 23, 42, 0.12);
        }

        .meta {
            margin-top: 22px;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
        }

        @media (max-width: 576px) {
            .unauthorized-shell {
                padding: 22px 18px 20px;
            }

            .actions {
                flex-direction: column;
            }

            .btn-primary,
            .btn-secondary {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="unauthorized-shell">
        <div class="brand" aria-label="UtsavDesk brand">
            <div class="brand-badge">
                <img src="../assets/images/logo.png" alt="UtsavDesk Logo">
            </div>
            <div>
                <div class="brand-name">Utsav<span>Desk</span></div>
            </div>
        </div>

        <div class="card">
            <div class="alert-icon" aria-hidden="true">
                <i class="bi bi-shield-slash"></i>
            </div>

            <div class="eyebrow">Access denied</div>
            <h1>Unauthorized</h1>
            <p class="subtitle">
                You do not have permission to view this page. Please sign in with the correct account or contact your administrator.
            </p>

            <div class="actions">
                <a href="../index.php" class="btn-primary">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Back to Login
                </a>
                <button type="button" class="btn-secondary" onclick="history.back()">
                    <i class="bi bi-arrow-left me-2"></i>Go Back
                </button>
            </div>

            <div class="meta">403 · Permission required</div>
        </div>
    </div>
</body>

</html>