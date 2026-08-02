<?php
/**
 * Billable-hours CSV — invoice-ready per client per period.
 *   GET /reports_export.php?days=30
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
enforce_https();
$user = require_login();

$days = max(1, min(365, (int)($_GET['days'] ?? 30)));

$q = db()->prepare(
    "SELECT c.name AS client, t.id AS ticket_id, t.subject,
            u.full_name AS tech, e.minutes, e.note, e.created_at
       FROM time_entries e
       JOIN tickets t ON t.id = e.ticket_id
       JOIN clients c ON c.id = t.client_id
       JOIN users u   ON u.id = e.user_id
      WHERE t.tenant_id = ? AND e.billable = 1
        AND e.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
      ORDER BY c.name, e.created_at"
);
$q->execute([tenant_id(), $days]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="safeharbor-billable-' . $days . 'd-' . gmdate('Ymd') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['client', 'ticket', 'subject', 'tech', 'minutes', 'hours', 'note', 'logged_at_utc']);
foreach ($q->fetchAll() as $r) {
    fputcsv($out, [
        $r['client'], '#' . $r['ticket_id'], $r['subject'], $r['tech'],
        $r['minutes'], round((int)$r['minutes'] / 60, 2), $r['note'], $r['created_at'],
    ]);
}
fclose($out);
