<?php

declare(strict_types=1);

require_once __DIR__ . '/cms_db.php';

function supportTicketCategories(): array
{
    return ['TECHNICAL', 'BILLING', 'ACCOUNT', 'GENERAL'];
}

function supportTicketPriorities(): array
{
    return ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];
}

function supportTicketStatuses(): array
{
    return ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];
}

function supportTicketCategoryIcon(string $category): string
{
    switch (strtoupper(trim($category))) {
        case 'TECHNICAL':
            return 'bi-code-slash';
        case 'BILLING':
            return 'bi-credit-card';
        case 'ACCOUNT':
            return 'bi-person';
        default:
            return 'bi-chat-dots';
    }
}

function supportDisplayStatus(string $status): string
{
    $status = strtoupper(trim($status));

    switch ($status) {
        case 'IN_PROGRESS':
            return 'In Progress';
        case 'RESOLVED':
            return 'Resolved';
        case 'CLOSED':
            return 'Closed';
        default:
            return 'Open';
    }
}

function supportTicketStatusClass(string $status): string
{
    return 'status-' . strtolower(str_replace('_', '-', strtoupper(trim($status))));
}

function supportPriorityClass(string $priority): string
{
    return 'priority-' . strtolower(strtoupper(trim($priority)));
}

function supportCategoryClass(string $category): string
{
    return 'category-' . strtolower(strtoupper(trim($category)));
}

function supportHumanizeTimestamp(?string $dateValue): string
{
    if ($dateValue === null || $dateValue === '') {
        return 'Just now';
    }

    try {
        $date = new DateTimeImmutable($dateValue);
    } catch (Throwable $exception) {
        return 'Just now';
    }

    $diff = $date->diff(new DateTimeImmutable());

    if ($diff->days === 0) {
        if ($diff->h < 1) {
            return $diff->i . ' min ago';
        }

        if ($diff->h < 24) {
            return $diff->h . ' hrs ago';
        }
    }

    if ($diff->days === 1) {
        return '1 day ago';
    }

    if ($diff->days < 7) {
        return $diff->days . ' days ago';
    }

    return $date->format('d M Y');
}

function supportTextValue(array $data, string $key, int $maxLength, bool $required = true): string
{
    $value = $data[$key] ?? '';

    if (!is_string($value)) {
        throw new InvalidArgumentException('Please provide a valid ' . str_replace('_', ' ', $key) . '.');
    }

    $value = trim($value);

    if ($required && $value === '') {
        throw new InvalidArgumentException('Please provide a valid ' . str_replace('_', ' ', $key) . '.');
    }

    if (mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException('The ' . str_replace('_', ' ', $key) . ' is too long.');
    }

    return $value;
}

function supportGenerateTicketCode(PDO $db): string
{
    $day = date('ymd');
    $row = $db->query(
        "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(ticket_code, '-', -1) AS UNSIGNED)), 0) + 1 AS next_code
         FROM support_ticket
         WHERE ticket_code LIKE 'SUP-" . $day . "-%'"
    )->fetch();

    $sequence = (int) ($row['next_code'] ?? 1);

    return 'SUP-' . $day . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
}

function supportNormalizeStatus(string $status): string
{
    $normalized = strtoupper(trim($status));

    return in_array($normalized, supportTicketStatuses(), true) ? $normalized : 'OPEN';
}

function supportNormalizePriority(string $priority): string
{
    $normalized = strtoupper(trim($priority));

    return in_array($normalized, supportTicketPriorities(), true) ? $normalized : 'MEDIUM';
}

function supportNormalizeCategory(string $category): string
{
    $normalized = strtoupper(trim($category));

    return in_array($normalized, supportTicketCategories(), true) ? $normalized : 'GENERAL';
}

function supportSummary(PDO $db): array
{
    $row = $db->query(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END), 0) AS open_tickets,
            COALESCE(SUM(CASE WHEN status = 'IN_PROGRESS' THEN 1 ELSE 0 END), 0) AS in_progress_tickets,
            COALESCE(SUM(CASE WHEN priority = 'URGENT' THEN 1 ELSE 0 END), 0) AS urgent_tickets,
            COALESCE(SUM(CASE WHEN status = 'RESOLVED' THEN 1 ELSE 0 END), 0) AS resolved_tickets,
            COALESCE(ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, NOW())), 0), 0) AS average_response_minutes
         FROM support_ticket"
    )->fetch();

    return [
        'open' => (int) ($row['open_tickets'] ?? 0),
        'in_progress' => (int) ($row['in_progress_tickets'] ?? 0),
        'urgent' => (int) ($row['urgent_tickets'] ?? 0),
        'resolved' => (int) ($row['resolved_tickets'] ?? 0),
        'avg_response' => (int) ($row['average_response_minutes'] ?? 0),
    ];
}

function supportListTickets(PDO $db, array $filters = []): array
{
    $where = [];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 't.status = :status';
        $params['status'] = supportNormalizeStatus((string) $filters['status']);
    }

    if (!empty($filters['priority'])) {
        $where[] = 't.priority = :priority';
        $params['priority'] = supportNormalizePriority((string) $filters['priority']);
    }

    if (!empty($filters['category'])) {
        $where[] = 't.category = :category';
        $params['category'] = supportNormalizeCategory((string) $filters['category']);
    }

    if (!empty($filters['search'])) {
        $search = '%' . trim((string) $filters['search']) . '%';
        $where[] = '(t.ticket_code LIKE :search OR t.subject LIKE :search OR t.requester_name LIKE :search OR t.requester_email LIKE :search OR c.catering_name LIKE :search OR c.catering_code LIKE :search)';
        $params['search'] = $search;
    }

    $sql = "SELECT
            t.*,
            c.catering_name,
            c.catering_code,
            CONCAT(t.requester_name, ' ', COALESCE(t.requester_email, '')) AS requester_meta,
            (SELECT m.message
             FROM support_ticket_message m
             WHERE m.ticket_id = t.id
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT 1) AS latest_message
         FROM support_ticket t
         LEFT JOIN catering_info c ON c.id = t.caterer_id";

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY t.updated_at DESC, t.id DESC';

    $statement = $db->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function supportCreateTicket(PDO $db, array $input, array $admin): array
{
    $catererId = filter_var($input['caterer_id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$catererId) {
        throw new InvalidArgumentException('Choose a valid caterer for this support ticket.');
    }

    $subject = supportTextValue($input, 'subject', 160);
    $description = supportTextValue($input, 'description', 4000);
    $requesterName = supportTextValue($input, 'requester_name', 120);
    $requesterEmail = supportTextValue($input, 'requester_email', 150);
    if (!filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid requester email address.');
    }

    $category = supportNormalizeCategory((string) ($input['category'] ?? 'GENERAL'));
    $priority = supportNormalizePriority((string) ($input['priority'] ?? 'MEDIUM'));
    $status = supportNormalizeStatus((string) ($input['status'] ?? 'OPEN'));
    $assignedTo = supportTextValue($input, 'assigned_to', 150, false);
    $adminId = isset($admin['ID']) ? (int) $admin['ID'] : 0;
    $adminName = trim((string) ($admin['NAME'] ?? 'Platform Admin'));

    $code = supportGenerateTicketCode($db);

    $db->beginTransaction();

    try {
        $statement = $db->prepare(
            'INSERT INTO support_ticket (
                ticket_code,
                caterer_id,
                subject,
                description,
                requester_name,
                requester_email,
                category,
                priority,
                status,
                assigned_to,
                created_by_admin_id,
                created_by_admin_name,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );

        $statement->execute([
            $code,
            $catererId,
            $subject,
            $description,
            $requesterName,
            $requesterEmail,
            $category,
            $priority,
            $status,
            $assignedTo !== '' ? $assignedTo : 'Unassigned',
            $adminId,
            $adminName,
        ]);

        $ticketId = (int) $db->lastInsertId();

        $messageStatement = $db->prepare(
            'INSERT INTO support_ticket_message (ticket_id, sender_type, sender_name, sender_email, message, is_internal, created_at)
             VALUES (?, ?, ?, ?, ?, 0, NOW())'
        );

        $messageStatement->execute([
            $ticketId,
            'CUSTOMER',
            $requesterName,
            $requesterEmail,
            $description,
        ]);

        $db->commit();

        return [
            'id' => $ticketId,
            'ticket_code' => $code,
            'status' => $status,
        ];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $error;
    }
}

function supportAddMessage(PDO $db, int $ticketId, array $input, array $admin): void
{
    $message = supportTextValue($input, 'message', 4000);
    $isInternal = (($input['is_internal'] ?? 0) === 1 || ($input['reply_type'] ?? '') === 'note');
    $senderType = $isInternal ? 'ADMIN' : 'ADMIN';
    $senderName = trim((string) ($admin['NAME'] ?? 'Support Team'));
    $senderEmail = trim((string) ($admin['EMAIL'] ?? 'support@utsavdesk.com'));

    $statement = $db->prepare(
        'INSERT INTO support_ticket_message (ticket_id, sender_type, sender_name, sender_email, message, is_internal, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );

    $statement->execute([
        $ticketId,
        $senderType,
        $senderName,
        $senderEmail,
        $message,
        $isInternal ? 1 : 0,
    ]);

    $update = $db->prepare('UPDATE support_ticket SET updated_at = NOW(), status = CASE WHEN status = "OPEN" THEN "IN_PROGRESS" ELSE status END WHERE id = ?');
    $update->execute([$ticketId]);
}

function supportUpdateTicket(PDO $db, int $ticketId, array $input): void
{
    $status = supportNormalizeStatus((string) ($input['status'] ?? 'OPEN'));
    $priority = supportNormalizePriority((string) ($input['priority'] ?? 'MEDIUM'));
    $assignedTo = supportTextValue($input, 'assigned_to', 150, false);

    $statement = $db->prepare(
        'UPDATE support_ticket
         SET status = :status,
             priority = :priority,
             assigned_to = :assigned_to,
             updated_at = NOW()
         WHERE id = :id'
    );

    $statement->execute([
        'status' => $status,
        'priority' => $priority,
        'assigned_to' => $assignedTo !== '' ? $assignedTo : 'Unassigned',
        'id' => $ticketId,
    ]);
}
