<?php

declare(strict_types=1);

function configuration(): array
{
    $file = __DIR__ . '/config.php';
    if (!is_file($file)) {
        throw new RuntimeException('Application setup is incomplete.');
    }
    return require $file;
}

function database(array $config): PDO
{
    return new PDO($config['dsn'], $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function input(string $key): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
}

function throttle(PDO $db, string $key, int $maximum, int $seconds): void
{
    $now = time();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT IGNORE INTO ud_registration_limits (bucket_key, attempts, window_start) VALUES (?, 0, ?)')->execute([$key, $now]);
        $q = $db->prepare('SELECT attempts, window_start FROM ud_registration_limits WHERE bucket_key = ? FOR UPDATE');
        $q->execute([$key]);
        $row = $q->fetch();
        $expired = $now - (int)$row['window_start'] >= $seconds;
        $attempts = $expired ? 1 : (int)$row['attempts'] + 1;
        if (!$expired && (int)$row['attempts'] >= $maximum) {
            $db->commit();
            throw new DomainException('Too many attempts. Please try again in 15 minutes.');
        }
        $db->prepare('UPDATE ud_registration_limits SET attempts = ?, window_start = ? WHERE bucket_key = ?')->execute([$attempts, $expired ? $now : $row['window_start'], $key]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function bootstrapAvailable(PDO $db): bool
{
    return (bool)$db->query("SELECT 1 FROM ud_registration_bootstrap WHERE id=1 AND claimed_at IS NULL AND NOT EXISTS (SELECT 1 FROM admin_user WHERE role='SUPER_ADMIN')")->fetchColumn();
}

function registrationInvitesAvailable(PDO $db): bool
{
    return (bool)$db->query('SELECT 1 FROM admin_registration_invites WHERE used_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() LIMIT 1')->fetchColumn();
}

function validateRegistration(array $values): array
{
    $errors = [];
    foreach (['first_name' => 100, 'last_name' => 100, 'display_name' => 150] as $field => $max) {
        $length = mb_strlen($values[$field], 'UTF-8');
        if (($field === 'first_name' && $length === 0) || $length > $max || preg_match('/[\x00-\x1F\x7F]/u', $values[$field])) {
            $errors[] = $field === 'first_name' ? 'Enter a first name of 1–100 characters.' : ucfirst(str_replace('_', ' ', $field)) . ' must be at most ' . $max . ' characters.';
        }
    }
    if (strlen($values['email']) > 190 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    foreach (['contact_number' => 'phone', 'whatsapp_number' => 'WhatsApp'] as $field => $label) {
        if ($values[$field] !== '' && !preg_match('/^\+?[0-9]{7,15}$/D', $values[$field])) $errors[] = 'Use 7–15 digits for the ' . $label . ' number, optionally starting with +.';
    }
    $languages = ['en', 'hi', 'bn', 'gu', 'mr', 'ta', 'te'];
    $themes = ['SYSTEM', 'LIGHT', 'DARK'];
    if (!in_array($values['language'], $languages, true)) $errors[] = 'Choose a supported language.';
    if (!in_array($values['theme'], $themes, true)) $errors[] = 'Choose a supported appearance.';
    if (mb_strlen($values['password'], 'UTF-8') < 15 || strlen($values['password']) > 72 || strpos($values['password'], "\0") !== false) $errors[] = 'Use a password of at least 15 characters and at most 72 bytes.';
    if (!hash_equals($values['password'], $values['confirm_password'])) $errors[] = 'The passwords do not match.';
    return $errors;
}

function generateAdminCode(PDO $db): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $query = $db->prepare('SELECT 1 FROM admin_user WHERE admin_code = ? LIMIT 1');
    do {
        $code = '';
        for ($index = 0; $index < 10; $index++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $query->execute([$code]);
    } while ($query->fetchColumn());
    return $code;
}

function insertAdminUser(PDO $db, array $values, string $passwordHash, string $role, string $email): array
{
    $adminCode = generateAdminCode($db);
    $stmt = $db->prepare("INSERT INTO admin_user (admin_code,first_name,last_name,display_name,email,contact_number,whatsapp_number,password_hash,language,theme,role,account_status,is_active,last_password_changed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,'ACTIVE',1,UTC_TIMESTAMP())");
    $displayName = trim($values['display_name']) ?: trim($values['first_name'] . ' ' . $values['last_name']);
    $displayName = mb_substr($displayName, 0, 150, 'UTF-8');
    $stmt->execute([$adminCode, $values['first_name'], $values['last_name'] ?: null, $displayName, $email, $values['contact_number'] ?: null, $values['whatsapp_number'] ?: null, $passwordHash, $values['language'], $values['theme'], $role]);
    return ['admin_code' => $adminCode, 'id' => $db->lastInsertId()];
}

function registerSuperAdmin(PDO $db, array $values): string
{
    $hash = password_hash($values['password'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    $db->beginTransaction();
    try {
        // Lock the same permanent row for every claimant. Never delete or reset it.
        $row = $db->query('SELECT claimed_at FROM ud_registration_bootstrap WHERE id=1 FOR UPDATE')->fetch();
        if (!$row || $row['claimed_at'] !== null || !bootstrapAvailable($db)) {
            throw new DomainException('Super admin registration has already been completed. This code is no longer valid.');
        }
        $admin = insertAdminUser($db, $values, $hash, 'SUPER_ADMIN', $values['email']);
        $db->prepare('UPDATE ud_registration_bootstrap SET claimed_at=UTC_TIMESTAMP(), claimed_by=? WHERE id=1 AND claimed_at IS NULL')->execute([$admin['id']]);
        $db->commit();
        return $admin['admin_code'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function registerInvitedAdmin(PDO $db, int $inviteId, string $expectedEmail, array $values): array
{
    $hash = password_hash($values['password'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT id,email,role FROM admin_registration_invites WHERE id=? AND used_at IS NULL AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
        $query->execute([$inviteId]);
        $invite = $query->fetch();
        if (!$invite || strcasecmp($invite['email'], $expectedEmail) !== 0) {
            throw new DomainException('This invitation has expired or has already been used. Request a new invitation.');
        }
        $roles = ['ADMIN', 'OPERATIONS', 'SUPPORT', 'FINANCE', 'VIEWER'];
        if (!in_array($invite['role'], $roles, true)) {
            throw new DomainException('This invitation has an invalid role. Contact the application owner.');
        }
        $admin = insertAdminUser($db, $values, $hash, $invite['role'], $invite['email']);
        $update = $db->prepare('UPDATE admin_registration_invites SET used_at=UTC_TIMESTAMP(), used_by=? WHERE id=? AND used_at IS NULL AND revoked_at IS NULL');
        $update->execute([$admin['id'], $inviteId]);
        if ($update->rowCount() !== 1) throw new DomainException('This invitation has already been used. Request a new invitation.');
        $db->commit();
        return ['admin_code' => $admin['admin_code'], 'role' => $invite['role']];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Shared SHA-256 verifier for new approvals; old config-HMAC invites remain compatible. */
function findRegistrationInvite(PDO $db, string $code, string $legacySecret): ?array
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z0-9]{16}$/D', $code)) return null;
    $q = $db->prepare('SELECT id,email,role FROM admin_registration_invites WHERE token_hash IN (?,?) AND used_at IS NULL AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1');
    $q->execute([hash('sha256',$code),hash_hmac('sha256',$code,$legacySecret)]);
    $row = $q->fetch();
    return $row ?: null;
}
