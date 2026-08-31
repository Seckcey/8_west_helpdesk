<?php
/** Bounded default-off managed-customer physical containment worker. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/managed_customer_lifecycle.php';
require_once __DIR__ . '/../lib/managed_customer_id_evidence.php';

try {
    $config = managed_customer_lifecycle_config(cfg('managed_customer_lifecycle', []));
    if ($config['enabled'] !== true) {
        echo "managed_customer_lifecycle: disabled\n";
        exit(0);
    }
    $results = managed_customer_lifecycle_run(
        db(),
        $config,
        cfg('business_reports', []),
        cfg('id_report_contacts', []),
    );
    $failed = false;
    foreach ($results as $result) {
        if (($result['action'] ?? null) === 'refused') $failed = true;
        echo json_encode(
            $result,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }
    exit($failed ? 1 : 0);
} catch (Throwable $error) {
    fwrite(
        STDERR,
        'managed_customer_lifecycle: refused ' . hash('sha256', $error->getMessage()) . "\n",
    );
    exit(1);
}
