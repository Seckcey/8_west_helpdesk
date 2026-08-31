<?php
/** Bounded default-off managed-customer activation worker. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/managed_customer_activation.php';
require_once __DIR__ . '/../lib/managed_customer_id_evidence.php';

try {
    $activationConfig = managed_customer_activation_config(
        cfg('managed_customer_activation', []),
    );
    if ($activationConfig['enabled'] !== true) {
        echo "managed_customer_activation: disabled\n";
        exit(0);
    }
    $businessReportConfig = cfg('business_reports', []);
    $idConfig = cfg('id_report_contacts', []);
    if (!is_array($businessReportConfig) || !is_array($idConfig)) {
        throw new ManagedCustomerActivationValidationException(
            'Managed-customer activation dependencies are invalid.',
        );
    }
    $results = managed_customer_activation_run(
        db(),
        $activationConfig,
        $businessReportConfig,
        $idConfig,
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
        'managed_customer_activation: refused ' . hash('sha256', $error->getMessage()) . "\n",
    );
    exit(1);
}
