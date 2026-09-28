<?php

declare(strict_types=1);

require_once __DIR__ . '/../../common/security.php';
require_once __DIR__ . '/../../common/response.php';
require_once __DIR__ . '/../../common/cms_db.php';
require_once __DIR__ . '/../../common/notifications.php';

sendSecurityHeaders();
startSecureSession();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (!verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null))) {
    jsonResponse(['success' => false, 'message' => 'Your security token expired. Refresh the page and try again.'], 419);
}

$requestBody = trim((string) file_get_contents('php://input'));
$payload = [];

if ($requestBody !== '') {
    $decoded = json_decode($requestBody, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

if ($payload === []) {
    $payload = $_POST;
}

$title = trim((string) ($payload['title'] ?? ''));
$message = trim((string) ($payload['message'] ?? ''));
$type = userNotificationNormalizeType((string) ($payload['type'] ?? 'MAINTENANCE'));
$priority = userNotificationNormalizePriority((string) ($payload['priority'] ?? 'NORMAL'));
$status = userNotificationNormalizeStatus((string) ($payload['status'] ?? 'PUBLISHED'));
$publishType = strtoupper((string) ($payload['publish_type'] ?? 'NOW'));
$targetScope = (string) ($payload['target_scope'] ?? 'ALL_USERS');
$publishAt = (string) ($payload['publish_at'] ?? '');
$expiresAt = (string) ($payload['expires_at'] ?? '');
$bannerEnabled = !empty($payload['banner_enabled']) ? 1 : 1;
$ackRequired = !empty($payload['acknowledgement_required']) ? 1 : 0;
$emailNotification = !empty($payload['email_notification']) ? 1 : 0;

if ($title === '' || $message === '') {
    jsonResponse(['success' => false, 'message' => 'Title and message are required.'], 422);
}

if (mb_strlen($title, 'UTF-8') > 180 || mb_strlen($message, 'UTF-8') > 5000) {
    jsonResponse(['success' => false, 'message' => 'Title or message length is too long.'], 422);
}

$admin = $_SESSION['PLATFORM_ADMIN'] ?? [];
$adminName = trim((string) ($admin['NAME'] ?? 'System Admin'));
$adminId = (int) ($admin['ID'] ?? 0);

try {
    $database = cmsDatabaseConnection();
    userNotificationEnsureTable($database);

    $publishDate = null;
    if ($publishAt !== '') {
        $date = new DateTimeImmutable($publishAt, new DateTimeZone('UTC'));
        $publishDate = $date->format('Y-m-d H:i:s');
    }

    $expiryDate = null;
    if ($expiresAt !== '') {
        $date = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));
        $expiryDate = $date->format('Y-m-d H:i:s');
    }

    if ($status === 'SCHEDULED' && ($publishDate === null || $publishDate === '')) {
        jsonResponse(['success' => false, 'message' => 'Schedule date is required for a scheduled notification.'], 422);
    }

    $notificationCode = userNotificationNextCode($database);
    $statement = $database->prepare(
        'INSERT INTO user_announcement (
            notification_code,
            title,
            message,
            type,
            priority,
            status,
            publish_type,
            target_scope,
            publish_at,
            expires_at,
            banner_enabled,
            acknowledgement_required,
            email_notification,
            created_by,
            created_by_name,
            created_at,
            updated_at
        ) VALUES (
            :notification_code,
            :title,
            :message,
            :type,
            :priority,
            :status,
            :publish_type,
            :target_scope,
            :publish_at,
            :expires_at,
            :banner_enabled,
            :acknowledgement_required,
            :email_notification,
            :created_by,
            :created_by_name,
            UTC_TIMESTAMP(),
            UTC_TIMESTAMP()
        )'
    );

    $statement->execute([
        'notification_code' => $notificationCode,
        'title' => $title,
        'message' => $message,
        'type' => $type,
        'priority' => $priority,
        'status' => $status,
        'publish_type' => in_array($publishType, ['NOW', 'SCHEDULE'], true) ? $publishType : 'NOW',
        'target_scope' => in_array($targetScope, ['ALL_USERS', 'ACTIVE_USERS', 'BILLING_USERS', 'SECURITY_USERS'], true) ? $targetScope : 'ALL_USERS',
        'publish_at' => $publishDate,
        'expires_at' => $expiryDate,
        'banner_enabled' => $bannerEnabled,
        'acknowledgement_required' => $ackRequired,
        'email_notification' => $emailNotification,
        'created_by' => $adminId,
        'created_by_name' => $adminName,
    ]);

    jsonResponse([
        'success' => true,
        'message' => 'System notification was sent successfully.',
        'notification' => [
            'code' => $notificationCode,
            'type' => $type,
            'priority' => $priority,
            'status' => $status,
        ],
    ], 201);
} catch (Throwable $exception) {
    error_log('Announcement creation failed: ' . $exception->getMessage());
    jsonResponse(['success' => false, 'message' => 'Notification could not be created at the moment.'], 500);
}
