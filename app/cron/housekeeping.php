<?php
/**
 * Standalone housekeeping run (also piggybacked on mail_dispatch, so a
 * dedicated cron entry is optional):
 *   * * * * * php /srv/8west/apps/safeharbor/current/cron/housekeeping.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit('CLI only.');

require_once __DIR__ . '/../lib/housekeeping.php';

$n = housekeeping_run();
if ($n > 0) echo "[" . gmdate('c') . " housekeeping: {$n} waiting tickets resurfaced]\n";
