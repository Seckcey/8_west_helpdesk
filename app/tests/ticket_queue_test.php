<?php
/**
 * The queue's address handling, views bar, filters, pager, summary and row wording
 * (lib/ticket_queue.php, lib/render.php), loaded with isolated dependency edges: no config, no
 * database. The SQL itself is exercised against MySQL in ticket_queue_http_test.php.
 *
 *   php app/tests/ticket_queue_test.php            runs the checks
 *   php app/tests/ticket_queue_test.php --render   prints a queue built from the same helpers, for
 *       tools/shots/queue-views.test.mjs and tools/shots/first-response-tag.test.mjs
 *   php app/tests/ticket_queue_test.php --client-rows   prints the rows of one client's own list,
 *       without the client's name, for tools/shots/client-page.test.mjs
 */
declare(strict_types=1);
$checks = 0;
function queue_check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}

$temp = sys_get_temp_dir() . '/safeharbor-ticket-queue-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
try {
    copy(__DIR__ . '/../lib/render.php', $temp . '/render.php');
    // The real first-response rules (sla_info), so "due soon" here is checked against them.
    file_put_contents($temp . '/auth.php', '<?php require_once ' . var_export(realpath(__DIR__ . '/../lib/service_goals.php'), true) . ';'
        . ' function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,"UTF-8"); } function rel_time($v): string { return "2h"; }');
    file_put_contents($temp . '/westy.php', '<?php');
    require $temp . '/render.php';
    require __DIR__ . '/../lib/ticket_queue.php';

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
        foreach ($x->query($query) as $node) $out[] = trim((string)preg_replace('/\s+/', ' ', $node->textContent));
        return $out;
    };
    $plain = ['view' => 'needs', 'status' => '', 'client' => 0, 'priority' => '', 'owner' => '', 'sort' => '', 'page' => 1, 'after' => 0, 'before' => 0];

    // ---- what the address asks for ----
    queue_check(queue_request([]) === $plain, 'no address opens Needs attention, unfiltered, page 1');
    // A link from before the views existed keeps its meaning: every ticket, or every ticket with one status.
    foreach (['all', 'open', 'in_progress', 'waiting', 'resolved'] as $old) {
        $legacy = queue_request(['f' => $old]);
        queue_check($legacy['view'] === 'all' && $legacy['status'] === $old, "an old ?f=$old link still lists what it listed");
        queue_check(queue_url($legacy) === '/?f=' . $old, "and keeps its address (?f=$old)");
    }
    queue_check(queue_request(['f' => 'bogus']) === $plain && queue_request(['f' => ['all']]) === $plain && queue_request(['view' => 'all']) === $plain,
        'an unknown old link, or the old list asked for as a view, opens the default');
    queue_check(queue_request(['view' => 'mine', 'f' => 'resolved']) === array_replace($plain, ['view' => 'mine']), 'a view wins over an old status link');
    $legacy = queue_request(['f' => 'all', 'client' => '12', 'sort' => 'reply', 'page' => '2']);
    queue_check(queue_url($legacy) === '/?f=all&client=12&sort=reply&page=2' && queue_request(['f' => 'all', 'sort' => 'priority'])['sort'] === '',
        'an old link takes the filters, and its own order (priority) is not an explicit choice');
    queue_check(queue_view_label($legacy) === 'Every ticket' && queue_view_label(queue_request(['f' => 'in_progress'])) === 'Tickets with the status ' . STATUS_META['in_progress'][0]
        && queue_view_label($plain) === 'Needs attention', 'every list has a name');
    queue_check(queue_legacy_note($legacy) === 'Every ticket, resolved included: this is how an older link listed the queue.'
        && queue_legacy_note(queue_request(['f' => 'open'])) === 'Tickets with the status Open: this is how an older link listed the queue.'
        && queue_legacy_note($plain) === '', 'an old link says what it is; a view needs no note');
    queue_check(queue_request(['page' => '2', 'after' => '9050', 'before' => 'x'])['after'] === 9050 && queue_request(['after' => '-3', 'before' => ['1']]) === $plain,
        'the page edge a link names is a ticket number or nothing');
    $hostile = queue_request(['view' => "open' OR 1=1", 'client' => '7 OR 1=1', 'priority' => "high'", 'owner' => '<script>', 'sort' => 'id;DROP', 'page' => '-4']);
    queue_check($hostile === $plain, 'anything that is not a known choice falls back to the default');
    queue_check(queue_request(['view' => ['open'], 'client' => ['1'], 'owner' => ['me'], 'page' => ['2']]) === $plain, 'lists in the address are ignored');
    queue_check(queue_request(['client' => '99999999999999999999', 'page' => '99999999999999999999']) === $plain, 'numbers too long to be ids are ignored');
    $full = queue_request(['view' => 'open', 'client' => '12', 'priority' => 'urgent', 'owner' => '103', 'sort' => 'newest', 'page' => '3']);
    queue_check($full === ['view' => 'open', 'status' => '', 'client' => 12, 'priority' => 'urgent', 'owner' => '103', 'sort' => 'newest', 'page' => 3, 'after' => 0, 'before' => 0],
        'every choice is read');
    queue_check(queue_request(['owner' => 'me'])['owner'] === 'me' && queue_request(['owner' => 'none'])['owner'] === 'none'
        && queue_request(['owner' => '0'])['owner'] === '', 'owner is me, none, or a real id');
    queue_check(queue_request(['sort' => 'reply'])['sort'] === '' && queue_request(['view' => 'resolved', 'sort' => 'updated'])['sort'] === ''
        && queue_request(['view' => 'resolved', 'sort' => 'reply'])['sort'] === 'reply', 'a view\'s own order is not an explicit choice');

    // ---- addresses ----
    queue_check(queue_url($plain) === '/', 'the plain queue is /');
    queue_check(queue_url($plain, ['view' => 'mine']) === '/?view=mine', 'a view is one parameter');
    queue_check(queue_url($full) === '/?view=open&client=12&priority=urgent&owner=103&sort=newest&page=3', 'every choice is in the address');
    queue_check(queue_url($full, ['page' => 1, 'sort' => 'reply']) === '/?view=open&client=12&priority=urgent&owner=103', 'defaults are left out');
    queue_check(queue_url(array_replace($full, ['after' => 77])) === '/?view=open&client=12&priority=urgent&owner=103&sort=newest&page=3'
        && queue_url($full, ['page' => 4, 'after' => 77]) === '/?view=open&client=12&priority=urgent&owner=103&sort=newest&page=4&after=77',
        'a page edge travels only on the link that asks for it');
    foreach (array_keys(QUEUE_VIEWS) as $view) {
        foreach (['', 'reply', 'updated', 'priority', 'newest'] as $sort) {
            $state = queue_request(['view' => $view, 'client' => '4', 'owner' => 'me', 'sort' => $sort, 'page' => '2']);
            parse_str((string)parse_url(queue_url($state), PHP_URL_QUERY), $query);
            queue_check(queue_request($query) === $state, "the address of $view sorted by '$sort' reads back as the same state");
        }
    }

    // ---- views and orders are closed lists ----
    foreach (array_keys(QUEUE_VIEWS) as $view) {
        queue_check(!str_contains(queue_view_sql($view, 102), '?'), "the $view view takes no value from the address");
        foreach (array_keys(QUEUE_SORTS) as $sort) queue_check(queue_order_sql($view, $sort) !== '', "$view can be ordered by $sort");
    }
    queue_check(queue_view_sql('all', 102) === '1 = 1' && str_starts_with(queue_order_sql('all', 'priority'), "(t.status = 'resolved') ASC"),
        'an old link lists every ticket, resolved last');
    // The rules the review asked for, as the query states them.
    $needs = queue_view_sql('needs', 102);
    queue_check(str_contains($needs, '{first_response} IS NULL AND t.sla_due_at <') && !str_contains(explode(' OR ', $needs)[0], 'assignee_id'),
        'Needs attention takes a late or nearly late unanswered ticket whoever owns it');
    queue_check(str_contains($needs, 't.assignee_id IS NULL AND NOT {westy}') && str_contains(queue_view_sql('unassigned', 102), 't.assignee_id IS NULL AND NOT {westy}')
        && str_contains(queue_filter_sql(array_replace($plain, ['owner' => 'none']), 102)[0], 't.assignee_id IS NULL AND NOT {westy}'),
        'a ticket the assistant is working is not unassigned: in the view, in the default view and in the filter alike');
    $reply = queue_order_sql('open', 'reply');
    queue_check(str_starts_with($reply, "({first_response} IS NULL AND t.status <> 'resolved') DESC") && !str_contains($reply, "'waiting'"),
        'reply order puts every unanswered ticket first, waiting or not');
    foreach ([static fn() => queue_view_sql('everything', 1), static fn() => queue_order_sql('open', 'id')] as $unknown) {
        $refused = false;
        try { $unknown(); } catch (InvalidArgumentException $e) { $refused = true; }
        queue_check($refused, 'an unknown view or order is refused, not guessed');
    }
    [$filterSql, $filterParams] = queue_filter_sql($full, 102);
    queue_check(substr_count($filterSql, '?') === 3 && $filterParams === [12, 'urgent', 103], 'filters are bound values, never text in the query');
    queue_check(queue_filter_sql(array_replace($plain, ['owner' => 'me']), 102)[1] === [102] && queue_filter_sql(array_replace($plain, ['owner' => 'none']), 102)[1] === [],
        'owner me binds the signed-in person; unassigned binds nothing');
    queue_check(queue_filter_sql($plain, 102) === ['', []], 'no filter adds nothing');

    // "Due soon" here and "at risk" on the tag are the same two hours.
    $due = static fn(int $seconds): array => ['status' => 'open', 'created_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        'sla_due_at' => gmdate('Y-m-d H:i:s', time() + $seconds), 'first_response_at' => null];
    queue_check(sla_info($due(QUEUE_DUE_SOON_SECONDS - 30))['state'] === 'at_risk' && sla_info($due(QUEUE_DUE_SOON_SECONDS + 30))['state'] === 'healthy',
        'the queue\'s due-soon window is the tag\'s at-risk window');

    // ---- the sentence under the title ----
    $count = static fn(int $open, int $unanswered, int $overdue): array => ['open' => $open, 'unanswered' => $unanswered, 'overdue' => $overdue];
    foreach ([
        [$count(0, 0, 0), 'No open tickets.'],
        [$count(1, 0, 0), '1 open ticket. It has had a first reply.'],
        [$count(12, 0, 0), '12 open tickets. Every one has had a first reply.'],
        [$count(12, 1, 0), '12 open tickets. 1 is waiting for a first reply.'],
        [$count(12, 1, 1), '12 open tickets. 1 is waiting for a first reply, and it is overdue.'],
        [$count(12, 3, 0), '12 open tickets. 3 are waiting for a first reply.'],
        [$count(1204, 3, 2), '1,204 open tickets. 3 are waiting for a first reply, 2 of them overdue.'],
    ] as [$counts, $sentence]) {
        queue_check(queue_summary($counts) === $sentence, "summary: $sentence");
    }

    // ---- the views bar ----
    $counts = ['needs' => 6, 'mine' => 4, 'unassigned' => 3, 'waiting' => 2, 'open' => 1204, 'resolved' => 5000, 'legacy' => 6204, 'everything' => 6204];
    queue_check(queue_total($plain, $counts) === 6 && queue_total(array_replace($plain, ['view' => 'resolved']), $counts) === 5000
        && queue_total(queue_request(['f' => 'all']), $counts) === 6204, 'the list on screen holds what its count says');
    $x = $dom(queue_tabs(queue_request(['f' => 'all']), $counts));
    queue_check($x->query('//a[@aria-current="page"]')->length === 0 && $x->query('//nav/a')->length === 6, 'an old link marks no view as current');
    $x = $dom(queue_tabs(array_replace($plain, ['view' => 'mine', 'client' => 12, 'page' => 3, 'after' => 44]), $counts));
    queue_check($texts($x, '//nav[@aria-label="Queue views"]/a') === ['Needs attention61', 'Mine42', 'Unassigned33', 'Waiting on client24', 'All open1,2045', 'Resolved6'],
        'six views in order, each with its count and key; Resolved shows no count');
    $hrefs = [];
    foreach ($x->query('//nav/a') as $a) $hrefs[] = $a->getAttribute('href');
    queue_check($hrefs === ['/?client=12', '/?view=mine&client=12', '/?view=unassigned&client=12', '/?view=waiting&client=12', '/?view=open&client=12', '/?view=resolved&client=12'],
        'a view link keeps the filters and starts at page 1');
    queue_check($texts($x, '//a[@aria-current="page"]') === ['Mine42'] && $x->query('//a[contains(@class,"pill-on")]')->length === 1, 'the current view is marked');

    // ---- the filters ----
    $clients = [['id' => 12, 'name' => 'Bayside <Realty>'], ['id' => 11, 'name' => 'Fixture Dental']];
    $team = [['id' => 103, 'full_name' => 'Jon Sato'], ['id' => 102, 'full_name' => 'Maya Reyes']];
    $x = $dom(queue_filters($plain, $clients, $team, 102));
    $form = $x->query('//form[@id="queue-filters"]')->item(0);
    queue_check($form instanceof DOMElement && strtolower($form->getAttribute('method')) === 'get' && $form->getAttribute('action') === '/', 'the filters are a GET form on the queue');
    queue_check($x->query('//input[@name="view"]')->length === 0, 'the default view is not repeated in the form');
    queue_check($texts($x, '//select[@name="client"]/option') === ['All', 'Bayside <Realty>', 'Fixture Dental'] && $x->query('//realty')->length === 0, 'client names are text');
    queue_check($texts($x, '//select[@name="priority"]/option') === ['Any', 'Urgent', 'High', 'Normal', 'Low'], 'priorities, most pressing first');
    queue_check($texts($x, '//select[@name="owner"]/option') === ['Anyone', 'Me', 'Unassigned', 'Jon Sato'], 'owners: anyone, me, unassigned, then teammates (not me twice)');
    queue_check($texts($x, '//select[@name="sort"]/option') === array_values(QUEUE_SORTS) && $texts($x, '//select[@name="sort"]/option[@selected]') === ['First response due'],
        'the order shows the view\'s own order as chosen');
    queue_check($texts($x, '//a') === [] && $texts($x, '//button[@type="submit"]') === ['Apply'], 'unfiltered: Apply for browsers without script, nothing to clear');
    $labels = [];
    foreach ($x->query('//select') as $control) {
        $labels[] = $texts($x, '//label[@for="' . $control->getAttribute('id') . '"]');
    }
    queue_check($labels === [['Client'], ['Priority'], ['Owner'], ['Sort']], 'every select is named by its own visible label, and by nothing else');

    $x = $dom(queue_filters(array_replace($full, ['view' => 'resolved', 'sort' => '']), $clients, $team, 102));
    queue_check($x->query('//input[@type="hidden"][@name="view"][@value="resolved"]')->length === 1, 'another view travels with the form');
    queue_check($texts($x, '//select[@name="client"]/option[@selected]') === ['Bayside <Realty>'] && $texts($x, '//select[@name="priority"]/option[@selected]') === ['Urgent']
        && $texts($x, '//select[@name="owner"]/option[@selected]') === ['Jon Sato'] && $texts($x, '//select[@name="sort"]/option[@selected]') === ['Last updated'],
        'the chosen filters and the view\'s order are shown as chosen');
    $clear = $x->query('//a')->item(0);
    queue_check($clear instanceof DOMElement && trim($clear->textContent) === 'Clear filters' && $clear->getAttribute('href') === '/?view=resolved', 'Clear filters keeps the view');

    // ---- the pager ----
    queue_check(queue_pager($plain, 0) === '', 'an empty view has no count line');
    queue_check($texts($dom(queue_pager($plain, 1)), '//nav') === ['1 ticket'] && $texts($dom(queue_pager($plain, 50)), '//nav') === ['50 tickets'], 'one page: just the count');
    queue_check(queue_pages(0) === 1 && queue_pages(50) === 1 && queue_pages(51) === 2 && queue_pages(131) === 3, 'fifty tickets to a page');
    $x = $dom(queue_pager(array_replace($full, ['page' => 1]), 131));
    queue_check($texts($x, '//span[contains(@class,"pager-count")]') === ['Showing 1 to 50 of 131'], 'page 1 says what it shows');
    queue_check($texts($x, '//span[@aria-disabled="true"]') === ['Previous'] && $x->query('//a[@rel="next"]')->item(0)?->getAttribute('href') === '/?view=open&client=12&priority=urgent&owner=103&sort=newest&page=2',
        'Previous is off on page 1 and Next keeps every choice');
    $x = $dom(queue_pager(array_replace($full, ['page' => 3]), 131));
    queue_check($texts($x, '//span[contains(@class,"pager-count")]') === ['Showing 101 to 131 of 131'] && $texts($x, '//span[@aria-disabled="true"]') === ['Next']
        && $x->query('//a[@rel="prev"]')->item(0)?->getAttribute('href') === '/?view=open&client=12&priority=urgent&owner=103&sort=newest&page=2', 'the last page turns Next off');
    queue_check($texts($dom(queue_pager(array_replace($plain, ['page' => 40]), 131)), '//span[contains(@class,"pager-count")]') === ['Showing 101 to 131 of 131'],
        'a page past the end is the last page');
    // With the rows in hand, each link names the ticket at this page's edge, so the next page can tell whether the list moved.
    $x = $dom(queue_pager(array_replace($full, ['page' => 2]), 131, [['id' => 310], ['id' => 77], ['id' => 205]]));
    queue_check($x->query('//a[@rel="prev"]')->item(0)?->getAttribute('href') === '/?view=open&client=12&priority=urgent&owner=103&sort=newest&before=310'
        && $x->query('//a[@rel="next"]')->item(0)?->getAttribute('href') === '/?view=open&client=12&priority=urgent&owner=103&sort=newest&page=3&after=205',
        'Previous names the first ticket on the page and Next the last');

    // ---- what the view leaves out ----
    $else = static fn(array $state, array $c): array => $texts($dom(queue_elsewhere($state, $c)), '//p[contains(@class,"queue-elsewhere")]');
    $hrefsOf = static function (array $state, array $c) use ($dom): array {
        $out = [];
        foreach ($dom(queue_elsewhere($state, $c))->query('//p/a') as $a) $out[] = $a->getAttribute('href');
        return $out;
    };
    $c = ['needs' => 6, 'mine' => 4, 'unassigned' => 3, 'waiting' => 2, 'open' => 15, 'resolved' => 1400, 'legacy' => 1415, 'everything' => 1415];
    queue_check($else($plain, $c) === ['Not in this view: 9 more in All open, 1,400 resolved.'] && $hrefsOf($plain, $c) === ['/?view=open', '/?view=resolved'],
        'the default view counts what it hides and links to it');
    queue_check($else(array_replace($plain, ['view' => 'open']), $c) === ['Not in this view: 1,400 resolved.'], 'All open hides only the resolved ones');
    queue_check($else(array_replace($plain, ['view' => 'resolved']), $c) === ['Not in this view: 15 open.']
        && $hrefsOf(array_replace($plain, ['view' => 'resolved', 'sort' => 'priority', 'page' => 4]), $c) === ['/?view=open&sort=priority'], 'Resolved counts the open ones');
    queue_check($else(array_replace($plain, ['view' => 'open']), array_replace($c, ['resolved' => 0, 'everything' => 15])) === []
        && queue_elsewhere(array_replace($plain, ['view' => 'open']), array_replace($c, ['resolved' => 0, 'everything' => 15])) === '', 'a view that hides nothing says nothing');
    $narrow = array_replace($plain, ['view' => 'mine', 'client' => 12]);
    $cn = array_replace($c, ['mine' => 1, 'open' => 3, 'resolved' => 20]);
    queue_check($else($narrow, $cn) === ['Not in this view: 2 more in All open, 20 resolved, 1,392 more hidden by the filters.']
        && $hrefsOf($narrow, $cn) === ['/?view=open&client=12', '/?view=resolved&client=12', '/?view=mine'], 'filters are counted too, and their link clears them');
    $legacy = queue_request(['f' => 'open']);
    queue_check($else($legacy, array_replace($c, ['legacy' => 5])) === ['Not in this view: 1,410 more with another status.']
        && $hrefsOf($legacy, array_replace($c, ['legacy' => 5])) === ['/?f=all'] && $else(queue_request(['f' => 'all']), $c) === [],
        'an old status link counts the other statuses; the old full list hides nothing');

    // ---- empty views say one thing and offer one way on ----
    foreach ([
        'needs' => ['Nothing needs you right now.', 'Open the full queue', '/?view=open'],
        'mine' => ['No open tickets are assigned to you.', 'See unassigned tickets', '/?view=unassigned'],
        'unassigned' => ['Every open ticket has an owner.', 'Open the full queue', '/?view=open'],
        'waiting' => ['No tickets are waiting on a client.', 'Open the full queue', '/?view=open'],
        'open' => ['No open tickets.', 'New ticket', '/ticket_new.php'],
        'resolved' => ['No resolved tickets yet.', 'Open the full queue', '/?view=open'],
    ] as $view => $expected) {
        queue_check(queue_empty(array_replace($plain, ['view' => $view])) === $expected, "the empty $view view");
    }
    queue_check(queue_empty(queue_request(['f' => 'all'])) === ['No tickets yet.', 'New ticket', '/ticket_new.php']
        && queue_empty(queue_request(['f' => 'waiting'])) === ['No tickets have the status Waiting.', 'Open the full queue', '/?view=open'], 'an empty old link says so');
    queue_check(queue_empty(array_replace($plain, ['view' => 'mine', 'priority' => 'low'])) === ['No tickets match these filters.', 'Clear filters', '/?view=mine'],
        'an empty filtered view offers to clear the filters');

    // ---- a row ----
    $row = ['id' => 101, 'tenant_id' => 1, 'client_id' => 11, 'status' => 'in_progress', 'priority' => 'high', 'channel' => 'email',
        'subject' => 'Front desk PC will not print', 'client_name' => 'Fixture <Dental>', 'created_at' => '2026-10-30 08:00:00',
        'sla_due_at' => '2026-10-30 16:00:00', 'first_response_at' => '2026-10-30 09:00:00',
        'assignee_id' => null, 'westy_owned' => false];
    foreach ([
        [['channel' => 'email', 'contact_name' => 'Dana Whitfield'], 'Fixture <Dental>, by email from Dana Whitfield'],
        [['channel' => 'phone', 'contact_name' => '', 'opener_name' => 'Luis Ortega'], 'Fixture <Dental>, by phone from Luis Ortega'],
        [['channel' => 'portal', 'opener_name' => 'Priya Anand'], 'Fixture <Dental>, from the portal by Priya Anand'],
        [['channel' => 'portal'], 'Fixture <Dental>, from the portal'],
        [['channel' => 'alert', 'opener_name' => 'Milepost'], 'Fixture <Dental>, from a Milepost alert'],
        [['channel' => 'email', 'contact_name' => 'Dana', 'opener_name' => 'Someone Else'], 'Fixture <Dental>, by email from Dana'],
    ] as [$with, $line]) {
        queue_check(ticket_origin(array_replace($row, $with)) === $line, "origin: $line");
    }
    // A list that is all one client's (the client page) leaves the client's name out of every row.
    foreach ([
        [['channel' => 'email', 'contact_name' => 'Dana Whitfield'], 'By email from Dana Whitfield'],
        [['channel' => 'phone'], 'By phone'],
        [['channel' => 'portal', 'opener_name' => 'Priya Anand'], 'From the portal by Priya Anand'],
        [['channel' => 'alert', 'opener_name' => 'Milepost'], 'From a Milepost alert'],
        [['channel' => 'carrier pigeon', 'contact_name' => 'Dana'], ''],
    ] as [$with, $line]) {
        queue_check(ticket_origin(array_replace($row, $with), false) === $line, "origin without the client: $line");
    }
    $x = $dom(ticket_row(array_replace($row, ['contact_name' => 'Dana <b>Whitfield</b>']), true, '', false));
    queue_check($texts($x, '//*[contains(@class,"trow-origin")]') === ['By email from Dana <b>Whitfield</b>'] && $x->query('//b')->length === 0
        && !str_contains($x->document->textContent, 'Fixture <Dental>'), 'a row without the client says how it came in and who asked, as text');
    $x = $dom(ticket_row(array_replace($row, ['contact_name' => 'Dana <b>Whitfield</b>'])));
    queue_check($texts($x, '//*[contains(@class,"trow-origin")]') === ['Fixture <Dental>, by email from Dana <b>Whitfield</b>'] && $x->query('//b')->length === 0, 'the origin line is text, never markup');
    queue_check($texts($x, '//*[contains(@class,"trow-flag")]') === [], 'no flag while we wrote last');
    $replied = array_replace($row, ['last_kind' => 'client', 'last_message_at' => '2026-10-30 11:00:00']);
    queue_check($texts($dom(ticket_row($replied)), '//*[contains(@class,"trow-flag")]') === ['Customer replied'], 'the customer answering after us is flagged');
    queue_check($texts($dom(ticket_row(array_replace($replied, ['first_response_at' => null]))), '//*[contains(@class,"trow-flag")]') === [], 'an opening message is not a reply');
    queue_check($texts($dom(ticket_row(array_replace($replied, ['status' => 'resolved']))), '//*[contains(@class,"trow-flag")]') === [], 'a resolved ticket is not flagged');
    queue_check($dom(ticket_row($replied))->query('//*[contains(@class,"trow-age")][@title="Last message"]')->length === 1
        && $dom(ticket_row($row))->query('//*[contains(@class,"trow-age")][@title="Opened"]')->length === 1, 'the last column is the last message when the row knows it');
    $x = $dom(ticket_head_row(false));
    queue_check($texts($x, '//*[contains(@class,"trow-head")]/span') === ['Priority', 'No.', 'Ticket', 'Status', 'Owner', 'First response', 'Updated'], 'the column names are words');
    $toggle = $dom(ticket_bulk_toggle())->query('//button')->item(0);
    queue_check($toggle instanceof DOMElement && $toggle->getAttribute('id') === 'bulk-toggle' && $toggle->getAttribute('aria-pressed') === 'false'
        && trim($toggle->textContent) === 'Select', 'Select starts off');

    // ---- the first-response tag in a row: one row for each of its seven states ----
    $at = static fn(int $seconds): string => gmdate('Y-m-d H:i:s', time() + $seconds);
    $unanswered = array_replace($row, ['status' => 'open', 'created_at' => $at(-9 * 86400), 'first_response_at' => null, 'contact_name' => 'Dana Whitfield']);
    $tagRows = [
        array_replace($unanswered, ['id' => 201, 'subject' => 'New starter needs a mailbox', 'sla_due_at' => $at(6 * 3600 + 600)]),
        array_replace($unanswered, ['id' => 202, 'subject' => 'Front desk PC will not print', 'sla_due_at' => $at(40 * 60 + 20)]),
        array_replace($unanswered, ['id' => 203, 'subject' => 'Disk space low on FD-01', 'sla_due_at' => $at(-2 * 3600 + 600)]),
        array_replace($unanswered, ['id' => 204, 'subject' => 'Shared calendar is missing', 'status' => 'in_progress',
            'sla_due_at' => $at(-8 * 86400), 'first_response_at' => $at(-8 * 86400 - 3600)]),
        array_replace($unanswered, ['id' => 205, 'subject' => 'Scanner saves to the wrong folder', 'status' => 'waiting',
            'sla_due_at' => $at(-8 * 86400), 'first_response_at' => $at(-8 * 86400 + 3 * 3600 - 600)]),
        array_replace($unanswered, ['id' => 207, 'subject' => 'Monitor flickers', 'status' => 'resolved',
            'sla_due_at' => $at(-8 * 86400), 'resolved_at' => $at(-7 * 86400)]),
        array_replace($unanswered, ['id' => 206, 'subject' => 'Printer offline again', 'status' => 'resolved', 'merged_into_id' => 202]),
    ];
    $tagList = '<div class="card list">' . ticket_head_row(false) . implode('', array_map('ticket_row', $tagRows)) . '</div>';
    $x = $dom($tagList);
    queue_check($texts($x, '//*[contains(@class,"trow-sla")]/*[contains(@class,"sla")]')
        === ['Due in 6 h', 'Due in 40 min', 'Overdue by 2 h', 'Answered in time', 'Answered 3 h late', 'Closed without a reply', 'Merged'],
        'the seven states read in plain words, one unit each');
    $tagStates = [];
    foreach ($x->query('//*[contains(@class,"trow-sla")]/*[contains(@class,"sla")]') as $node) $tagStates[] = $node->getAttribute('class');
    queue_check($tagStates === ['sla sla-healthy', 'sla sla-at_risk', 'sla sla-breached', 'sla sla-met', 'sla sla-late', 'sla sla-closed', 'sla sla-excluded'],
        'only the unanswered late ticket carries the urgent state; one answered late or closed without a reply does not');

    if (in_array('--client-rows', $argv, true)) {
        // The tickets app/tests/client_shared_directory_ui_test.php gives Harbor Dental, drawn by the
        // real row the way the client page asks for it: without the client's name.
        $soon = gmdate('Y-m-d H:i:s', time() + 48 * 60);
        $mine = [
            101 => ['subject' => 'Front desk PC will not print', 'status' => 'open', 'priority' => 'normal', 'channel' => 'phone', 'contact_name' => 'Dana Front Desk'],
            102 => ['subject' => 'Server room is hot', 'status' => 'in_progress', 'priority' => 'urgent', 'channel' => 'email', 'contact_name' => 'Dana Front Desk',
                'last_kind' => 'client', 'last_message_at' => '2026-10-30 11:00:00'],
            103 => ['subject' => 'New hygienist starts Monday', 'status' => 'waiting', 'priority' => 'high', 'channel' => 'portal', 'opener_name' => 'Priya Anand',
                'first_response_at' => null, 'sla_due_at' => $soon],
            199 => ['subject' => 'Answered long ago', 'status' => 'resolved', 'priority' => 'normal', 'channel' => 'email', 'contact_name' => 'Dana Front Desk'],
        ];
        for ($n = 1; $n <= 26; $n++) $mine[200 + $n] = ['subject' => "Resolved ticket $n", 'status' => 'resolved', 'priority' => 'normal', 'channel' => 'email'];
        $drawn = [];
        foreach ($mine as $id => $with) $drawn[$id] = ticket_row(array_replace($row, ['id' => $id, 'client_name' => 'Harbor Dental'], $with), true, '', false);
        echo json_encode($drawn, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    } elseif (in_array('--render', $argv, true)) {
        $state = array_replace($plain, ['view' => 'open']);
        $rows = [
            array_replace($row, ['id' => 101, 'status' => 'open', 'first_response_at' => null, 'contact_name' => 'Dana Whitfield']),
            array_replace($replied, ['id' => 102, 'subject' => 'New hire laptop setup', 'priority' => 'normal', 'channel' => 'portal', 'opener_name' => 'Priya Anand']),
            array_replace($row, ['id' => 103, 'subject' => 'Disk space low on FD-01', 'status' => 'waiting', 'priority' => 'low', 'channel' => 'alert']),
        ];
        $counts = ['needs' => 2, 'mine' => 1, 'unassigned' => 3, 'waiting' => 1, 'open' => 131, 'resolved' => 40, 'unanswered' => 1, 'overdue' => 1,
            'legacy' => 171, 'everything' => 171];
        echo json_encode([
            'summary' => queue_summary($counts),
            'toolbar' => '<div class="toolbar queue-toolbar">' . queue_tabs($state, $counts) . queue_filters($state, $clients, $team, 102) . ticket_bulk_toggle() . '</div>',
            'list' => '<div class="card list">' . ticket_head_row(true) . implode('', array_map('ticket_pick_row', $rows)) . '</div>',
            'pager' => queue_pager($state, 131, $rows),
            'elsewhere' => queue_elsewhere($state, $counts),
            'bar' => ticket_bulk_bar(queue_view_label($state), count($rows)),
            'tag_rows' => $tagList,
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    } else {
        echo "Ticket queue: {$checks} passed.\n";
    }
} finally {
    $actual = realpath($temp);
    $parent = realpath(sys_get_temp_dir());
    if (!$actual || !$parent || !str_starts_with($actual, $parent . DIRECTORY_SEPARATOR) || !str_starts_with(basename($actual), 'safeharbor-ticket-queue-')) {
        throw new RuntimeException('Fixture cleanup scope changed');
    }
    foreach (['render.php', 'auth.php', 'westy.php'] as $file) unlink($actual . '/' . $file);
    rmdir($actual);
}
