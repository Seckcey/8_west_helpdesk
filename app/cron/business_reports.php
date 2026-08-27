<?php
/**
 * Bounded scheduled runner for archived weekly client service summaries.
 * It is inert while both gates are false. Each schedule catches up at most one
 * missed period per invocation; pending delivery is enumerated independently.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/business_reports.php';

$config = cfg('business_reports', []);
if (!is_array($config)) {
    fwrite(STDERR, "Business report configuration is invalid.\n");
    exit(1);
}

try {
    $normalized = business_report_config($config);
} catch (Throwable $error) {
    fwrite(STDERR, 'Business report configuration refused: ' . $error->getMessage() . "\n");
    exit(1);
}

if ($normalized['generation_enabled'] !== true && $normalized['delivery_enabled'] !== true) {
    echo "BUSINESS_REPORTS_INERT=1\n";
    exit(0);
}

$pdo = db();
$lock = $pdo->query("SELECT GET_LOCK('safeharbor_business_reports_v1', 0)")->fetchColumn();
if ((int)$lock !== 1) {
    echo "BUSINESS_REPORTS_ALREADY_RUNNING=1\n";
    exit(0);
}

$generated = 0;
$submitted = 0;
$uncertain = 0;
$errors = 0;
try {
    if ($normalized['generation_enabled'] === true) {
        foreach (business_report_due_schedule_keys($pdo, time(), BUSINESS_REPORT_MAX_DUE_SCHEDULES, $config) as $due) {
            try {
                $operationNow = time();
                $result = business_report_generate(
                    $pdo,
                    $due['tenant_slug'],
                    $due['schedule_key'],
                    $config,
                    $operationNow,
                    false,
                    true,
                );
                if ($result['action'] === 'created') $generated++;
            } catch (Throwable $error) {
                $errors++;
                fwrite(
                    STDERR,
                    'Business report generation refused for '
                    . $due['tenant_slug'] . '/' . $due['schedule_key'] . ': '
                    . $error->getMessage() . "\n",
                );
            }
        }
    }

    if ($normalized['delivery_enabled'] === true) {
        foreach (business_report_pending_archive_ids($pdo, time(), BUSINESS_REPORT_MAX_DUE_SCHEDULES, $config) as $archiveId) {
            try {
                $result = business_report_deliver($pdo, $archiveId, $config, null, time());
                if ($result['status'] === 'submitted') $submitted++;
                if ($result['status'] === 'uncertain') $uncertain++;
            } catch (Throwable $error) {
                $errors++;
                fwrite(STDERR, "Business report delivery refused for archive {$archiveId}: " . $error->getMessage() . "\n");
            }
        }
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('safeharbor_business_reports_v1')");
}

echo 'GENERATED=' . $generated . "\n";
echo 'SUBMITTED=' . $submitted . "\n";
echo 'UNCERTAIN=' . $uncertain . "\n";
echo 'ERRORS=' . $errors . "\n";
exit($errors === 0 ? 0 : 1);
