<?php
declare(strict_types=1);

require_once __DIR__ . '/cms_db.php';

/** Read-only snapshot. Activity metrics use the selected calendar-month window. */
function cmsAnalytics(PDO $db, int $months): array
{
    if (!in_array($months, [1, 3, 6, 12], true)) {
        throw new InvalidArgumentException('Invalid analytics period.');
    }
    $now = new DateTimeImmutable((string) $db->query('SELECT NOW()')->fetchColumn());
    $start = $now->modify('first day of this month')->setTime(0, 0)->modify('-' . ($months - 1) . ' months');
    $end = $now->modify('+1 second');
    $range = [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    $query = static function (string $sql, array $params = []) use ($db): array {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    };
    $scalar = static function (string $sql, array $params = []) use ($query) {
        return array_values($query($sql, $params)[0])[0];
    };
    $db->beginTransaction();
    try {
        $metrics = [];
        $metrics['Total caterers'] = (int) $scalar('SELECT COUNT(*) FROM catering_info');
        $metrics['Active caterers'] = (int) $scalar('SELECT COUNT(*) FROM catering_info WHERE is_active=1 AND status=1');
        $metrics['New caterers'] = (int) $scalar('SELECT COUNT(*) FROM catering_info WHERE created_at >= ? AND created_at < ?', $range);
        $metrics['Active users'] = (int) $scalar('SELECT COUNT(*) FROM user_info WHERE is_active=1 AND status=1');
        $metrics['Users logged in today'] = (int) $scalar('SELECT COUNT(*) FROM user_info WHERE is_active=1 AND status=1 AND last_login_at >= CURRENT_DATE AND last_login_at <= NOW()');
        $metrics['Leads'] = (int) $scalar('SELECT COUNT(*) FROM customer_query WHERE is_active=1 AND status=1 AND created_at >= ? AND created_at < ?', $range);
        $metrics['Converted leads'] = (int) $scalar('SELECT COUNT(*) FROM customer_query WHERE is_active=1 AND status=1 AND is_converted=1 AND created_at >= ? AND created_at < ?', $range);
        $metrics['Conversion rate'] = $metrics['Leads'] ? round(100 * $metrics['Converted leads'] / $metrics['Leads'], 1) : 0;
        $metrics['Events created'] = (int) $scalar('SELECT COUNT(*) FROM event_details WHERE is_active=1 AND status=1 AND created_at >= ? AND created_at < ?', $range);
        $metrics['Booking value'] = (float) $scalar("SELECT COALESCE(SUM(contract_amount),0) FROM event_details WHERE is_active=1 AND status=1 AND event_status <> 'CANCELLED' AND created_at >= ? AND created_at < ?", $range);
        $metrics['Payments collected'] = (float) $scalar("SELECT COALESCE(SUM(amount),0) FROM event_payment WHERE is_active=1 AND status=1 AND payment_status='SUCCESS' AND payment_date >= ? AND payment_date < ?", $range);
        $metrics['Refunds processed'] = (float) $scalar("SELECT COALESCE(SUM(refund_amount),0) FROM event_refund WHERE is_active=1 AND status=1 AND refund_status='PROCESSED' AND refund_date >= ? AND refund_date < ?", $range);
        $metrics['Net collections'] = $metrics['Payments collected'] - $metrics['Refunds processed'];
        $metrics['Approved expenses'] = (float) $scalar("SELECT COALESCE(SUM(amount),0) FROM event_expense WHERE is_active=1 AND status=1 AND approval_status='APPROVED' AND expense_date >= DATE(?) AND expense_date <= DATE(?)", $range);
        $metrics['Quotations created'] = (int) $scalar('SELECT COUNT(*) FROM customer_quotation WHERE is_active=1 AND status=1 AND created_at >= ? AND created_at < ?', $range);
        $metrics['Documents uploaded'] = (int) $scalar('SELECT COUNT(*) FROM event_document WHERE status=1 AND created_at >= ? AND created_at < ?', $range);
        $metrics['Upcoming events'] = (int) $scalar("SELECT COUNT(*) FROM event_details WHERE is_active=1 AND status=1 AND event_status IN ('UPCOMING','CONFIRMED') AND event_date >= CURRENT_DATE");
        $roles = $query('SELECT role AS label, COUNT(*) AS total FROM user_info WHERE is_active=1 AND status=1 GROUP BY role ORDER BY total DESC');
        $events = $query('SELECT event_status AS label, COUNT(*) AS total FROM event_details WHERE is_active=1 AND status=1 AND created_at >= ? AND created_at < ? GROUP BY event_status ORDER BY total DESC', $range);
        $leads = $query('SELECT query_status AS label, COUNT(*) AS total FROM customer_query WHERE is_active=1 AND status=1 AND created_at >= ? AND created_at < ? GROUP BY query_status ORDER BY total DESC', $range);
        $top = $query("SELECT c.catering_code AS code, c.catering_name AS name,
            COALESCE(q.leads,0) AS leads, COALESCE(q.converted,0) AS converted,
            COALESCE(e.events,0) AS events, COALESCE(e.booking_value,0) AS booking_value
            FROM catering_info c
            LEFT JOIN (SELECT catering_id, COUNT(*) AS leads, SUM(is_converted=1) AS converted
                FROM customer_query WHERE is_active=1 AND status=1 AND created_at >= ? AND created_at < ? GROUP BY catering_id) q ON q.catering_id=c.id
            LEFT JOIN (SELECT catering_id, COUNT(*) AS events, SUM(CASE WHEN event_status <> 'CANCELLED' THEN contract_amount ELSE 0 END) AS booking_value
                FROM event_details WHERE is_active=1 AND status=1 AND created_at >= ? AND created_at < ? GROUP BY catering_id) e ON e.catering_id=c.id
            WHERE c.is_active=1 AND c.status=1 ORDER BY booking_value DESC, leads DESC, c.id LIMIT 10", array_merge($range, $range));
        $series = [];
        foreach (['caterers','users','payments','bookings'] as $key) {
            $series[$key] = [];
        }
        $labels = [];
        for ($cursor = $start; $cursor <= $now; $cursor = $cursor->modify('+1 month')) {
            $labels[] = $cursor->format('M Y');
            foreach ($series as &$values) { $values[$cursor->format('Y-m')] = 0; }
            unset($values);
        }
        $trends = [
            'caterers' => "SELECT DATE_FORMAT(created_at,'%Y-%m') AS period, COUNT(*) AS total FROM catering_info WHERE created_at >= ? AND created_at < ? GROUP BY period",
            'users' => "SELECT DATE_FORMAT(created_at,'%Y-%m') AS period, COUNT(*) AS total FROM user_info WHERE is_active=1 AND status=1 AND created_at >= ? AND created_at < ? GROUP BY period",
            'payments' => "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS period, SUM(amount) AS total FROM event_payment WHERE is_active=1 AND status=1 AND payment_status='SUCCESS' AND payment_date >= ? AND payment_date < ? GROUP BY period",
            'bookings' => "SELECT DATE_FORMAT(created_at,'%Y-%m') AS period, SUM(contract_amount) AS total FROM event_details WHERE is_active=1 AND status=1 AND event_status <> 'CANCELLED' AND created_at >= ? AND created_at < ? GROUP BY period",
        ];
        foreach ($trends as $key => $sql) {
            foreach ($query($sql, $range) as $row) { $series[$key][$row['period']] = (float) $row['total']; }
            $series[$key] = array_values($series[$key]);
        }
        $currencies = $query("SELECT DISTINCT COALESCE(NULLIF(currency,''),'UNSPECIFIED') AS currency FROM catering_info ORDER BY currency");
        $currency = count($currencies) === 1 ? $currencies[0]['currency'] : 'Mixed currencies';
        $platformBilling = [
            'active' => (int)$scalar("SELECT COUNT(DISTINCT user_id) FROM user_subscription WHERE status='ACTIVE' AND starts_on<=CURRENT_DATE AND ends_on>CURRENT_DATE"),
            'received' => (float)$scalar("SELECT COALESCE(SUM(amount),0) FROM user_subscription_payment WHERE status='PAID' AND paid_at>=? AND paid_at<?",$range),
        ];
        $db->commit();
        return compact('metrics', 'roles', 'events', 'leads', 'top', 'series', 'labels', 'currency', 'platformBilling') + [
            'months' => $months, 'start' => $start->format('Y-m-d'), 'end' => $now->format('Y-m-d'),
            'updated' => $now->format('Y-m-d H:i:s'),
        ];
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $error;
    }
}
