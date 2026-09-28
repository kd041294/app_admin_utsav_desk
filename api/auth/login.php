<?php

declare(strict_types=1);

require_once __DIR__ . '/../../common/security.php';
require_once __DIR__ . '/../../common/response.php';

sendSecurityHeaders();
startSecureSession();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (!verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null))) {
    jsonResponse(['success' => false, 'message' => 'Your sign-in session expired. Refresh the page and try again.'], 419);
}

$requestBody = file_get_contents('php://input');
$payload = json_decode($requestBody === false ? '' : $requestBody, true);

if (!is_array($payload)) {
    $payload = $_POST;
}

$email = strtolower(trim((string) ($payload['email'] ?? '')));
$password = (string) ($payload['password'] ?? '');

if (strlen($email) > 190 || strlen($password) > 4096 || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    jsonResponse(['success' => false, 'message' => 'Invalid email or password.'], 401);
}

try {
    require_once __DIR__ . '/../../common/config.php';
    require_once __DIR__ . '/../../common/db.php';

    $connection = databaseConnection();
    $loginPolicy = platformSettingsRuntime();
    $lockAttemptLimit = max(3, min(20, (int)$loginPolicy['login_lock_attempts']));
    $lockMinutes = max(1, min(1440, (int)$loginPolicy['login_lock_minutes']));
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateLimitKey = hash('sha256', 'utsavdesk-admin-login|' . $remoteAddress);
    $now = time();

    $limitStatement = $connection->prepare(
        'INSERT INTO admin_registration_limits (bucket_key, attempts, window_start)
		 VALUES (?, 1, ?)
		 ON DUPLICATE KEY UPDATE
			 attempts = IF(window_start < VALUES(window_start) - 900, 1, attempts + 1),
			 window_start = IF(window_start < VALUES(window_start) - 900, VALUES(window_start), window_start)'
    );
    $limitStatement->execute([$rateLimitKey, $now]);

    $limitStatement = $connection->prepare('SELECT attempts FROM admin_registration_limits WHERE bucket_key = ?');
    $limitStatement->execute([$rateLimitKey]);

    if ((int) $limitStatement->fetchColumn() > 12) {
        jsonResponse(['success' => false, 'message' => 'Too many sign-in attempts. Try again later.'], 429);
    }

    $statement = $connection->prepare(
        'SELECT id, admin_code, first_name, last_name, display_name, email, password_hash, role,
				failed_login_attempts, locked_until,
				(locked_until IS NOT NULL AND locked_until > UTC_TIMESTAMP()) AS is_locked,
				is_active, account_status, deleted_at
		 FROM admin_user
		 WHERE email = :email AND deleted_at IS NULL
		 LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $admin = $statement->fetch();

    $passwordHash = $admin && !empty($admin['password_hash'])
        ? (string) $admin['password_hash']
        : password_hash('invalid-admin-password', PASSWORD_DEFAULT);
    $passwordMatches = password_verify($password, $passwordHash);
    $accountIsActive = $admin
        && (int) $admin['is_active'] === 1
        && $admin['account_status'] === 'ACTIVE'
        && empty($admin['deleted_at']);

    if (!$admin || !$passwordMatches || !$accountIsActive || (int) $admin['is_locked'] === 1) {
        if ($admin && $accountIsActive && (int) $admin['is_locked'] !== 1) {
            $connection->beginTransaction();

            $attemptStatement = $connection->prepare(
                'SELECT failed_login_attempts,
						(locked_until IS NOT NULL AND locked_until <= UTC_TIMESTAMP()) AS lock_expired
				 FROM admin_user
				 WHERE id = ?
				 FOR UPDATE'
            );
            $attemptStatement->execute([(int) $admin['id']]);
            $attemptState = $attemptStatement->fetch();

            if ($attemptState) {
                $attempts = (int) $attemptState['failed_login_attempts'];
                if ((int) $attemptState['lock_expired'] === 1) {
                    $attempts = 0;
                }

                $attempts++;
                $updateStatement = $connection->prepare(
                    'UPDATE admin_user
					 SET failed_login_attempts = :attempts,
						 locked_until = CASE WHEN :lock_attempts >= :lock_limit
							 THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL :lock_minutes MINUTE)
							 ELSE NULL
						 END
					 WHERE id = :id'
                );
                $updateStatement->execute([
                    'attempts' => $attempts,
                    'lock_attempts' => $attempts,
                    'lock_limit' => $lockAttemptLimit,
                    'lock_minutes' => $lockMinutes,
                    'id' => (int) $admin['id'],
                ]);
            }

            $connection->commit();
        }

        try {
            adminActivityInsert($connection, ['log_code'=>'AUTH-'.bin2hex(random_bytes(10)), 'admin_id'=>$admin['id']??0, 'actor_name'=>'Admin sign-in', 'actor_role'=>$admin['role']??'ADMIN', 'type'=>'SECURITY', 'module'=>'AUTHENTICATION', 'action'=>'LOGIN_FAILED', 'description'=>'An admin sign-in attempt failed.', 'severity'=>'WARNING']);
        } catch (Throwable $logError) { error_log('Failed to record login failure.'); }
        jsonResponse(['success' => false, 'message' => 'Invalid email or password.'], 401);
    }

    if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
        $rehashStatement = $connection->prepare('UPDATE admin_user SET password_hash = :password_hash WHERE id = :id');
        $rehashStatement->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => (int) $admin['id'],
        ]);
    }

    $loginStatement = $connection->prepare(
        'UPDATE admin_user
		 SET failed_login_attempts = 0,
			 locked_until = NULL,
			 last_login_at = UTC_TIMESTAMP(),
			 last_login_ip = :ip,
			 last_login_user_agent = :user_agent
		 WHERE id = :id'
    );
    $loginStatement->execute([
        'ip' => substr($remoteAddress, 0, 45),
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        'id' => (int) $admin['id'],
    ]);

    $connection->prepare('DELETE FROM admin_registration_limits WHERE bucket_key = ?')->execute([$rateLimitKey]);

    session_regenerate_id(true);
    $_SESSION['CSRF_TOKEN'] = bin2hex(random_bytes(32));
    $_SESSION['PLATFORM_ADMIN'] = [
        'ID' => (int) $admin['id'],
        'ADMIN_CODE' => (string) $admin['admin_code'],
        'NAME' => trim((string) ($admin['display_name'] ?: ($admin['first_name'] . ' ' . $admin['last_name']))),
        'EMAIL' => (string) $admin['email'],
        'ROLE' => (string) $admin['role'],
        'IS_LOGGED_IN' => true,
    ];

    jsonResponse(['success' => true, 'redirect' => './dashboard/']);
} catch (Throwable $exception) {
    if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }

    error_log('Admin login failed: ' . $exception->getMessage());
    jsonResponse(['success' => false, 'message' => 'Sign-in is temporarily unavailable.'], 500);
}
