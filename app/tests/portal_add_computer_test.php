<?php
/**
 * Your devices: adding a computer as three steps, and a list that puts each computer's state first.
 *
 * Synthetic data only. No database, no settings file, no sign-in, no device service.
 * Run:  php app/tests/portal_add_computer_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
ob_start();
$settings = ['portal_devices' => ['diagnostics_enabled' => true, 'security_orders_enabled' => true]];
function cfg(string $key, mixed $default = null): mixed
{
    global $settings;
    return $settings[$key] ?? $default;
}
function portal_csrf_token(): string { return str_repeat('c', 64); }
function portal_action_nonce(string $purpose, ?int $now = null): string { return hash('sha256', $purpose); }
require_once __DIR__ . '/../lib/portal_data.php';
require_once __DIR__ . '/../lib/portal_devices_render.php';

$checks = 0;
$failures = 0;
function add_check(string $name, bool $ok): void
{
    global $checks, $failures;
    $checks++;
    if (! $ok) $failures++;
    echo ($ok ? 'ok ' : 'FAIL ') . $checks . ' - ' . $name . "\n";
}

$_SERVER['REQUEST_URI'] = '/portal/devices.php';
$owner = ['identity' => ['display_name' => 'Priya Anand', 'role' => 'client_admin'], 'binding' => ['client_name' => 'Harbor Dental']];
$viewer = $owner;
$viewer['identity']['role'] = 'client_viewer';
$delegated = $owner;
$delegated['identity']['customer_access'] = ['role' => 'client_admin'];
$device = static fn(string $ref, string $label, string $connection, string $state, string $help): array => [
    'reference' => $ref, 'label' => $label, 'platform' => 'Windows 11', 'connection' => $connection,
    'connection_label' => $state, 'connection_help' => $help, 'last_seen_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 240),
    'troubleshooting' => 'support_request',
];
$front = $device('1:' . str_repeat('a', 64), 'Front desk computer', 'reporting', 'Connected and reporting', 'Fresh check-in and inventory received.')
    + ['hardware' => ['ram_gb' => 15.8, 'observed_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]];
$warehouse = $device('2:' . str_repeat('b', 64), 'Warehouse laptop', 'stale', 'Not checking in', 'Keep this computer online so support can help.');
$devices = ['items' => [$front, $warehouse], 'next_after' => 2];
$none = ['items' => [], 'next_after' => null];
$link = ['reference' => str_repeat('e', 32), 'state' => 'ready', 'created_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 600)];
$download = ['download_url' => 'https://support.example.test/download.php?t=' . str_repeat('f', 48), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400)];
$page = static function (array $context, ?array $devices, array $links = [], ?array $download = null, ?string $notice = null): string {
    ob_start();
    portal_render_devices($context, $devices, $links, null, $download, $notice);
    $html = (string)ob_get_clean();
    return (string)substr($html, (int)strpos($html, '<main'), (int)strpos($html, '</main>') - (int)strpos($html, '<main'));
};
$panel = static function (string $main): string {
    $start = (int)strpos($main, '<section class="portal-device-setup portal-add-computer"');
    return (string)substr($main, $start, (int)strpos($main, '</section>', $start) - $start);
};
$stepStates = static function (string $panel): array {
    preg_match_all('/<li class="setup-step is-(done|current|later)">/', $panel, $found);
    return $found[1];
};

/* ---- A customer who already has computers ------------------------------------------------------- */
$main = $page($owner, $devices, [$link]);
$add = $panel($main);
add_check('adding a computer is three numbered steps, and the first is the current one',
    $stepStates($add) === ['current', 'later', 'later']
    && (bool)preg_match('~<strong>Confirm</strong>.*<strong>Install</strong>.*<strong>Wait for it to appear</strong>~s', $add));
add_check('step 1 is the same consent form as before: same action, same fields, same button',
    str_contains($add, '<form method="post" action="/portal/devices.php">')
    && str_contains($add, 'name="action" value="enrollment_create"') && (bool)preg_match('/name="request_key" value="[a-f0-9]{32}"/', $add)
    && str_contains($add, '<input type="checkbox" name="consent" value="yes" required>')
    && str_contains($add, 'I am authorized to add this computer to Harbor Dental and allow the support team to manage it.')
    && str_contains($add, '<button type="submit" class="btn-primary">Create Windows setup link</button>'));
add_check('nothing about installing is offered before the customer confirms',
    ! str_contains($main, 'Install on your Windows computer') && ! str_contains($main, 'download.php')
    && ! str_contains($main, 'Copy link') && ! str_contains($main, 'portal-devices.js'));
add_check('the page has one filled button, and the antivirus forms use secondary buttons',
    substr_count($main, 'btn-primary') === 1 && str_contains($main, '<button type="submit" class="btn-ghost">Send protection request</button>'));
add_check('with computers already listed, the panel sits beside the list and a jump link reaches it on a narrow screen',
    strpos($main, 'id="computers-heading"') < strpos($main, 'id="add-computer"')
    && str_contains($main, '<a class="btn-ghost portal-add-jump" href="#add-computer">Add a computer</a>'));
add_check('the words the approved Guide names are still on the page',
    str_contains($main, '<h1>Your devices</h1>') && str_contains($main, '>Connected computers</h2>') && str_contains($main, 'Refresh status')
    && str_contains($main, 'Last check-in: ') && str_contains($main, 'Next computers') && substr_count($main, 'Computer access') === 2);
add_check('each computer leads with its state as a tag, coloured by what it means',
    (bool)preg_match('~<h3>Front desk computer</h3>\s*<p class="portal-device-status"><span class="portal-device-state is-online">Connected and reporting</span>~', $main)
    && str_contains($main, '<span class="portal-device-state is-attention">Not checking in</span>'));
add_check('a help sentence appears only for a computer that needs something',
    str_contains($main, 'Keep this computer online so support can help.') && ! str_contains($main, 'Fresh check-in and inventory received.'));
add_check('the memory fact stays on the card as a dated inventory fact',
    str_contains($main, 'Memory: 15.8 GB. Inventory recorded '));
add_check('the customer reads everyday words, not vendor or system words',
    ! preg_match('/Bitdefender|enroll|\bagent\b|signed Milepost|Milepost|READY TO INSTALL|YOUR COMPUTERS/i', strip_tags($main))
    && str_contains($main, 'Adding a computer does not order antivirus or create a charge.'));
add_check('setup links are listed in plain words with the same two actions',
    str_contains($main, '>Setup links you created</h2>') && str_contains($main, '<strong>Ready to use</strong>')
    && str_contains($main, 'value="enrollment_download" class="btn-link">Get setup link</button>')
    && str_contains($main, 'value="enrollment_revoke" class="btn-link">Revoke</button>'));
add_check('the two antivirus requests are one later section, unchanged inside and still side by side in the markup',
    strpos($main, '>Antivirus protection</h2>') > strpos($main, 'id="add-computer"')
    && (bool)preg_match('~<section class="portal-device-setup" aria-labelledby="protection-heading">.*?</section>\s*<section class="portal-device-setup" aria-labelledby="employee-protection-heading">.*?</section>~s', $main)
    && str_contains($main, 'name="action" value="protection_request"') && str_contains($main, 'name="action" value="employee_choices"')
    && str_contains($main, 'Choose “the same computer” if this computer was replaced or set up again.'));

/* ---- A new customer with no computer yet --------------------------------------------------------- */
$first = $page($owner, $none);
add_check('with no computer yet, adding one leads the page and no jump link is needed',
    strpos($first, 'id="add-computer"') < strpos($first, 'id="computers-heading"') && ! str_contains($first, 'portal-add-jump')
    && str_contains($first, 'No computers on this page yet') && str_contains($first, 'with the three steps above'));

/* ---- A setup file is waiting to be used ---------------------------------------------------------- */
$adding = $page($owner, $devices, [$link], $download);
$add = $panel($adding);
add_check('after confirming, step 1 is done, step 2 is current and holds the download',
    $stepStates($add) === ['done', 'current', 'later'] && ! str_contains($add, 'name="consent"')
    && str_contains($add, '<h3 id="install-title">Install on your Windows computer</h3>')
    && str_contains($add, '<a class="btn-primary" href="' . $download['download_url'] . '" rel="noreferrer">Download Milepost setup</a>'));
add_check('the link can be copied for a different computer, and says how long it works',
    str_contains($add, '<button type="button" class="btn-ghost" data-copy-text="' . $download['download_url'] . '" data-copy-status="portal-copy-status">Copy link</button>')
    && str_contains($add, 'id="portal-copy-status" role="status"') && str_contains($add, 'This private link adds one computer and stops working '));
add_check('step 3 waits out loud: a status line that the page script updates, with its two endings ready',
    (bool)preg_match('~<p id="portal-device-wait" role="status" data-device-wait data-found-text="[^"]+" data-stopped-text="[^"]+">~', $add)
    && str_contains($adding, '<div id="portal-device-list">'));
add_check('the waiting script is loaded only in this state, from a file, with a content-hash version',
    (bool)preg_match('~<script src="/assets/js/portal-devices\.js\?v=[a-f0-9]{20}" defer></script>~', $adding));
add_check('while a setup file is waiting, the panel leads the page and is still the only filled button',
    strpos($adding, 'id="add-computer"') < strpos($adding, 'id="computers-heading"') && substr_count($adding, 'btn-primary') === 1);

/* ---- People who may not add computers ------------------------------------------------------------ */
$read = $page($viewer, $devices, [$link]);
add_check('a viewer sees the list and is told who can add computers, with no form, link list or per-computer management',
    str_contains($read, 'Owners and admins can add computers.') && ! str_contains($read, '<form')
    && ! str_contains($read, 'Create Windows setup link') && ! str_contains($read, 'Setup links you created')
    && ! str_contains($read, 'Computer access') && ! str_contains($read, '>Antivirus protection</h2>') && ! str_contains($read, 'portal-add-jump'));
$other = $page($delegated, $devices, [$link]);
add_check('delegated access can add a computer but still gets no antivirus request forms',
    str_contains($other, 'Create Windows setup link') && ! str_contains($other, '>Antivirus protection</h2>') && ! str_contains($other, 'Protection request'));
$down = $page($owner, null);
add_check('when the device service cannot be read, the page says so and does not pretend there are no computers',
    str_contains($down, 'We cannot retrieve your computers right now.') && ! str_contains($down, 'No computers on this page yet')
    && strpos($down, 'id="computers-heading"') < strpos($down, 'id="add-computer"'));

/* ---- Safety ------------------------------------------------------------------------------------- */
$hostile = $page($owner, ['items' => [$device('3:' . str_repeat('c', 64), '<script>alert(1)</script>', 'waiting', '<b>state</b>', '<i>help</i>')], 'next_after' => null]);
add_check('names, states and help text from the device service are escaped',
    ! str_contains($hostile, '<script>alert(1)</script>') && ! str_contains($hostile, '<b>state</b>') && ! str_contains($hostile, '<i>help</i>')
    && str_contains($hostile, '&lt;script&gt;alert(1)&lt;/script&gt;'));
add_check('no inline style, inline script or inline handler anywhere on the page, as its Content Security Policy requires',
    ! preg_match('/\sstyle\s*=|<style|<script(?![^>]*\ssrc=)|\son[a-z]+\s*=/i', $main . $adding . $first . $read));

ob_end_flush();
echo "Portal add a computer: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
