<?php
/** Exact one-report operator runner. No batch mode and no recipient output. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/business_reports.php';

/** @return array<string,string|bool> */
function business_report_run_options(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (in_array($argument, ['--dry-run', '--generate', '--deliver'], true)) {
            $key = substr($argument, 2);
            if (array_key_exists($key, $options)) {
                throw new BusinessReportValidationException('Duplicate report mode.');
            }
            $options[$key] = true;
            continue;
        }
        if (preg_match('/\A--(tenant-slug|schedule-key|archive-id|content-sha256)=(.+)\z/D', $argument, $parts) !== 1
            || array_key_exists($parts[1], $options)
        ) {
            throw new BusinessReportValidationException('Report runner arguments are invalid.');
        }
        $options[$parts[1]] = $parts[2];
    }
    return $options;
}

$usage = "Usage:\n"
    . "  php db/run_business_report.php --tenant-slug=SLUG --schedule-key=KEY (--dry-run|--generate)\n"
    . "  php db/run_business_report.php --tenant-slug=SLUG --schedule-key=KEY --archive-id=ID --content-sha256=HASH --deliver\n";

try {
    $options = business_report_run_options(array_slice($argv, 1));
    $modes = array_values(array_filter(
        ['dry-run', 'generate', 'deliver'],
        static fn(string $mode): bool => ($options[$mode] ?? false) === true,
    ));
    if (count($modes) !== 1
        || !is_string($options['tenant-slug'] ?? null)
        || !is_string($options['schedule-key'] ?? null)
    ) {
        throw new BusinessReportValidationException('Select one exact report mode and scope.');
    }
    $mode = $modes[0];
    $expected = $mode === 'deliver'
        ? ['tenant-slug', 'schedule-key', 'archive-id', 'content-sha256', 'deliver']
        : ['tenant-slug', 'schedule-key', $mode];
    $actual = array_keys($options);
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        throw new BusinessReportValidationException('The report mode requires an exact option set.');
    }
    business_report_slug((string)$options['tenant-slug']);
    business_report_schedule_key((string)$options['schedule-key']);
    $rawConfig = cfg('business_reports', []);
    if (!is_array($rawConfig)) {
        throw new BusinessReportValidationException('Business report configuration is invalid.');
    }
    $pdo = db();

    if ($mode === 'dry-run' || $mode === 'generate') {
        $result = business_report_generate(
            $pdo,
            (string)$options['tenant-slug'],
            (string)$options['schedule-key'],
            $rawConfig,
            null,
            $mode === 'dry-run',
            $mode !== 'dry-run',
        );
        echo 'ACTION=' . $result['action'] . "\n";
        echo 'ARCHIVE_ID=' . ($result['archive']['id'] === null ? 'NONE' : (int)$result['archive']['id']) . "\n";
        echo 'CONTENT_SHA256=' . (string)$result['archive']['content_sha256'] . "\n";
        echo 'PERIOD_START=' . (string)$result['archive']['period_start'] . "\n";
        echo 'PERIOD_END=' . (string)$result['archive']['period_end'] . "\n";
        echo 'TICKETS_OPENED=' . (int)$result['metrics']['tickets']['opened'] . "\n";
        echo 'APPROVED_BILLABLE_MINUTES=' . (int)$result['metrics']['approved_billable_time']['minutes'] . "\n";
        echo 'NO_NETWORK_REQUEST=1' . "\n";
        exit(0);
    }

    $archiveId = (string)$options['archive-id'];
    $expectedHash = (string)$options['content-sha256'];
    if (preg_match('/\A[1-9][0-9]*\z/D', $archiveId) !== 1
        || preg_match('/\A[0-9a-f]{64}\z/D', $expectedHash) !== 1
    ) {
        throw new BusinessReportValidationException('Archive id and content hash must be exact.');
    }
    $context = business_report_delivery_context($pdo, (int)$archiveId);
    if ((string)$context['tenant_slug'] !== $options['tenant-slug']
        || (string)$context['schedule_key'] !== $options['schedule-key']
        || !hash_equals((string)$context['content_sha256'], $expectedHash)
    ) {
        throw new BusinessReportGateException('Archive scope or content hash did not match exactly.');
    }
    $result = business_report_deliver($pdo, (int)$archiveId, $rawConfig);
    echo 'ACTION=' . $result['action'] . "\n";
    echo 'ARCHIVE_ID=' . $result['archive_id'] . "\n";
    echo 'ATTEMPT_ID=' . ($result['attempt_id'] ?? 'NONE') . "\n";
    echo 'STATUS=' . $result['status'] . "\n";
    echo 'DELIVERY_TRUTH=' . ($result['status'] === 'submitted'
        ? 'PROVIDER_ACCEPTED_NOT_RECIPIENT_DELIVERED'
        : 'NOT_CONFIRMED') . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Business report run refused: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $usage);
    exit($error instanceof BusinessReportValidationException ? 2 : 1);
}
