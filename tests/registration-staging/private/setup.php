<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

try {
    if (is_file(__DIR__ . '/config.php')) throw new RuntimeException('Already configured. Setup will not reset or replace a consumed access code.');
    $options = getopt('', ['database:', 'port:', 'host:', 'url:', 'shared-hosting']);
    $name = $options['database'] ?? 'utsavdesk_admin_db';
    $port = $options['port'] ?? '3306';
    $host = $options['host'] ?? (getenv('UTSAV_SETUP_DB_HOST') ?: '127.0.0.1');
    $url = $options['url'] ?? (getenv('UTSAV_APP_URL') ?: 'http://localhost:8383/admin_registration_utsav_desk/');
    $sharedHosting = isset($options['shared-hosting']);
    $setupUser = getenv('UTSAV_SETUP_DB_USER');
    $setupPassword = getenv('UTSAV_SETUP_DB_PASSWORD');
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $name) || !preg_match('/^[0-9]{1,5}$/D', $port) || (int)$port > 65535 || !preg_match('/^[a-zA-Z0-9._:-]{1,255}$/D', $host)) throw new RuntimeException('Invalid database name, host, or port.');
    if ($sharedHosting && ($setupUser === false || $setupUser === '' || $setupPassword === false || $setupPassword === '')) throw new RuntimeException('Shared-hosting mode requires UTSAV_SETUP_DB_USER and UTSAV_SETUP_DB_PASSWORD from the hosting panel.');
    if ($sharedHosting && (filter_var($url, FILTER_VALIDATE_URL) === false || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https')) throw new RuntimeException('Shared-hosting mode requires the public HTTPS registration URL via --url or UTSAV_APP_URL.');
    $dbUser = $setupUser !== false && $setupUser !== '' ? $setupUser : 'root';
    $dbPassword = $setupPassword !== false ? $setupPassword : '';
    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
    $root = new PDO($dsn, $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $count = (int)$root->query("SELECT COUNT(*) FROM admin_user WHERE role='SUPER_ADMIN'")->fetchColumn();
    if ($count > 1) throw new RuntimeException('Multiple super admins already exist. Resolve these accounts before setup. No accounts were changed.');
    $engine = $root->query("SHOW TABLE STATUS LIKE 'admin_user'")->fetch(PDO::FETCH_ASSOC);
    if (!$engine || $engine['Engine'] !== 'InnoDB') throw new RuntimeException('The admin_user table must use InnoDB transactions.');
    $column = $root->query("SHOW COLUMNS FROM admin_user LIKE 'utsav_super_admin_slot'")->fetch();
    if (!$column) {
        $root->exec("ALTER TABLE admin_user ADD COLUMN utsav_super_admin_slot TINYINT GENERATED ALWAYS AS (CASE WHEN role='SUPER_ADMIN' THEN 1 ELSE NULL END) STORED, ADD UNIQUE KEY uq_utsav_single_super_admin (utsav_super_admin_slot)");
    }
    $root->exec("CREATE TABLE IF NOT EXISTS ud_registration_bootstrap (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, claimed_at DATETIME NULL, claimed_by BIGINT UNSIGNED NULL, CONSTRAINT ck_ud_bootstrap_singleton CHECK (id=1)) ENGINE=InnoDB");
    $root->exec("INSERT IGNORE INTO ud_registration_bootstrap (id,claimed_at,claimed_by) VALUES (1,NULL,NULL)");
    if ($count === 1) $root->exec("UPDATE ud_registration_bootstrap SET claimed_at=COALESCE(claimed_at,UTC_TIMESTAMP()), claimed_by=(SELECT id FROM admin_user WHERE role='SUPER_ADMIN' LIMIT 1) WHERE id=1");
    $root->exec("CREATE TABLE IF NOT EXISTS ud_registration_limits (bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, attempts INT UNSIGNED NOT NULL DEFAULT 0, window_start BIGINT UNSIGNED NOT NULL, KEY ud_limit_cleanup (window_start)) ENGINE=InnoDB");
    $root->exec("CREATE TABLE IF NOT EXISTS admin_registration_invites (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, email VARCHAR(190) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL, role ENUM('ADMIN','OPERATIONS','SUPPORT','FINANCE','VIEWER') NOT NULL DEFAULT 'ADMIN', expires_at DATETIME NOT NULL, used_at DATETIME NULL DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uq_admin_registration_invite_token (token_hash), KEY invite_expiry (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $inviteColumns = $root->query('SHOW COLUMNS FROM admin_registration_invites')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['token_encrypted'=>'TEXT NULL','created_by'=>'BIGINT UNSIGNED NULL','revoked_at'=>'DATETIME NULL','revoked_by'=>'BIGINT UNSIGNED NULL','used_by'=>'BIGINT UNSIGNED NULL'] as $column=>$definition) {
        if (!in_array($column,$inviteColumns,true)) $root->exec('ALTER TABLE admin_registration_invites ADD COLUMN ' . $column . ' ' . $definition);
    }
    $inviteRole = $root->query("SHOW COLUMNS FROM admin_registration_invites LIKE 'role'")->fetch();
    if (!$inviteRole) $root->exec("ALTER TABLE admin_registration_invites ADD COLUMN role ENUM('ADMIN','OPERATIONS','SUPPORT','FINANCE','VIEWER') NOT NULL DEFAULT 'ADMIN' AFTER email");
    if ($sharedHosting) {
        // Shared hosts create and scope database users in their control panel.
        $user = $dbUser;
        $password = $dbPassword;
    } else {
        // A dedicated runtime identity cannot update existing users or grant privileges.
        $user = 'utsav_reg_' . bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(24));
        $account = $root->quote($user) . "@'127.0.0.1'";
        $root->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $root->quote($password));
        $root->exec("GRANT SELECT (admin_code,role), INSERT (admin_code,first_name,last_name,display_name,email,contact_number,whatsapp_number,password_hash,language,theme,role,account_status,is_active,last_password_changed_at) ON `$name`.admin_user TO $account");
        $root->exec("GRANT SELECT (id,claimed_at), UPDATE (claimed_at,claimed_by) ON `$name`.ud_registration_bootstrap TO $account");
        $root->exec("GRANT SELECT (bucket_key,attempts,window_start), INSERT (bucket_key,attempts,window_start), UPDATE (attempts,window_start) ON `$name`.ud_registration_limits TO $account");
        $root->exec("GRANT SELECT (id,token_hash,email,role,expires_at,used_at,revoked_at), UPDATE (used_at,used_by) ON `$name`.admin_registration_invites TO $account");
    }
    $secret = bin2hex(random_bytes(32));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = 'UD-ADMIN-';
    for ($i = 0; $i < 10; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    $config = ['dsn' => $dsn, 'db_user' => $user, 'db_password' => $password, 'app_secret' => $secret, 'super_code_hash' => hash_hmac('sha256', $code, $secret)];
    $file = fopen(__DIR__ . '/config.php', 'x');
    if ($file === false) throw new RuntimeException('Cannot create private/config.php.');
    fwrite($file, "<?php\n// Server-only secrets. Never publish or share this file.\nreturn " . var_export($config, true) . ";\n");
    fclose($file);
    $db = database($config);
    echo "Setup complete. Database: $name\n";
    echo bootstrapAvailable($db) ? "ONE-TIME SUPER ADMIN ACCESS CODE: $code\nStore this privately. It is shown only now.\n" : "Super admin registration is already closed. No usable code issued.\n";
    echo "Open $url\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Setup stopped: " . $e->getMessage() . "\n");
    exit(1);
}
