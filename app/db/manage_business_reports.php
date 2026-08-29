<?php
/**
 * Operator lifecycle for versioned Safeharbor business-report definitions and
 * schedules. Recipient addresses are accepted as input but never echoed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/business_reports.php';
require_once __DIR__ . '/../lib/id_report_contacts.php';

/** @return array<string,string> */
function report_cli_options(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (preg_match('/\A--([a-z][a-z0-9-]*)=(.+)\z/D', $argument, $parts) !== 1
            || array_key_exists($parts[1], $options)
        ) {
            throw new BusinessReportValidationException('Command arguments are invalid.');
        }
        $options[$parts[1]] = $parts[2];
    }
    return $options;
}

function report_cli_expect(array $options, array $expected): void
{
    $actual = array_keys($options);
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        throw new BusinessReportValidationException('The command requires an exact option set.');
    }
}

function report_cli_positive_int(string $value, string $label): int
{
    if (preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
        throw new BusinessReportValidationException("{$label} must be a positive integer.");
    }
    return (int)$value;
}

function report_cli_emit_schedule(array $schedule, string $action): void
{
    echo 'ACTION=' . $action . "\n";
    echo 'SCHEDULE_ID=' . (int)$schedule['id'] . "\n";
    echo 'SCHEDULE_KEY=' . (string)$schedule['schedule_key'] . "\n";
    echo 'VERSION=' . (int)$schedule['version_no'] . "\n";
    echo 'STATUS=' . (string)$schedule['status'] . "\n";
    echo 'CLIENT_KEY=safeharbor-client:' . (int)$schedule['client_id'] . "\n";
    echo 'DEFINITION_ID=' . (int)$schedule['definition_version_id'] . "\n";
    echo 'CANARY=' . ((int)$schedule['canary'] === 1 ? '1' : '0') . "\n";
    echo 'RECIPIENT_SHA256=' . hash('sha256', (string)$schedule['recipient_email']) . "\n";
}

function report_cli_emit_id_contact(?array $evidence): void
{
    if (!is_array($evidence)) {
        echo "CONTACT_SOURCE=MANUAL\n";
        return;
    }
    echo "CONTACT_SOURCE=8WEST_ID\n";
    echo 'CONTACT_SCOPE=' . (array_key_exists('client_id', $evidence) ? 'CLIENT' : 'TENANT') . "\n";
    echo 'ID_TENANT_KEY=' . (string)$evidence['id_tenant_key'] . "\n";
    echo 'CONTACT_VERSION=' . (int)$evidence['contact_version'] . "\n";
    echo 'CONTACT_RESPONSE_SHA256=' . (string)$evidence['response_sha256'] . "\n";
}

$command = $argv[1] ?? '';
$usage = "Usage:\n"
    . "  php db/manage_business_reports.php publish-definition --tenant-slug=SLUG --actor-user-id=ID --reason=TEXT\n"
    . "  php db/manage_business_reports.php prepare --tenant-slug=SLUG --schedule-key=KEY --client-id=ID --definition-id=ID --recipient-email=EMAIL --timezone=ZONE --delivery-weekday=1..7 --delivery-local-time=HH:MM:SS --canary=0|1 --actor-user-id=ID --reason=TEXT\n"
    . "  php db/manage_business_reports.php prepare-from-id --tenant-slug=SLUG --schedule-key=KEY --client-id=ID --definition-id=ID --timezone=ZONE --delivery-weekday=1..7 --delivery-local-time=HH:MM:SS --canary=0|1 --actor-user-id=ID --reason=TEXT\n"
    . "  php db/manage_business_reports.php prepare-client-from-id --tenant-slug=SLUG --schedule-key=KEY --client-id=ID --definition-id=ID --timezone=ZONE --delivery-weekday=1..7 --delivery-local-time=HH:MM:SS --canary=0|1 --actor-user-id=ID --reason=TEXT\n"
    . "  php db/manage_business_reports.php enable|disable --tenant-slug=SLUG --schedule-key=KEY --expected-version=N --actor-user-id=ID --reason=TEXT\n"
    . "  php db/manage_business_reports.php inspect --tenant-slug=SLUG --schedule-key=KEY\n";

try {
    if (!in_array($command, ['publish-definition', 'prepare', 'prepare-from-id', 'prepare-client-from-id', 'enable', 'disable', 'inspect'], true)) {
        throw new BusinessReportValidationException('Command is invalid.');
    }
    $options = report_cli_options(array_slice($argv, 2));
    $pdo = db();

    if ($command === 'publish-definition') {
        report_cli_expect($options, ['tenant-slug', 'actor-user-id', 'reason']);
        $result = business_report_publish_definition(
            $pdo,
            $options['tenant-slug'],
            report_cli_positive_int($options['actor-user-id'], 'Actor user id'),
            $options['reason'],
        );
        echo 'ACTION=' . $result['action'] . "\n";
        echo 'DEFINITION_ID=' . (int)$result['definition']['id'] . "\n";
        echo 'DEFINITION_KEY=' . (string)$result['definition']['definition_key'] . "\n";
        echo 'VERSION=' . (int)$result['definition']['version_no'] . "\n";
        echo 'CONTRACT_SHA256=' . (string)$result['definition']['contract_sha256'] . "\n";
        exit(0);
    }

    if ($command === 'prepare') {
        report_cli_expect($options, [
            'tenant-slug', 'schedule-key', 'client-id', 'definition-id',
            'recipient-email', 'timezone', 'delivery-weekday',
            'delivery-local-time', 'canary', 'actor-user-id', 'reason',
        ]);
        if (!in_array($options['canary'], ['0', '1'], true)) {
            throw new BusinessReportValidationException('Canary must be exactly 0 or 1.');
        }
        $result = business_report_prepare_schedule(
            $pdo,
            $options['tenant-slug'],
            $options['schedule-key'],
            report_cli_positive_int($options['client-id'], 'Client id'),
            report_cli_positive_int($options['definition-id'], 'Definition id'),
            $options['recipient-email'],
            $options['timezone'],
            report_cli_positive_int($options['delivery-weekday'], 'Delivery weekday'),
            $options['delivery-local-time'],
            $options['canary'] === '1',
            report_cli_positive_int($options['actor-user-id'], 'Actor user id'),
            $options['reason'],
        );
        report_cli_emit_schedule($result['schedule'], $result['action']);
        report_cli_emit_id_contact(null);
        exit(0);
    }

    if ($command === 'prepare-from-id') {
        report_cli_expect($options, [
            'tenant-slug', 'schedule-key', 'client-id', 'definition-id',
            'timezone', 'delivery-weekday', 'delivery-local-time',
            'canary', 'actor-user-id', 'reason',
        ]);
        if (!in_array($options['canary'], ['0', '1'], true)) {
            throw new BusinessReportValidationException('Canary must be exactly 0 or 1.');
        }
        // This is the only call site for the network client. Fetch before the
        // database transaction, authenticate the exact document, then pin it.
        $contact = id_report_contact_fetch($options['tenant-slug']);
        $result = business_report_prepare_schedule_from_id(
            $pdo,
            $options['tenant-slug'],
            $options['schedule-key'],
            report_cli_positive_int($options['client-id'], 'Client id'),
            report_cli_positive_int($options['definition-id'], 'Definition id'),
            $contact,
            $options['timezone'],
            report_cli_positive_int($options['delivery-weekday'], 'Delivery weekday'),
            $options['delivery-local-time'],
            $options['canary'] === '1',
            report_cli_positive_int($options['actor-user-id'], 'Actor user id'),
            $options['reason'],
        );
        report_cli_emit_schedule($result['schedule'], $result['action']);
        report_cli_emit_id_contact($result['id_contact']);
        exit(0);
    }

    if ($command === 'prepare-client-from-id') {
        report_cli_expect($options, [
            'tenant-slug', 'schedule-key', 'client-id', 'definition-id',
            'timezone', 'delivery-weekday', 'delivery-local-time',
            'canary', 'actor-user-id', 'reason',
        ]);
        if (!in_array($options['canary'], ['0', '1'], true)) {
            throw new BusinessReportValidationException('Canary must be exactly 0 or 1.');
        }
        $clientId = report_cli_positive_int($options['client-id'], 'Client id');
        $definitionId = report_cli_positive_int($options['definition-id'], 'Definition id');
        $actorUserId = report_cli_positive_int($options['actor-user-id'], 'Actor user id');
        // Prove the operator, provider tenant, client, and definition reach
        // before making the one exact, configured ID lookup.
        business_report_schedule_target(
            $pdo,
            $options['tenant-slug'],
            $clientId,
            $definitionId,
            $actorUserId,
        );
        $contact = id_report_contact_fetch_client($clientId);
        $result = business_report_prepare_client_schedule_from_id(
            $pdo,
            $options['tenant-slug'],
            $options['schedule-key'],
            $clientId,
            $definitionId,
            $contact,
            $options['timezone'],
            report_cli_positive_int($options['delivery-weekday'], 'Delivery weekday'),
            $options['delivery-local-time'],
            $options['canary'] === '1',
            $actorUserId,
            $options['reason'],
        );
        report_cli_emit_schedule($result['schedule'], $result['action']);
        report_cli_emit_id_contact($result['id_contact']);
        exit(0);
    }

    if ($command === 'enable' || $command === 'disable') {
        report_cli_expect($options, [
            'tenant-slug', 'schedule-key', 'expected-version', 'actor-user-id', 'reason',
        ]);
        $rawConfig = cfg('business_reports', []);
        if (!is_array($rawConfig)) {
            throw new BusinessReportValidationException('Business report configuration is invalid.');
        }
        $result = business_report_transition_schedule(
            $pdo,
            $options['tenant-slug'],
            $options['schedule-key'],
            report_cli_positive_int($options['expected-version'], 'Expected version'),
            $command === 'enable' ? 'active' : 'disabled',
            report_cli_positive_int($options['actor-user-id'], 'Actor user id'),
            $options['reason'],
            $rawConfig,
        );
        report_cli_emit_schedule($result['schedule'], $result['action']);
        exit(0);
    }

    report_cli_expect($options, ['tenant-slug', 'schedule-key']);
    business_report_slug($options['tenant-slug']);
    business_report_schedule_key($options['schedule-key']);
    $tenant = $pdo->prepare('SELECT id FROM tenants WHERE slug = ?');
    $tenant->execute([$options['tenant-slug']]);
    $tenantId = $tenant->fetchColumn();
    if ($tenantId === false) {
        throw new BusinessReportGateException('The exact tenant was not found.');
    }
    $schedule = business_report_latest_schedule($pdo, (int)$tenantId, $options['schedule-key']);
    if (!is_array($schedule)) {
        throw new BusinessReportGateException('The report schedule was not found.');
    }
    report_cli_emit_schedule($schedule, 'inspected');
    $contactEvidence = business_report_latest_id_client_contact_for_key(
        $pdo,
        (int)$tenantId,
        $options['schedule-key'],
    );
    if (!is_array($contactEvidence)) {
        $contactEvidence = business_report_latest_id_contact_for_key(
            $pdo,
            (int)$tenantId,
            $options['schedule-key'],
        );
    }
    report_cli_emit_id_contact($contactEvidence);
    $counts = $pdo->prepare(
        "SELECT
           (SELECT COUNT(*) FROM business_report_archives
             WHERE tenant_id = ? AND schedule_key = ?) AS archives,
           (SELECT COUNT(*) FROM business_report_deliveries d
              JOIN business_report_archives a
                ON a.tenant_id = d.tenant_id AND a.id = d.archive_id
             WHERE a.tenant_id = ? AND a.schedule_key = ?) AS deliveries"
    );
    $counts->execute([(int)$tenantId, $options['schedule-key'], (int)$tenantId, $options['schedule-key']]);
    $summary = $counts->fetch(PDO::FETCH_ASSOC) ?: [];
    echo 'ARCHIVES=' . (int)($summary['archives'] ?? 0) . "\n";
    echo 'DELIVERIES=' . (int)($summary['deliveries'] ?? 0) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Business report command refused: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $usage);
    exit($error instanceof BusinessReportValidationException ? 2 : 1);
}
