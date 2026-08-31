<?php
/**
 * POST /api/ticket_merge.php — merge one ticket into another.
 * Body: {source_id, target_id}. Moves messages, attachments, and
 * email-conversation mappings to the target; the source becomes a resolved
 * stub pointing at the survivor (merged_into_id). Approval-grade time stays
 * on that source stub so its captured ticket/client provenance never changes.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/render.php';

$user = require_login();
$in = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
csrf_check();

$src = (int)($in['source_id'] ?? 0);
$dst = (int)($in['target_id'] ?? 0);
if ($src < 1 || $dst < 1) json_out(['ok' => false, 'error' => 'Choose two valid tickets.'], 422);
if ($src === $dst) json_out(['ok' => false, 'error' => 'A ticket can’t merge into itself.'], 422);

$pdo = db();
$pdo->beginTransaction();
try {
    // Lock and validate both rows in the same transaction as the move. A
    // cross-customer merge would otherwise put one customer's messages on a
    // ticket visible to another customer through the portal.
    $q = $pdo->prepare(
        'SELECT id, subject, client_id, merged_into_id
           FROM tickets
          WHERE id IN (?, ?) AND tenant_id = ?
          FOR UPDATE'
    );
    $q->execute([$src, $dst, tenant_id()]);
    $rows = [];
    foreach ($q->fetchAll() as $r) $rows[(int)$r['id']] = $r;
    if (!isset($rows[$src], $rows[$dst])) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'Both tickets must exist.'], 404);
    }
    if ((int)$rows[$src]['client_id'] !== (int)$rows[$dst]['client_id']) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'Tickets may only be merged within the same customer.'], 422);
    }
    if (!empty($rows[$dst]['merged_into_id'])) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'Target #' . $dst . ' was itself merged — pick its survivor.'], 422);
    }
    if (!empty($rows[$src]['merged_into_id'])) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => '#' . $src . ' is already merged.'], 422);
    }

    $pdo->prepare('UPDATE messages     SET ticket_id = ? WHERE ticket_id = ?')->execute([$dst, $src]);
    $pdo->prepare('UPDATE attachments  SET ticket_id = ? WHERE ticket_id = ?')->execute([$dst, $src]);
    // conversation mappings follow the survivor (IGNORE: target may already map one)
    $pdo->prepare('UPDATE IGNORE email_threads SET ticket_id = ? WHERE ticket_id = ?')->execute([$dst, $src]);
    $pdo->prepare('DELETE FROM email_threads WHERE ticket_id = ?')->execute([$src]);
    $merged = $pdo->prepare(
        "UPDATE tickets
            SET status = 'resolved', resolved_at = UTC_TIMESTAMP(), merged_into_id = ?, resurface_at = NULL
          WHERE id = ? AND tenant_id = ? AND client_id = ? AND merged_into_id IS NULL"
    );
    $merged->execute([$dst, $src, tenant_id(), (int)$rows[$src]['client_id']]);
    if ($merged->rowCount() !== 1) {
        throw new RuntimeException('The source ticket changed during the merge.');
    }
    $sys = $pdo->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)');
    $sys->execute([$dst, 'Safeharbor', 'system',
        'Merged #' . $src . ' (' . mb_substr((string)$rows[$src]['subject'], 0, 80) . ') into this ticket — by ' . $user['full_name']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok' => false, 'error' => 'Merge failed — nothing was changed.'], 500);
}

json_out(['ok' => true, 'toast' => 'Merged #' . $src . ' into #' . $dst, 'target_id' => $dst]);
