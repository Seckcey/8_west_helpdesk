<?php
/** Database-free ownership contract for telemetry ticket closure. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

$checks = 0;
$failures = 0;
function auto_close_contract_check(bool $condition, string $message): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "ok {$checks} - {$message}\n";
        return;
    }
    $failures++;
    echo "FAIL {$checks} - {$message}\n";
}

function auto_close_contract_source(string $relative): string
{
    $source = file_get_contents(__DIR__ . '/../' . $relative);
    if (!is_string($source)) throw new RuntimeException("Cannot read {$relative}");
    return $source;
}

$intake = auto_close_contract_source('lib/svc_intake.php');
$endpoint = auto_close_contract_source('public/api/svc/alerts.php');
$westyEndpoint = auto_close_contract_source('public/api/svc/westy.php');
$westyReport = auto_close_contract_source('lib/westy_report.php');
$lifecycle = auto_close_contract_source('lib/ticket_lifecycle.php');
$migration = auto_close_contract_source('db/migrations/018_ticket_auto_close_eligibility.sql');

auto_close_contract_check(
    str_contains($intake, "const SVC_ALERT_SERVICE = 'milepost';")
        && str_contains($endpoint, 'svc_alert_service_authorized($auth)'),
    'signed alert authority is narrowed to the exact Milepost identity',
);
auto_close_contract_check(
    str_contains($intake, "preg_match('/\\A(?:alert|netalert|netrule):([1-9][0-9]{0,19})\\z/D'")
        && str_contains($intake, "const SVC_ALERT_ID_MAX = '18446744073709551615';"),
    'alert intake pins the three separate unsigned Milepost key spaces',
);
auto_close_contract_check(
    str_contains($intake, 'auto_close_eligible, sla_due_at')
        && str_contains($intake, "\$autoCloseEligible = str_starts_with(\$extKey, 'alert:') ? 1 : 0;")
        && str_contains($intake, '$extKey, $autoCloseEligible,')
        && str_contains($migration, "NEW.external_key NOT REGEXP '^alert:[1-9][0-9]{0,19}$'"),
    'only original Milepost alert keys receive migration 018 one-use eligibility',
);
auto_close_contract_check(
    hash_file('sha256', __DIR__ . '/../../docs/contracts/svc-alert-vectors.json')
        === '66b544f75e1ea81057f67248f56cdb52be0c414ff96dc4ebc6115444b5e47e0f',
    'wire vectors match the shared Milepost and Safeharbor fixture bytes',
);

require_once __DIR__ . '/../lib/westy_workflow.php';
$claim = [
    'schema_version' => 1, 'event_key' => '00000000-0000-4000-8000-000000000001',
    'workflow_key' => '00000000-0000-4000-8000-000000000002', 'tenant_slug' => '8west',
    'customer_id' => '00000000-0000-4000-8000-000000000003', 'alert_key' => 'alert:1',
    'action' => 'claim', 'expected_version' => 0, 'summary' => 'Checking the alert.',
    'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'evidence_sha256' => null, 'verification_method' => null,
    'job_id' => null, 'job_completed_at' => null, 'alert_resolved_at' => null, 'assignee_id' => null,
];
auto_close_contract_check(
    westy_workflow_request(json_encode($claim, JSON_THROW_ON_ERROR)) === $claim,
    'the control claim with alert:1 passes the workflow request validator',
);
foreach (['netalert:1', 'netrule:1'] as $networkKey) {
    $claim['alert_key'] = $networkKey;
    $error = null;
    try {
        westy_workflow_request(json_encode($claim, JSON_THROW_ON_ERROR));
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
    auto_close_contract_check($error === 'invalid_alert', "Westy refuses a claim for {$networkKey}");
}
auto_close_contract_check(
    !str_contains($intake, 'UPDATE tickets SET priority = ?, subject = ?'),
    'machine re-fire no longer overwrites human ticket fields',
);
auto_close_contract_check(
    str_contains($lifecycle, "status = 'open' AND auto_close_eligible = 1")
        && str_contains($lifecycle, 'auto_close_eligible = 0'),
    'machine recovery is an atomic eligible-to-consumed transition',
);
auto_close_contract_check(
    str_contains($westyReport, "const WESTY_REPORT_MILEPOST_SERVICE = 'milepost-westy';")
        && str_contains($westyEndpoint, 'westy_report_service_authorized($auth, $payload)'),
    'Westy endpoint checks the dedicated identity plus payload job authority',
);
auto_close_contract_check(
    !preg_match('/UPDATE\s+tickets\s+SET\s+status\b/i', $westyReport)
        && !preg_match('/INSERT\s+INTO\s+tickets[\s\S]*?auto_close_eligible/i', $westyReport),
    'Westy can neither close a ticket nor mint machine-close eligibility',
);

// Other creators omit the capability or explicitly write literal zero. Inspect
// each INSERT's column/value tuple rather than unrelated reads/UPDATEs in the
// same module: the guarded workflow checks eligibility before consuming it.
$otherEligibleCreators = [];
foreach ([__DIR__ . '/../lib', __DIR__ . '/../public', __DIR__ . '/../cron'] as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
        $path = $file->getPathname();
        $source = file_get_contents($path);
        if (!is_string($source) || !str_contains($source, 'INSERT INTO tickets')) continue;
        preg_match_all('/INSERT\s+INTO\s+tickets\s*\(([^)]*)\)\s*VALUES\s*\(([^)]*)\)/is', $source, $inserts, PREG_SET_ORDER);
        foreach ($inserts as $insert) {
            $columns = array_map('trim', explode(',', $insert[1]));
            $values = array_map('trim', explode(',', $insert[2]));
            $index = array_search('auto_close_eligible', $columns, true);
            if ($index !== false && ($values[$index] ?? '') !== '0') {
                $otherEligibleCreators[] = str_replace('\\', '/', $path);
            }
        }
    }
}
auto_close_contract_check(
    count($otherEligibleCreators) === 1
        && str_ends_with($otherEligibleCreators[0], '/lib/svc_intake.php'),
    'no email, portal, manual, support, or Westy creator can mint eligibility',
);

foreach ([
    'DEFAULT 0' => 'eligibility defaults off',
    'Automatic close eligibility cannot be restored' => 'database forbids restoring eligibility',
    "OLD.auto_close_eligible = 1 AND NEW.auto_close_eligible = 1" => 'any ticket update consumes eligibility',
    'trg_messages_auto_close_after_insert' => 'human/customer messages consume eligibility',
    'trg_messages_auto_close_after_update' => 'message edits or moves consume eligibility',
    'trg_messages_auto_close_after_delete' => 'message deletion consumes eligibility',
    'trg_time_entries_auto_close_after_insert' => 'technician time consumes eligibility',
    'Ineligible ticket cannot receive an automatic-close line' => 'pre-018 code rollback stays fail-closed',
] as $needle => $message) {
    auto_close_contract_check(str_contains($migration, $needle), $message);
}
auto_close_contract_check(
    str_contains($migration, 'IF column_count = 0 THEN')
        && substr_count($migration, 'IF column_count = 0 THEN') >= 2,
    'historical proof backfill runs only on first column creation',
);
auto_close_contract_check(
    str_contains($migration, "t.updated_at = t.created_at")
        && str_contains($migration, "(SELECT COUNT(*) FROM messages m WHERE m.ticket_id = t.id) = 1")
        && str_contains($migration, "BINARY m.author_name = BINARY 'Milepost'")
        && str_contains($migration, 'SELECT 1 FROM time_entries te')
        && str_contains($migration, "18446744073709551615"),
    'historical backfill requires positive untouched-machine proof',
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} auto-close contract checks failed.\n");
    exit(1);
}
echo "Ticket auto-close contract: {$checks}/{$checks} passed.\n";
