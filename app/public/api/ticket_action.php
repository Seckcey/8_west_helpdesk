<?php
/**
 * POST /api/ticket_action.php — one field change from the ticket menus, the keyboard or the
 * Resolve button. Body: {id, field, value[, chosen][, undo]} with strict whitelists; the rules for each
 * field live in lib/ticket_changes.php. Returns the updated tag HTML for in-place repaint.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/render.php';
require_once __DIR__ . '/../../lib/ticket_changes.php';

$user = require_login();
$in = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
csrf_check();

$id    = (int)($in['id'] ?? 0);
$field = (string)($in['field'] ?? '');
$value = (string)($in['value'] ?? '');
// An Undo puts a value back. It never asks a customer for a rating and never emails a teammate.
$undo  = ($in['undo'] ?? false) === true;

$tq = db()->prepare(
    'SELECT t.*,
            (SELECT MIN(m.created_at) FROM messages m WHERE m.ticket_id = t.id AND m.kind = "tech") AS first_response_at,
            EXISTS (SELECT 1 FROM tickets merged_source WHERE merged_source.tenant_id = t.tenant_id AND merged_source.merged_into_id = t.id) AS has_merged_sources
       FROM tickets t
      WHERE t.id = ? AND t.tenant_id = ?'
);
$tq->execute([$id, tenant_id()]);
$ticket = $tq->fetch();
if (!$ticket) json_out(['ok' => false, 'error' => 'not found'], 404);

$toast = '';
switch ($field) {
    case 'status':
        if (!array_key_exists($value, STATUS_META)) json_out(['ok' => false, 'error' => 'bad status'], 422);
        // "chosen" is sent only when a person picked this exact status (a menu, the Resolve button).
        // Without it a resolve still saves, but never asks the customer for a rating.
        $change = ticket_change_status($ticket, $value, !$undo && ($in['chosen'] ?? false) === true);
        $toast = $change['toast'];
        break;

    case 'priority':
        if (!array_key_exists($value, PRIORITY_META)) json_out(['ok' => false, 'error' => 'bad priority'], 422);
        $toast = ticket_change_priority($ticket, $value)['toast'];
        break;

    case 'assignee':
        $toast = ticket_change_assignee($ticket, $user, $value, !$undo)['toast'];
        break;

    default:
        json_out(['ok' => false, 'error' => 'unknown field'], 422);
}

// Fresh row for repaint fragments
$tq->execute([$id, tenant_id()]);
$t = $tq->fetch();
$aq = db()->prepare('SELECT full_name, initials, color FROM users WHERE id = ?');
$aq->execute([(int)($t['assignee_id'] ?? 0)]);
$assignee = $aq->fetch() ?: null;

json_out([
    'ok'      => true,
    'toast'   => $toast,
    'status'  => $t['status'],
    'priority'=> $t['priority'],
    'assignee_id' => $t['assignee_id'] !== null ? (int)$t['assignee_id'] : null,
    'chip'    => status_chip($t['status']),
    'pri'     => priority_glyph($t['priority'], true),
    'sla'     => sla_lamp($t),
    'assignee_html' => '<span class="assignee-cell">' . avatar($assignee, 22) . '<span class="assignee-name">' . h($assignee['full_name'] ?? 'Unassigned') . '</span></span>',
    'assignee_avatar' => avatar($assignee),
]);
