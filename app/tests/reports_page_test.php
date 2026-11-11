<?php
/**
 * The Reports page's sums and pieces (lib/reports_page.php), loaded with isolated dependency
 * edges: no config, no MySQL. The page's own "tickets opened" query is lifted from
 * public/reports.php and run on SQLite, so the by-owner sums are checked against the tested
 * first-reply rule (service_goal_response_attainment()). The page as a whole is read from MySQL
 * in ticket_queue_http_test.php.
 *
 *   php app/tests/reports_page_test.php            runs the checks
 *   php app/tests/reports_page_test.php --render   prints the page's pieces built from the same
 *       helpers, for tools/shots/reports-page.test.mjs
 */
declare(strict_types=1);
$checks = 0;
function reports_check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

require __DIR__ . '/../lib/service_goals.php';
require __DIR__ . '/../lib/ticket_queue.php';   // queue_facts(): the rule for a ticket the assistant holds
require __DIR__ . '/../lib/reports_page.php';

$dom = static function (string $html): DOMXPath {
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"><div>' . $html . '</div>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($doc);
};
$texts = static function (DOMXPath $x, string $query): array {
    $out = [];
    foreach ($x->query($query) as $node) $out[] = trim((string)preg_replace('/\s+/u', ' ', $node->textContent));
    return $out;
};
$attrs = static function (DOMXPath $x, string $query): array {
    $out = [];
    foreach ($x->query($query) as $node) $out[] = (string)$node->nodeValue;
    return $out;
};
$has = static fn(string $class): string => 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';

// A fixed clock: Tuesday 10 November 2026, 18:00 UTC.
$now = strtotime('2026-11-10 18:00:00 UTC');
$at = static fn(int $minutesAgo): string => gmdate('Y-m-d H:i:s', $now - $minutesAgo * 60);
$day = 1440;

// The team and lists the checks and the rendered page share.
// As avatar() in lib/render.php draws one (the shared helper sizes and colours it inline).
$avatar = static fn(string $letters): string => '<span class="avatar" style="width:24px;height:24px;background:#2D8CFF">' . $letters . '</span>';
$teamRows = [
    ['name' => 'Ana Ruiz', 'avatar' => $avatar('AR'), 'open' => 4, 'open_url' => '/?view=open&owner=11', 'resolved' => 7,
        'median' => 31, 'pct' => 92, 'decided' => 12, 'met' => 11, 'minutes' => 370, 'before_corrections' => null],
    ['name' => 'Marcus <Tran>', 'avatar' => $avatar('MT'), 'open' => 2, 'open_url' => '/?view=open&owner=12', 'resolved' => 5,
        'median' => 55, 'pct' => 80, 'decided' => 10, 'met' => 8, 'minutes' => 245, 'before_corrections' => 275],
    ['name' => 'Jo Park', 'avatar' => $avatar('JP'), 'open' => 0, 'open_url' => '/?view=open&owner=13', 'resolved' => 0,
        'median' => null, 'pct' => null, 'decided' => 0, 'met' => 0, 'minutes' => 0, 'before_corrections' => null],
    ['name' => 'Westy', 'avatar' => $avatar('W'), 'open' => 1, 'open_url' => '', 'resolved' => 0,
        'median' => 20, 'pct' => 50, 'decided' => 2, 'met' => 1, 'minutes' => null, 'before_corrections' => null],
    ['name' => 'Unassigned', 'avatar' => $avatar('—'), 'open' => 3, 'open_url' => '/?view=unassigned', 'resolved' => 1,
        'median' => 190, 'pct' => 0, 'decided' => 1, 'met' => 0, 'minutes' => null, 'before_corrections' => null],
];
$facts = ['new' => 14, 'new_before' => 11, 'resolved' => 12, 'resolved_before' => 13, 'median' => 42, 'replied' => 12, 'median_before' => 50,
    'on_time' => ['n' => 22, 'met' => 19, 'pct' => 86], 'on_time_before' => ['n' => 20, 'met' => 18, 'pct' => 90],
    'surveys' => 9, 'rated' => 4, 'great' => 3];
$quiet = ['new' => 0, 'new_before' => 0, 'resolved' => 0, 'resolved_before' => 0, 'median' => null, 'replied' => 0, 'median_before' => null,
    'on_time' => ['n' => 0, 'met' => 0, 'pct' => null], 'on_time_before' => ['n' => 0, 'met' => 0, 'pct' => null],
    'surveys' => 0, 'rated' => 0, 'great' => 0];
// What a technician gets: their own row, then the whole team in one.
$ownRows = [$teamRows[0], ['name' => 'Whole team', 'avatar' => '', 'open' => 10, 'open_url' => '/?view=open', 'resolved' => 12,
    'median' => 42, 'pct' => 86, 'decided' => 22, 'met' => 19, 'minutes' => 615, 'before_corrections' => 645]];
$longest = [
    ['id' => 108, 'subject' => 'Quarterly phishing <refresher>', 'client_name' => 'Copper Kettle Bakery', 'assignee_name' => 'Ana Ruiz', 'created_at' => $at(2 * $day + 30)],
    ['id' => 111, 'subject' => 'Imaging software license expires Friday', 'client_name' => 'Harbor Dental', 'assignee_name' => null, 'created_at' => $at($day + 30)],
    ['id' => 115, 'subject' => 'New hygienist starts Monday', 'client_name' => 'Harbor Dental', 'assignee_name' => 'Marcus Tran', 'created_at' => $at(190)],
    ['id' => 118, 'subject' => 'Backup job failed overnight', 'client_name' => 'Copper Kettle Bakery', 'assignee_name' => null, 'westy_held' => 1, 'created_at' => $at(75)],
];
$byClient = [
    ['id' => 30, 'name' => 'Harbor <Dental>', 'min_total' => 200, 'original_min_total' => 200, 'adjusted_count' => 0],
    ['id' => 32, 'name' => 'Copper Kettle Bakery', 'min_total' => 45, 'original_min_total' => 75, 'adjusted_count' => 1],
    ['id' => 34, 'name' => 'Northwind Partners', 'min_total' => 0, 'original_min_total' => 15, 'adjusted_count' => 1],
];

if (in_array('--render', $argv, true)) {
    $sections = [];
    foreach (REPORT_PERIODS as $days) {
        $sections[$days] = [
            'sub' => 'How the desk did in ' . report_period_words($days) . '.',
            'picker' => report_period_picker($days),
            'tiles' => report_tiles($facts, $days),
            'team' => report_team_table($teamRows, $days),
            'team_own' => report_team_table($ownRows, $days, false),
            'longest' => report_open_longest($longest, $now),
            'clients' => report_time_by_client($byClient, $days),
        ];
    }
    $sections['quiet'] = [
        'sub' => 'How the desk did in ' . report_period_words(30) . '.',
        'picker' => report_period_picker(30),
        'tiles' => report_tiles($quiet, 30),
        'team' => report_team_table([array_replace($teamRows[2], ['name' => 'Jo Park']), array_replace($teamRows[4], ['open' => 0, 'resolved' => 0, 'median' => null, 'pct' => null, 'decided' => 0])], 30),
        'team_own' => report_team_table([$teamRows[2], array_replace($ownRows[1], ['open' => 0, 'resolved' => 0, 'median' => null, 'pct' => null, 'decided' => 0, 'met' => 0, 'minutes' => 0, 'before_corrections' => null])], 30, false),
        'longest' => report_open_longest([], $now),
        'clients' => report_time_by_client([], 30),
    ];
    echo json_encode($sections, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

try {
    // ---- the period ----
    foreach ([
        [[], 30], [['days' => '7'], 7], [['days' => '30'], 30], [['days' => '90'], 90],
        [['days' => '365'], 30], [['days' => '0'], 30], [['days' => '-7'], 30], [['days' => '7.0'], 30], [['days' => '07'], 30],
        [['days' => ' 7'], 30], [['days' => '7 OR 1=1'], 30], [['days' => ['7']], 30], [['days' => 7], 30], [['days' => '9e1'], 30],
    ] as $n => [$get, $days]) {
        reports_check(report_period($get) === $days, "period $n");
    }
    reports_check(report_url(30) === '/reports.php' && report_url(7) === '/reports.php?days=7' && report_url(90) === '/reports.php?days=90', 'each period has one address');
    $x = $dom(report_period_picker(7));
    reports_check($texts($x, '//nav/a') === ['Last 7 days', 'Last 30 days', 'Last 90 days']
        && $attrs($x, '//nav/a/@href') === ['/reports.php?days=7', '/reports.php', '/reports.php?days=90']
        && $attrs($x, '//nav/a[@aria-current="page"]/@href') === ['/reports.php?days=7'] && $attrs($x, '//nav/@aria-label') === ['Period'],
        'the period is one filter for the whole page');

    // ---- the median ----
    reports_check(report_median([]) === null && report_median([42]) === 42 && report_median([5, 1, 9]) === 5 && report_median([10, 20]) === 15
        && report_median([10, 21]) === 16 && report_median([1, 2, 3, 1350]) === 3 && report_median([30, 30, 30, 30, 1350]) === 30,
        'the median is the middle wait: one very late ticket does not speak for the rest');

    // ---- by owner, against the tested first-reply rule ----
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE tickets(id INTEGER PRIMARY KEY, tenant_id INTEGER, assignee_id INTEGER, status TEXT, created_at TEXT, resolved_at TEXT, sla_due_at TEXT, merged_into_id INTEGER, client_id INTEGER DEFAULT 21);
        CREATE TABLE messages(id INTEGER PRIMARY KEY, ticket_id INTEGER, kind TEXT, created_at TEXT);
        CREATE TABLE westy_workflows(id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, ticket_id INTEGER, state TEXT, closed_at TEXT);
        INSERT INTO westy_workflows(tenant_id, client_id, ticket_id, state) VALUES (1, 21, 14, "working"), (1, 21, 15, "working"), (1, 21, 16, "needs_human"),
            (1, 21, 6, "working"), (2, 21, 14, "working"), (1, 21, 19, "human_owned");');
    // The assistant resolved #17, #20, #21 and #22: each workflow's closed time is the moment it resolved the
    // ticket. Someone has since written on #17; #22 still has its original resolved state.
    $closed = $pdo->prepare('INSERT INTO westy_workflows(tenant_id, client_id, ticket_id, state, closed_at) VALUES (1, 21, ?, ?, ?)');
    foreach ([[17, 'human_owned', 50], [20, 'resolved', 70], [21, 'human_owned', 500], [22, 'resolved', 45]] as [$id, $state, $closedAgo]) {
        $closed->execute([$id, $state, $at($closedAgo)]);
    }
    // A matching timestamp from another workspace or client is not closure evidence for #18.
    $elsewhere = $pdo->prepare('INSERT INTO westy_workflows(tenant_id, client_id, ticket_id, state, closed_at) VALUES (?, ?, 18, ?, ?)');
    $elsewhere->execute([2, 21, 'resolved', $at(50)]);
    $elsewhere->execute([1, 99, 'resolved', $at(50)]);
    $ticket = $pdo->prepare('INSERT INTO tickets(id, tenant_id, assignee_id, status, created_at, resolved_at, sla_due_at, merged_into_id) VALUES(?,?,?,?,?,?,?,?)');
    $message = $pdo->prepare('INSERT INTO messages(ticket_id, kind, created_at) VALUES(?,?,?)');
    foreach ([
        // id, tenant, owner, status, opened (minutes ago), resolved, due, merged into, first tech replies (minutes ago)
        [1, 1, 11, 'open', 600, null, 480, null, [570]],                 // Ana: 30 minutes, on time
        [2, 1, 11, 'resolved', 3000, 2000, 2880, null, [2960, 2900]],    // Ana: 40 minutes, on time (the earlier reply counts)
        [3, 1, 11, 'open', 5000, null, 4880, null, [4700]],              // Ana: 300 minutes, late
        [4, 1, 12, 'open', 900, null, 780, null, [780]],                 // Marcus: 120 minutes, on the clock is on time
        [5, 1, 12, 'resolved', 2000, 1500, 1880, null, []],              // Marcus: closed without a reply, decided and not on time
        [6, 1, 12, 'open', 100, null, -380, null, []],                   // Marcus: due later today, undecided
        [7, 1, null, 'open', 4000, null, 3880, null, []],                // nobody: due long ago, no reply
        [8, 1, null, 'open', 700, null, 580, null, [650, 9000]],         // nobody: 50 minutes; a "reply" from before it opened is not one
        [9, 1, 11, 'resolved', 800, 700, 680, 10, [790]],                // merged into #10: left out
        [10, 1, 11, 'open', 850, null, 730, null, [840]],                // something was merged into it: left out
        [11, 2, 11, 'open', 600, null, 480, null, [590]],                // another tenant
        [12, 1, 13, 'open', 31 * $day + 5, null, 31 * $day - 100, null, [31 * $day]],   // opened before the thirty days
        [13, 1, 13, 'open', 200, null, 80, null, [-30]],                 // its only reply is after "now": not yet a reply, and it is overdue
        [14, 1, null, 'in_progress', 300, null, 180, null, []],          // the assistant is working it: Westy's, overdue with no reply
        [15, 1, null, 'in_progress', 400, null, 280, null, [380]],       // Westy's: 20 minutes, on time
        [16, 1, null, 'in_progress', 500, null, -100, null, []],         // the assistant handed it to a person: nobody's, and not due yet
        [17, 1, null, 'resolved', 120, 50, 0, null, []],                 // the assistant resolved it, then someone added a note: still Westy's
        [18, 1, null, 'resolved', 70 * $day, 50, 70 * $day - 100, null, []],   // opened long ago, resolved today by nobody in particular
        [19, 1, null, 'resolved', 70 * $day, 60, 70 * $day - 100, null, []],   // the assistant handed it over before it was resolved: nobody's
        [20, 1, 12, 'resolved', 70 * $day, 70, 70 * $day - 100, null, []],     // the assistant resolved it, then Marcus took it: Marcus's
        [21, 1, null, 'resolved', 70 * $day, 40, 70 * $day - 100, null, []],   // the assistant resolved it, a person reopened it and resolved it again later: nobody's
        [22, 1, null, 'resolved', 70 * $day, 45, 70 * $day - 100, null, []],   // the assistant resolved it and nothing has touched it: Westy's
    ] as [$id, $tenant, $owner, $status, $opened, $resolved, $due, $merged, $replies]) {
        $ticket->execute([$id, $tenant, $owner, $status, $at($opened), $resolved === null ? null : $at($resolved), $at($due), $merged]);
        foreach ($replies as $reply) $message->execute([$id, 'tech', $at($reply)]);
        $message->execute([$id, 'client', $at($opened)]);
    }
    $source = (string)file_get_contents(__DIR__ . '/../public/reports.php');
    reports_check(preg_match('/\$opened = db\(\)->prepare\(\s*"([^"]+)"\s*\)/s', $source, $openedSql) === 1, 'the page reads the tickets opened in the period with one query');
    // The page writes the queue's own rule for "the assistant holds this ticket" into the query; so does this.
    reports_check(substr_count($openedSql[1], '$westyOwned') === 1, 'the query asks one rule which tickets are the assistant\'s');
    $westyOwned = report_westy_owned_sql(queue_facts($pdo)['{westy}']);
    reports_check(report_westy_owned_sql('(0 = 1)') === '(0 = 1)', 'where the assistant has no workflow table, no ticket is its');
    $read = $pdo->prepare(str_replace('$westyOwned', $westyOwned, $openedSql[1]));
    $read->execute([1, $at(30 * $day)]);
    $rows = $read->fetchAll();
    reports_check(array_map('intval', array_column($rows, 'id')) === [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 13, 14, 15, 16, 17], 'the query reads this tenant\'s tickets opened in the period, and no others');
    reports_check(array_map('intval', array_column(array_filter($rows, static fn(array $row): bool => (int)$row['westy_held'] === 1), 'id')) === [14, 15, 17],
        'a ticket is Westy\'s while nobody owns it and the assistant is working it or has resolved it: not one a person owns, and not one handed to a person');
    // Resolved in the period, by who has the ticket now: the page's own query.
    reports_check(preg_match('/\$resolvedBy = db\(\)->prepare\(\s*"([^"]+)"\s*\)/s', $source, $resolvedSql) === 1 && substr_count($resolvedSql[1], '$westyOwned') === 1,
        'the page counts resolved tickets by owner with one query and the same rule');
    $resolved = $pdo->prepare(str_replace('$westyOwned', $westyOwned, $resolvedSql[1]));
    $resolved->execute([1, $at(30 * $day)]);
    $byOwner = [];
    foreach ($resolved->fetchAll() as $row) $byOwner[($row['assignee_id'] ?? 'nobody') . ((int)$row['westy_held'] === 1 ? ' (Westy)' : '')] = (int)$row['n'];
    ksort($byOwner);
    reports_check($byOwner === ['11' => 2, '12' => 2, 'nobody' => 3, 'nobody (Westy)' => 2],
        'a ticket the assistant resolved counts as resolved by Westy, whatever its workflow says now; one it handed over, one a person now owns, and one a person resolved again do not');
    $out = report_outcomes($rows, $now);
    $rule = service_goal_response_attainment($pdo, 1, 30, $now);
    reports_check($rule === ['n' => 11, 'met' => 5, 'pct' => 45]
        && ['n' => $out['all']['decided'], 'met' => $out['all']['met'], 'pct' => $out['all']['pct']] === $rule,
        'the page counts "on time" exactly as the service-goal rule does');
    reports_check($out['owners']['11'] === ['replied' => 3, 'median' => 40, 'decided' => 3, 'met' => 2, 'pct' => 67]
        && $out['owners']['12'] === ['replied' => 1, 'median' => 120, 'decided' => 2, 'met' => 1, 'pct' => 50]
        && $out['owners'][''] === ['replied' => 1, 'median' => 50, 'decided' => 2, 'met' => 1, 'pct' => 50]
        && $out['owners']['13'] === ['replied' => 0, 'median' => null, 'decided' => 1, 'met' => 0, 'pct' => 0]
        && $out['owners']['westy'] === ['replied' => 1, 'median' => 20, 'decided' => 3, 'met' => 1, 'pct' => 33]
        && array_map('strval', array_keys($out['owners'])) === ['11', '12', '', '13', 'westy'],
        'each owner gets their own tickets, Westy included: the median wait, and how many first replies were on time');
    reports_check($out['all']['replied'] === 6 && $out['all']['median'] === 45
        && array_sum(array_column($out['owners'], 'decided')) === $out['all']['decided'] && array_sum(array_column($out['owners'], 'met')) === $out['all']['met'],
        'the owners add up to the whole, and the whole page\'s wait is the median (45 minutes, where the mean is 93)');
    reports_check(report_outcomes([], $now) === ['all' => ['replied' => 0, 'median' => null, 'decided' => 0, 'met' => 0, 'pct' => null], 'owners' => []],
        'no tickets, nothing to say');
    // The period before, judged as it stood when it ended: the same rule with an earlier clock.
    $earlier = $now - 30 * 86400;
    $read->execute([1, gmdate('Y-m-d H:i:s', $earlier - 30 * 86400)]);
    $beforeRows = array_values(array_filter($read->fetchAll(), static fn(array $row): bool => $row['created_at'] < $at(30 * $day)));
    reports_check(array_map('intval', array_column($beforeRows, 'id')) === [12]
        && service_goal_response_attainment($pdo, 1, 30, $earlier) === ['n' => 1, 'met' => 1, 'pct' => 100]
        && report_outcomes($beforeRows, $now)['all']['median'] === 5, 'the period before has its own tickets');

    // ---- how a number moved ----
    reports_check(report_change_count(14, 11, 30) === '3 more than the 30 days before' && report_change_count(12, 13, 7) === '1 fewer than the 7 days before'
        && report_change_count(5, 5, 90) === 'Same as the 90 days before' && report_change_count(1200, 0, 30) === '1,200 more than the 30 days before', 'a count says how it moved');
    reports_check(report_change_minutes(42, 50, 30) === '8 min quicker than the 30 days before' && report_change_minutes(125, 50, 30) === '1 h 15 min slower than the 30 days before'
        && report_change_minutes(42, 42, 30) === 'Same as the 30 days before' && report_change_minutes(42, null, 30) === 'No first replies in the 30 days before'
        && report_change_minutes(null, 50, 30) === '', 'a wait says whether it got quicker or slower');
    reports_check(report_change_points(86, 90, 30) === '4 points down on the 30 days before' && report_change_points(91, 90, 30) === '1 point up on the 30 days before'
        && report_change_points(90, 90, 30) === 'Same as the 30 days before' && report_change_points(90, null, 30) === 'Nothing was due in the 30 days before'
        && report_change_points(null, 90, 30) === '', 'a percentage moves in points');

    // ---- the tiles ----
    $x = $dom(report_tiles($facts, 30));
    reports_check($texts($x, '//div[' . $has('stat-k') . ']') === ['New tickets', 'Resolved', 'First reply (median)', 'On time', 'Rated Great']
        && $texts($x, '//div[' . $has('stat-v') . ']') === ['14', '12', '42 min', '86%', '3 of 4'], 'five tiles, one row, one period');
    reports_check($texts($x, '//div[' . $has('stat-hint') . ']') === ['3 more than the 30 days before', '1 fewer than the 30 days before',
        'Over 12 tickets', '8 min quicker than the 30 days before', '19 of 22 first replies', '4 points down on the 30 days before', '4 of 9 surveys answered'],
        'each tile says what it is counted over and how it moved');
    reports_check($attrs($x, '//progress[' . $has('stat-meter') . ']/@value') === ['86'] && $attrs($x, '//progress/@max') === ['100']
        && $attrs($x, '//progress/@aria-label') === ['86% of first replies on time'] && $x->query('//*[@style]')->length === 0, 'On time has a meter, with no inline style');
    $x = $dom(report_tiles($quiet, 7));
    reports_check($texts($x, '//div[' . $has('stat-v') . ']') === ['0', '0', 'No replies', 'Nothing due', 'No ratings']
        && $texts($x, '//div[' . $has('stat-hint') . ']') === ['Same as the 7 days before', 'Same as the 7 days before',
            'No ticket opened in the last 7 days has a reply yet', 'No first reply was due in the last 7 days', 'No survey was sent in the last 7 days']
        && $x->query('//progress')->length === 0 && $x->query('//div[' . $has('stat') . ' and ' . $has('card') . ']')->length === 5
        && !preg_match('/—|NaN|%/u', implode(' ', $texts($x, '//div[' . $has('stat-v') . ']'))), 'a quiet period still shows five tiles, in words where there is no number');
    reports_check($texts($dom(report_tiles(array_replace($facts, ['surveys' => 3, 'rated' => 0, 'great' => 0]), 30)), '//div[' . $has('stat-hint') . ']')[6] === '3 surveys sent, none answered yet'
        && $texts($dom(report_tiles(array_replace($facts, ['surveys' => 1, 'rated' => 1, 'great' => 1, 'replied' => 1]), 30)), '//div[' . $has('stat-hint') . ']')[6] === '1 of 1 survey answered'
        && $texts($dom(report_tiles(array_replace($facts, ['replied' => 1]), 30)), '//div[' . $has('stat-hint') . ']')[2] === 'Over 1 ticket', 'one of a thing is singular');

    // ---- the team ----
    $x = $dom(report_team_table($teamRows, 30));
    reports_check($texts($x, '//thead//th') === ['Technician', 'Open now', 'Resolved', 'First reply', 'On time', 'Billable time']
        && $x->query('//thead//th[@scope="col"]')->length === 6 && $x->query('//tbody//th[@scope="row"]')->length === 5
        && $texts($x, '//caption') === ['The team in the last 30 days'], 'the team is a real table with named columns and rows');
    reports_check($texts($x, '//tbody/tr[1]/*') === ['ARAna Ruiz', '4', '7', '31 min', '92% 11 of 12', '6 h 10 min']
        && $texts($x, '//tbody/tr[2]/*') === ['MTMarcus <Tran>', '2', '5', '55 min', '80% 8 of 10', '4 h 5 min 4 h 35 min before corrections']
        && $x->query('//tbody//th//*[not(self::span)]')->length === 0 && $x->query('//tbody//td/*[not(' . $has('report-cell') . ')]')->length === 0, 'a row is a person: open now, resolved, first reply, on time and approved billable time');
    reports_check($texts($x, '//tbody/tr[3]/*') === ['JPJo Park', '0', '0', 'None', 'None', '0 min']
        && $texts($x, '//tbody/tr[4]/*') === ['WWesty', '1', '0', '20 min', '50% 1 of 2', '']
        && $texts($x, '//tbody/tr[5]/*') === ['—Unassigned', '3', '1', '3 h 10 min', '0% 0 of 1', ''], 'someone with nothing yet, the assistant, and tickets nobody owns each have a row');
    reports_check($attrs($x, '//tbody//td[@data-label="Open now"]//a/@href') === ['/?view=open&owner=11', '/?view=open&owner=12', '/?view=unassigned']
        && $x->query('//tbody//td[not(@data-label="Open now")]//a')->length === 0,
        'Open now opens the queue\'s own list of those tickets; a zero, and a number the queue has no list for, is not a link');
    reports_check($attrs($x, '//tbody/tr[1]/td/@data-label') === ['Open now', 'Resolved', 'First reply', 'On time', 'Billable time'], 'every cell carries its column\'s name for a narrow screen');
    reports_check(str_contains(report_team_table($teamRows, 30), 'go by who owns the ticket now') && str_contains(report_team_table($teamRows, 30), 'Corrections are included.'),
        'the table says what its columns go by');
    // A technician's table: their own row and the whole team's, worded for one person.
    $x = $dom(report_team_table($ownRows, 30, false));
    reports_check($texts($x, '//tbody/tr/th') === ['ARAna Ruiz', 'Whole team'] && $texts($x, '//caption') === ['You and the whole team in the last 30 days']
        && $texts($x, '//tbody/tr[2]/*') === ['Whole team', '10', '12', '42 min', '86% 19 of 22', '10 h 15 min 10 h 45 min before corrections']
        && $attrs($x, '//tbody//td[@data-label="Open now"]//a/@href') === ['/?view=open&owner=11', '/?view=open']
        && $texts($x, '//p[' . $has('page-note') . ']') === ['Your row counts the tickets you own now and the approved time you logged. Whole team counts every ticket and everyone’s approved time. First reply is the median wait. Corrections are included.']
        && !str_contains(report_team_table($ownRows, 30, false), 'who owns the ticket now'),
        'a technician\'s table is their own row beside the whole team\'s, and says what each counts');

    // ---- open longest, and time by client ----
    $x = $dom(report_open_longest($longest, $now));
    reports_check($texts($x, '//a//span[' . $has('report-row-title') . ']') === ['#108 Quarterly phishing <refresher>', '#111 Imaging software license expires Friday', '#115 New hygienist starts Monday', '#118 Backup job failed overnight']
        && $texts($x, '//a//span[' . $has('report-row-sub') . ']') === ['Copper Kettle Bakery, Ana Ruiz', 'Harbor Dental, unassigned', 'Harbor Dental, Marcus Tran', 'Copper Kettle Bakery, Westy']
        && $texts($x, '//a//span[' . $has('report-row-value') . ']') === ['Opened 2 days ago', 'Opened yesterday', 'Opened 3 hours ago', 'Opened 1 hour ago']
        && $attrs($x, '//a/@href') === ['/ticket.php?id=108', '/ticket.php?id=111', '/ticket.php?id=115', '/ticket.php?id=118'], 'the tickets open longest are links, oldest first, with who has them: a person, Westy, or nobody');
    reports_check($texts($dom(report_open_longest([], $now)), '//p') === ['No open tickets.'], 'no open tickets says so');
    $x = $dom(report_time_by_client($byClient, 90));
    reports_check($texts($x, '//a//span[' . $has('report-row-title') . ']') === ['Harbor <Dental>', 'Copper Kettle Bakery', 'Northwind Partners']
        && $texts($x, '//a//span[' . $has('report-row-value') . ']') === ['3 h 20 min', '45 min', '0 min']
        && $texts($x, '//a//span[' . $has('report-row-sub') . ']') === ['1 h 15 min before corrections', '15 min before corrections']
        && $attrs($x, '//a/@href') === ['/client.php?id=30', '/client.php?id=32', '/client.php?id=34']
        && $texts($x, '//p[' . $has('page-note') . ']') === ['Approved billable time. Corrections are included.'], 'time by client is approved billable time, with what a correction changed');
    reports_check($texts($dom(report_time_by_client([], 7)), '//div[' . $has('empty') . ']/p') === ['No approved billable time in the last 7 days.'], 'no approved time says so');
    $all = report_tiles($facts, 30) . report_team_table($teamRows, 30) . report_open_longest($longest, $now) . report_time_by_client($byClient, 30);
    reports_check(!preg_match('/effective approved|decided tickets|append-only|correction slip|attainment|CSAT|\(30d\)|raw /i', $all)
        && $dom($all)->query('//*[@style][not(' . $has('avatar') . ')]')->length === 0, 'nothing is worded for an auditor, and the markup of the page itself carries no inline style');

    echo "PASS: $checks reports page checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
