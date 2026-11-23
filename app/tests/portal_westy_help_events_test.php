<?php
/** Help gap privacy and best-effort writes, without a provider or server. */
declare(strict_types=1);
require_once __DIR__.'/../lib/portal_westy_help_events.php';

$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    echo 'ok '.++$checks.' - '.$label."\n";
}

$fence = str_repeat(chr(96), 3);
foreach ([
    'mail' => ['a.person+sample@example.test', '[redacted email]'],
    'URL with a long token' => ['https://private.example.test/'.str_repeat('a', 45).'/still-private', '[redacted URL]'],
    'IPv4' => ['192.0.2.146', '[redacted address]'],
    'drive path' => ['C:\\Users\\Example Person\\private.txt', '[redacted path]'],
    'drive path with slashes' => ['D:/Private/example.txt', '[redacted path]'],
    'UNC path' => ['\\\\example-server\\private share\\data.txt', '[redacted path]'],
    'password' => ['password=private test value', '[redacted credential field]'],
    'quoted refresh token field' => ['"refresh_token": "private-value"', '"[redacted credential field]'],
    'Bearer' => ['Bearer synthetic.value', '[redacted token]'],
    'opaque token' => [str_repeat('t', 40), '[redacted token]'],
    'provider key' => ['sk-ant-'.str_repeat('a', 16), '[redacted credential]'],
    'JWT' => ['eyJexample.head.signature', '[redacted credential]'],
    'PEM' => ["-----BEGIN PRIVATE KEY-----\nsample\n-----END PRIVATE KEY-----", '[redacted private key]'],
    'fenced code' => [$fence."php\nprivate code\n".$fence, '[omitted code block]'],
    'unclosed fence' => [$fence."sh\nprivate code", '[omitted code block]'],
] as $label => [$input, $expected]) {
    check(portal_westy_gap_text($input) === $expected, 'scrubs '.$label);
}
check(portal_westy_gap_text(" \tFirst\x01\r\nsecond\u{00a0}\u{2003}third\x7f\u{009f} ") === 'First second third',
    'collapses line breaks and Unicode whitespace and removes controls');
$prefix = str_repeat('ab ', 60);
check(portal_westy_gap_text($prefix.str_repeat('z', 55)) === $prefix.'[redacted token]',
    'redacts a token spanning the byte limit before cutting');
$nearLimit = str_repeat('é ', 66);
check(portal_westy_gap_text($nearLimit.'界') === $nearLimit, 'cuts before a three-byte character straddling byte 200');
check(strlen(portal_westy_gap_text(str_repeat('é', 102))) === 200, 'keeps a complete character ending at byte 200');
check(mb_check_encoding(portal_westy_gap_text("bad\xff text"), 'UTF-8'), 'invalid input cannot produce malformed UTF-8');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE portal_westy_turns (id INTEGER PRIMARY KEY, tenant_id INT, client_id INT, scope_key TEXT, input_text TEXT);
    CREATE TABLE portal_westy_ai_attempts (id INTEGER PRIMARY KEY, turn_id INT, sequence INT);
    INSERT INTO portal_westy_ai_attempts VALUES (1,1,1),(2,2,1),(3,3,1);
    CREATE TABLE portal_westy_help_events (id INTEGER PRIMARY KEY, tenant_id INT, client_id INT, turn_id INT, kind TEXT,
        article_id TEXT, catalog_version TEXT, surface TEXT, created_at TEXT, expires_at TEXT);
    CREATE TABLE help_gap_questions (id INTEGER PRIMARY KEY, tenant_id INT, client_id INT, surface TEXT, question TEXT, created_at TEXT, expires_at TEXT)');
$scope = ['tenant'=>2, 'client'=>21, 'key'=>str_repeat('a', 64)];
$seed = $pdo->prepare('INSERT INTO portal_westy_turns VALUES (?,2,21,?,?)');
foreach ([1,2,3] as $id) $seed->execute([$id, $scope['key'], "Contact a.person@example.test\nabout this?"]);
$record = static fn(int $id, array $articles, bool $gap) => portal_westy_help_events_record($pdo, $scope, $id, $id, 'companion', $articles, $gap);
$record(1, ['sh-requests','sh-requests'], false);
$record(1, ['sh-requests','sh-devices'], false);
$record(1, [], true);
check($pdo->query('SELECT article_id FROM portal_westy_help_events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['sh-requests','sh-devices'],
    'repeat citations across writes are unique and a cited turn cannot gain a gap');
$record(2, [], true);
$record(2, [], true);
$question = $pdo->query('SELECT tenant_id,client_id,surface,question FROM help_gap_questions')->fetchAll(PDO::FETCH_ASSOC);
check($question === [['tenant_id'=>2,'client_id'=>21,'surface'=>'companion','question'=>'Contact [redacted email] about this?']],
    'a repeated gap stores exactly one scoped scrubbed question');
foreach ([['tenant'=>3], ['client'=>22], ['key'=>str_repeat('b', 64)]] as $wrong) {
    portal_westy_help_events_record($pdo, array_replace($scope, $wrong), 3, 3, 'portal', [], true);
}
check((int)$pdo->query('SELECT COUNT(*) FROM portal_westy_help_events')->fetchColumn() === 3, 'wrong tenant, client or account records nothing');
$pdo->exec('INSERT INTO portal_westy_ai_attempts VALUES (4,2,2)');
portal_westy_help_events_record($pdo, $scope, 2, 4, 'companion', ['sh-requests'], false);
$record(2, [], true);
check($pdo->query('SELECT kind FROM portal_westy_help_events WHERE turn_id=2')->fetchAll(PDO::FETCH_COLUMN) === ['cited']
    && (int)$pdo->query('SELECT COUNT(*) FROM help_gap_questions')->fetchColumn() === 0,
    'a reopened turn withdraws its old gap and an earlier attempt cannot restore it');

$log = tempnam(sys_get_temp_dir(), 'westy-help-log-');
$oldLog = ini_set('error_log', $log);
try {
    $pdo->exec("CREATE TRIGGER fail_question BEFORE INSERT ON help_gap_questions BEGIN SELECT RAISE(ABORT,'PRIVATE_ERROR_BODY'); END");
    $record(3, [], true);
    check(!$pdo->inTransaction() && (int)$pdo->query('SELECT COUNT(*) FROM portal_westy_help_events WHERE turn_id=3')->fetchColumn() === 0,
        'question failure rolls back the paired event and releases the transaction');
    $line = trim(file_get_contents($log));
    check(preg_match('/^(?:\[[^\]\r\n]+\] )?\[portal-westy-help-events\] record_failed type=PDOException$/D', $line) === 1,
        'failure logs only the fixed event and exception class');
    $pdo->exec('DROP TABLE help_gap_questions');
    $record(3, ['sh-requests'], false);
    $pdo->exec('DROP TABLE portal_westy_help_events');
    $record(3, [], true);
    check(trim(file_get_contents($log)) === $line, 'partially applied and missing migration are quiet');
} finally {
    ini_set('error_log', $oldLog);
    unlink($log);
}
echo "$checks Westy help event checks passed\n";
