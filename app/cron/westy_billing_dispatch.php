<?php
/** Operator-installed optional cron; no browser entry and no invoice email. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/westy_billing.php';
if (cfg('westy_billing_handoff.enabled',false)!==true) exit(0);
try {
    $config=westy_billing_settings((array)cfg('westy_billing_handoff',[]));
    $rows=db()->query("SELECT id FROM westy_billing_outbox WHERE state NOT IN ('accepted','blocked') AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP()) ORDER BY id LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $id) {
        $state=westy_billing_dispatch(db(),(int)$id,$config);
        echo 'Handoff '.(int)$id.': '.$state."\n";
    }
} catch (Throwable $error) { error_log('Westy billing worker: '.$error::class); exit(1); }
