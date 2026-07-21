<?php
/**
 * POST /api/timer.php — log a time entry when a timer stops.
 * Body: {ticket_id, minutes, note?, billable?}
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/render.php';

$user = require_login();
$in = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
csrf_check();

$ticketId = (int)($in['ticket_id'] ?? 0);
$minutes  = max(1, min(24 * 60, (int)($in['minutes'] ?? 0)));
$note     = mb_substr(trim((string)($in['note'] ?? '')), 0, 255);
$billable = !empty($in['billable']) ? 1 : 0;

$tq = db()->prepare('SELECT id FROM tickets WHERE id = ? AND tenant_id = ?');
$tq->execute([$ticketId, tenant_id()]);
if (!$tq->fetch()) json_out(['ok' => false, 'error' => 'ticket not found'], 404);

if ($note === '') $note = 'Work on #' . $ticketId;

db()->prepare('INSERT INTO time_entries (ticket_id, user_id, minutes, note, billable) VALUES (?,?,?,?,?)')
    ->execute([$ticketId, (int)$user['id'], $minutes, $note, $billable]);

json_out([
    'ok'    => true,
    'toast' => "Time logged · {$minutes}m on #{$ticketId}",
    'entry' => ['ticket_id' => $ticketId, 'minutes' => $minutes, 'note' => $note, 'billable' => $billable],
]);
