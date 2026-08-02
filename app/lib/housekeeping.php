<?php
/**
 * Housekeeping — the queue's self-tending: reopen Waiting tickets whose
 * resurface date passed with no client reply (a parked ticket must never
 * be a forgotten ticket). Called by cron/housekeeping.php and piggybacked
 * on the 1-min mail dispatch so it needs no extra cron entry.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function housekeeping_run(): int
{
    $resurfaced = 0;
    try {
        $q = db()->query(
            "SELECT id FROM tickets
              WHERE status = 'waiting' AND resurface_at IS NOT NULL AND resurface_at <= UTC_TIMESTAMP()"
        );
        foreach ($q->fetchAll() as $t) {
            db()->prepare("UPDATE tickets SET status = 'open', resurface_at = NULL WHERE id = ?")
                ->execute([(int)$t['id']]);
            db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
                ->execute([(int)$t['id'], 'Safeharbor', 'system',
                           'No client reply while Waiting — resurfaced to Open']);
            $resurfaced++;
        }
    } catch (Throwable $e) {
        // housekeeping must never take the caller down (e.g. pre-migration)
    }
    return $resurfaced;
}
