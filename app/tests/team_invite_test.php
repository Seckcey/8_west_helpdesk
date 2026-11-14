<?php
/**
 * Team page, invite first: who sees "Invite a teammate", who is told to ask, and who sees the backup
 * local account form. Runs the real users.php against in-memory stubs: no database, mail or accounts.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

$scenario = $argv[1] ?? null;
if ($scenario !== null) {
    // Child run: render one scenario and print the page.
    error_reporting(E_ALL);
    set_error_handler(static function (int $level, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $level, $file, $line);
    });
    $full = ['suite_settings_context' => ['capabilities' => ['manage_people' => true], 'panels' => ['msp_admin']]];
    [$role, $session, $post] = match ($scenario) {
        'owner-capable' => ['owner', $full, []],
        'admin-capable' => ['admin', $full, []],
        'owner-plain' => ['owner', [], []],
        'owner-no-panel' => ['owner', ['suite_settings_context' => ['capabilities' => ['manage_people' => true], 'panels' => ['client_admin']]], []],
        'owner-yes-string' => ['owner', ['suite_settings_context' => ['capabilities' => ['manage_people' => 'yes'], 'panels' => ['msp_admin']]], []],
        'tech' => ['tech', $full, []],
        'owner-failed-add' => ['owner', $full, ['action' => 'add', 'csrf' => 'synthetic-csrf', 'full_name' => 'Rowan "><b>Hale',
            'email' => '"><b>not-an-email', 'password' => 'x', 'role' => 'owner', 'color' => '#F6C95B']],
        default => exit(2),
    };
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $_SESSION = $session;
    $GLOBALS['fixtureUser'] = ['id' => 1, 'tenant_id' => 1, 'full_name' => 'Avery Lane', 'email' => 'avery@example.test', 'role' => $role,
        'initials' => 'AL', 'color' => '#2D8CFF', 'suite_subject' => 't1u1', 'is_active' => 1, 'last_login_at' => null];
    function enforce_https(): void {}
    function require_login(): array { return $GLOBALS['fixtureUser']; }
    function suite_local_password_allowed(array $user): bool { return empty($user['suite_subject']); }
    function tenant_id(): int { return 1; }
    function h(mixed $text): string { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function initials_of(string $name): string { return 'RH'; }
    function avatar(array $user, int $size): string { return '<span class="avatar">' . h($user['initials']) . '</span>'; }
    function rel_time(string $time): string { return '1d'; }
    function ticket_ago(string $utc): string { return $utc === '2026-11-13 09:00:00' ? '3 hours ago' : 'some time ago'; }
    function csrf_field(): string { return '<input type="hidden" name="csrf" value="synthetic-csrf">'; }
    function csrf_check(): void { if (($_POST['csrf'] ?? '') !== 'synthetic-csrf') throw new RuntimeException('csrf'); }
    function palette_data(): array { return []; }
    function page_top(array $user, string $title, string $active): void { echo '<main>'; }
    function page_bottom(array $data): void { echo '</main>'; }
    final class TeamStatement {
        public function execute(array $params): void {}
        public function fetch(): bool { return false; }
        public function fetchAll(): array { return [$GLOBALS['fixtureUser'], ['id' => 2, 'tenant_id' => 1, 'full_name' => 'Jordan Ellis',
            'email' => 'jordan@example.test', 'role' => 'tech', 'initials' => 'JE', 'color' => '#62F6B0', 'suite_subject' => null,
            'is_active' => 1, 'last_login_at' => '2026-11-13 09:00:00'], ['id' => 3, 'tenant_id' => 1, 'full_name' => 'Sam Ortiz',
            'email' => 'sam@example.test', 'role' => 'admin', 'initials' => 'SO', 'color' => '#7DDCFF', 'suite_subject' => 't1u3',
            'is_active' => 0, 'last_login_at' => null]]; }
    }
    final class TeamDatabase { public function prepare(string $sql): TeamStatement { return new TeamStatement(); } }
    function db(): TeamDatabase { return new TeamDatabase(); }
    require_once __DIR__ . '/../lib/first_steps.php';
    $source = (string)file_get_contents(__DIR__ . '/../public/users.php');
    $source = preg_replace('/^require_once .*;\R/m', '', $source);
    eval(substr($source, strlen('<?php')));
    exit(0);
}

$checks = 0;
$failures = [];
function team_check(bool $ok, string $name): void
{
    global $checks, $failures;
    $checks++;
    echo ($ok ? 'ok ' : 'not ok ') . $checks . ' - ' . $name . "\n";
    if (!$ok) $failures[] = $name;
}
function team_render(string $scenario): string
{
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($scenario) . ' 2>&1', $out, $code);
    if ($code !== 0) throw new RuntimeException("scenario $scenario failed: " . implode("\n", $out));
    return implode("\n", $out);
}

$invite = '<a class="btn-primary" href="https://id.8westit.com/users.php">Invite a teammate</a>';

$html = team_render('owner-capable');
team_check(str_contains($html, $invite), 'an owner with the 8 West ID capability sees Invite a teammate with the 8 West ID link');
team_check(substr_count($html, 'https://id.8westit.com/users.php') === 1, 'there is one way in: the old Manage people button is gone');
team_check(str_contains($html, 'People are added once, in 8 West ID.') && str_contains($html, 'They appear on this list after they sign in.'),
    'the callout says where people are added');
team_check(str_contains($html, '<details class="card backup-signin">') && str_contains($html, 'Backup sign-in for emergencies'),
    'the backup local account form is folded away, closed');
team_check(str_contains($html, 'name="full_name"') && str_contains($html, 'name="email"') && str_contains($html, 'name="password"')
    && str_contains($html, '>Add local account</button>') && str_contains($html, 'This creates a Safeharbor-only account'),
    'the local account form keeps its fields, button and hint');
team_check(str_contains($html, 'aria-label="Disable Safeharbor access for Jordan Ellis"'), 'the member list keeps its controls');

// The member list: who they are on their own lines, role, access and the one action in their own columns.
$list = substr($html, (int)strpos($html, 'team-list'), (int)strpos($html, 'backup-signin') - (int)strpos($html, 'team-list'));
team_check(str_contains($html, '<p class="page-sub">2 people with access, 1 without</p>'), 'the summary counts people, and who has no access');
team_check(substr_count($list, 'Uses a backup password') === 1 && str_contains($list, 'jordan@example.test</span>')
    && strpos($list, 'Uses a backup password') > strpos($list, 'Jordan Ellis') && strpos($list, 'Uses a backup password') < strpos($list, 'Sam Ortiz'),
    'only the account with a backup password says so');
team_check(str_contains($list, 'Signed in 3 hours ago') && substr_count($list, 'Not signed in yet') === 2, 'sign-ins are said in plain words');
team_check(!str_contains($list, ' · ') && !str_contains($list, 'Local recovery account') && !str_contains($list, '8 West ID account'),
    'no chain of facts joined by dots');
team_check(substr_count($list, 'class="btn-secondary"') === 2 && !str_contains($list, 'btn-danger')
    && str_contains($list, 'aria-label="Enable Safeharbor access for Sam Ortiz">Enable access</button>'),
    'disabling and enabling access are quiet secondary buttons');
team_check(str_contains($html, 'class="card list team-list team-list-actions"'), 'an owner\'s list has the action column');

$admin = team_render('admin-capable');
team_check(str_contains($admin, $invite) && str_contains($admin, 'Backup sign-in for emergencies'),
    'an administrator with the capability gets the same invite and backup form');
foreach (['owner-no-panel' => 'without the MSP Admin panel', 'owner-yes-string' => 'with the capability set to "yes" instead of true'] as $case => $why) {
    $html = team_render($case);
    team_check(!str_contains($html, 'Invite a teammate') && !str_contains($html, 'id.8westit.com/users.php')
        && str_contains($html, 'Ask the person who manages your 8 West ID account'), "an owner {$why} sees no invite button");
}

$plain = team_render('owner-plain');
team_check(!str_contains($plain, 'Invite a teammate') && !str_contains($plain, 'id.8westit.com/users.php'),
    'an owner without the capability sees no invite button');
team_check(str_contains($plain, 'Ask the person who manages your 8 West ID account to invite teammates.'),
    'an owner without the capability is told who to ask');

$tech = team_render('tech');
team_check(!str_contains($tech, 'Invite a teammate') && !str_contains($tech, 'Backup sign-in for emergencies')
    && !str_contains($tech, 'Add local account') && !str_contains($tech, 'class="callout'), 'a technician sees the list only');
team_check(str_contains($tech, 'Jordan Ellis'), 'a technician still sees the member list');

$failed = team_render('owner-failed-add');
team_check(str_contains($failed, '<details class="card backup-signin" open>') && str_contains($failed, 'role="alert"'),
    'a failed add opens the disclosure so the error is visible');
team_check(str_contains($failed, 'value="Rowan &quot;&gt;&lt;b&gt;Hale"') && str_contains($failed, 'value="&quot;&gt;&lt;b&gt;not-an-email"')
    && !str_contains($failed, '"><b>') && !str_contains($failed, 'value="x"'),
    'a failed add keeps the typed name and email, escaped, and never the password');
team_check(preg_match('/name="role" value="owner"\s+checked/', $failed) === 1 && preg_match('/name="role" value="tech"\s+checked/', $failed) !== 1,
    'a failed add keeps the chosen role');
team_check(preg_match('/name="color" value="#F6C95B"\s+checked/', $failed) === 1 && preg_match('/name="color" value="#2D8CFF"\s+checked/', $failed) !== 1,
    'a failed add keeps the chosen colour');
$fresh = team_render('owner-capable');
team_check(preg_match('/name="role" value="tech"\s+checked/', $fresh) === 1 && preg_match('/name="color" value="#2D8CFF"\s+checked/', $fresh) === 1,
    'a fresh form still starts at Technician and Blue');

echo $failures === [] ? "\nTeam invite: {$checks} passed.\n" : "\nTeam invite: " . count($failures) . " of {$checks} failed.\n";
exit($failures === [] ? 0 : 1);
