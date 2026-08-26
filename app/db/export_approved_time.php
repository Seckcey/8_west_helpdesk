<?php
/**
 * Export one explicitly identified approved, billable time entry to
 * Coastmark's draft-only receiver.
 *
 * Dry run:
 *   php db/export_approved_time.php --tenant-slug=8west --entry-id=123 \
 *     --entry-key=timer:... --dry-run
 *
 * Deliberate send (still requires the server-side feature gate + allowlists):
 *   php db/export_approved_time.php --tenant-slug=8west --entry-id=123 \
 *     --entry-key=timer:... --send
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/coastmark_time_export.php';

$options = [];
$argumentsValid = true;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run' || $argument === '--send') {
        $name = substr($argument, 2);
        if (array_key_exists($name, $options)) {
            $argumentsValid = false;
            break;
        }
        $options[$name] = true;
        continue;
    }
    if (preg_match('/\A--(tenant-slug|entry-id|entry-key)=(.+)\z/D', $argument, $parts) !== 1
        || array_key_exists($parts[1], $options)
    ) {
        $argumentsValid = false;
        break;
    }
    $options[$parts[1]] = $parts[2];
}
$tenantSlug = is_string($options['tenant-slug'] ?? null) ? $options['tenant-slug'] : '';
$entryKey = is_string($options['entry-key'] ?? null) ? $options['entry-key'] : '';
$entryIdRaw = $options['entry-id'] ?? null;
$dryRun = ($options['dry-run'] ?? false) === true;
$send = ($options['send'] ?? false) === true;

if (!$argumentsValid
    || count($options) !== 4
    || $dryRun === $send
    || !is_string($entryIdRaw)
    || preg_match('/\A[1-9][0-9]*\z/D', $entryIdRaw) !== 1
    || $tenantSlug === ''
    || $entryKey === ''
) {
    fwrite(
        STDERR,
        "Usage: php db/export_approved_time.php --tenant-slug=SLUG --entry-id=ID "
        . "--entry-key=KEY (--dry-run|--send)\n",
    );
    exit(2);
}

try {
    $config = cfg('coastmark_time_export', []);
    if (!is_array($config)) {
        throw new CoastmarkTimeExportValidationException('Coastmark time export configuration is invalid.');
    }
    $payload = coastmark_time_export_payload(
        db(),
        $tenantSlug,
        (int) $entryIdRaw,
        $entryKey,
        $config,
    );
    $payloadHash = coastmark_time_export_payload_hash($payload);

    if ($dryRun) {
        echo "DRY_RUN=PASS\n";
        echo 'TENANT_KEY=' . $payload['tenant_key'] . "\n";
        echo 'CLIENT_KEY=' . $payload['client_key'] . "\n";
        echo 'ENTRY_KEY=' . $payload['entry_key'] . "\n";
        echo 'PAYLOAD_SHA256=' . $payloadHash . "\n";
        echo "NO_NETWORK_REQUEST=1\n";
        exit(0);
    }

    $result = coastmark_time_export_send($payload, $config);
    echo "SEND_ACKNOWLEDGED=PASS\n";
    echo 'ACTION=' . $result['action'] . "\n";
    echo 'IMPORT_ID=' . $result['import_id'] . "\n";
    echo 'DRAFT_INVOICE_ID=' . $result['invoice_id'] . "\n";
    echo 'DRAFT_INVOICE_LINE_ID=' . $result['invoice_line_id'] . "\n";
    echo 'PAYLOAD_SHA256=' . $result['payload_sha256'] . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Export refused: ' . $error->getMessage() . "\n");
    exit(1);
}
