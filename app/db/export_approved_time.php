<?php
/**
 * One-entry operator CLI for Coastmark v3 claims, delivery, and status.
 * There is intentionally no batch, cron, queue, or automatic retry mode.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/coastmark_time_export.php';

$options = [];
$valid = true;
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--claim', '--send', '--status', '--inspect-claim'], true)) {
        $name = substr($argument, 2);
        if (array_key_exists($name, $options)) {
            $valid = false;
            break;
        }
        $options[$name] = true;
        continue;
    }
    if (preg_match(
        '/\A--(tenant-slug|entry-id|entry-key|actor-user-id|claim-id)=([^\r\n]+)\z/D',
        $argument,
        $parts,
    ) !== 1 || array_key_exists($parts[1], $options)) {
        $valid = false;
        break;
    }
    $options[$parts[1]] = $parts[2];
}

$modes = array_values(array_filter(
    ['claim', 'send', 'status', 'inspect-claim'],
    fn(string $mode): bool => ($options[$mode] ?? false) === true,
));
$claimMode = $modes === ['claim'];
$claimIdMode = count($modes) === 1 && in_array($modes[0], ['send', 'status', 'inspect-claim'], true);
$entryId = $options['entry-id'] ?? null;
$actorId = $options['actor-user-id'] ?? null;
$claimId = $options['claim-id'] ?? null;
$integer = static fn(mixed $value): bool => is_string($value)
    && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1;

if (!$valid
    || (!$claimMode && !$claimIdMode)
    || ($claimMode && (
        count($options) !== 5
        || !is_string($options['tenant-slug'] ?? null)
        || !is_string($options['entry-key'] ?? null)
        || !$integer($entryId)
        || !$integer($actorId)
    ))
    || ($claimIdMode && (count($options) !== 2 || !$integer($claimId)))
) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php db/export_approved_time.php --claim --tenant-slug=SLUG --entry-id=ID --entry-key=KEY --actor-user-id=ID\n");
    fwrite(STDERR, "  php db/export_approved_time.php (--send|--status|--inspect-claim) --claim-id=ID\n");
    exit(2);
}

try {
    $config = cfg('coastmark_time_export', []);
    if (!is_array($config)) {
        throw new CoastmarkTimeExportValidationException('Coastmark export configuration is invalid.');
    }
    $exportDb = coastmark_time_export_database((array) cfg('db', []), $config);

    if ($claimMode) {
        $result = coastmark_time_export_claim(
            $exportDb,
            (string) $options['tenant-slug'],
            (int) $entryId,
            (string) $options['entry-key'],
            (int) $actorId,
            $config,
        );
        echo 'CLAIM=' . ($result['replayed'] ? 'EXACT_REPLAY' : 'CREATED') . "\n";
        echo 'CLAIM_ID=' . (int) $result['claim']['id'] . "\n";
        echo 'EVENT_KEY=' . $result['claim']['event_key'] . "\n";
        echo 'SOURCE_VERSION=' . (int) $result['claim']['source_version'] . "\n";
        echo 'PAYLOAD_SHA256=' . $result['claim']['payload_sha256'] . "\n";
        echo "NO_NETWORK_REQUEST=1\n";
        exit(0);
    }

    if ($modes[0] === 'inspect-claim') {
        $pdo = $exportDb;
        $query = $pdo->prepare(
            'SELECT claim.*,tenant.slug AS tenant_slug
               FROM coastmark_time_export_claims claim
               JOIN tenants tenant ON tenant.id=claim.tenant_id
              WHERE claim.id=?'
        );
        $query->execute([(int) $claimId]);
        $claim = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($claim)) {
            throw new CoastmarkTimeExportValidationException('Claim was not found.');
        }
        $receipt = coastmark_time_export_latest_receipt($pdo, (int) $claim['id']);
        echo "CLAIM=FOUND\n";
        echo 'CLAIM_ID=' . (int) $claim['id'] . "\n";
        echo 'TENANT_KEY=' . $claim['tenant_slug'] . "\n";
        echo 'EVENT_KEY=' . $claim['event_key'] . "\n";
        echo 'SOURCE_VERSION=' . (int) $claim['source_version'] . "\n";
        echo 'PAYLOAD_SHA256=' . $claim['payload_sha256'] . "\n";
        echo 'LATEST_OUTCOME=' . ($receipt['outcome'] ?? 'unattempted') . "\n";
        echo "NO_NETWORK_REQUEST=1\n";
        exit(0);
    }

    $result = $modes[0] === 'send'
        ? coastmark_time_export_send_claim($exportDb, (int) $claimId, $config)
        : coastmark_time_export_status_claim($exportDb, (int) $claimId, $config);
    echo strtoupper($modes[0]) . "=RECORDED\n";
    echo 'CLAIM_ID=' . $result['claim_id'] . "\n";
    echo 'EVENT_KEY=' . $result['event_key'] . "\n";
    echo 'SOURCE_VERSION=' . $result['source_version'] . "\n";
    echo 'OUTCOME=' . $result['outcome'] . "\n";
    echo 'COASTMARK_EVENT_ID=' . ($result['coastmark_event_id'] ?? '') . "\n";
    echo 'DRAFT_INVOICE_ID=' . ($result['invoice_id'] ?? '') . "\n";
    echo 'DRAFT_INVOICE_LINE_ID=' . ($result['invoice_line_id'] ?? '') . "\n";
    echo 'PAYLOAD_SHA256=' . $result['payload_sha256'] . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Export refused: ' . $error->getMessage() . "\n");
    exit(1);
}
