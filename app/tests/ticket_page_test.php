<?php
/**
 * The ticket page's head, message cards, Details column and Merge choices (lib/ticket_page.php),
 * and the link that tells a ticket which list it was opened from (lib/ticket_queue.php), loaded
 * with isolated dependency edges: no config, no database. The queries and the place in a list are
 * exercised against MySQL in ticket_queue_http_test.php.
 *
 *   php app/tests/ticket_page_test.php            runs the checks
 *   php app/tests/ticket_page_test.php --render   prints the page's parts built from the same
 *       helpers, for tools/shots/ticket-customer.test.mjs
 */
declare(strict_types=1);
$checks = 0;
function page_check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}

$temp = sys_get_temp_dir() . '/safeharbor-ticket-page-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
try {
    copy(__DIR__ . '/../lib/render.php', $temp . '/render.php');
    file_put_contents($temp . '/auth.php', '<?php require_once ' . var_export(realpath(__DIR__ . '/../lib/service_goals.php'), true) . ';'
        . ' function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,"UTF-8"); } function rel_time($v): string { return "2h"; }');
    file_put_contents($temp . '/westy.php', '<?php');
    require $temp . '/render.php';
    require __DIR__ . '/../lib/ticket_queue.php';
    require __DIR__ . '/../lib/ticket_page.php';

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
    $has = static fn(string $class): string => 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';

    // A fixed clock: Tuesday 10 November 2026, 18:00 UTC, which is 10:00 AM in Los Angeles.
    $now = strtotime('2026-11-10 18:00:00 UTC');
    $zone = 'America/Los_Angeles';
    $at = static fn(int $secondsAgo): string => gmdate('Y-m-d H:i:s', $now - $secondsAgo);

    // ---- times in words ----
    page_check(ticket_ago($at(20), $now) === '1 minute ago' && ticket_ago($at(20 * 60), $now) === '20 minutes ago'
        && ticket_ago($at(3600), $now) === '1 hour ago' && ticket_ago($at(5 * 3600 + 59 * 60), $now) === '5 hours ago',
        'minutes and hours are counted in words');
    page_check(ticket_ago($at(30 * 3600), $now) === 'yesterday' && ticket_ago($at(5 * 86400), $now) === '5 days ago'
        && ticket_ago($at(40 * 86400), $now, $zone) === 'on Oct 1, 2026', 'then yesterday, days, and after a month the date');
    page_check(ticket_ago('not a time', $now) === '' && ticket_ago($at(-600), $now) === '1 minute ago', 'a bad or future time never prints nonsense');
    page_check(ticket_clock($at(18 * 60), $zone, $now) === '9:42 AM', 'a message from today shows the desk\'s clock time');
    page_check(ticket_clock('2026-11-04 02:15:00', $zone, $now) === 'Nov 3, 6:15 PM', 'an older one adds the date, on the desk\'s calendar day');
    page_check(ticket_clock('2025-12-31 20:00:00', $zone, $now) === 'Dec 31, 2025, 12:00 PM', 'and the year when it is another year');
    page_check(ticket_clock($at(60), 'Not/AZone', $now) === '5:59 PM', 'an unknown time zone falls back to UTC');
    page_check(ticket_minutes_label(0) === '0 min' && ticket_minutes_label(12) === '12 min' && ticket_minutes_label(60) === '1 h'
        && ticket_minutes_label(80) === '1 h 20 min', 'time logged reads in minutes, then hours and minutes');
    page_check(ticket_initials('Dana Whitfield') === 'DW' && ticket_initials('  maya ') === 'M' && ticket_initials('Ana María de la Cruz') === 'AC'
        && ticket_initials('') === '', 'initials come from the first and last name');

    // ---- the link that names a list, and reading it back ----
    $plain = ['view' => 'needs', 'status' => '', 'client' => 0, 'priority' => '', 'owner' => '', 'sort' => '', 'page' => 1, 'after' => 0, 'before' => 0];
    page_check(queue_ticket_query($plain) === '&in=needs', 'a row in Needs attention says so');
    $filtered = array_replace($plain, ['view' => 'open', 'client' => 12, 'priority' => 'high', 'owner' => 'me', 'sort' => 'newest', 'page' => 3, 'after' => 9050]);
    page_check(queue_ticket_query($filtered) === '&in=open&client=12&priority=high&owner=me&sort=newest', 'the filters and order go along; the page does not');
    page_check(queue_ticket_state(['id' => '7', 'in' => 'open', 'client' => '12', 'priority' => 'high', 'owner' => 'me', 'sort' => 'newest'])
        === array_replace($filtered, ['page' => 1, 'after' => 0]), 'and the ticket page reads the same list back');
    $legacy = queue_request(['f' => 'waiting']);
    page_check(queue_ticket_query($legacy) === '&in=all&f=waiting' && queue_ticket_state(['in' => 'all', 'f' => 'waiting']) === $legacy, 'an old ?f= list goes along too');
    page_check(queue_ticket_state(['id' => '7']) === null && queue_ticket_state(['in' => 'bogus']) === null && queue_ticket_state(['in' => ['open']]) === null
        && queue_ticket_state(['in' => 'all', 'f' => 'bogus']) === null && queue_ticket_state(['view' => 'open']) === null,
        'a link that names no list, or one that does not exist, has no place');
    page_check(queue_ticket_state(['in' => 'mine', 'f' => 'resolved', 'view' => 'open', 'page' => '9', 'after' => '4'])
        === array_replace($plain, ['view' => 'mine']), 'only "in" chooses the list');

    $row = ['id' => 101, 'tenant_id' => 1, 'client_id' => 11, 'status' => 'open', 'priority' => 'high', 'channel' => 'email',
        'subject' => 'Front desk PC will not print', 'client_name' => 'Fixture <Dental>', 'created_at' => $at(1200), 'sla_due_at' => gmdate('Y-m-d H:i:s', time() + 40 * 60 + 20),
        'first_response_at' => null, 'resolved_at' => null, 'merged_into_id' => null, 'has_merged_sources' => 0,
        'assignee_id' => null, 'westy_owned' => false, 'contact_name' => 'Dana Whitfield'];
    $a = $dom(ticket_row($row, true, queue_ticket_query($filtered)))->query('//a')->item(0);
    page_check($a->getAttribute('href') === '/ticket.php?id=101&in=open&client=12&priority=high&owner=me&sort=newest', 'a queue row links to the ticket with its list');
    page_check($dom(ticket_row($row))->query('//a')->item(0)->getAttribute('href') === '/ticket.php?id=101'
        && $dom(ticket_pick_row($row, '&in=needs'))->query('//a')->item(0)->getAttribute('href') === '/ticket.php?id=101&in=needs',
        'a row elsewhere links plainly; a row with a checkbox carries the list too');

    // ---- the sentence under the title ----
    $ticket = array_replace($row, ['id' => 1041, 'channel' => 'phone', 'subject' => 'Can\'t print to the <front> office printer']);
    $sentence = ticket_subtitle($ticket, 'Dana Whitfield', $now);
    $x = $dom('<p>' . $sentence . '</p>');
    page_check($texts($x, '//p') === ['#1041 for Fixture <Dental>, opened 20 minutes ago by phone from Dana Whitfield'], 'the subtitle is one sentence');
    page_check($x->query('//p/a[@href="/client.php?id=11"]')->length === 1 && $texts($x, '//p/a') === ['Fixture <Dental>'], 'with the client as its link, escaped');
    page_check($texts($dom('<p>' . ticket_subtitle(array_replace($ticket, ['channel' => 'portal']), 'Priya <b>', $now) . '</p>'), '//p')
        === ['#1041 for Fixture <Dental>, opened 20 minutes ago from the portal by Priya <b>'], 'a portal request names who asked, as text');
    page_check($texts($dom('<p>' . ticket_subtitle(array_replace($ticket, ['channel' => 'alert']), 'Dana', $now) . '</p>'), '//p')
        === ['#1041 for Fixture <Dental>, opened 20 minutes ago from a Milepost alert'], 'an alert names nobody');
    page_check($texts($dom('<p>' . ticket_subtitle(array_replace($ticket, ['channel' => 'carrier-pigeon']), '', $now) . '</p>'), '//p')
        === ['#1041 for Fixture <Dental>, opened 20 minutes ago'], 'an unknown channel is left out, never printed raw');

    // ---- the head ----
    $place = ['label' => 'Needs attention', 'query' => '&in=needs', 'back' => '/', 'position' => 2, 'total' => 6, 'at' => 2,
        'prev' => '/ticket.php?id=1036&in=needs', 'next' => '/ticket.php?id=1044&in=needs'];
    $x = $dom(ticket_page_head($ticket, $place, $sentence, false));
    page_check($texts($x, '//*[' . $has('ticket-pos') . ']') === ['2 of 6'], 'opened from a list, the head says where the ticket stands in it');
    $prev = $x->query('//a[@data-step="prev"]')->item(0);
    $next = $x->query('//a[@data-step="next"]')->item(0);
    page_check($prev instanceof DOMElement && $prev->getAttribute('href') === '/ticket.php?id=1036&in=needs' && $prev->getAttribute('aria-label') === 'Previous ticket in Needs attention'
        && $next instanceof DOMElement && $next->getAttribute('href') === '/ticket.php?id=1044&in=needs' && $next->getAttribute('aria-label') === 'Next ticket in Needs attention',
        'with a named link to the ticket before it and the one after');
    page_check(str_contains($prev->getAttribute('title'), '([)') && str_contains($next->getAttribute('title'), '(])'), 'each says its key');
    page_check($x->query('//a[' . $has('backlink') . '][@href="/"]')->length === 1 && $texts($x, '//a[' . $has('backlink') . ']') === ['Queue'], 'the way back is a link named Queue, with no arrow typed into it');
    page_check($x->query('//*[@id="presence"]')->length === 1 && $x->query('//button[@id="merge-btn"][@data-id="1041"]')->length === 1
        && $texts($x, '//button[@id="merge-btn"]') === ['Merge'] && $x->query('//*[' . $has('ticket-actions') . ']/button[@id="resolve-btn"]')->length === 1,
        'presence, Merge and Resolve keep their ids');
    page_check($texts($x, '//h1') === ['Can\'t print to the <front> office printer'] && $x->query('//h1/*')->length === 0, 'the title is text');

    $x = $dom(ticket_page_head($ticket, array_replace($place, ['position' => 1, 'at' => 1, 'prev' => null]), $sentence, false));
    page_check($x->query('//a[@data-step="prev"]')->length === 0 && $x->query('//span[' . $has('ticket-step-off') . '][@aria-hidden="true"]')->length === 1
        && $x->query('//a[@data-step="next"]')->length === 1, 'the first ticket has no link before it, and the last none after');
    $x = $dom(ticket_page_head($ticket, array_replace($place, ['position' => 0, 'total' => 5]), $sentence, false));
    page_check($texts($x, '//*[' . $has('ticket-pos') . ']') === ['No longer in Needs attention'] && $x->query('//a[@data-step]')->length === 2,
        'a ticket that has left its list says so and still steps to the ones that were beside it');
    $x = $dom(ticket_page_head($ticket, null, $sentence, false));
    page_check($x->query('//*[' . $has('ticket-pos') . ']')->length === 0 && $x->query('//*[' . $has('ticket-step') . ']')->length === 0
        && $x->query('//a[' . $has('backlink') . '][@href="/"]')->length === 1, 'opened without a list there is no counter and no arrows, only the way back');
    $x = $dom(ticket_page_head($ticket, null, $sentence, true));
    page_check($x->query('//button[@id="merge-btn"]')->length === 0 && $x->query('//button[@id="resolve-btn"]')->length === 0 && $x->query('//*[@id="presence"]')->length === 1,
        'a merged ticket offers neither Merge nor Resolve');

    // ---- a notice under the head ----
    $x = $dom(ticket_callout('caution', 'Possible duplicate.', 'The same contact opened <a class="link" href="/ticket.php?id=112">#112 Wi-Fi</a>.',
        '<button type="button" class="btn-secondary ticket-callout-btn" id="merge-dupe-btn" data-src="1041" data-dst="112">Merge into #112</button>'));
    page_check($x->query('//div[' . $has('ticket-callout-caution') . ']/p/strong')->length === 1 && $texts($x, '//p/strong') === ['Possible duplicate.']
        && $x->query('//div/p/a[@href="/ticket.php?id=112"]')->length === 1, 'the duplicate notice has a bold first line and a link');
    page_check($x->query('//div[' . $has('ticket-callout') . ']/*[last()][self::button][@id="merge-dupe-btn"][@data-src="1041"][@data-dst="112"]')->length === 1,
        'and its button is the last thing in it');

    // ---- message cards ----
    $messages = [
        ['id' => 1, 'kind' => 'client', 'author_name' => 'Dana Whitfield', 'body' => "The front office printer shows offline.\n<b>Since 9.</b>", 'created_at' => $at(18 * 60)],
        ['id' => 2, 'kind' => 'note', 'author_name' => 'Maya Reyes', 'body' => 'Print spooler is stopped on FD-01.', 'created_at' => $at(10 * 60)],
        ['id' => 3, 'kind' => 'tech', 'author_name' => 'Maya Reyes', 'body' => 'I restarted the print service. Please try again.', 'created_at' => $at(4 * 60)],
        ['id' => 4, 'kind' => 'system', 'author_name' => 'Safeharbor', 'body' => 'Merged #1030 (Printer offline) into this ticket', 'created_at' => $at(2 * 60)],
    ];
    $cards = implode('', array_map(static fn(array $m): string => ticket_message($m, '', $zone, $now), $messages));
    $x = $dom($cards);
    page_check($x->query('//article[' . $has('msg') . '][' . $has('card') . ']')->length === 3, 'every message is a card');
    page_check($texts($x, '//article//*[' . $has('msg-role') . ']') === ['Customer', 'Internal note', 'Support'], 'the role is a word: Customer, Internal note, Support');
    page_check($texts($x, '//article//*[' . $has('msg-author') . ']') === ['Dana Whitfield', 'Maya Reyes', 'Maya Reyes']
        && $texts($x, '//article//*[' . $has('msg-avatar') . ']') === ['DW', 'MR', 'MR'], 'with the writer\'s name and initials');
    page_check($x->query('//article[' . $has('msg-client') . ']')->length === 1 && $x->query('//article[' . $has('msg-note') . ']')->length === 1
        && $x->query('//article[' . $has('msg-tech') . ']')->length === 1, 'a reply from the team and an internal note keep their own tint');
    page_check($texts($x, '//article//time') === ['9:42 AM', '9:50 AM', '9:56 AM'], 'the time is the clock time');
    $time = $x->query('//article//time')->item(0);
    page_check($time->getAttribute('title') === '18 minutes ago' && $time->getAttribute('datetime') === gmdate('Y-m-d\TH:i:s\Z', $now - 18 * 60),
        'with how long ago on hover, and the exact time for machines');
    page_check($x->query('//article//b')->length === 0 && str_contains($texts($x, '//article//p[' . $has('msg-body') . ']')[0], '<b>Since 9.</b>'), 'a message body is text, never markup');
    page_check($texts($x, '//div[' . $has('msg-system') . ']') === ['Merged #1030 (Printer offline) into this ticket 9:58 AM'], 'a system line stays a quiet line, with its time');
    $x = $dom(ticket_message(['id' => 5, 'kind' => 'tech', 'author_name' => '', 'body' => 'Hello', 'created_at' => $at(60)], '<div class="att-row">files</div>', $zone, $now));
    page_check($texts($x, '//*[' . $has('msg-author') . ']') === ['Support'] && $texts($x, '//*[' . $has('msg-avatar') . ']') === ['?']
        && $x->query('//article/div[' . $has('att-row') . ']')->length === 1, 'a message with no name falls back to its role, and keeps its files');

    // ---- the client's other open tickets, as Merge choices ----
    $others = [];
    foreach ([[1036, 'High memory use on FD-02', 'open', 'email', 5 * 86400, -5 * 86400, 'Dana Whitfield'],
        [1038, 'New starter needs a <mailbox>', 'in_progress', 'portal', 3 * 3600, 6 * 3600, 'Priya Anand'],
        [1040, 'Disk space low on FD-01', 'waiting', 'alert', 86400 + 3600, 86400, 'Dana Whitfield']] as [$id, $subject, $status, $channel, $opened, $due, $who]) {
        $others[] = array_replace($row, ['id' => $id, 'subject' => $subject, 'status' => $status, 'channel' => $channel, 'created_at' => $at($opened),
            'sla_due_at' => gmdate('Y-m-d H:i:s', time() + $due + 20), 'contact_name' => $who]);
    }
    page_check(ticket_merge_choices($others, $now) === [
        ['id' => 1036, 'subject' => 'High memory use on FD-02', 'summary' => 'Open, opened 5 days ago by email from Dana Whitfield.'],
        ['id' => 1038, 'subject' => 'New starter needs a <mailbox>', 'summary' => 'In progress, opened 3 hours ago from the portal by Priya Anand.'],
        ['id' => 1040, 'subject' => 'Disk space low on FD-01', 'summary' => 'Waiting, opened yesterday from a Milepost alert.'],
    ], 'each choice has its number, its title and one line about it');
    $many = array_map(static fn(int $n): array => array_replace($others[0], ['id' => 2000 + $n]), range(1, TICKET_OTHER_OPEN_LIMIT + 1));
    page_check(count(ticket_merge_choices($many, $now)) === TICKET_OTHER_OPEN_LIMIT, 'the dialog never lists more than its limit');

    // ---- Details ----
    $menus = ticket_menu_button('status', 1041, 'Status', 'S', 'data-chip', status_chip('open'))
        . ticket_menu_button('priority', 1041, 'Priority', 'P', 'data-pri', priority_glyph('high', true))
        . ticket_menu_button('assignee', 1041, 'Assigned to', 'T', 'data-assignee', '<span class="assignee-cell">' . avatar(null, 22) . '<span class="assignee-name">Unassigned</span></span>');
    $parts = ['menus' => $menus, 'tag' => sla_lamp($ticket), 'plan' => 'Standard plan: first reply within 8 hours.', 'minutes' => 12,
        'contact' => ['name' => 'Dana <Whitfield>', 'email' => 'dana@example.test', 'portal' => false],
        'note' => "Call the office line before 9.\n<i>Ask for Dana.</i>", 'others' => $others, 'device_url' => 'https://milepost.example.test/devices?client=11'];
    $details = ticket_details($ticket, $parts);
    $x = $dom($details);
    page_check($x->query('//aside[' . $has('rail') . ']//button[' . $has('menu-btn') . ']')->length === 3 && $x->query('//*[@data-sla]/*[' . $has('sla-at_risk') . ']')->length === 1,
        'Details keeps the three menus and the first-response tag with its repaint hook');
    page_check($texts($x, '//p[' . $has('rail-note') . ']') === ['Standard plan: first reply within 8 hours.'], 'the plan is one plain sentence');
    page_check($texts($x, '//*[' . $has('rail-minutes') . ']') === ['12 min'] && $x->query('//button[@id="timer-btn"][@data-id="1041"]')->length === 1
        && $texts($x, '//*[' . $has('rail-time') . ']/*[' . $has('rail-k') . ']') === ['Your time'], 'the person\'s own time is beside the timer button, which keeps its id');
    page_check($texts($x, '//p[' . $has('rail-person') . ']') === ['Dana <Whitfield>'] && $texts($x, '//*[' . $has('rail-email') . ']') === ['dana@example.test'],
        'the contact\'s name and address are text');
    $copy = $x->query('//button[@data-copy]')->item(0);
    page_check($copy instanceof DOMElement && $copy->getAttribute('data-copy') === 'dana@example.test' && trim($copy->textContent) === 'Copy'
        && $copy->getAttribute('aria-label') === 'Copy Dana <Whitfield>\'s email address', 'Copy carries the address and says whose it is');
    page_check($x->query('//p[' . $has('rail-line') . ']/a[@href="/client.php?id=11"]')->length === 1 && $texts($x, '//p[' . $has('rail-line') . ']/a[@href="/client.php?id=11"]') === ['Open client'],
        'the client has an Open client link');
    page_check($x->query('//p[' . $has('rail-client-note') . ']//i')->length === 0 && str_contains($texts($x, '//p[' . $has('rail-client-note') . ']')[0], 'Call the office line before 9. <i>Ask for Dana.</i>')
        && str_starts_with($texts($x, '//p[' . $has('rail-client-note') . ']')[0], 'Note about Fixture <Dental>'), 'the client\'s note is shown as text, and says whose note it is');
    page_check($texts($x, '//*[' . $has('rail-others') . ']/h3') === ['Other open tickets for Fixture <Dental> (3)'], 'the other open tickets are counted');
    $links = [];
    foreach ($x->query('//a[' . $has('rail-ticket') . ']') as $node) $links[] = $node->getAttribute('href');
    page_check($links === ['/ticket.php?id=1036', '/ticket.php?id=1038', '/ticket.php?id=1040'], 'and each is a link to that ticket');
    page_check($texts($x, '//a[' . $has('rail-ticket') . ']//*[' . $has('sla') . ']') === ['Overdue by 5 d', 'Due in 6 h', 'Due in 24 h'], 'with the same first-response tag as the queue');
    page_check($x->query('//a[' . $has('rail-ticket') . ']//mailbox')->length === 0 && str_contains($texts($x, '//a[' . $has('rail-ticket') . ']')[1], '#1038 New starter needs a <mailbox>'),
        'their titles are text');
    $device = $x->query('//a[@href="https://milepost.example.test/devices?client=11"]')->item(0);
    page_check($device instanceof DOMElement && trim($device->textContent) === 'Open in Milepost' && $device->getAttribute('rel') === 'noopener', 'a linked client\'s computers open in Milepost');
    page_check($x->query('//*[contains(., "Team")][' . $has('rail-label') . ']')->length === 0 && $texts($x, '//*[' . $has('rail-label') . ']') === ['Details'], 'there is one heading, Details, and no Team or Merge section');

    $x = $dom(ticket_details($ticket, array_replace($parts, ['contact' => null, 'note' => '  ', 'others' => [], 'device_url' => null, 'minutes' => 0])));
    page_check($texts($x, '//*[' . $has('rail-customer') . ']/p[' . $has('rail-quiet') . ']') === ['No contact is attached to this ticket.'] && $x->query('//button[@data-copy]')->length === 0,
        'a ticket with nobody attached says so and offers no Copy');
    page_check($x->query('//p[' . $has('rail-client-note') . ']')->length === 0 && $x->query('//a[contains(., "Milepost")]')->length === 0, 'no note and no link when there is none');
    page_check($texts($x, '//*[' . $has('rail-others') . ']/h3') === ['Other open tickets for Fixture <Dental> (0)']
        && $texts($x, '//*[' . $has('rail-others') . ']/p') === ['None. This is their only open ticket.'], 'no other open ticket is said in words');
    $x = $dom(ticket_details($ticket, array_replace($parts, ['contact' => ['name' => 'Priya Anand', 'email' => '', 'portal' => true]])));
    page_check($texts($x, '//*[' . $has('rail-customer') . ']/p[' . $has('rail-quiet') . ']') === ['Asked from the portal.'] && $x->query('//button[@data-copy]')->length === 0,
        'a portal requester is named, with no address to copy');
    $x = $dom(ticket_details($ticket, array_replace($parts, ['contact' => ['name' => 'Dana', 'email' => '', 'portal' => false]])));
    page_check($texts($x, '//*[' . $has('rail-customer') . ']/p[' . $has('rail-quiet') . ']') === ['No email address on file.'], 'a contact without an address says so');
    $x = $dom(ticket_details($ticket, array_replace($parts, ['others' => $many])));
    page_check($x->query('//a[' . $has('rail-ticket') . ']')->length === TICKET_OTHER_OPEN_SHOWN
        && $texts($x, '//*[' . $has('rail-others') . ']/h3') === ['Other open tickets for Fixture <Dental> (' . TICKET_OTHER_OPEN_LIMIT . '+)']
        && $x->query('//*[' . $has('rail-others') . ']//a[@href="/?view=open&client=11"]')->length === 1, 'a long list shows the first few and links to the rest in the queue');
    $x = $dom(ticket_details($ticket, array_replace($parts, ['others' => null])));
    page_check($x->query('//*[' . $has('rail-others') . ']')->length === 0, 'a merged ticket lists no other tickets');

    if (in_array('--render', $argv, true)) {
        $dupe = ticket_callout('caution', 'Possible duplicate.',
            'The same contact opened <a class="link" href="/ticket.php?id=1036">#1036 High memory use on FD-02</a> within 48 hours of this one.',
            '<button type="button" class="btn-secondary ticket-callout-btn" id="merge-dupe-btn" data-src="1041" data-dst="1036" data-subject="High memory use on FD-02">Merge into #1036</button>');
        // The same page with everyday names, for the hand-opened preview (tools/shots/preview/ticket.html).
        $real = array_replace($ticket, ['client_name' => 'Bayside Realty', 'subject' => 'Can\'t print to the front office printer']);
        $realOthers = [
            array_replace($others[0], ['client_name' => 'Bayside Realty']),
            array_replace($others[1], ['client_name' => 'Bayside Realty', 'subject' => 'New starter needs a mailbox']),
        ];
        $realMessages = [
            ['id' => 1, 'kind' => 'client', 'author_name' => 'Dana Whitfield', 'created_at' => $at(20 * 60),
                'body' => 'The front office printer shows offline on my computer and on Luis\'s. We have closing papers to print at 2 pm.'],
            ['id' => 2, 'kind' => 'system', 'author_name' => 'Safeharbor', 'created_at' => $at(19 * 60), 'body' => 'Merged #1030 (Printer offline) into this ticket'],
            ['id' => 3, 'kind' => 'note', 'author_name' => 'Maya Reyes', 'created_at' => $at(8 * 60),
                'body' => 'Print spooler is stopped on FRONTDESK-02. Restarting it from Milepost before I reply.'],
            ['id' => 4, 'kind' => 'tech', 'author_name' => 'Maya Reyes', 'created_at' => $at(2 * 60),
                'body' => 'Hi Dana, I restarted the print service on the front desk computer. Could you try printing one page and tell me if it comes out?'],
        ];
        $preview = [
            'head' => ticket_page_head($real, $place, ticket_subtitle($real, 'Dana Whitfield', $now), false),
            'dupe' => ticket_callout('caution', 'Possible duplicate.',
                'The same contact opened <a class="link" href="#">#1036 High memory use on FD-02</a> within 48 hours of this one.',
                '<button type="button" class="btn-secondary ticket-callout-btn" id="merge-dupe-btn" data-src="1041" data-dst="1036" data-subject="High memory use on FD-02">Merge into #1036</button>'),
            'messages' => implode('', array_map(static fn(array $m): string => ticket_message($m,
                $m['id'] === 1 ? '<div class="att-row"><a class="att-chip" href="#">printer-error.png <span class="att-size">84 KB</span></a></div>' : '', $zone, $now), $realMessages)),
            'details' => ticket_details($real, array_replace($parts, ['contact' => ['name' => 'Dana Whitfield', 'email' => 'dana@baysiderealty.example', 'portal' => false],
                'note' => 'Call the office line before 9.', 'others' => $realOthers, 'device_url' => '#'])),
            'merge' => ['ticket' => ['id' => 1041, 'subject' => $real['subject']], 'client' => 'Bayside Realty', 'choices' => ticket_merge_choices($realOthers, $now), 'more' => false],
        ];
        echo json_encode([
            'preview' => $preview,
            'menus' => ticket_menu_choices([]),
            'head' => ticket_page_head($ticket, $place, $sentence, false),
            'head_first' => ticket_page_head($ticket, array_replace($place, ['position' => 1, 'at' => 1, 'prev' => null]), $sentence, false),
            'head_plain' => ticket_page_head($ticket, null, $sentence, false),
            'dupe' => $dupe,
            'messages' => $cards,
            'details' => $details,
            'details_alone' => ticket_details($ticket, array_replace($parts, ['others' => [], 'note' => '', 'device_url' => null])),
            'merge' => ['ticket' => ['id' => 1041, 'subject' => $ticket['subject']], 'client' => 'Fixture <Dental>', 'choices' => ticket_merge_choices($others, $now), 'more' => false],
            'merge_alone' => ['ticket' => ['id' => 1041, 'subject' => $ticket['subject']], 'client' => 'Fixture <Dental>', 'choices' => [], 'more' => false],
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    } else {
        echo "Ticket page: {$checks} passed.\n";
    }
} finally {
    $actual = realpath($temp);
    $parent = realpath(sys_get_temp_dir());
    if (!$actual || !$parent || !str_starts_with($actual, $parent . DIRECTORY_SEPARATOR) || !str_starts_with(basename($actual), 'safeharbor-ticket-page-')) {
        throw new RuntimeException('Fixture cleanup scope changed');
    }
    foreach (['render.php', 'auth.php', 'westy.php'] as $file) unlink($actual . '/' . $file);
    rmdir($actual);
}
