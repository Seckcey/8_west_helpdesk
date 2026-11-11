<?php
/**
 * Computer access: giving a colleague one computer as two numbered steps, and the colleague's side.
 *
 * The page is its controller, so the real controller runs here in a throwaway folder beside stand-in
 * libraries: no database, no settings file, no sign-in, no device service. The real portal templates
 * render the page. Synthetic data only.
 *
 * Run:  php app/tests/portal_computer_access_test.php
 * With PORTAL_ACCESS_PREVIEW_DIR set to a folder, the rendered pages are also saved there.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__, 2);
$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'safeharbor-computer-access-' . bin2hex(random_bytes(6));
foreach (['/app/lib', '/app/public/portal', '/app/public/assets/js'] as $folder) mkdir($sandbox . $folder, 0700, true);
copy($root . '/app/public/portal/computer-access.php', $sandbox . '/app/public/portal/computer-access.php');
foreach (['portal-computer-access.js', 'portal-devices.js'] as $script) copy($root . '/app/public/assets/js/' . $script, $sandbox . '/app/public/assets/js/' . $script);
file_put_contents($sandbox . '/app/lib/portal_auth.php', '<?php');
file_put_contents($sandbox . '/app/lib/portal_devices.php', '<?php');
file_put_contents($sandbox . '/app/lib/portal_render.php', '<?php require ' . var_export($root . '/app/lib/portal_render.php', true) . ';');
file_put_contents($sandbox . '/app/lib/bootstrap.php', <<<'PHP'
<?php
$input = json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR);
$_SERVER['REQUEST_METHOD'] = $input['method'];
$_SERVER['REQUEST_URI'] = $input['uri'];
parse_str(parse_url($input['uri'], PHP_URL_QUERY) ?? '', $_GET);
$_POST = $input['form'];
$calls = [];
register_shutdown_function(static function () use (&$calls): void { fwrite(STDERR, json_encode($calls, JSON_THROW_ON_ERROR)); });
function cfg(string $key, mixed $default = null): mixed { return $default; }
function enforce_https(): void {}
function db(): mixed { return null; }
function portal_enabled(): bool { return true; }
function portal_safe_return_path(mixed $value): string { return '/portal/'; }
function portal_csrf_token(): string { return str_repeat('c', 64); }
function portal_csrf_valid(mixed $value): bool { return $value === portal_csrf_token(); }
function portal_authenticated_context(mixed $pdo): ?array {
    global $input;
    $identity = ['role' => $input['role'], 'display_name' => $input['name']];
    if ($input['delegated']) { $identity['role'] = 'admin'; $identity['customer_access'] = ['role' => $input['role']]; }
    return ['identity' => $identity, 'binding' => ['client_name' => 'Harbor Dental']];
}
// The same rule as the real library: client owners and admins, by the role of the selected client.
function portal_devices_can_manage(array $context): bool {
    return in_array($context['identity']['customer_access']['role'] ?? $context['identity']['role'], ['client_owner', 'client_admin'], true);
}
class PortalDevicesException extends RuntimeException {
    public function __construct(public readonly string $reason, int $status = 503) { parent::__construct($reason, $status); }
}
function portal_devices_error(string $reason): string { return 'Stand-in refusal: ' . $reason; }
function portal_devices_request(mixed $pdo, array $context, string $action, array $values): array {
    global $calls, $input;
    $calls[] = ['action' => $action, 'values' => $values];
    return match ($action) {
        'device_access_create' => ['token' => $input['token']],
        'device_access_redeem' => ['state' => 'active'],
        'device_access_revoke' => ['state' => 'revoked'],
        'device_access_list' => ['items' => $input['access'], 'next_after' => $input['next_after']],
    };
}
PHP);

/** Run the controller once. @return array{html:string,main:string,calls:list<array<string,mixed>>} */
$open = static function (array $input) use ($sandbox): array {
    $input += ['role' => 'client_admin', 'name' => 'Priya Anand', 'delegated' => false, 'method' => 'GET', 'uri' => '/portal/computer-access.php',
        'form' => [], 'access' => [], 'next_after' => null, 'token' => str_repeat('a', 64)];
    $process = proc_open([PHP_BINARY, $sandbox . '/app/public/portal/computer-access.php', json_encode($input, JSON_THROW_ON_ERROR)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $html = (string)stream_get_contents($pipes[1]);
    $calls = (string)stream_get_contents($pipes[2]);
    proc_close($process);
    $start = (int)strpos($html, '<main');
    return ['html' => $html, 'main' => (string)substr($html, $start, (int)strpos($html, '</main>') - $start),
        'calls' => json_decode($calls === '' ? '[]' : $calls, true) ?? []];
};

$checks = 0;
$failures = 0;
function access_check(string $name, bool $ok): void
{
    global $checks, $failures;
    $checks++;
    if (! $ok) $failures++;
    echo ($ok ? 'ok ' : 'FAIL ') . $checks . ' - ' . $name . "\n";
}

$device = '1:' . str_repeat('d', 64);
$page = '/portal/computer-access.php?device=' . rawurlencode($device);
$csrf = str_repeat('c', 64);
$people = [
    ['reference' => str_repeat('1', 32), 'employee' => 'Dana Whitfield', 'state' => 'active'],
    ['reference' => str_repeat('2', 32), 'employee' => null, 'state' => 'pending'],
    ['reference' => str_repeat('3', 32), 'employee' => 'Sam Rivera', 'state' => 'revoked'],
    ['reference' => str_repeat('4', 32), 'employee' => null, 'state' => 'expired'],
];
$invite = '<a class="btn-ghost portal-access-invite" href="https://id.8westit.com/users.php#employee-invitations">Invite a member in 8 West ID</a>';

/* ---- A client admin giving a colleague this computer ---------------------------------------------- */
$admin = $open(['uri' => $page, 'access' => $people]);
$main = $admin['main'];
preg_match_all('/<li class="setup-step [^"]*">.*?<strong>([^<]+)<\/strong>/s', $main, $steps);
access_check('giving a member access is two numbered steps, in order: a sign-in, then this computer',
    $steps[1] === ['The person needs their own sign-in', 'Give them this computer']
    && (bool)preg_match('~<span class="setup-mark" aria-hidden="true">1</span>.*<span class="setup-mark" aria-hidden="true">2</span>~s', $main));
access_check('step 1 sends an actual owner or admin to the 8 West ID invitation, and says it can be skipped',
    substr_count($main, $invite) === 1 && str_contains($main, 'Skip this if they can already sign in here.'));
access_check('step 2 is the same form as before: same action, same fields, same button',
    str_contains($main, '<input type="hidden" name="action" value="device_access_create">')
    && str_contains($main, '<input type="hidden" name="device_reference" value="' . $device . '">')
    && (bool)preg_match('/name="request_key" value="[a-f0-9]{32}"/', $main)
    && str_contains($main, '<button class="btn-primary" type="submit">Create member access link</button>'));
access_check('before a link exists the page says what the link is, and offers nothing to copy',
    str_contains($main, 'You get a private link to send to that person. It works once and stops working in 48 hours. No email is sent.')
    && ! str_contains($main, 'computer-access-link') && ! str_contains($main, 'data-copy-text') && ! str_contains($admin['html'], 'portal-devices.js'));
access_check('one filled button on the page', substr_count($main, 'btn-primary') === 1);
access_check('opening the page only reads the access list for that computer',
    $admin['calls'] === [['action' => 'device_access_list', 'values' => ['device_reference' => $device, 'after' => 0]]]);
access_check('the words the approved Guide names are on the page',
    str_contains($main, '>Give a member access</h2>') && str_contains($main, '>People and access links</h2>')
    && str_contains($main, 'Create member access link') && str_contains($main, 'Waiting for a member') && str_contains($main, 'Has access')
    && str_contains($main, '>Revoke access</button>'));
access_check('each person or link has a tag whose colour says its state',
    str_contains($main, '<strong>Dana Whitfield</strong><span class="portal-device-state is-online">Has access</span>')
    && str_contains($main, '<strong>Unclaimed access link</strong><span class="portal-device-state is-attention">Waiting for a member</span>')
    && str_contains($main, '<span class="portal-device-state">Revoked</span>') && str_contains($main, '<span class="portal-device-state">Link expired</span>'));
access_check('only access that is still open can be revoked, each by its own reference',
    substr_count($main, 'value="device_access_revoke"') === 2
    && str_contains($main, 'name="reference" value="' . str_repeat('1', 32) . '"') && str_contains($main, 'name="reference" value="' . str_repeat('2', 32) . '"')
    && ! str_contains($main, 'name="reference" value="' . str_repeat('3', 32) . '"'));
$hostile = $open(['uri' => $page, 'access' => [['reference' => str_repeat('5', 32), 'employee' => 'Sam <b>Rivera</b>', 'state' => 'active']]])['main'];
access_check('a name from the device service is escaped', str_contains($hostile, 'Sam &lt;b&gt;Rivera&lt;/b&gt;') && ! str_contains($hostile, '<b>Rivera</b>'));

/* ---- The link, once created ------------------------------------------------------------------------ */
$created = $open(['uri' => '/portal/computer-access.php', 'method' => 'POST', 'access' => $people,
    'form' => ['csrf' => $csrf, 'action' => 'device_access_create', 'device_reference' => $device, 'request_key' => str_repeat('e', 32)]]);
$link = 'https://safeharbor.8westit.com/portal/computer-access.php#token=' . str_repeat('a', 64);
access_check('creating a link asks the service once, with the computer and the request key, then reads the list',
    array_column($created['calls'], 'action') === ['device_access_create', 'device_access_list']
    && $created['calls'][0]['values'] === ['device_reference' => $device, 'request_key' => str_repeat('e', 32)]);
access_check('the link is shown under its Guide label, with its code after #token= and never in a query',
    str_contains($created['main'], '<label for="computer-access-link">Private access link</label><input id="computer-access-link" type="text" readonly autocomplete="off" value="' . $link . '">')
    && ! str_contains($created['html'], '?token='));
access_check('a Copy button carries the same link, and the page says who to send it to and how long it works',
    str_contains($created['main'], 'data-copy-text="' . $link . '" data-copy-status="computer-access-copy-status" data-copied-text="Link copied. Send it to that person only.">Copy</button>')
    && str_contains($created['main'], 'Send this link to that person only. The first member of Harbor Dental who opens it while signed in gets this computer. It works once and stops working in 48 hours. No email is sent.'));
access_check('step 2 is marked done, and creating another link is a quiet button, so nothing filled competes with Copy',
    (bool)preg_match('~<li class="setup-step is-done">.*<strong>Give them this computer</strong><span class="sr-only"> \(done\)</span>~s', $created['main'])
    && str_contains($created['main'], '<button class="btn-ghost" type="submit">Create member access link</button>') && ! str_contains($created['main'], 'btn-primary'));
access_check('the copy script is loaded from a file, only when there is a link to copy',
    (bool)preg_match('~<script src="/assets/js/portal-devices\.js\?v=[a-f0-9]{20}" defer></script>~', $created['html']));
$again = $open(['uri' => '/portal/computer-access.php', 'method' => 'POST', 'token' => null,
    'form' => ['csrf' => $csrf, 'action' => 'device_access_create', 'device_reference' => $device, 'request_key' => str_repeat('e', 32)]]);
access_check('a repeated request shows no link and says the first one was not replaced',
    ! str_contains($again['main'], 'computer-access-link') && str_contains($again['main'], 'This link was already created.'));

/* ---- Someone managing through a customer profile: can give a computer, cannot invite ------------- */
$delegated = $open(['uri' => $page, 'delegated' => true]);
access_check('delegated access gets both steps but no 8 West ID invitation, and is told who can add people',
    str_contains($delegated['main'], 'Create member access link') && ! str_contains($delegated['main'], 'id.8westit.com')
    && str_contains($delegated['main'], 'Your support provider can add people for you.'));
access_check('with no access yet the list says so', str_contains($delegated['main'], 'No member access has been assigned to this computer yet.'));

/* ---- A member: cannot invite or give access; this page is where they accept a computer ------------ */
$member = $open(['role' => 'client_staff', 'name' => 'Dana Whitfield', 'uri' => $page]);
access_check('a member gets no invitation, no link form and no access list, and the service is not asked for one',
    ! str_contains($member['main'], 'Invite a member') && ! str_contains($member['main'], 'device_access_create')
    && ! str_contains($member['main'], 'People and access links') && $member['calls'] === []);
access_check('a member sees the Guide\'s own words for accepting a computer, with the code field and its help',
    str_contains($member['main'], '>Use your assigned computer</h2>') && str_contains($member['main'], '<label for="computer-access-code">Private access code</label>')
    && str_contains($member['main'], 'Copy the code after #token= from your original private link and paste it here.')
    && str_contains($member['main'], '<button class="btn-primary" type="submit">Use this computer</button>'));
$refused = $open(['role' => 'client_staff', 'method' => 'POST', 'form' => ['csrf' => $csrf, 'action' => 'device_access_create', 'device_reference' => $device, 'request_key' => str_repeat('e', 32)]]);
$refusedRevoke = $open(['role' => 'client_staff', 'method' => 'POST', 'form' => ['csrf' => $csrf, 'action' => 'device_access_revoke', 'reference' => str_repeat('1', 32)]]);
access_check('a member who posts the owner\'s actions anyway is refused before the service is asked',
    $refused['calls'] === [] && $refusedRevoke['calls'] === [] && str_contains($refused['main'], 'Stand-in refusal: role') && ! str_contains($refused['html'], 'computer-access.php#token='));
$viewer = $open(['role' => 'client_viewer', 'uri' => $page]);
access_check('a viewer gets the same accepting page and nothing to manage',
    str_contains($viewer['main'], 'Use this computer') && ! str_contains($viewer['main'], 'Create member access link') && $viewer['calls'] === []);

/* ---- Safety ----------------------------------------------------------------------------------------- */
access_check('no inline style, inline script or inline handler on any of these pages',
    ! preg_match('/\sstyle\s*=|<style|<script(?![^>]*\ssrc=)|\son[a-z]+\s*=/i', $admin['html'] . $created['html'] . $member['html'] . $delegated['html']));

$previews = getenv('PORTAL_ACCESS_PREVIEW_DIR');
if (is_string($previews) && is_dir($previews)) {
    foreach (['admin' => $admin, 'admin-link' => $created, 'member' => $member, 'delegated' => $delegated] as $name => $result) {
        file_put_contents($previews . '/computer-access-' . $name . '.html', $result['html']);
    }
}

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($sandbox);
echo "Portal computer access: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
