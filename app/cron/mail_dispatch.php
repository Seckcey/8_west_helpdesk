<?php
/**
 * Outbound dispatch — run every minute via cron (Milepost pattern):
 *   * * * * * php /srv/8west/apps/safeharbor/current/cron/mail_dispatch.php
 * Sends everything due in mail_queue, with retry + failure marking.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit('CLI only.');

require_once __DIR__ . '/../lib/mailer.php';

$due = db()->query(
    'SELECT * FROM mail_queue
      WHERE sent_at IS NULL AND next_try_at <= UTC_TIMESTAMP() AND attempts < max_attempts
      ORDER BY id ASC LIMIT 25'
)->fetchAll();

$sent = 0;
$failed = 0;
foreach ($due as $mail) {
    $error = null;
    if (mail_send($mail['to_addr'], $mail['subject'], $mail['body_text'], $error)) {
        db()->prepare('UPDATE mail_queue SET sent_at = UTC_TIMESTAMP(), last_error = "" WHERE id = ?')
            ->execute([(int)$mail['id']]);
        $sent++;
    } else {
        // backoff: try again in attempts^2 minutes
        $delay = max(1, ((int)$mail['attempts'] + 1) ** 2);
        db()->prepare('UPDATE mail_queue SET attempts = attempts + 1, last_error = ?, next_try_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE id = ?')
            ->execute([mb_substr((string)$error, 0, 255), $delay, (int)$mail['id']]);
        $failed++;
    }
}

if ($due) echo "[" . gmdate('c') . " mail_dispatch: {$sent} sent, {$failed} deferred of " . count($due) . "]\n";

// Piggybacked housekeeping (waiting-ticket resurface) — no extra cron needed.
require_once __DIR__ . '/../lib/housekeeping.php';
$hk = housekeeping_run();
if ($hk > 0) echo "[" . gmdate('c') . " housekeeping: {$hk} waiting tickets resurfaced]\n";
