<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function adminActivityTypes(): array
{
    return ['ADMIN', 'SECURITY', 'CATERER', 'SUBSCRIPTION', 'BILLING', 'SUPPORT', 'SETTINGS', 'ANNOUNCEMENT'];
}

function adminActivitySeverities(): array
{
    return ['INFO', 'SUCCESS', 'WARNING', 'DANGER'];
}

function adminActivityActions(): array
{
    return ['CREATE', 'UPDATE', 'DELETE', 'VIEW', 'LOGIN', 'LOGOUT', 'LOGIN_FAILED', 'PAYMENT', 'STATUS_CHANGE', 'SUSPEND'];
}

function adminActivityInsert(PDO $db, array $payload): int
{
    $adminId = isset($payload['admin_id']) ? (int) $payload['admin_id'] : 0;
    $adminCode = trim((string) ($payload['admin_code'] ?? ''));
    $actorName = trim((string) ($payload['actor_name'] ?? ($payload['name'] ?? 'System')));
    $actorRole = strtoupper((string) ($payload['actor_role'] ?? ($payload['role'] ?? 'ADMIN')));
    $type = strtoupper((string) ($payload['type'] ?? 'ADMIN'));
    $module = strtoupper((string) ($payload['module'] ?? $type));
    $action = strtoupper((string) ($payload['action'] ?? 'VIEW'));
    $subjectType = trim((string) ($payload['subject_type'] ?? ($payload['target_type'] ?? '')));
    $subjectId = isset($payload['subject_id']) ? (int) $payload['subject_id'] : null;
    $subjectName = trim((string) ($payload['subject_name'] ?? ($payload['target_name'] ?? '')));
    $description = trim((string) ($payload['description'] ?? ''));
    $ipAddress = trim((string) ($payload['ip_address'] ?? ($payload['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''))));
    $userAgent = trim((string) ($payload['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    $device = trim((string) ($payload['device'] ?? 'Web Browser'));
    $severity = strtoupper((string) ($payload['severity'] ?? 'INFO'));

    if ($actorName === '') {
        $actorName = 'System';
    }

    if ($description === '') {
        $description = ucfirst(strtolower($action)) . ' action recorded in ' . strtolower($module) . '.';
    }

    $metadata = $payload['metadata'] ?? null;
    if (is_array($metadata)) {
        $encodedMetadata = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $metadata = $encodedMetadata !== false ? $encodedMetadata : null;
    } elseif (is_string($metadata)) {
        $metadata = $metadata;
    } else {
        $metadata = null;
    }

    $generatedCode = 'LOG-' . date('ymd') . '-' . str_pad((string) ((int) (microtime(true) * 1000) % 10000), 4, '0', STR_PAD_LEFT);
    $logCode = trim((string) ($payload['log_code'] ?? $generatedCode));

    $statement = $db->prepare(
        'INSERT INTO activity_log (
            log_code,
            admin_id,
            admin_code,
            actor_name,
            actor_role,
            type,
            module,
            action,
            subject_type,
            subject_id,
            subject_name,
            description,
            ip_address,
            user_agent,
            device,
            severity,
            metadata,
            created_at
        ) VALUES (
            :log_code,
            :admin_id,
            :admin_code,
            :actor_name,
            :actor_role,
            :type,
            :module,
            :action,
            :subject_type,
            :subject_id,
            :subject_name,
            :description,
            :ip_address,
            :user_agent,
            :device,
            :severity,
            :metadata,
            NOW()
        )'
    );

    $statement->execute([
        'log_code' => $logCode,
        'admin_id' => $adminId > 0 ? $adminId : null,
        'admin_code' => $adminCode !== '' ? $adminCode : null,
        'actor_name' => $actorName,
        'actor_role' => $actorRole,
        'type' => $type,
        'module' => $module,
        'action' => $action,
        'subject_type' => $subjectType !== '' ? $subjectType : null,
        'subject_id' => $subjectId,
        'subject_name' => $subjectName !== '' ? $subjectName : null,
        'description' => $description,
        'ip_address' => $ipAddress !== '' ? $ipAddress : null,
        'user_agent' => $userAgent !== '' ? $userAgent : null,
        'device' => $device !== '' ? $device : 'Web Browser',
        'severity' => in_array($severity, adminActivitySeverities(), true) ? $severity : 'INFO',
        'metadata' => $metadata,
    ]);

    return (int) $db->lastInsertId();
}

function adminActivitySummary(PDO $db): array
{
    $summary = [
        'today' => 0,
        'admin_actions' => 0,
        'security' => 0,
        'failed_logins' => 0,
        'caterer_actions' => 0,
    ];

    $summary['today'] = (int) $db->query('SELECT COUNT(*) FROM activity_log WHERE DATE(created_at) = CURDATE()')->fetchColumn();
    $summary['admin_actions'] = (int) $db->query("SELECT COUNT(*) FROM activity_log WHERE DATE(created_at) = CURDATE() AND (type IN ('ADMIN', 'SETTINGS', 'ANNOUNCEMENT') OR module IN ('ADMIN', 'SETTINGS', 'ANNOUNCEMENT'))")->fetchColumn();
    $summary['security'] = (int) $db->query("SELECT COUNT(*) FROM activity_log WHERE DATE(created_at) = CURDATE() AND (type = 'SECURITY' OR module = 'AUTHENTICATION')")->fetchColumn();
    $summary['failed_logins'] = (int) $db->query("SELECT COUNT(*) FROM activity_log WHERE DATE(created_at) = CURDATE() AND action = 'LOGIN_FAILED'")->fetchColumn();
    $summary['caterer_actions'] = (int) $db->query("SELECT COUNT(*) FROM activity_log WHERE DATE(created_at) = CURDATE() AND type = 'CATERER'")->fetchColumn();

    return $summary;
}

function adminActivityList(PDO $db, array $filters = []): array
{
    $where = ['1 = 1'];
    $parameters = [];

    $type = strtoupper((string) ($filters['type'] ?? ''));
    $action = strtoupper((string) ($filters['action'] ?? ''));
    $severity = strtoupper((string) ($filters['severity'] ?? ''));
    $search = trim((string) ($filters['search'] ?? ''));
    $dateWindow = (string) ($filters['date_window'] ?? '');

    if ($type !== '' && in_array($type, adminActivityTypes(), true)) {
        $where[] = 'type = :type';
        $parameters['type'] = $type;
    }

    if ($action !== '' && in_array($action, adminActivityActions(), true)) {
        $where[] = 'action = :action';
        $parameters['action'] = $action;
    }

    if ($severity !== '' && in_array($severity, adminActivitySeverities(), true)) {
        $where[] = 'severity = :severity';
        $parameters['severity'] = $severity;
    }

    if ($search !== '') {
        $where[] = '(actor_name LIKE :search OR subject_name LIKE :search OR description LIKE :search OR module LIKE :search OR action LIKE :search OR ip_address LIKE :search OR log_code LIKE :search)';
        $parameters['search'] = '%' . $search . '%';
    }

    if ($dateWindow === 'today') {
        $where[] = 'DATE(created_at) = CURDATE()';
    } elseif ($dateWindow === '7') {
        $where[] = 'created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
    } elseif ($dateWindow === '30') {
        $where[] = 'created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
    }

    $statement = $db->prepare(
        'SELECT *
         FROM activity_log
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY created_at DESC
         LIMIT 200'
    );
    $statement->execute($parameters);

    return $statement->fetchAll();
}

function adminActivityRelativeLabel(string $createdAt): string
{
    try {
        $timestamp = new DateTimeImmutable($createdAt, new DateTimeZone('UTC'));
    } catch (Throwable $exception) {
        return 'Recently';
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $difference = $timestamp->diff($now);
    $seconds = max(0, $now->getTimestamp() - $timestamp->getTimestamp());

    if ($seconds < 60) {
        return $seconds . ' sec ago';
    }

    if ($seconds < 3600) {
        return floor($seconds / 60) . ' min ago';
    }

    if ($seconds < 86400) {
        return floor($seconds / 3600) . ' hrs ago';
    }

    if ($difference->days === 1) {
        return '1 day ago';
    }

    return $difference->days . ' days ago';
}
