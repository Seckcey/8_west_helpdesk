<?php
/**
 * CSRF over real HTTP: a disposable loopback MySQL, a runtime copy of the app on PHP's built-in server,
 * synthetic data and a synthetic 8 West ID (HS256 test tokens signed with a per-run secret, an issuer
 * under .invalid that can never resolve, and a signed revocation snapshot in the runtime's private
 * cache). No live identity, mail or network.
 *
 * An empty token is never a match. A session can hold no token: 8 West ID can open one inside the very
 * request that posts, an older session may never have rendered a page, and a first-time visitor has
 * none. A post that sends no token must get through none of them. Signing in also starts a fresh token,
 * so a token issued before sign-in (on the sign-in page) never works afterwards.
 */
declare(strict_types=1);
require_once __DIR__ . '/sql_file.php';
require_once __DIR__ . '/../lib/suite_revocation_policy.php';

if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_CSRF_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
$host = getenv('SAFEHARBOR_CSRF_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_CSRF_TEST_PORT') ?: '3306';
$base = getenv('SAFEHARBOR_CSRF_TEST_DB') ?: '';
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535
    || preg_match('/\Asafeharbor_csrf_test(?:_[a-z0-9_]+)?\z/D', $base) !== 1 || strlen($base) > 42) exit(2);
$user = getenv('SAFEHARBOR_CSRF_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_CSRF_TEST_PASS') ?: '';
$database = $base . '_' . bin2hex(random_bytes(5));
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$pdo->exec("USE `$database`");
$pdo->exec("SET time_zone = '+00:00'");

$app = realpath(__DIR__ . '/..');
$runtime = sys_get_temp_dir() . '/safeharbor-csrf-http-' . bin2hex(random_bytes(8));
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
    foreach (['config', 'public/api', 'sessions', 'attachments', 'cache'] as $part) mkdir($runtime . '/' . $part, 0700, true);
    chmod($runtime . '/cache', 0700);
    $copyTree($app . '/lib', $runtime . '/lib');
    foreach (['index.php', 'login.php', 'api/ticket_action.php'] as $page) copy($app . '/public/' . $page, $runtime . '/public/' . $page);
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

    // Two signed-in sessions as files: one that has never been given a token (a session from before
    // tokens were checked this strictly, or one 8 West ID opened on a request that rendered no page),
    // and one that has.
    $token = bin2hex(random_bytes(32));
    $tokenless = ['PHPSESSID' => bin2hex(random_bytes(16))];
    $tokened = ['PHPSESSID' => bin2hex(random_bytes(16))];
    file_put_contents($runtime . '/sessions/sess_' . $tokenless['PHPSESSID'], 'user_id|' . serialize(101));
    file_put_contents($runtime . '/sessions/sess_' . $tokened['PHPSESSID'], 'user_id|' . serialize(101) . 'csrf|' . serialize($token));

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
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
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
    $api = '/api/ticket_action.php';
    $json = ['Content-Type: application/json'];
    // curl drops a header written "Name:" with no value; "Name;" sends it empty.
    $xcsrf = static fn(string $sent): array => ['Content-Type: application/json', $sent === '' ? 'X-CSRF;' : "X-CSRF: $sent"];
    $raise = static fn(string $value): string => json_encode(['id' => 7001, 'field' => 'priority', 'value' => $value]);
    $field = static fn(string $value, mixed $sent): string => http_build_query(['csrf' => $sent, 'id' => 7001, 'field' => 'priority', 'value' => $value]);
    $priority = static fn(): string => (string)$pdo->query('SELECT priority FROM tickets WHERE id = 7001')->fetchColumn();
    $reset = static fn() => $pdo->exec("UPDATE tickets SET priority = 'low' WHERE id = 7001");
    $pageToken = static fn(string $html): string => preg_match('/<body[^>]* data-csrf="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
    $formToken = static fn(string $html): string => preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
    // Development settings print PHP warnings into the page, so a clean page means a clean run.
    $clean = static fn(string $body): bool => preg_match('/<b>(Warning|Notice|Deprecated|Fatal error)<\/b>/', $body) !== 1;
    $signIn = static fn(array $fields): string => http_build_query($fields + ['email' => 'u101@fixture.invalid', 'password' => $password, 'next' => '/']);

    // A signed-in session that holds no token: sending none, or an empty one, is no match.
    $check($http('POST', $api, $tokenless, $json, $raise('urgent'))['status'] === 419, 'a session with no token refuses a JSON post that sends none');
    $check($http('POST', $api, $tokenless, $xcsrf(''), $raise('urgent'))['status'] === 419, 'a session with no token refuses an empty X-CSRF header');
    $check($http('POST', $api, $tokenless, [], $field('urgent', ''))['status'] === 419, 'a session with no token refuses an empty csrf form field');
    $check($priority() === 'low', 'none of those posts changed the ticket');
    $r = $http('GET', '/', $tokenless);
    $late = $pageToken($r['body']);
    $check($r['status'] === 200 && $late !== '', 'that session gets a token as soon as it opens a page');
    $check($http('POST', $api, $tokenless, $xcsrf($late), $raise('high'))['status'] === 200 && $priority() === 'high', 'and then posts with it');

    // A session with a token: only that token works, as a header (app.js) or a form field (forms, westy.js).
    $reset();
    $check($http('POST', $api, $tokened, $xcsrf(str_repeat('0', 64)), $raise('urgent'))['status'] === 419, 'a wrong token is refused');
    $check($http('POST', $api, $tokened, $xcsrf(''), $raise('urgent'))['status'] === 419, 'an empty token is refused');
    $r = $http('POST', $api, $tokened, [], $field('urgent', [$token]));
    $check($r['status'] === 419 && $clean($r['body']), 'a token sent as a list is refused cleanly');
    $check($priority() === 'low', 'none of those posts changed the ticket');
    $check($http('POST', $api, $tokened, $xcsrf($token), $raise('high'))['status'] === 200 && $priority() === 'high', 'the session\'s token works as an X-CSRF header');
    $check($http('POST', $api, $tokened, [], $field('normal', $token))['status'] === 200 && $priority() === 'normal', 'the session\'s token works as a csrf form field');

    // 8 West ID alone: a request that carries only the suite cookie opens its session inside that request.
    $reset();
    $check($http('POST', $api, $suite, $json, $raise('urgent'))['status'] === 419 && $priority() === 'low',
        'a post carrying only the 8 West ID cookie and no token is refused');
    $r = $http('GET', '/', $suite);
    $opened = ['PHPSESSID' => $r['session']];
    $sso = $pageToken($r['body']);
    $check($r['status'] === 200 && $r['session'] !== '' && $sso !== '', 'a page opened through 8 West ID issues a token with its new session');
    $check($http('POST', $api, $suite + $opened, $xcsrf($sso), $raise('high'))['status'] === 200 && $priority() === 'high', 'that session posts with that token');

    // 8 West ID sign-in starts a fresh token: the one the sign-in page issued stops working.
    $r = $http('GET', '/login.php');
    $visitor = ['PHPSESSID' => $r['session']];
    $early = $formToken($r['body']);
    $check($r['status'] === 200 && $r['session'] !== '' && $early !== '', 'the sign-in page issues a token to a new visitor');
    $r = $http('GET', '/', $suite + $visitor);
    $signed = ['PHPSESSID' => $r['session']];
    $fresh = $pageToken($r['body']);
    $check($r['status'] === 200 && $r['session'] !== '' && $fresh !== '' && $fresh !== $early, '8 West ID sign-in replaces that token');
    $check($http('POST', $api, $suite + $signed, $xcsrf($early), $raise('urgent'))['status'] === 419 && $priority() === 'high',
        'the token from before 8 West ID sign-in is refused');
    $check($http('POST', $api, $suite + $signed, $xcsrf($fresh), $raise('normal'))['status'] === 200 && $priority() === 'normal', 'the new token works');

    // A forged password sign-in, from a first-time visitor whose session holds no token, signs nobody in.
    $r = $http('POST', '/login.php', [], [], $signIn([]));
    $check($r['status'] === 419, 'a sign-in post from a visitor with no token is refused');
    $check($r['session'] === '' || $http('GET', '/', ['PHPSESSID' => $r['session']])['status'] === 302, 'and leaves nobody signed in');

    // A real one works, and starts a fresh token as well.
    $r = $http('GET', '/login.php');
    $visitor = ['PHPSESSID' => $r['session']];
    $early = $formToken($r['body']);
    $r = $http('POST', '/login.php', $visitor, [], $signIn(['csrf' => $early]));
    $signed = ['PHPSESSID' => $r['session']];
    $check($r['status'] === 302 && $r['location'] === '/' && $r['session'] !== '', 'the sign-in form with its token signs in');
    $r = $http('GET', '/', $signed);
    $fresh = $pageToken($r['body']);
    $check($r['status'] === 200 && $fresh !== '' && $fresh !== $early, 'password sign-in replaces the sign-in page\'s token');
    $check($http('POST', $api, $signed, $xcsrf($early), $raise('urgent'))['status'] === 419 && $priority() === 'normal',
        'the token from before password sign-in is refused');
    $check($http('POST', $api, $signed, $xcsrf($fresh), $raise('low'))['status'] === 200 && $priority() === 'low', 'the new token works');
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
    fwrite(STDERR, 'FAIL: ' . count($failures) . " of $checks CSRF checks\n" . implode("\n", $failures) . "\n");
    exit(1);
}
echo "PASS: $checks CSRF HTTP checks\n";
