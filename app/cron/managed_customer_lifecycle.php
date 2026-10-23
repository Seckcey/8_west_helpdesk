<?php
/** Bounded default-off managed-customer physical containment worker. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/managed_customer_lifecycle.php';
require_once __DIR__ . '/../lib/managed_customer_id_evidence.php';

try {
    // No argument: the explicit approved scope (unchanged). `--scheduled`: the one bounded fair run
    // the report wrapper starts as its child (A82).
    $scheduled = ($argv[1] ?? null) === '--scheduled';
    if (count($argv) > ($scheduled ? 2 : 1)) {
        throw new ManagedCustomerLifecycleValidationException('Unknown managed-customer lifecycle arguments.');
    }
    $config = managed_customer_lifecycle_config(cfg('managed_customer_lifecycle', []));
    if ($config['enabled'] !== true) {
        echo "managed_customer_lifecycle: disabled\n";
        exit(0);
    }
    $failed = false;
    if ($scheduled) {
        $run = managed_customer_lifecycle_scheduled_run(
            db(),
            $config,
            cfg('business_reports', []),
            cfg('id_report_contacts', []),
        );
        $results = $run['results'];
        $failed = isset($run['stopped']);
        // `reserved` counts transition attempts reserved before each fetch/action, not commits (A141).
        echo json_encode(
            ['mode' => 'scheduled', 'claims' => $run['claims'], 'reserved' => $run['reserved'],
                'stopped' => $run['stopped'] ?? null],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    } else {
        $results = managed_customer_lifecycle_run(
            db(),
            $config,
            cfg('business_reports', []),
            cfg('id_report_contacts', []),
        );
    }
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
