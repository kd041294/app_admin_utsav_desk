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

$id = (int) ($payload['id'] ?? 0);
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

if ($id <= 0) {
    jsonResponse(['success' => false, 'message' => 'Announcement id is required.'], 422);
}

if ($title === '' || $message === '') {
    jsonResponse(['success' => false, 'message' => 'Title and message are required.'], 422);
}

if (mb_strlen($title, 'UTF-8') > 180 || mb_strlen($message, 'UTF-8') > 5000) {
    jsonResponse(['success' => false, 'message' => 'Title or message length is too long.'], 422);
}

try {
    $database = cmsDatabaseConnection();
    userNotificationEnsureTable($database);

    $record = $database->prepare('SELECT id FROM user_announcement WHERE id = :id LIMIT 1');
    $record->execute(['id' => $id]);
    if ($record->fetchColumn() === false) {
        jsonResponse(['success' => false, 'message' => 'Announcement not found.'], 404);
    }

    $publishDate = null;
    if ($publishAt !== '') {
        $publishDate = (new DateTimeImmutable($publishAt, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    $expiryDate = null;
    if ($expiresAt !== '') {
        $expiryDate = (new DateTimeImmutable($expiresAt, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    if ($status === 'SCHEDULED' && $publishDate === null) {
        jsonResponse(['success' => false, 'message' => 'Schedule date is required for a scheduled notification.'], 422);
    }

    $statement = $database->prepare(
        'UPDATE user_announcement SET
            title = :title,
            message = :message,
            type = :type,
            priority = :priority,
            status = :status,
            publish_type = :publish_type,
            target_scope = :target_scope,
            publish_at = :publish_at,
            expires_at = :expires_at,
            banner_enabled = :banner_enabled,
            acknowledgement_required = :acknowledgement_required,
            email_notification = :email_notification,
            updated_at = UTC_TIMESTAMP()
        WHERE id = :id'
    );

    $statement->execute([
        'id' => $id,
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
    ]);

    jsonResponse([
        'success' => true,
        'message' => 'Announcement updated successfully.',
        'id' => $id,
    ]);
} catch (Throwable $exception) {
    error_log('Announcement update failed: ' . $exception->getMessage());
    jsonResponse(['success' => false, 'message' => 'Announcement could not be updated right now.'], 500);
}
