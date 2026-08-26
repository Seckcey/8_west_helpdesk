<?php
/**
 * Approved billable-hours CSV for owner/admin review and controlled handoff.
 *   GET /reports_export.php?days=30
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
enforce_https();
$user = require_login();
if (!in_array((string)$user['role'], ['owner', 'admin'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Owner or admin role required.';
    exit;
}

$days = max(1, min(365, (int)($_GET['days'] ?? 30)));

$q = db()->prepare(
    "SELECT e.id AS time_entry_id, c.name AS client, t.id AS ticket_id, t.subject,
            u.full_name AS tech, e.minutes, e.note, e.worked_at, e.reviewed_at
       FROM time_entries e
       JOIN tickets t ON t.id = e.ticket_id AND t.tenant_id = e.tenant_id
       JOIN clients c ON c.id = e.client_id AND c.tenant_id = e.tenant_id
       JOIN users u   ON u.id = e.user_id AND u.tenant_id = e.tenant_id
      WHERE e.tenant_id = ? AND e.approval_status = 'approved' AND e.billable = 1
        AND e.worked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
      ORDER BY c.name, e.worked_at, e.id"
);
$q->execute([(int)$user['tenant_id'], $days]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="safeharbor-approved-billable-' . $days . 'd-' . gmdate('Ymd') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['time_entry_id', 'client', 'ticket', 'subject', 'tech', 'minutes', 'hours', 'note', 'worked_at_utc', 'approved_at_utc']);
foreach ($q->fetchAll() as $r) {
    fputcsv($out, [
        $r['time_entry_id'], time_entry_csv_safe_cell($r['client']), '#' . $r['ticket_id'],
        time_entry_csv_safe_cell($r['subject']), time_entry_csv_safe_cell($r['tech']), $r['minutes'],
        round((int)$r['minutes'] / 60, 2), time_entry_csv_safe_cell($r['note']),
        $r['worked_at'], $r['reviewed_at'],
    ]);
}
fclose($out);
