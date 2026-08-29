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
            u.full_name AS tech, e.minutes AS original_minutes,
            COALESCE(adjustment.effective_minutes, e.minutes) AS effective_minutes,
            e.billable AS original_billable,
            COALESCE(adjustment.effective_billable, e.billable) AS effective_billable,
            e.note, e.worked_at, e.reviewed_at,
            adjustment.version_no AS adjustment_version,
            adjustment.reason AS adjustment_reason,
            adjustment.created_at AS adjusted_at
       FROM time_entries e
       JOIN tickets t ON t.id = e.ticket_id AND t.tenant_id = e.tenant_id
       JOIN clients c ON c.id = e.client_id AND c.tenant_id = e.tenant_id
       JOIN users u   ON u.id = e.user_id AND u.tenant_id = e.tenant_id
       LEFT JOIN time_entry_approval_adjustments adjustment
         ON adjustment.tenant_id = e.tenant_id
        AND adjustment.time_entry_id = e.id
        AND adjustment.version_no = (
            SELECT MAX(latest.version_no)
              FROM time_entry_approval_adjustments latest
             WHERE latest.tenant_id = e.tenant_id AND latest.time_entry_id = e.id
        )
      WHERE e.tenant_id = ? AND e.approval_status = 'approved'
        AND COALESCE(adjustment.effective_billable, e.billable) = 1
        AND e.worked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
      ORDER BY c.name, e.worked_at, e.id"
);
$q->execute([(int)$user['tenant_id'], $days]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="safeharbor-approved-billable-' . $days . 'd-' . gmdate('Ymd') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, [
    'time_entry_id', 'client', 'ticket', 'subject', 'tech',
    'original_minutes', 'effective_minutes', 'effective_hours',
    'original_billable', 'effective_billable', 'original_note',
    'adjustment_version', 'adjustment_reason', 'adjusted_at_utc',
    'worked_at_utc', 'approved_at_utc',
]);
foreach ($q->fetchAll() as $r) {
    fputcsv($out, [
        $r['time_entry_id'], time_entry_csv_safe_cell($r['client']), '#' . $r['ticket_id'],
        time_entry_csv_safe_cell($r['subject']), time_entry_csv_safe_cell($r['tech']),
        $r['original_minutes'], $r['effective_minutes'],
        round((int)$r['effective_minutes'] / 60, 2),
        $r['original_billable'], $r['effective_billable'], time_entry_csv_safe_cell($r['note']),
        $r['adjustment_version'], time_entry_csv_safe_cell((string)($r['adjustment_reason'] ?? '')),
        $r['adjusted_at'],
        $r['worked_at'], $r['reviewed_at'],
    ]);
}
fclose($out);
