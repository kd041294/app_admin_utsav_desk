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

if ($id <= 0) {
    jsonResponse(['success' => false, 'message' => 'Announcement id is required.'], 422);
}

try {
    $database = cmsDatabaseConnection();
    userNotificationEnsureTable($database);

    $statement = $database->prepare('DELETE FROM user_announcement WHERE id = :id');
    $statement->execute(['id' => $id]);

    jsonResponse([
        'success' => true,
        'message' => 'Announcement deleted successfully.',
        'id' => $id,
    ]);
} catch (Throwable $exception) {
    error_log('Announcement delete failed: ' . $exception->getMessage());
    jsonResponse(['success' => false, 'message' => 'Announcement could not be deleted right now.'], 500);
}
