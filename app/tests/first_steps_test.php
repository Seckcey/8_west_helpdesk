<?php
/**
 * First steps: the "Get set up" card on an empty staff queue and on a new customer's portal Home, and the
 * staff sign-in page's empty email field. Synthetic records only; no database, configuration or network.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
ob_start();
function portal_csrf_token(): string { return str_repeat('c', 64); }
function portal_action_nonce(string $purpose, ?int $now = null): string { return hash('sha256', $purpose); }
function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
require_once __DIR__ . '/../lib/portal_render.php';
require_once __DIR__ . '/../lib/first_steps.php';

$checks = 0;
$failures = [];
function step_check(bool $ok, string $name): void
{
    global $checks, $failures;
    $checks++;
    echo ($ok ? 'ok ' : 'not ok ') . $checks . ' - ' . $name . "\n";
    if (!$ok) $failures[] = $name;
}

/* ── Staff queue ───────────────────────────────────────────────────────────────────────────── */
$owner = ['role' => 'owner'];
$admin = ['role' => 'admin'];
$tech = ['role' => 'tech'];
$card = staff_first_steps_card($owner, 0, 0, true);
step_check(str_contains($card, '>Get set up</h2>') && str_contains($card, 'Add your first client')
    && str_contains($card, 'Invite your team') && str_contains($card, 'File a test ticket'), 'an owner with no tickets sees the three steps');
step_check(str_contains($card, '<a class="btn-primary" href="/client_new.php">Add a client</a>'), 'with no client yet, Add a client is the current step');
step_check(str_contains($card, 'href="https://id.8westit.com/users.php">Invite a teammate</a>'), 'the invite step uses the Team page link when 8 West ID allows it');
step_check(str_contains($card, 'href="/ticket_new.php">New ticket</a>'), 'the test ticket step opens a new ticket');
step_check(staff_first_steps_card($admin, 0, 0, false) !== '', 'an administrator with no tickets sees the card too');
step_check(staff_first_steps_card($owner, 1, 0, true) === '', 'the card is hidden once the tenant has one ticket');
step_check(staff_first_steps_card($tech, 0, 0, true) === '', 'a technician never sees the card');

$withClient = staff_first_steps_card($owner, 0, 2, true);
step_check(str_contains($withClient, 'setup-step is-done') && !str_contains($withClient, 'href="/client_new.php"'),
    'step 1 is done, with no button, once a client exists');
step_check(str_contains($withClient, '<a class="btn-primary" href="https://id.8westit.com/users.php">Invite a teammate</a>'),
    'with a client, inviting the team becomes the current step');
$noInvite = staff_first_steps_card($owner, 0, 2, false);
step_check(str_contains($noInvite, 'href="/users.php">Open Team</a>') && !str_contains($noInvite, 'id.8westit.com'),
    'without the 8 West ID people capability, the step opens Team instead');
step_check(substr_count($withClient, 'setup-step is-done') === 1, 'only the client step can be marked done');

step_check(staff_invite_allowed($owner, ['suite_settings_context' => ['capabilities' => ['manage_people' => true], 'panels' => ['msp_admin']]]),
    'the invite condition holds for an owner with people management in MSP Admin');
step_check(!staff_invite_allowed($tech, ['suite_settings_context' => ['capabilities' => ['manage_people' => true], 'panels' => ['msp_admin']]])
    && !staff_invite_allowed($owner, ['suite_settings_context' => ['capabilities' => ['manage_people' => true], 'panels' => []]])
    && !staff_invite_allowed($owner, ['suite_settings_context' => ['capabilities' => ['manage_people' => 'yes'], 'panels' => ['msp_admin']]])
    && !staff_invite_allowed($owner, []), 'the invite condition fails for a technician, without the panel, or without a strict capability');

$index = (string)file_get_contents(__DIR__ . '/../public/index.php');
step_check(str_contains($index, "\$counts['all'] === 0 && in_array(\$user['role'], ['owner', 'admin'], true)")
    && str_contains($index, 'tenant_client_count(db(), tenant_id())'), 'the queue computes the card only for owners and administrators with no tickets');

/* ── Portal Home ───────────────────────────────────────────────────────────────────────────── */
$_SERVER['REQUEST_URI'] = '/portal/';
$identity = ['subject' => 't9u51', 'identity_tenant_slug' => 'synthetic-client', 'identity_tenant_id' => '9',
    'role' => 'client_owner', 'session_version' => '1.2', 'tenant_id' => 1, 'client_id' => 11, 'display_name' => 'Avery Lane'];
$context = ['identity' => $identity, 'settings_context' => [], 'binding' => ['client_name' => 'Harbor Dental', 'identity_tenant_slug' => 'synthetic-client']];
$summary = ['client' => ['name' => 'Harbor Dental'], 'counts' => ['open' => 0, 'in_progress' => 0, 'waiting' => 0, 'resolved' => 0], 'tickets' => []];
$none = ['items' => [], 'next_after' => null];
$one = ['items' => [['reference' => '1:' . str_repeat('a', 64), 'label' => 'Front desk computer', 'platform' => 'Windows 11',
    'connection' => 'reporting', 'connection_label' => 'Connected and reporting', 'connection_help' => 'Fresh check-in.',
    'last_seen_at' => '2026-11-01T12:00:00Z', 'troubleshooting' => 'support_request']], 'next_after' => null];
$home = static function (array $c, ?array $devices) use ($summary): string {
    ob_start(); portal_render_home($c, $summary, $devices, []); return (string)ob_get_clean();
};
$invite = 'href="https://id.8westit.com/users.php#employee-invitations"';

$html = $home($context, $none);
step_check(str_contains($html, 'id="home-setup-heading">Get set up</h2>'), 'a client owner with no computers sees Get set up');
step_check(strpos($html, 'setup-card') > strpos($html, 'portal-overview-hero') && strpos($html, 'setup-card') < strpos($html, 'home-reply-heading'),
    'the card is the first card after the hero');
step_check(str_contains($html, '<a class="btn-primary" href="/portal/devices.php">Open Your devices</a>'), 'adding a computer is the current step');
step_check(substr_count($html, $invite) === 2 && str_contains($html, 'Each person gets their own sign-in'),
    'the owner gets the invite in the card and in the Manage card');
step_check(str_contains($html, 'No computers are connected for Harbor Dental yet.'), 'the Computers empty state stays as it was');
step_check(!str_contains($html, ' style=') && !preg_match('/<script(?![^>]*\bsrc=)/', $html), 'no inline style or inline script');

step_check(!str_contains($home($context, $one), 'Get set up'), 'the card disappears once a computer exists');
step_check(!str_contains($home($context, null), 'Get set up'), 'the card stays away when the computer list could not be read');

$member = $context; $member['identity']['role'] = 'client_staff';
step_check(!str_contains($home($member, $none), 'Get set up'), 'a member never sees the card');

$delegated = $context; $delegated['identity']['customer_access'] = ['role' => 'client_owner', 'customer_id' => 'synthetic'];
$delegatedHtml = $home($delegated, $none);
step_check(str_contains($delegatedHtml, 'Get set up') && !str_contains($delegatedHtml, $invite)
    && str_contains($delegatedHtml, 'Your support provider can add people for you.'),
    'with customer access, the invite step has no button and says who adds people');

$adminHome = $context; $adminHome['identity']['role'] = 'client_admin';
step_check(substr_count($home($adminHome, $one), $invite) === 1, 'a client administrator with computers keeps the invite in the Manage card');

/* ── Staff sign-in ─────────────────────────────────────────────────────────────────────────── */
$login = (string)file_get_contents(__DIR__ . '/../public/login.php');
step_check(preg_match('/\$email\s*=\s*\'[^\']*@[^\']*\'/', $login) !== 1 && str_contains($login, "\$email = '';"),
    'login.php has no email address default');
step_check(str_contains($login, '<title>Sign in · Safeharbor</title>'), 'the sign-in title is unchanged');
step_check(strpos($login, 'Continue with 8 West ID') < strpos($login, '<form method="post" action="/login.php"'),
    '8 West ID comes before the password form');

ob_end_flush();
echo $failures === [] ? "\nFirst steps: {$checks} passed.\n" : "\nFirst steps: " . count($failures) . " of {$checks} failed.\n";
exit($failures === [] ? 0 : 1);
