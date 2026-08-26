<?php
/**
 * POST /api/ticket_action.php — optimistic field updates from keyboard
 * or rail buttons. Body: {id, field, value} with strict whitelists.
 * Returns the updated chip/glyph HTML fragments for in-place repaint.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/render.php';

$user = require_login();
$in = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
csrf_check();

$id    = (int)($in['id'] ?? 0);
$field = (string)($in['field'] ?? '');
$value = (string)($in['value'] ?? '');

$STATUS = ['open', 'in_progress', 'waiting', 'resolved'];
$PRIORITY = ['low', 'normal', 'high', 'urgent'];

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
        if (!in_array($value, $STATUS, true)) json_out(['ok' => false, 'error' => 'bad status'], 422);
        $resolved  = $value === 'resolved' ? 'NOW()' : 'NULL';
        // Waiting parks the ticket with a 72h leash; housekeeping resurfaces it.
        $resurface = $value === 'waiting' ? 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 72 HOUR)' : 'NULL';
        db()->prepare("UPDATE tickets SET status = ?, resolved_at = $resolved, resurface_at = $resurface WHERE id = ?")->execute([$value, $id]);
        if ($value === 'resolved') csat_send($ticket);
        $toast = 'Status → ' . STATUS_META[$value][0] . ($value === 'waiting' ? ' (auto-resurfaces in 3 days)' : '');
        break;

    case 'priority':
        if (!in_array($value, $PRIORITY, true)) json_out(['ok' => false, 'error' => 'bad priority'], 422);
        db()->prepare('UPDATE tickets SET priority = ? WHERE id = ?')->execute([$value, $id]);
        $toast = 'Priority → ' . PRIORITY_META[$value][0];
        break;

    case 'assignee':
        // value: "me" toggles current user / unassign; a numeric id assigns
        // a teammate (and emails them — accountability without watching).
        if ($value === 'me') {
            $target = ((int)$ticket['assignee_id'] !== (int)$user['id']) ? (int)$user['id'] : null;
        } else {
            $tq2 = db()->prepare('SELECT id FROM users WHERE id = ? AND tenant_id = ? AND is_active = 1');
            $tq2->execute([(int)$value, tenant_id()]);
            $target = $tq2->fetch() ? (int)$value : null;
        }
        db()->prepare('UPDATE tickets SET assignee_id = ? WHERE id = ?')->execute([$target, $id]);
        if ($target !== null && $target !== (int)$user['id']) {
            require_once __DIR__ . '/../../lib/mailer.php';
            $eq = db()->prepare('SELECT email, full_name FROM users WHERE id = ?');
            $eq->execute([$target]);
            if ($assignee = $eq->fetch()) {
                mail_queue(
                    (string)$assignee['email'],
                    '[#' . $id . '] Assigned to you: ' . $ticket['subject'],
                    explode(' ', $user['full_name'])[0] . " assigned you ticket #{$id}:\n\n"
                      . $ticket['subject'] . "\n\n"
                      . 'Open: https://safeharbor.8westit.com/ticket.php?id=' . $id,
                    $id
                );
            }
        }
        $toast = $target ? 'Assigned' . ($target === (int)$user['id'] ? ' to ' . explode(' ', $user['full_name'])[0] : '') : 'Unassigned';
        break;

    default:
        json_out(['ok' => false, 'error' => 'unknown field'], 422);
}

/**
 * One-click CSAT on resolve: first resolve of a ticket with a real human
 * contact queues the 3-face survey email. Idempotent (unique per ticket);
 * machine addresses never get one.
 */
function csat_send(array $ticket): void
{
    if (empty($ticket['contact_id'])) return;
    try {
        $kq = db()->prepare('SELECT email, name FROM contacts WHERE id = ?');
        $kq->execute([(int)$ticket['contact_id']]);
        $contact = $kq->fetch();
        $email = mb_strtolower(trim((string)($contact['email'] ?? '')));
        if ($email === '' || preg_match('/^(mailer-daemon|postmaster|no-?reply|donotreply|bounce[s]?|autoreply)@/i', $email)) return;

        $token = bin2hex(random_bytes(20));
        $ins = db()->prepare('INSERT IGNORE INTO csat (ticket_id, token) VALUES (?,?)');
        $ins->execute([(int)$ticket['id'], $token]);
        if ($ins->rowCount() === 0) return;   // already surveyed — resolve toggling never re-asks

        require_once __DIR__ . '/../../lib/mailer.php';
        $first = explode(' ', trim((string)($contact['name'] ?? '')))[0] ?: 'there';
        $base = 'https://safeharbor.8westit.com/csat.php?t=' . $token;
        mail_queue(
            $email,
            '[#' . (int)$ticket['id'] . '] Resolved: ' . $ticket['subject'],
            "Hi {$first},\n\nYour ticket is resolved. How did we do? One tap answers:\n\n"
              . "  Great:  {$base}&s=3\n  Okay:   {$base}&s=2\n  Rough:  {$base}&s=1\n\n"
              . "Reply to this email any time to reopen the ticket.\n— Safeharbor by 8 West IT",
            (int)$ticket['id']
        );
    } catch (Throwable $e) {
        // surveys must never block resolving (e.g. pre-migration)
    }
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
    'chip'    => status_chip($t['status']),
    'pri'     => priority_glyph($t['priority'], true),
    'sla'     => sla_lamp($t),
    'assignee_html' => '<span class="assignee-cell">' . avatar($assignee, 22) . '<span class="assignee-name">' . h($assignee['full_name'] ?? 'Unassigned') . '</span></span>',
    'assignee_avatar' => avatar($assignee),
]);
