<?php

declare(strict_types=1);

function userNotificationTypes(): array
{
    return [
        'MAINTENANCE' => 'Maintenance',
        'FEATURE' => 'New Feature',
        'UPDATE' => 'Platform Update',
        'BILLING' => 'Billing',
        'SECURITY' => 'Security',
    ];
}

function userNotificationTypeLabel(string $type): string
{
    $normalized = strtoupper(trim($type));
    $types = userNotificationTypes();

    return $types[$normalized] ?? 'Maintenance';
}

function userNotificationNormalizeType(?string $type): string
{
    $normalized = strtoupper(trim((string) ($type ?? 'MAINTENANCE')));

    return in_array($normalized, array_keys(userNotificationTypes()), true) ? $normalized : 'MAINTENANCE';
}

function userNotificationPriorities(): array
{
    return [
        'LOW' => 'Low',
        'NORMAL' => 'Normal',
        'HIGH' => 'High',
    ];
}

function userNotificationPriorityLabel(string $priority): string
{
    $normalized = strtoupper(trim($priority));
    $priorities = userNotificationPriorities();

    return $priorities[$normalized] ?? 'Normal';
}

function userNotificationNormalizePriority(?string $priority): string
{
    $normalized = strtoupper(trim((string) ($priority ?? 'NORMAL')));

    return in_array($normalized, array_keys(userNotificationPriorities()), true) ? $normalized : 'NORMAL';
}

function userNotificationStatusLabel(string $status): string
{
    $normalized = strtoupper(trim($status));

    switch ($normalized) {
        case 'DRAFT':
            return 'Draft';
        case 'PUBLISHED':
            return 'Published';
        case 'SCHEDULED':
            return 'Scheduled';
        case 'EXPIRED':
            return 'Expired';
        default:
            return 'Draft';
    }
}

function userNotificationNormalizeStatus(?string $status): string
{
    $normalized = strtoupper(trim((string) ($status ?? 'DRAFT')));

    return in_array($normalized, ['DRAFT', 'PUBLISHED', 'SCHEDULED', 'EXPIRED'], true) ? $normalized : 'DRAFT';
}

function userNotificationEnsureTable(PDO $database): void
{
    $database->exec(
        'CREATE TABLE IF NOT EXISTS user_announcement (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            notification_code VARCHAR(30) NOT NULL,
            title VARCHAR(180) NOT NULL,
            message TEXT NOT NULL,
            type ENUM("MAINTENANCE","FEATURE","UPDATE","BILLING","SECURITY") NOT NULL DEFAULT "MAINTENANCE",
            priority ENUM("LOW","NORMAL","HIGH") NOT NULL DEFAULT "NORMAL",
            status ENUM("DRAFT","PUBLISHED","SCHEDULED","EXPIRED") NOT NULL DEFAULT "DRAFT",
            publish_type ENUM("NOW","SCHEDULE") NOT NULL DEFAULT "NOW",
            target_scope ENUM("ALL_USERS","ACTIVE_USERS","BILLING_USERS","SECURITY_USERS") NOT NULL DEFAULT "ALL_USERS",
            publish_at DATETIME NULL,
            expires_at DATETIME NULL,
            banner_enabled TINYINT(1) NOT NULL DEFAULT 1,
            acknowledgement_required TINYINT(1) NOT NULL DEFAULT 0,
            email_notification TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_by_name VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            sent_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_announcement_code (notification_code),
            KEY idx_user_announcement_type (type),
            KEY idx_user_announcement_status (status),
            KEY idx_user_announcement_priority (priority),
            KEY idx_user_announcement_publish_type (publish_type),
            KEY idx_user_announcement_publish_at (publish_at),
            KEY idx_user_announcement_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function userNotificationNextCode(PDO $database): string
{
    $row = $database->query('SELECT MAX(id) AS max_id FROM user_announcement')->fetch();
    $nextId = (int) ($row['max_id'] ?? 0) + 1;

    return 'NT-' . date('ymd') . '-' . str_pad((string) $nextId, 5, '0', STR_PAD_LEFT);
}
