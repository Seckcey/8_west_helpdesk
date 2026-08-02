<?php
/**
 * POST /api/presence.php — collision detection heartbeat + roster.
 * Body: {ticket_id, mode: viewing|typing}. Upserts my presence, returns
 * everyone ELSE on the ticket in the last 40s. Help Scout's default-on
 * feature, Safeharbor flavor.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/render.php';

$user = require_login();
$in = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
csrf_check();

$ticketId = (int)($in['ticket_id'] ?? 0);
$mode = in_array($in['mode'] ?? '', ['viewing', 'typing'], true) ? $in['mode'] : 'viewing';

$tq = db()->prepare('SELECT id FROM tickets WHERE id = ? AND tenant_id = ?');
$tq->execute([$ticketId, tenant_id()]);
if (!$tq->fetch()) json_out(['ok' => false, 'error' => 'not found'], 404);

db()->prepare(
    'INSERT INTO ticket_presence (ticket_id, user_id, mode, last_seen) VALUES (?,?,?,UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE mode = VALUES(mode), last_seen = UTC_TIMESTAMP()'
)->execute([$ticketId, (int)$user['id'], $mode]);

$others = db()->prepare(
    'SELECT u.full_name, u.initials, u.color, p.mode
       FROM ticket_presence p JOIN users u ON u.id = p.user_id
      WHERE p.ticket_id = ? AND p.user_id != ? AND p.last_seen > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 SECOND)
      ORDER BY p.mode DESC, u.full_name'
);
$others->execute([$ticketId, (int)$user['id']]);

json_out(['ok' => true, 'others' => array_map(static fn($r) => [
    'name' => explode(' ', trim((string)$r['full_name']))[0],
    'initials' => $r['initials'], 'color' => $r['color'], 'mode' => $r['mode'],
], $others->fetchAll())]);
