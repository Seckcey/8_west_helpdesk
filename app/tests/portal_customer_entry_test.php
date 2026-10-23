<?php
/**
 * Safeharbor — a customer who opens the staff sign-in page goes to the customer portal. CLI only.
 * DB-FREE, no settings file.
 *
 *   php app/tests/portal_customer_entry_test.php
 *
 * This runs the EXACT app/public/login.php under PHP's built-in web server and reads the real HTTP
 * status and Location header back with curl, once per case.
 *
 * login.php has one dependency, lib/auth.php. In a throwaway runtime that file is replaced by stubs,
 * the same way suite_chrome_test.php stubs suite_sso_claims(). The stub returns the claim set a case
 * supplies, which is what the real helper returns for a token it has already verified (or null).
 * current_user(), suite_sso_attempt() and attempt_login() return what the case says. Every stub call
 * is appended to a trace file, so a case can also show which staff paths ran.
 *
 * What this does NOT prove:
 * - A stubbed null claim set stands for both "no cookie" and "token rejected". It says nothing about
 *   signature, issuer, expiry, JWKS or claim-shape checks. The immutable suite_sso_test.php owns
 *   those (suite_rs256_test.php covers the RS256 keys), and it also owns the staff sign-in refusing
 *   client roles before it provisions anything.
 * - The stubs never start a session. "No staff provisioning" here means: no staff session, no
 *   password login, no second 8 West ID attempt, and no cookie set by login.php itself.
 * - No database, settings file, account, session store or provider call is used.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$PASS = 0; $FAIL = 0;
function t(string $name, bool $cond): void
{
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n"; }
}

// Stand-in for lib/auth.php. The case comes from case.json; every call is written to trace.log.
$stub = <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['entry_case'] = json_decode((string)file_get_contents(__DIR__ . '/case.json'), true, 16, JSON_THROW_ON_ERROR);
$GLOBALS['entry_user'] = $GLOBALS['entry_case']['user'];
function entry_trace(string $call): void { file_put_contents(__DIR__ . '/trace.log', $call . "\n", FILE_APPEND); }
function enforce_https(): void { entry_trace('enforce_https'); }
function current_user(): ?array { entry_trace('current_user'); return $GLOBALS['entry_user']; }
function suite_sso_attempt(): bool
{
    entry_trace('suite_sso_attempt');
    if ($GLOBALS['entry_case']['admit'] !== true) return false;
    $GLOBALS['entry_user'] = ['id' => 7, 'role' => 'tech'];
    return true;
}
function suite_sso_claims(): ?array { entry_trace('suite_sso_claims'); return $GLOBALS['entry_case']['claims']; }
function csrf_check(): void { entry_trace('csrf_check'); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="synthetic">'; }
function attempt_login(string $email, string $password): bool { entry_trace('attempt_login'); return $GLOBALS['entry_case']['password'] === true; }
function cfg(string $key, mixed $default = null): mixed { return $default; }
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
PHP;

$source = realpath(__DIR__ . '/../public/login.php');
$runtime = sys_get_temp_dir() . '/safeharbor-customer-entry-' . bin2hex(random_bytes(8));
foreach (['public', 'lib'] as $part) mkdir($runtime . '/' . $part, 0700, true);
copy($source, $runtime . '/public/login.php');
file_put_contents($runtime . '/lib/auth.php', $stub);

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr); $address = stream_socket_get_name($socket, false); fclose($socket);
$process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'display_errors=1', '-d', 'html_errors=0', '-d', 'error_reporting=-1',
    '-S', $address, '-t', $runtime . '/public'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $runtime . '/http.log', 'a'], 2 => ['file', $runtime . '/http.log', 'a']], $pipes);

/** One request against the exact login.php; returns what came back over HTTP plus the stub trace. */
function entry_request(string $runtime, string $address, array $case, string $method = 'GET', string $query = '', array $post = []): array
{
    file_put_contents($runtime . '/lib/case.json', json_encode($case + ['claims' => null, 'user' => null, 'admit' => false, 'password' => false], JSON_THROW_ON_ERROR));
    file_put_contents($runtime . '/lib/trace.log', '');
    $curl = curl_init('http://' . $address . '/login.php' . $query); $headers = [];
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => static function ($c, string $h) use (&$headers): int { $headers[] = $h; return strlen($h); }]);
    if ($method === 'POST') curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    if ($method === 'HEAD') curl_setopt($curl, CURLOPT_NOBODY, true);
    $body = (string)curl_exec($curl);
    $named = static fn(string $name): array => array_values(array_map(static fn(string $h): string => trim(substr($h, strlen($name) + 1)),
        array_filter($headers, static fn(string $h): bool => stripos($h, $name . ':') === 0)));
    return ['status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body,
        'location' => $named('Location'), 'cookies' => $named('Set-Cookie'),
        'trace' => array_values(array_filter(explode("\n", (string)file_get_contents($runtime . '/lib/trace.log'))))];
}

/** The staff page as it is today: the password form, no redirect, no diagnostics. */
function entry_staff_form(array $r): bool
{
    return $r['status'] === 200 && $r['location'] === [] && str_contains($r['body'], 'name="password"')
        && preg_match('/(?:Warning|Notice|Deprecated|Fatal error|Parse error):/', $r['body']) !== 1;
}

function entry_calls(array $r, string $call): int
{
    return count(array_keys($r['trace'], $call, true));
}

$claims = static fn(string $role, array $products = ['safeharbor']): array => [
    'sub' => 't4u12', 'email' => 'member@example.test', 'name' => 'Synthetic Member', '8west:tenant' => 'synthetic-client',
    '8west:role' => $role, '8west:products' => $products, 'exp' => time() + 600,
];

try {
    for ($n = 0; $n < 100; $n++) { $probe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1); if ($probe) { fclose($probe); break; } usleep(20000); }
    $req = static fn(array $case, string $method = 'GET', string $query = '', array $post = []): array
        => entry_request($runtime, $address, $case, $method, $query, $post);

    /* ── A. The harness runs the real page. ───────────────────────────────────────────────────── */
    t('A1: the runtime serves a byte-identical copy of app/public/login.php',
        hash_file('sha256', $source) === hash_file('sha256', $runtime . '/public/login.php'));
    $r = $req(['claims' => null]);
    t('A2: with no 8 West ID claims the exact page answers over HTTP with the staff password form',
        entry_staff_form($r) && in_array('suite_sso_claims', $r['trace'], true));

    /* ── B. A verified client role with Safeharbor, on GET, goes to the fixed portal address. ── */
    $hostile = ['https://evil.example/steal', '//evil.example/x', '/\\evil.example', '/portal/ticket.php?id=1', 'javascript:alert(1)'];
    foreach (['client_owner', 'client_admin', 'client_staff', 'client_viewer'] as $i => $role) {
        $next = $hostile[$i];
        $r = $req(['claims' => $claims($role)], 'GET', '?next=' . rawurlencode($next));
        t("B[$role]: GET redirects with exactly Location: /portal/ (next=$next ignored)",
            $r['status'] === 302 && $r['location'] === ['/portal/'] && !str_contains($r['body'], 'name="password"'));
        t("B[$role]: no staff provisioning (one existing 8 West ID attempt, no password login, no cookie from login.php)",
            entry_calls($r, 'suite_sso_attempt') === 1 && entry_calls($r, 'attempt_login') === 0
            && entry_calls($r, 'csrf_check') === 0 && $r['cookies'] === []);
    }
    $r = $req(['claims' => $claims('client_staff', ['milepost', 'safeharbor', 'coastmark'])], 'GET', '?next=' . rawurlencode($hostile[4]));
    t('B5: Safeharbor among other products still goes only to /portal/', $r['status'] === 302 && $r['location'] === ['/portal/']);

    /* ── C. Anything else keeps today's staff sign-in. ─────────────────────────────────────────── */
    $r = $req(['claims' => null], 'GET', '?next=' . rawurlencode($hostile[0]));
    t('C: null claims (no cookie, or a token the real helper rejected) get the staff form, never /portal/',
        entry_staff_form($r) && entry_calls($r, 'suite_sso_attempt') === 1);
    foreach (['owner', 'admin', 'tech', 'readonly', 'custodian', 'msp_owner', 'msp_admin', 'msp_tech', 'msp_viewer'] as $role) {
        $r = $req(['claims' => $claims($role)], 'GET', '?next=%2Ftickets.php');
        t("C[$role]: a staff or MSP role the staff sign-in refused gets the staff form, never /portal/",
            entry_staff_form($r) && entry_calls($r, 'suite_sso_attempt') === 1);
    }
    foreach (['client_superuser', 'Client_Owner', 'client_owner ', ''] as $role) {
        t("C[unknown '$role']: an unknown role gets the staff form", entry_staff_form($req(['claims' => $claims($role)])));
    }
    $noRole = $claims('client_owner'); unset($noRole['8west:role']);
    t('C: a missing role gets the staff form', entry_staff_form($req(['claims' => $noRole])));
    $listRole = $claims('client_owner'); $listRole['8west:role'] = ['client_owner'];
    t('C: a role that is not a string gets the staff form', entry_staff_form($req(['claims' => $listRole])));

    $noProducts = $claims('client_owner'); unset($noProducts['8west:products']);
    t('C: a missing products claim gets the staff form', entry_staff_form($req(['claims' => $noProducts])));
    foreach (['null' => null, 'string' => 'safeharbor', 'empty list' => [], 'other apps only' => ['milepost', 'coastmark'],
        'wrong case' => ['Safeharbor'], 'nested list' => [['safeharbor']]] as $label => $products) {
        $malformed = $claims('client_owner'); $malformed['8west:products'] = $products;
        t("C[products $label]: gets the staff form", entry_staff_form($req(['claims' => $malformed])));
    }

    $r = $req(['claims' => $claims('client_owner')], 'GET', '?reauth=1&next=%2F');
    t('C: reauth=1 keeps the staff form and makes no 8 West ID attempt', entry_staff_form($r) && entry_calls($r, 'suite_sso_attempt') === 0);

    $r = $req(['claims' => $claims('client_owner')], 'POST', '', ['email' => 'tech@example.test', 'password' => 'wrong', 'next' => '/tickets.php']);
    t('C: a POST with a customer cookie keeps the staff password path (form again, no /portal/)',
        entry_staff_form($r) && entry_calls($r, 'csrf_check') === 1 && entry_calls($r, 'attempt_login') === 1);
    $r = $req(['claims' => $claims('client_owner'), 'password' => true], 'POST', '', ['email' => 'tech@example.test', 'password' => 'right', 'next' => '/tickets.php']);
    // PHP answers a Location set during a POST with 303, a GET with 302.
    t('C: a successful staff password POST still goes to its own safe next, not /portal/',
        in_array($r['status'], [302, 303], true) && $r['location'] === ['/tickets.php']);
    $r = $req(['claims' => $claims('client_owner')], 'HEAD', '?next=%2F');
    t('C: a HEAD request is not redirected to /portal/', $r['status'] === 200 && $r['location'] === []);

    $staff = ['id' => 3, 'role' => 'admin'];
    $r = $req(['claims' => $claims('client_owner'), 'user' => $staff], 'GET', '?next=' . rawurlencode($hostile[0]));
    t('C: an existing staff session still goes to / (never /portal/) with no new 8 West ID attempt',
        $r['status'] === 302 && $r['location'] === ['/'] && entry_calls($r, 'suite_sso_attempt') === 0);
    t('C: an existing staff session with reauth=1 still gets the staff form',
        entry_staff_form($req(['claims' => $claims('client_owner'), 'user' => $staff], 'GET', '?reauth=1')));
    $r = $req(['claims' => $claims('msp_tech'), 'admit' => true]);
    t('C: a staff member the 8 West ID sign-in admits still goes to /', $r['status'] === 302 && $r['location'] === ['/']);
} catch (Throwable $error) {
    t('no exception: ' . $error->getMessage(), false);
} finally {
    proc_terminate($process); proc_close($process);
    if ($FAIL > 0 && is_file($runtime . '/http.log')) fwrite(STDERR, substr((string)file_get_contents($runtime . '/http.log'), -4000));
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($runtime);
}

echo "\n";
if ($FAIL > 0) { echo "CUSTOMER ENTRY TESTS FAILED: $FAIL of " . ($PASS + $FAIL) . " checks failed.\n"; exit(1); }
echo "CUSTOMER ENTRY TESTS PASSED: all $PASS checks passed.\n";
