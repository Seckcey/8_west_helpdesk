<?php
/**
 * Staff sign-out over real HTTP: a disposable loopback MySQL, a runtime copy of the app on PHP's built-in
 * server, synthetic data and a synthetic 8 West ID (HS256 test tokens signed with a per-run secret, an
 * issuer under .invalid that can never resolve, and a signed revocation snapshot in the runtime's private
 * cache). No live identity, mail or network.
 *
 * Only a post with the session's own token signs anyone out. Loading the address is all a link or an image
 * can make a browser do, so it leaves the person signed in and offers a button instead. A post without the
 * token goes to that same page, so a stale tab or a second press can still finish signing out.
 */
declare(strict_types=1);
require_once __DIR__ . '/sql_file.php';
require_once __DIR__ . '/../lib/suite_revocation_policy.php';

if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_LOGOUT_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
$host = getenv('SAFEHARBOR_LOGOUT_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_LOGOUT_TEST_PORT') ?: '3306';
$base = getenv('SAFEHARBOR_LOGOUT_TEST_DB') ?: '';
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535
    || preg_match('/\Asafeharbor_logout_test(?:_[a-z0-9_]+)?\z/D', $base) !== 1 || strlen($base) > 42) exit(2);
$user = getenv('SAFEHARBOR_LOGOUT_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_LOGOUT_TEST_PASS') ?: '';
$database = $base . '_' . bin2hex(random_bytes(5));
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$pdo->exec("USE `$database`");
$pdo->exec("SET time_zone = '+00:00'");

$app = realpath(__DIR__ . '/..');
$runtime = sys_get_temp_dir() . '/safeharbor-logout-http-' . bin2hex(random_bytes(8));
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
    // 101 signs in with a local password; 102 is linked to a synthetic 8 West ID subject.
    $u = $pdo->prepare('INSERT INTO users (id, tenant_id, email, suite_subject, password_hash, full_name, initials, role, is_active) VALUES (?,1,?,?,?,?,?,?,1)');
    $u->execute([101, 'u101@fixture.invalid', null, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'Fixture Local', 'FL', 'owner']);
    $u->execute([102, 'u102@fixture.invalid', 't1u102', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'Fixture Suite', 'FS', 'owner']);

    // A runtime copy of the app with a settings file built from the sample, as CI's settings step does.
    $copyTree = static function (string $source, string $target): void {
        if (!is_dir($target)) mkdir($target, 0700, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $to = $target . '/' . substr($file->getPathname(), strlen($source) + 1);
            $file->isDir() ? (is_dir($to) || mkdir($to, 0700, true)) : copy($file->getPathname(), $to);
        }
    };
    foreach (['config', 'public', 'sessions', 'attachments', 'cache'] as $part) mkdir($runtime . '/' . $part, 0700, true);
    chmod($runtime . '/cache', 0700);
    $copyTree($app . '/lib', $runtime . '/lib');
    foreach (['index.php', 'logout.php'] as $page) copy($app . '/public/' . $page, $runtime . '/public/' . $page);
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

    /** A new signed-in session for person 101, as a file. @return array{0:array{PHPSESSID:string},1:string} its cookie and its token */
    $signIn = static function () use ($runtime): array {
        $id = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        file_put_contents($runtime . '/sessions/sess_' . $id, 'user_id|' . serialize(101) . 'csrf|' . serialize($token));
        return [['PHPSESSID' => $id], $token];
    };

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'session.save_path=' . $runtime . '/sessions', '-S', $address, '-t', $runtime . '/public'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $runtime . '/http.log', 'a'], 2 => ['file', $runtime . '/http.log', 'a']], $pipes);
    for ($n = 0; $n < 100; $n++) { $probe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1); if ($probe) { fclose($probe); break; } usleep(20000); }

    /** @return array{status:int, body:string, location:string, session:string} session: the PHPSESSID the response set last, or '' */
    $http = static function (string $method, string $route, array $cookies = [], array $headers = [], ?string $body = null) use ($address, $revocations): array {
        $revocations();
        if ($cookies !== []) $headers[] = 'Cookie: ' . implode('; ', array_map(static fn(string $name, string $value): string => "$name=$value", array_keys($cookies), $cookies));
        $curl = curl_init('http://' . $address . $route);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        $raw = (string)curl_exec($curl);
        $size = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $head = substr($raw, 0, $size);
        preg_match_all('/^Set-Cookie:\s*PHPSESSID=([^;\r\n]*)/mi', $head, $set);
        return ['status' => $status, 'body' => substr($raw, $size), 'location' => preg_match('/^Location:\s*(\S+)/mi', $head, $to) === 1 ? $to[1] : '',
            'session' => $set[1] === [] ? '' : $set[1][array_key_last($set[1])]];
    };
    $logout = '/logout.php';
    $pageToken = static fn(string $html): string => preg_match('/<body[^>]* data-csrf="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
    /** Every token a sign-out form on this page would post, in page order. */
    $signOutForms = static fn(string $html): array => preg_match_all('~<form method="post" action="/logout\.php">\s*<input type="hidden" name="csrf" value="([0-9a-f]{64})">~', $html, $m) > 0 ? $m[1] : [];
    // Development settings print PHP warnings into the page (as HTML or plain text, per html_errors), so a
    // clean page means a clean run.
    $clean = static fn(string $body): bool => preg_match('/(?:<b>|^)(?:Warning|Notice|Deprecated|Fatal error)(?:<\/b>)?:/m', $body) !== 1;
    $turnedBack = static fn(array $r): bool => $r['status'] === 303 && $r['location'] === $logout && $clean($r['body']);
    $stillSignedIn = static fn(array $cookies): bool => $http('GET', '/', $cookies)['status'] === 200;
    $toSignIn = static fn(array $r, string $next): bool => $r['status'] === 302 && $r['location'] === '/login.php?next=' . urlencode($next);

    // Loading the address signs nobody out: it shows a button that posts this session's token.
    [$person, $token] = $signIn();
    $r = $http('GET', $logout, $person);
    $check($r['status'] === 200 && $clean($r['body']) && $r['location'] === '', 'opening the address shows a page, with no redirect');
    $forms = $signOutForms($r['body']);
    $check(count($forms) === 2 && $forms === [$token, $token], 'its Sign out button and the account menu both post this session\'s token');
    $check($stillSignedIn($person), 'a GET leaves the person signed in');
    foreach (['HEAD', 'PUT', 'DELETE'] as $method) {
        $http($method, $logout, $person);
        $check($stillSignedIn($person), "a $method leaves the person signed in");
    }
    $check($toSignIn($http('GET', $logout), '/'), 'opening the address with no session goes to the sign-in page, which then returns to the queue');

    // A post without this session's own token goes to that page, and the person stays signed in.
    [$other, $otherToken] = $signIn();
    foreach ([
        'with no token' => [[], ''],
        'with an empty token' => [[], 'csrf='],
        'with a wrong token' => [[], 'csrf=' . str_repeat('0', 64)],
        'with another session\'s token' => [[], 'csrf=' . $otherToken],
        'with an empty X-CSRF header' => [['X-CSRF;'], ''],
        'with a wrong X-CSRF header' => [['X-CSRF: ' . str_repeat('0', 64)], ''],
    ] as $name => [$headers, $body]) {
        [$person] = $signIn();
        $check($turnedBack($http('POST', $logout, $person, $headers, $body)), "a post $name goes to the page");
        $check($stillSignedIn($person), "and leaves the person signed in ($name)");
    }
    [$person, $token] = $signIn();
    $check($turnedBack($http('POST', $logout, $person, [], http_build_query(['csrf' => [$token]]))), 'a token sent as a list goes to the page, cleanly');
    $check($stillSignedIn($person), 'and leaves the person signed in (a token sent as a list)');
    $check($turnedBack($http('POST', $logout, [], [], '')), 'a post with no session goes to the page');

    // 8 West ID alone: the suite cookie gets a post no further than any other cookie does.
    $r = $http('POST', $logout, $suite, [], '');
    $check($turnedBack($r), 'a post carrying only the 8 West ID cookie goes to the page');
    $check($r['session'] === '' || $toSignIn($http('GET', '/', ['PHPSESSID' => $r['session']]), '/'), 'and signs nobody in');

    // The tokens the two ways in actually send: the account menu's field and the page's data-csrf (⌘K).
    [$person, $token] = $signIn();
    $r = $http('GET', '/', $person);
    $check($r['status'] === 200 && $signOutForms($r['body']) === [$token], 'a page\'s account menu posts this session\'s token');
    $check($pageToken($r['body']) === $token, 'the page carries the same token for the ⌘K action');
    $check(!str_contains($r['body'], 'href="/logout.php"'), 'and no plain link to the address');

    // The real one signs out and hands off to 8 West ID.
    $r = $http('POST', $logout, $person, [], 'csrf=' . $token);
    $check($r['status'] === 302 && $r['location'] === $issuer . '/logout.php', 'a post with this session\'s token hands off to 8 West ID');
    $check($r['session'] === 'deleted', 'the session cookie is cleared');
    $check(!is_file($runtime . '/sessions/sess_' . $person['PHPSESSID']), 'the session is gone from the server');
    $check($toSignIn($http('GET', '/', $person), '/'), 'the old session opens no pages');
    // Pressed again, or pressed in an old tab, with nothing left to sign out of.
    $check($turnedBack($http('POST', $logout, $person, [], 'csrf=' . $token)), 'posting the old token again goes to the page');
    $check($toSignIn($http('GET', $logout, $person), '/'), 'which, with no session left, goes to the sign-in page');

    // A session 8 West ID opened signs out the same way, with the token its page issued.
    $r = $http('GET', '/', $suite);
    $opened = ['PHPSESSID' => $r['session']];
    $sso = $pageToken($r['body']);
    $check($r['status'] === 200 && $r['session'] !== '' && $signOutForms($r['body']) === [$sso], 'a page opened through 8 West ID offers sign-out with its token');
    $check($turnedBack($http('POST', $logout, $suite + $opened, [], '')), 'that session sends a post with no token to the page');
    $r = $http('POST', $logout, $suite + $opened, [], 'csrf=' . $sso);
    $check($r['status'] === 302 && $r['location'] === $issuer . '/logout.php', 'and signs out with that token');
    $check($toSignIn($http('GET', '/', $opened), '/'), 'and its local session is gone');

    // Pressed twice, or pressed in an old tab, while the 8 West ID cookie is still good: the person can
    // still finish, because the page signs them back in locally and offers the button with a fresh token.
    $check($turnedBack($http('POST', $logout, $suite + $opened, [], 'csrf=' . $sso)), 'a second press before 8 West ID has cleared its cookie goes to the page');
    $r = $http('GET', $logout, $suite + $opened);
    $again = ['PHPSESSID' => $r['session']];
    $forms = $signOutForms($r['body']);
    $check($r['status'] === 200 && $r['session'] !== '' && count($forms) === 2 && $forms[0] !== $sso, 'which offers the button again with a fresh token');
    $r = $http('POST', $logout, $suite + $again, [], 'csrf=' . $forms[0]);
    $check($r['status'] === 302 && $r['location'] === $issuer . '/logout.php', 'and that token reaches 8 West ID');
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
    fwrite(STDERR, 'FAIL: ' . count($failures) . " of $checks staff sign-out checks\n" . implode("\n", $failures) . "\n");
    exit(1);
}
echo "PASS: $checks staff sign-out HTTP checks\n";
