<?php
/**
 * A POST from another site, over real HTTP: a disposable loopback MySQL, a runtime copy of the app on PHP's
 * built-in server, synthetic data and a synthetic 8 West ID (HS256 test tokens signed with a per-run secret, an
 * issuer under .invalid that can never resolve, and a signed revocation snapshot in the runtime's private
 * cache). No live identity, mail or network.
 *
 * A top-level POST from another site arrives without the SameSite=Lax session cookie, and the browser stores
 * whatever session cookie the response sets. So that response sets none, and every other first request still
 * gets one. curl has no cookie jar, so this checks the headers; tools/shots/cross-site-session.test.mjs checks
 * what a real browser does with them.
 */
declare(strict_types=1);
require_once __DIR__ . '/sql_file.php';
require_once __DIR__ . '/../lib/suite_revocation_policy.php';

if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_CROSS_SITE_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
$host = getenv('SAFEHARBOR_CROSS_SITE_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_CROSS_SITE_TEST_PORT') ?: '3306';
$base = getenv('SAFEHARBOR_CROSS_SITE_TEST_DB') ?: '';
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535
    || preg_match('/\Asafeharbor_cross_site_test(?:_[a-z0-9_]+)?\z/D', $base) !== 1 || strlen($base) > 42) exit(2);
$user = getenv('SAFEHARBOR_CROSS_SITE_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_CROSS_SITE_TEST_PASS') ?: '';
$database = $base . '_' . bin2hex(random_bytes(5));
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$pdo->exec("USE `$database`");
$pdo->exec("SET time_zone = '+00:00'");

$app = realpath(__DIR__ . '/..');
$runtime = sys_get_temp_dir() . '/safeharbor-cross-site-http-' . bin2hex(random_bytes(8));
$process = null;
$checks = 0;
$failures = [];
$check = static function (bool $ok, string $name) use (&$checks, &$failures): void {
    $checks++;
    echo ($ok ? 'ok ' : 'not ok ') . "$checks - $name\n";
    if (!$ok) $failures[] = $name;
};

try {
    foreach (['schema.sql', 'migrations/001_mail_queue.sql', 'migrations/002_svc_intake.sql',
        'migrations/009_support_intake.sql', 'migrations/018_ticket_auto_close_eligibility.sql'] as $file) {
        test_execute_sql_file($pdo, $app . '/db/' . $file);
    }
    $pdo->exec("INSERT INTO tenants (id, name, slug, plan) VALUES (1, 'Synthetic One', 'synthetic-one', 'suite')");
    $pdo->exec("INSERT INTO clients (id, tenant_id, name) VALUES (11, 1, 'Fixture Dental')");
    // 101 signs in with a local password; 102 is linked to a synthetic 8 West ID subject.
    $password = 'synthetic-' . bin2hex(random_bytes(8));
    $u = $pdo->prepare('INSERT INTO users (id, tenant_id, email, suite_subject, password_hash, full_name, initials, role, is_active) VALUES (?,1,?,?,?,?,?,?,1)');
    $u->execute([101, 'u101@fixture.invalid', null, password_hash($password, PASSWORD_DEFAULT), 'Fixture Local', 'FL', 'owner']);
    $u->execute([102, 'u102@fixture.invalid', 't1u102', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'Fixture Suite', 'FS', 'owner']);
    $pdo->exec("INSERT INTO tickets (id, tenant_id, client_id, subject, channel, priority, sla_due_at) VALUES (7001, 1, 11, 'Synthetic ticket', 'email', 'low', UTC_TIMESTAMP())");

    // A runtime copy of the app with a settings file built from the sample, as CI's settings step does.
    $copyTree = static function (string $source, string $target): void {
        if (!is_dir($target)) mkdir($target, 0700, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $to = $target . '/' . substr($file->getPathname(), strlen($source) + 1);
            $file->isDir() ? (is_dir($to) || mkdir($to, 0700, true)) : copy($file->getPathname(), $to);
        }
    };
    foreach (['config', 'sessions', 'attachments', 'cache/portal'] as $part) mkdir($runtime . '/' . $part, 0700, true);
    $copyTree($app . '/lib', $runtime . '/lib');
    $copyTree($app . '/public', $runtime . '/public');
    $secret = bin2hex(random_bytes(32));
    $issuer = 'https://id.fixture.invalid';
    $config = require $app . '/config/config.sample.php';
    $config['app_env'] = 'dev';
    $config['force_https'] = false;
    $config['db'] = ['host' => $host, 'port' => (int)$port, 'user' => $user, 'pass' => $pass, 'name' => $database, 'charset' => 'utf8mb4'];
    $config['storage']['attachments_dir'] = $runtime . '/attachments';
    $config['suite'] = array_replace($config['suite'], [
        'issuer' => $issuer, 'sso_secret' => $secret, 'cookie_name' => 'ewid_token', 'token_algorithms' => ['HS256'],
        'jwks_url' => $issuer . '/.well-known/jwks.json', 'jwks_cache_path' => $runtime . '/cache/jwks.json',
        'revocation_cache_path' => $runtime . '/cache/suite-revocations.json', 'session_version_mode' => 'compat', 'mfa_policy_mode' => 'off',
    ]);
    $config['portal'] = array_replace($config['portal'], [
        'enabled' => true, 'issuer' => $issuer, 'client_id' => 'fixture-client', 'client_secret' => bin2hex(random_bytes(16)),
        'redirect_uri' => 'http://127.0.0.1/portal/callback.php', 'cookie_secure' => false, 'revocation_cache_dir' => $runtime . '/cache/portal',
    ]);
    file_put_contents($runtime . '/config/config.php', '<?php return ' . var_export($config, true) . ';');

    // The signed snapshot 8 West ID would publish (nobody revoked), cached as if fetched just now. It is
    // rewritten before every request, so the 60-second cache never sends the app to the network.
    $revocations = static function () use ($runtime, $secret): void {
        $body = json_encode(['generated_at' => gmdate('c'), 'count' => 0, 'revoked' => []], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $envelope = suite_revocation_cache_envelope(time(), suite_revocation_body_mentions_versioned($body), $body,
            hash_hmac('sha256', $body, $secret), $secret);
        file_put_contents($runtime . '/cache/suite-revocations.json', json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        chmod($runtime . '/cache/suite-revocations.json', 0600);
    };
    // Person 102's 8 West ID cookie.
    $b64 = static fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $claims = $b64(json_encode(['iss' => $issuer, 'exp' => time() + 900, 'sub' => 't1u102', 'email' => 'u102@fixture.invalid',
        'name' => 'Fixture Suite', '8west:role' => 'msp_owner', '8west:tenant' => 'synthetic-one', '8west:products' => ['safeharbor'],
        '8west:session_version' => '1.1']));
    $suite = ['ewid_token' => "$head.$claims." . $b64(hash_hmac('sha256', "$head.$claims", $secret, true))];

    // Person 101's signed-in session, as a file, and the cookie their browser holds for it.
    $token = bin2hex(random_bytes(32));
    $person = ['PHPSESSID' => bin2hex(random_bytes(16))];
    file_put_contents($runtime . '/sessions/sess_' . $person['PHPSESSID'], 'user_id|' . serialize(101) . 'csrf|' . serialize($token));

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'session.save_path=' . $runtime . '/sessions', '-S', $address, '-t', $runtime . '/public'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $runtime . '/http.log', 'a'], 2 => ['file', $runtime . '/http.log', 'a']], $pipes);
    for ($n = 0; $n < 100; $n++) { $probe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1); if ($probe) { fclose($probe); break; } usleep(20000); }

    /** @return array{status:int, body:string, location:string, sessions:list<string>, portals:list<string>} every value the response set for each session cookie, deletions included */
    $http = static function (string $method, string $route, array $cookies = [], array $headers = [], ?string $body = null) use ($address, $revocations): array {
        $revocations();
        if ($cookies !== []) $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $name, string $value): string => "$name=$value", array_keys($cookies), $cookies));
        $curl = curl_init('http://' . $address . $route);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        $raw = (string)curl_exec($curl);
        $size = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $response = substr($raw, 0, $size);
        preg_match_all('/^Set-Cookie:\s*PHPSESSID=([^;\r\n]*)/mi', $response, $sessions);
        preg_match_all('/^Set-Cookie:\s*safeharbor_portal=([^;\r\n]*)/mi', $response, $portals);
        return ['status' => $status, 'body' => substr($raw, $size), 'location' => preg_match('/^Location:\s*(\S+)/mi', $response, $to) === 1 ? $to[1] : '',
            'sessions' => $sessions[1], 'portals' => $portals[1]];
    };
    // What a browser sends with a form on another site, and with a form on our own page.
    $forged = ['Sec-Fetch-Site: cross-site', 'Sec-Fetch-Mode: navigate', 'Origin: https://forged.example'];
    $own = ['Sec-Fetch-Site: same-origin', 'Sec-Fetch-Mode: navigate', 'Origin: http://' . $address];
    $api = '/api/ticket_action.php';
    $priority = static fn(): string => (string)$pdo->query('SELECT priority FROM tickets WHERE id = 7001')->fetchColumn();
    $pageToken = static fn(string $html): string => preg_match('/<body[^>]* data-csrf="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
    $formToken = static fn(string $html): string => preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
    // Development settings print PHP warnings into the page (as HTML or plain text, per html_errors), so a
    // clean page means a clean run.
    $clean = static fn(string $body): bool => preg_match('/(?:<b>|^)(?:Warning|Notice|Deprecated|Fatal error)(?:<\/b>)?:/m', $body) !== 1;

    // A form on another site: staff pages, with neither of the person's cookies, as a browser sends it.
    foreach (['/', '/login.php', '/logout.php', '/snippets.php', $api] as $page) {
        $r = $http('POST', $page, [], $forged, 'x=1');
        $check($r['sessions'] === [], "a post from another site to $page sets no session cookie and deletes none");
        $check($clean($r['body']), "and $page answers it without a PHP warning");
    }
    $r = $http('POST', '/', [], $forged, 'x=1');
    $check($r['status'] === 302 && $r['location'] === '/login.php?next=%2F', 'the app answers it as it answers anybody not signed in');
    $check($http('POST', '/login.php', [], $forged, 'x=1')['status'] === 419, 'the sign-in form still refuses it for its missing token');
    $r = $http('GET', '/', $person);
    $check($r['status'] === 200 && $pageToken($r['body']) === $token && $r['sessions'] === [], 'the person\'s own session and token are as they were');

    // A browser with no Fetch Metadata falls back on Origin.
    $r = $http('POST', '/', [], ['Origin: https://forged.example'], 'x=1');
    $check($r['status'] === 302 && $r['sessions'] === [], 'with no Sec-Fetch-Site, an Origin from another site sets no session cookie');
    $check($http('POST', '/', [], ['Origin: null'], 'x=1')['sessions'] === [], 'with no Sec-Fetch-Site, an Origin of null sets no session cookie');

    // A first POST from anywhere else still gets its session.
    foreach ([
        'our own page' => $own,
        'a sibling app' => ['Sec-Fetch-Site: same-site', 'Origin: https://id.fixture.invalid'],
        'the person (no page)' => ['Sec-Fetch-Site: none'],
        'a browser with no Fetch Metadata, on our own page' => ['Origin: http://' . $address],
        'a caller that is not a browser' => [],
    ] as $from => $headers) {
        $r = $http('POST', '/login.php', [], $headers, 'x=1');
        $first = $r['sessions'][0] ?? '';
        $check($r['status'] === 419 && count($r['sessions']) === 1 && $first !== '' && $first !== 'deleted', "a first post from $from gets a session cookie");
        $check(is_file($runtime . '/sessions/sess_' . $first), 'and the session it names was saved');
    }

    // A link on another site is a GET: a first visit gets a session, and the sign-in form works.
    $r = $http('GET', '/login.php', [], ['Sec-Fetch-Site: cross-site', 'Sec-Fetch-Mode: navigate']);
    $visitor = ['PHPSESSID' => $r['sessions'][0] ?? ''];
    $early = $formToken($r['body']);
    $check($r['status'] === 200 && $visitor['PHPSESSID'] !== '' && $early !== '', 'arriving by a link from another site starts a session');
    $r = $http('POST', '/login.php', $visitor, $own, http_build_query(['csrf' => $early, 'email' => 'u101@fixture.invalid', 'password' => $password, 'next' => '/']));
    $signed = ['PHPSESSID' => $r['sessions'][0] ?? ''];
    $check($r['status'] === 302 && $r['location'] === '/' && $signed['PHPSESSID'] !== '' && $signed !== $visitor, 'the sign-in form signs in and gives the session a new id');
    $check($http('GET', '/', $signed)['status'] === 200, 'and that session is signed in');

    // A browser that ignores SameSite sends the cookie across sites. Nothing is replaced, so the session carries on.
    $r = $http('POST', $api, $person, array_merge($forged, ["X-CSRF: $token", 'Content-Type: application/json']), json_encode(['id' => 7001, 'field' => 'priority', 'value' => 'high']));
    $check($r['status'] === 200 && $r['sessions'] === [] && $priority() === 'high', 'a post from another site that does carry the session cookie stays in that session');

    // Signing out from our own page still clears the cookie, with or without a token-checked sign-out.
    $leaving = ['PHPSESSID' => bin2hex(random_bytes(16))];
    file_put_contents($runtime . '/sessions/sess_' . $leaving['PHPSESSID'], 'user_id|' . serialize(101) . 'csrf|' . serialize($token));
    $check(in_array('deleted', $http('POST', '/logout.php', $leaving, $own, http_build_query(['csrf' => $token]))['sessions'], true), 'signing out from our own page clears the session cookie');

    // 8 West ID alone, signing in inside the POST. From our own page, the session it opens reaches the browser.
    $r = $http('POST', $api, $suite, array_merge($own, ['Content-Type: application/json']), json_encode(['id' => 7001, 'field' => 'priority', 'value' => 'urgent']));
    $opened = ['PHPSESSID' => $r['sessions'][count($r['sessions']) - 1] ?? ''];
    $check($r['status'] === 419 && $opened['PHPSESSID'] !== '' && $priority() === 'high', 'a post from our own page carrying only the 8 West ID cookie opens a session and is refused for its missing token');
    $r = $http('GET', '/', $suite + $opened);
    $check($r['status'] === 200 && $r['sessions'] === [] && $pageToken($r['body']) !== '', 'and that session is the person\'s from then on');

    // Browsers keep the Lax 8 West ID cookie off a cross-site POST too. Were one to send it, the sign-in stays inside that one request.
    $r = $http('POST', $api, $suite, array_merge($forged, ['Content-Type: application/json']), json_encode(['id' => 7001, 'field' => 'priority', 'value' => 'urgent']));
    $check($r['status'] === 419 && $r['sessions'] === [] && $clean($r['body']) && $priority() === 'high', 'a post from another site carrying the 8 West ID cookie sets no session cookie, cleanly, and changes nothing');
    $r = $http('POST', '/', $suite, $forged, 'x=1');
    $check($r['status'] === 200 && $r['sessions'] === [] && $clean($r['body']), 'a page opened that way renders cleanly and sets no session cookie');

    // The customer portal's own session, on its own cookie.
    foreach (['/portal/logout.php', '/portal/new.php', '/portal/ticket.php?id=1', '/portal/westy.php', '/portal/devices.php', '/portal/desktop.php'] as $page) {
        $r = $http('POST', $page, [], $forged, 'x=1');
        $check($r['portals'] === [], "a post from another site to $page sets no portal cookie and deletes none");
        $check($clean($r['body']), "and $page answers it without a PHP warning");
    }
    $check($http('POST', '/portal/logout.php', [], ['Origin: https://forged.example'], 'x=1')['portals'] === [], 'with no Sec-Fetch-Site, an Origin from another site sets no portal cookie');
    // The Companion sign-out needs no token once there is no identity, and a forged post arrives with neither cookie nor identity.
    foreach (['Sec-Fetch-Site' => $forged, 'Origin' => ['Origin: https://forged.example']] as $how => $headers) {
        $r = $http('POST', '/portal/logout.php', [], $headers, 'window=companion');
        $check($r['status'] === 302 && str_starts_with($r['location'], '/portal/signed-out.php') && $r['portals'] === [] && $clean($r['body']), "a forged Companion sign-out, told by $how, deletes no portal cookie");
    }
    $r = $http('POST', '/portal/desktop.php', [], $own, 'x=1');
    $first = $r['portals'][0] ?? '';
    $check(count($r['portals']) === 1 && $first !== '' && $first !== 'deleted', 'a first post from the portal\'s own page gets a portal cookie');
    // Strict mode refuses an id it has no session for, so a second post that gets no new cookie stayed in the first's session.
    $check($http('POST', '/portal/desktop.php', ['safeharbor_portal' => $first], $own, 'x=1')['portals'] === [], 'and that session carries on');

    // A browser that ignores SameSite sends the portal cookie across sites too. Nothing is replaced, so that session's token still works.
    $portalToken = bin2hex(random_bytes(32));
    $customer = ['safeharbor_portal' => bin2hex(random_bytes(16))];
    file_put_contents($runtime . '/sessions/sess_' . $customer['safeharbor_portal'], '_safeharbor_portal_csrf|' . serialize($portalToken));
    $r = $http('POST', '/portal/logout.php', $customer, $forged, http_build_query(['csrf' => $portalToken]));
    $check($r['status'] === 302 && in_array('deleted', $r['portals'], true), 'a post from another site that does carry the portal cookie stays in that session');

    // Pages with no browser session at all answer a post from another site as they answer anything else.
    foreach (['/csat.php', '/api/svc/support.php', '/api/svc/alerts.php', '/api/svc/westy.php', '/api/svc/customers.php'] as $page) {
        $forgedAnswer = $http('POST', $page, [], $forged, 'x=1');
        $plainAnswer = $http('POST', $page, [], [], 'x=1');
        $check($forgedAnswer['status'] === $plainAnswer['status'] && $forgedAnswer['sessions'] === [] && $plainAnswer['sessions'] === [], "$page starts no session and answers the same either way");
    }
} catch (Throwable $error) {
    $failures[] = 'unexpected ' . get_class($error) . ': ' . $error->getMessage();
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if ($failures !== [] && is_file($runtime . '/http.log')) echo "--- built-in server log ---\n" . substr((string)file_get_contents($runtime . '/http.log'), -6000) . "\n";
    if (is_dir($runtime)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($runtime);
    }
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
}
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " of $checks cross-site session checks\n" . implode("\n", $failures) . "\n");
    exit(1);
}
echo "PASS: $checks cross-site session HTTP checks\n";
