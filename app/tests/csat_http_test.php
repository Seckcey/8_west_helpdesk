<?php
/**
 * The satisfaction survey over real HTTP (SH-16, SH-119). Mail scanners and link previewers open
 * every link in the resolution email, so opening a link must never record a rating: only the
 * customer's own POST does. A recorded rating can be changed for 7 days, and the change keeps the
 * first answer time that reports count by. Two Sends at once never drop one silently, and a Send with
 * nothing new says so. Synthetic disposable MySQL and the actual csat.php behind
 * PHP's built-in server; no mail, no live data.
 *
 *   SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER=1 php app/tests/csat_http_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
require_once __DIR__ . '/sql_file.php';

$host = getenv('SAFEHARBOR_WESTY_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_WESTY_TEST_PORT') ?: '3306';
$user = getenv('SAFEHARBOR_WESTY_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_WESTY_TEST_PASS') ?: '';
$database = 'safeharbor_westy_test_csat_http';
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("DROP DATABASE IF EXISTS $database");
$pdo->exec("CREATE DATABASE $database");
$pdo->exec("USE $database");
$pdo->exec("SET time_zone = '+00:00'");
test_execute_sql_file($pdo, __DIR__ . '/../db/schema.sql');
$pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Synthetic provider', 'synthetic-provider')");
$pdo->exec("INSERT INTO clients (id, tenant_id, name) VALUES (11, 1, 'Northwind Studio')");
$pdo->exec("INSERT INTO contacts (id, client_id, name, email) VALUES (21, 11, 'Alex Morgan', 'alex@example.test')");

/** A resolved ticket and its survey; $answeredDaysAgo null means nobody has answered yet. */
function csat_fixture(PDO $pdo, string $token, ?int $score = null, ?int $answeredDaysAgo = null,
    string $comment = '', string $subject = 'Printer offline'): void
{
    $pdo->prepare("INSERT INTO tickets (tenant_id, client_id, contact_id, subject, status, sla_due_at, resolved_at)
                   VALUES (1, 11, 21, ?, 'resolved', UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([$subject]);
    $answered = $answeredDaysAgo === null ? 'NULL' : 'UTC_TIMESTAMP() - INTERVAL ' . $answeredDaysAgo . ' DAY';
    $pdo->prepare("INSERT INTO csat (ticket_id, token, score, comment, responded_at) VALUES (?, ?, ?, ?, $answered)")
        ->execute([(int)$pdo->lastInsertId(), $token, $score, $comment]);
}

/** @return array{score:?int,comment:string,responded_at:?string} */
function csat_row(PDO $pdo, string $token): array
{
    $q = $pdo->prepare('SELECT score, comment, responded_at FROM csat WHERE token = ?');
    $q->execute([$token]);
    $row = $q->fetch();
    return ['score' => $row['score'] === null ? null : (int)$row['score'], 'comment' => (string)$row['comment'],
        'responded_at' => $row['responded_at']];
}

function csat_dom(string $html): DOMXPath
{
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($doc);
}

/**
 * What a browser would submit from the page's survey form, or null when the page offers no form.
 * scores maps each offered score to whether it starts selected.
 *
 * @return array{t:?string,scores:array<int,bool>,comment:?string}|null
 */
function csat_form(string $html): ?array
{
    $xpath = csat_dom($html);
    $form = $xpath->query('//form[@method="post"][@action="/csat.php"]')->item(0);
    if (!$form instanceof DOMElement) return null;
    $scores = [];
    foreach ($xpath->query('.//input[@type="radio"][@name="s"]', $form) as $radio) {
        $scores[(int)$radio->getAttribute('value')] = $radio->hasAttribute('checked');
    }
    ksort($scores);
    $token = $xpath->query('.//input[@type="hidden"][@name="t"]', $form)->item(0);
    $comment = $xpath->query('.//textarea[@name="comment"]', $form)->item(0);
    return ['t' => $token instanceof DOMElement ? $token->getAttribute('value') : null, 'scores' => $scores,
        'comment' => $comment?->textContent];
}

/** @return array{0:int,1:string} */
function csat_http(string $address, string $method, string $query = '', ?array $post = null): array
{
    $curl = curl_init("http://$address/csat.php" . ($query === '' ? '' : "?$query"));
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    if ($method === 'HEAD') curl_setopt($curl, CURLOPT_NOBODY, true);
    if ($method === 'POST') curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post ?? [])]);
    $body = curl_exec($curl);
    return [(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string)$body];
}

/** @return array{note:string,error:string} what the page tells the customer ('' when absent) */
function csat_says(string $html): array
{
    $xpath = csat_dom($html);
    $text = static fn(string $query): string => trim((string)$xpath->query($query)->item(0)?->textContent);
    return ['note' => $text('//*[@role="status"]'), 'error' => $text('//*[@role="alert"]')];
}

/**
 * A POST that loses the race to answer: $other holds the survey row locked, so the page reads it
 * unanswered and then waits on the lock; $answerFirst answers it while the page waits.
 *
 * @return array{0:int,1:string,2:bool} status, body, and whether the page was seen waiting
 */
function csat_post_racing(PDO $watch, PDO $other, string $address, string $token, array $post, callable $answerFirst): array
{
    $other->beginTransaction();
    $other->prepare('SELECT id FROM csat WHERE token = ? FOR UPDATE')->execute([$token]);
    $multi = curl_multi_init();
    $curl = curl_init("http://$address/csat.php");
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post)]);
    curl_multi_add_handle($multi, $curl);
    $waiting = false;
    $deadline = microtime(true) + 10;
    while (!$waiting && microtime(true) < $deadline) {
        curl_multi_exec($multi, $running);
        usleep(20000);
        // Once the page's first-answer UPDATE shows up it is waiting: the row lock it needs is held here.
        foreach ($watch->query('SHOW FULL PROCESSLIST') as $thread) {
            $sql = (string)($thread['Info'] ?? '');
            $waiting = $waiting || (str_starts_with($sql, 'UPDATE csat ') && str_contains($sql, 'responded_at IS NULL'));
        }
    }
    $answerFirst($other);
    $other->commit();
    do {
        curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 0.1);
    } while ($running);
    $result = [(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string)curl_multi_getcontent($curl), $waiting];
    curl_multi_remove_handle($multi, $curl);
    curl_multi_close($multi);
    return $result;
}

$token = static fn(string $c): string => str_repeat($c, 40);
$scanned = $token('1');    // not answered: a scanner opens the emailed links
$blank = $token('2');      // not answered: opened without a score
$answer = $token('3');     // not answered: the customer sends a rating and a comment
$invalid = $token('4');    // not answered: posts without a usable score
$recent = $token('5');     // answered 2 days ago, no comment yet
$old = $token('6');        // answered 10 days ago, no comment yet
$commented = $token('7');  // answered yesterday, with a comment
$same = $token('8');       // answered yesterday, with a comment: a Send that changes nothing
$raced = $token('9');      // not answered: another Send answers while this one is on its way
$unknown = $token('a');    // well-formed, never issued
$twice = $token('b');      // not answered: a double click sends the same answer twice
csat_fixture($pdo, $scanned, subject: 'Printer <b>offline</b> & "stuck"');
csat_fixture($pdo, $blank);
csat_fixture($pdo, $answer);
csat_fixture($pdo, $invalid);
csat_fixture($pdo, $recent, 3, 2);
csat_fixture($pdo, $old, 3, 10);
csat_fixture($pdo, $commented, 2, 1, 'First words');
csat_fixture($pdo, $same, 3, 1, 'All good');
csat_fixture($pdo, $raced);
csat_fixture($pdo, $twice);
$untouched = ['score' => null, 'comment' => '', 'responded_at' => null];
$none = [1 => false, 2 => false, 3 => false];

// The actual page and libraries, run from a scratch copy so the checkout never gets a settings file.
$app = realpath(__DIR__ . '/..');
$runtime = sys_get_temp_dir() . '/safeharbor-csat-' . bin2hex(random_bytes(8));
foreach (['config', 'public', 'lib'] as $part) mkdir("$runtime/$part", 0700, true);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$app/lib", FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST) as $file) {
    $to = "$runtime/lib/" . substr($file->getPathname(), strlen("$app/lib") + 1);
    $file->isDir() ? (is_dir($to) || mkdir($to, 0700, true)) : copy($file->getPathname(), $to);
}
copy("$app/public/csat.php", "$runtime/public/csat.php");
file_put_contents("$runtime/config/config.php", '<?php return ' . var_export(['app_env' => 'dev', 'force_https' => false,
    'db' => ['host' => $host, 'port' => (int)$port, 'user' => $user, 'pass' => $pass, 'name' => $database, 'charset' => 'utf8mb4'],
], true) . ';');
$socket = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-S', $address, '-t', "$runtime/public"],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$runtime/http.log", 'a'], 2 => ['file', "$runtime/http.log", 'a']], $pipes);

$checks = 0;
$failures = [];
$check = static function (bool $ok, string $name) use (&$checks, &$failures): void {
    $checks++;
    echo ($ok ? 'ok ' : 'not ok ') . "$checks - $name\n";
    if (!$ok) $failures[] = $name;
};
// Development settings print PHP warnings into the page, so a clean page means a clean run.
$clean = static fn(string $body): bool => preg_match('/<b>(Warning|Notice|Deprecated|Fatal error)<\/b>/', $body) !== 1;

try {
    for ($n = 0; $n < 100; $n++) {
        $probe = @stream_socket_client("tcp://$address", $errno, $errstr, 0.1);
        if ($probe) { fclose($probe); break; }
        usleep(20000);
    }

    // A scanner opening the emailed links: the page opens, nothing is recorded.
    [$status, $body] = csat_http($address, 'GET', "t=$scanned&s=3");
    $form = csat_form($body);
    $check($status === 200 && $clean($body), 'an emailed survey link opens the survey page');
    $check(csat_row($pdo, $scanned) === $untouched, 'opening an emailed link records nothing');
    $check($form !== null && $form['t'] === $scanned && $form['scores'] === [1 => false, 2 => false, 3 => true],
        'the face tapped in the email comes back pre-selected, in a form that posts the token');
    $xpath = csat_dom($body);
    $check(str_contains((string)$xpath->document->textContent, 'Printer <b>offline</b> & "stuck"')
        && $xpath->query('//b[.="offline"]')->length === 0, 'the ticket subject shows as text, never as markup');
    [$status] = csat_http($address, 'HEAD', "t=$scanned&s=1");
    $check(csat_row($pdo, $scanned) === $untouched, 'a HEAD probe of an emailed link records nothing');

    [$status, $body] = csat_http($address, 'GET', "t=$blank");
    $form = csat_form($body);
    $check($status === 200 && $form !== null && $form['scores'] === $none && csat_row($pdo, $blank) === $untouched,
        'a link without a score pre-selects nothing and records nothing');

    // Send: one POST records the score and the comment together.
    [$status, $body] = csat_http($address, 'POST', '', ['t' => $answer, 's' => '2', 'comment' => '  Quick fix, thanks  ']);
    $row = csat_row($pdo, $answer);
    $check($status === 200 && $clean($body) && $row['score'] === 2 && $row['responded_at'] !== null
        && $row['comment'] === 'Quick fix, thanks', 'one POST records the score and the trimmed comment together');

    // No usable score: nothing is recorded and the typed comment comes back in the form.
    foreach (['0', '4', 'great', ''] as $bad) {
        [$status, $body] = csat_http($address, 'POST', '', ['t' => $invalid, 's' => $bad, 'comment' => 'Kept on error']);
        $form = csat_form($body);
        $check($status === 422 && $form !== null && $form['comment'] === 'Kept on error' && csat_row($pdo, $invalid) === $untouched,
            "a POST with score '$bad' records nothing and keeps the typed comment");
    }
    [$status] = csat_http($address, 'POST', '', ['t' => $invalid, 'comment' => 'No score at all']);
    $check($status === 422 && csat_row($pdo, $invalid) === $untouched, 'a POST without a score records nothing');

    // Two Sends at once: the one that loses the race to answer counts like a Send that came just after
    // the other, so it is never dropped silently, and the other's answer time stands.
    $other = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $otherAnswered = gmdate('Y-m-d H:i:s', time() - 60);
    [$status, $body, $waited] = csat_post_racing($pdo, $other, $address, $raced,
        ['t' => $raced, 's' => '1', 'comment' => 'From the laptop'],
        static fn(PDO $db): bool => $db->prepare('UPDATE csat SET score = 3, responded_at = ? WHERE token = ?')
            ->execute([$otherAnswered, $raced]));
    $row = csat_row($pdo, $raced);
    $check($waited && $status === 200 && $clean($body) && $row['score'] === 1 && $row['comment'] === 'From the laptop'
        && $row['responded_at'] === $otherAnswered, 'a Send that loses the race to answer counts as a change and keeps the first answer time');
    $check(csat_says($body)['note'] === 'Your rating is now 🙁 Rough. We got your comment.',
        'a Send that loses the race to answer says what it saved');

    // A double click sends the same answer twice: the second Send says nothing changed.
    csat_http($address, 'POST', '', ['t' => $twice, 's' => '2', 'comment' => 'Thanks']);
    [$status, $body] = csat_http($address, 'POST', '', ['t' => $twice, 's' => '2', 'comment' => 'Thanks']);
    $row = csat_row($pdo, $twice);
    $check($status === 200 && $row['score'] === 2 && $row['comment'] === 'Thanks'
        && csat_says($body)['note'] === 'That’s already your rating, so nothing changed.', 'a repeated Send says nothing changed');

    // Only a token that was issued counts.
    $answered = (int)$pdo->query('SELECT COUNT(*) FROM csat WHERE responded_at IS NOT NULL')->fetchColumn();
    [$status] = csat_http($address, 'POST', '', ['t' => $unknown, 's' => '3']);
    $check($status === 404, 'a POST with a token that was never issued is refused');
    [$status] = csat_http($address, 'POST', '', ['t' => 'not-a-token', 's' => '3']);
    $check($status === 404, 'a POST with a malformed token is refused');
    [$status] = csat_http($address, 'GET', "t=$unknown&s=3");
    $check($status === 404, 'a link with a token that was never issued opens the not-found page');
    $check((int)$pdo->query('SELECT COUNT(*) FROM csat WHERE responded_at IS NOT NULL')->fetchColumn() === $answered,
        'unknown and malformed tokens record nothing');

    // Within 7 days a different score replaces the first one; the first answer time stays.
    $first = csat_row($pdo, $recent);
    [$status, $body] = csat_http($address, 'GET', "t=$recent&s=1");
    $form = csat_form($body);
    $check(csat_row($pdo, $recent) === $first, 'opening a link for a different score records nothing');
    $check($form !== null && $form['scores'] === [1 => true, 2 => false, 3 => false],
        'a link for a different score offers that score as the change');
    [$status, $body] = csat_http($address, 'POST', '', ['t' => $recent, 's' => '1']);
    $row = csat_row($pdo, $recent);
    $check($status === 200 && $row['score'] === 1 && $row['responded_at'] === $first['responded_at'],
        'within 7 days a POST changes the score and keeps the first answer time');
    [$status, $body] = csat_http($address, 'GET', "t=$recent");
    $form = csat_form($body);
    $check($form !== null && $form['scores'] === [1 => true, 2 => false, 3 => false], 'the page pre-selects the recorded score');
    csat_http($address, 'POST', '', ['t' => $recent, 's' => '1', 'comment' => 'Added later']);
    $check(csat_row($pdo, $recent)['comment'] === 'Added later', 'a comment can follow a rating');
    csat_http($address, 'POST', '', ['t' => $commented, 's' => '2', 'comment' => 'Second words']);
    $check(csat_row($pdo, $commented)['comment'] === 'First words', 'a saved comment is never overwritten');
    $first = csat_row($pdo, $same);
    [$status, $body] = csat_http($address, 'POST', '', ['t' => $same, 's' => '3']);
    $check($status === 200 && csat_row($pdo, $same) === $first
        && csat_says($body)['note'] === 'That’s already your rating, so nothing changed.',
        'a Send with the recorded face and nothing else says nothing changed');

    // After 7 days the score is final; a comment can still be added once.
    $first = csat_row($pdo, $old);
    [$status, $body] = csat_http($address, 'GET', "t=$old&s=1");
    $form = csat_form($body);
    $check(csat_row($pdo, $old) === $first && $form !== null && $form['scores'] === [] && $form['comment'] === '',
        'after 7 days the page offers a comment but no score change');
    [$status] = csat_http($address, 'POST', '', ['t' => $old, 's' => '1']);
    $check($status === 409 && csat_row($pdo, $old) === $first, 'after 7 days a POST cannot change the score');
    [$status, $body] = csat_http($address, 'POST', '', ['t' => $old, 'comment' => '   ']);
    $check($status === 422 && csat_row($pdo, $old) === $first
        && csat_says($body)['error'] === 'Type a comment first, then press Send.',
        'after 7 days an empty Send asks for a comment and records nothing');
    [$status] = csat_http($address, 'POST', '', ['t' => $old, 's' => '1', 'comment' => 'Late note']);
    $row = csat_row($pdo, $old);
    $check($status === 200 && $row['score'] === 3 && $row['comment'] === 'Late note',
        'after 7 days a late change keeps the score and still saves the comment');
} finally {
    proc_terminate($server);
    proc_close($server);
    if ($failures !== []) echo "--- built-in server log ---\n" . @file_get_contents("$runtime/http.log");
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($runtime);
}
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " of $checks satisfaction survey checks\n");
    exit(1);
}
echo "PASS: $checks satisfaction survey HTTP checks\n";
