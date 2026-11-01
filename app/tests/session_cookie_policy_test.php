<?php
/**
 * Which requests count as a POST from another site, from what a browser (or a caller that isn't one) really
 * sends. cross_site_session_http_test.php and tools/shots/cross-site-session.test.mjs cover what follows from it.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/session_cookie_policy.php';
require_once __DIR__ . '/../lib/portal_auth.php';

$here = 'safeharbor.test';
$cases = [
    // Browsers with Fetch Metadata say where the request came from. Origin doesn't get a say.
    'a form on another site' => [true, 'POST', $here, 'cross-site', 'https://forged.example'],
    'an https page posting to http, whose Origin is null' => [true, 'POST', $here, 'cross-site', 'null'],
    'a chain that left this site, whose Origin may be this host' => [true, 'POST', $here, 'cross-site', 'https://safeharbor.test'],
    'cross-site with no Origin' => [true, 'POST', $here, 'cross-site', null],
    'our own page' => [false, 'POST', $here, 'same-origin', 'https://safeharbor.test'],
    'a sibling app, which carries the Lax cookie' => [false, 'POST', $here, 'same-site', 'https://id.suite.test'],
    'the person, not a page' => [false, 'POST', $here, 'none', null],

    // No Fetch Metadata (Safari before 16.4, plain-http pages): Origin decides.
    'Origin from another site' => [true, 'POST', $here, null, 'https://forged.example'],
    'Origin null' => [true, 'POST', $here, null, 'null'],
    'Origin that only ends with this host' => [true, 'POST', $here, null, 'https://forged.example.safeharbor.test'],
    'Origin that only starts with this host' => [true, 'POST', $here, null, 'https://safeharbor.test.forged.example'],
    'Origin on another port of this host' => [true, 'POST', $here, null, 'https://safeharbor.test:8443'],
    'Origin with a scheme that is not a web page' => [true, 'POST', $here, null, 'chrome-extension://safeharbor.test'],
    'Origin from a sibling app, which can\'t be told from another site' => [true, 'POST', $here, null, 'https://id.suite.test'],
    'Origin and no Host to compare it with' => [true, 'POST', null, null, 'https://safeharbor.test'],
    'Origin of this host' => [false, 'POST', $here, null, 'https://safeharbor.test'],
    'Origin of this host over plain http' => [false, 'POST', $here, null, 'http://safeharbor.test'],
    'Origin of this host and its port' => [false, 'POST', 'safeharbor.test:8443', null, 'https://safeharbor.test:8443'],
    'Origin of this host in another case' => [false, 'POST', 'SafeHarbor.test', null, 'https://safeharbor.test'],

    // Neither header: not a browser. api/svc callers and curl stay as they were.
    'neither header' => [false, 'POST', $here, null, null],
    'an empty Origin' => [false, 'POST', $here, null, ''],

    // Only a POST. A link from another site arrives as a GET, carries the Lax cookie and may be a first visit.
    'a link on another site' => [false, 'GET', $here, 'cross-site', null],
    'a HEAD from another site' => [false, 'HEAD', $here, 'cross-site', null],
    'a GET whose Origin is another site' => [false, 'GET', $here, null, 'https://forged.example'],
    'the command line' => [false, null, null, null, null],
];

$failures = [];
foreach ($cases as $name => [$want, $method, $host, $fetchSite, $origin]) {
    $server = array_filter(['REQUEST_METHOD' => $method, 'HTTP_HOST' => $host, 'HTTP_SEC_FETCH_SITE' => $fetchSite, 'HTTP_ORIGIN' => $origin],
        static fn(?string $value): bool => $value !== null);
    if (session_request_is_cross_site_post($server) !== $want) $failures[] = $name . ': expected ' . ($want ? 'cross-site' : 'not cross-site');
}

// Withholding lasts the whole request, and the portal's session can start again after being destroyed in it.
$_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => $here, 'HTTP_SEC_FETCH_SITE' => 'cross-site'];
$_COOKIE = [];
session_save_path(sys_get_temp_dir());
set_error_handler(static function (int $level, string $message) use (&$failures): bool {
    $failures[] = "starting the portal's session raised: $message";
    return true;
});
portal_session_start();
portal_destroy_session();
portal_session_start();
if (ini_get('session.use_cookies') !== '0') $failures[] = 'a cross-site post without its cookie should leave session cookies off';
session_destroy();
restore_error_handler();

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' of ' . count($cases) . " cases\n" . implode("\n", $failures) . "\n");
    exit(1);
}
echo 'session_cookie_policy_test: ok (' . count($cases) . " cases)\n";
