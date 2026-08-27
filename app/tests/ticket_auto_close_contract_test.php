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
$lifecycle = auto_close_contract_source('lib/ticket_lifecycle.php');
$migration = auto_close_contract_source('db/migrations/018_ticket_auto_close_eligibility.sql');

auto_close_contract_check(
    str_contains($intake, "const SVC_ALERT_SERVICE = 'milepost';")
        && str_contains($endpoint, 'svc_alert_service_authorized($auth)'),
    'signed alert authority is narrowed to the exact Milepost identity',
);
auto_close_contract_check(
    str_contains($intake, "preg_match('/\\Aalert:([1-9][0-9]{0,19})\\z/D'")
        && str_contains($intake, "const SVC_ALERT_ID_MAX = '18446744073709551615';"),
    'alert intake pins the exact unsigned Milepost telemetry-id namespace',
);
auto_close_contract_check(
    str_contains($intake, 'auto_close_eligible, sla_due_at')
        && str_contains($intake, '"alert",?,1,?,?,?,?'),
    'signed Milepost alert creation explicitly receives one-use eligibility',
);
auto_close_contract_check(
    !str_contains($intake, 'UPDATE tickets SET priority = ?, subject = ?'),
    'machine re-fire no longer overwrites human ticket fields',
);
auto_close_contract_check(
    str_contains($lifecycle, "status = 'open' AND auto_close_eligible = 1")
        && str_contains($lifecycle, 'auto_close_eligible = 0'),
    'machine recovery is an atomic eligible-to-consumed transition',
);

// Every other ticket creator omits the capability and therefore receives the
// database default 0. This scans executable PHP, not a hand-maintained list.
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
        if (str_contains($source, 'auto_close_eligible')) {
            $otherEligibleCreators[] = str_replace('\\', '/', $path);
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
