<?php
/**
 * POST /api/ticket_merge.php — merge one ticket into another.
 * Body: {source_id, target_id}. Moves messages, attachments, and
 * email-conversation mappings to the target; the source becomes a resolved
 * stub pointing at the survivor (merged_into_id). Time remains on the source
 * stub so a later approval migration can preserve original provenance.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/render.php';

$user = require_login();
$in = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
csrf_check();

$src = (int)($in['source_id'] ?? 0);
$dst = (int)($in['target_id'] ?? 0);
if ($src === $dst) json_out(['ok' => false, 'error' => 'A ticket can’t merge into itself.'], 422);

$q = db()->prepare('SELECT id, subject, merged_into_id FROM tickets WHERE id IN (?, ?) AND tenant_id = ?');
$q->execute([$src, $dst, tenant_id()]);
$rows = [];
foreach ($q->fetchAll() as $r) $rows[(int)$r['id']] = $r;
if (!isset($rows[$src], $rows[$dst])) json_out(['ok' => false, 'error' => 'Both tickets must exist.'], 404);
if (!empty($rows[$dst]['merged_into_id'])) json_out(['ok' => false, 'error' => 'Target #' . $dst . ' was itself merged — pick its survivor.'], 422);
if (!empty($rows[$src]['merged_into_id'])) json_out(['ok' => false, 'error' => '#' . $src . ' is already merged.'], 422);

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE messages     SET ticket_id = ? WHERE ticket_id = ?')->execute([$dst, $src]);
    $pdo->prepare('UPDATE attachments  SET ticket_id = ? WHERE ticket_id = ?')->execute([$dst, $src]);
    // conversation mappings follow the survivor (IGNORE: target may already map one)
    $pdo->prepare('UPDATE IGNORE email_threads SET ticket_id = ? WHERE ticket_id = ?')->execute([$dst, $src]);
    $pdo->prepare('DELETE FROM email_threads WHERE ticket_id = ?')->execute([$src]);
    $pdo->prepare("UPDATE tickets SET status = 'resolved', resolved_at = UTC_TIMESTAMP(), merged_into_id = ?, resurface_at = NULL WHERE id = ?")
        ->execute([$dst, $src]);
    $sys = $pdo->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)');
    $sys->execute([$dst, 'Safeharbor', 'system',
        'Merged #' . $src . ' (' . mb_substr((string)$rows[$src]['subject'], 0, 80) . ') into this ticket — by ' . $user['full_name']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_out(['ok' => false, 'error' => 'Merge failed — nothing was changed.'], 500);
}

json_out(['ok' => true, 'toast' => 'Merged #' . $src . ' into #' . $dst, 'target_id' => $dst]);
