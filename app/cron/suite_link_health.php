<?php
declare(strict_types=1);
// On demand, read-only: php app/cron/suite_link_health.php
// Prints whether the managed-customer activation switch is on, then one JSON object per client:
// its sync binding and its customer-portal binding. It writes nothing and prints no email, note or
// request hash. There is no schedule.
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/suite_link_health.php';
try {
    if (count($argv) !== 1) throw new InvalidArgumentException('usage: suite_link_health.php');
    $activation = cfg('managed_customer_activation', []);
    echo json_encode(['managed_customer_activation_enabled' => is_array($activation) && ($activation['enabled'] ?? null) === true],
        JSON_THROW_ON_ERROR) . "\n";
    foreach (suite_link_health(db()) as $row) echo json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'link health check refused: ' . ($error instanceof InvalidArgumentException ? $error->getMessage() : get_class($error)) . "\n");
    exit(1);
}
