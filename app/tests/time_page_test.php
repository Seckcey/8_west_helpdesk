<?php
/**
 * The Time page's rows in plain words (lib/time_page.php), loaded with isolated dependency edges:
 * no config, no database. Every fact the page showed before is still shown: these checks read the
 * rows a person would see and what "History" holds.
 *
 *   php app/tests/time_page_test.php            runs the checks
 *   php app/tests/time_page_test.php --render   prints the sections built from the same helpers,
 *       for tools/shots/time-page.test.mjs and tools/shots/preview/time.html
 */
declare(strict_types=1);
$checks = 0;
function time_page_check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

require __DIR__ . '/../lib/time_page.php';

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
$names = [1 => 'Olive Owner', 11 => 'Maya <Reyes>'];
$entry = ['id' => 73, 'tid' => 106, 'subject' => 'Hygienist <account> setup', 'note' => 'Set up the <b>account</b>', 'minutes' => 20, 'billable' => 1,
    'approval_status' => 'pending', 'source' => 'timer', 'worked_at' => '2026-11-10 17:30:00', 'started_at' => '2026-11-10 17:10:00', 'ended_at' => '2026-11-10 17:30:00',
    'reviewed_by_user_id' => null, 'reviewed_at' => null, 'review_note' => '', 'corrects_time_entry_id' => null, 'replacement_entry_id' => null, 'replacement_status' => null,
    'tech_name' => 'Ana <Ruiz>', 'client_name' => 'Bluefin <Realty>', 'reviewer_name' => null];

// ---- small words ----
time_page_check(time_rfc3339('2026-08-29 10:00:00') === '2026-08-29T10:00:00Z' && time_rfc3339('') === '' && time_rfc3339(null) === '' && time_rfc3339('nonsense') === '',
    'a stored time is handed to the script in the form it expects, or not at all');
time_page_check(time_when('2026-11-10 17:30:00', $zone, $now) === '9:30 AM' && time_when('2026-11-09 00:46:59', $zone, $now) === 'Nov 8, 4:46 PM'
    && time_when(null, $zone, $now) === 'a time that was not recorded' && time_when('', $zone, $now) === 'a time that was not recorded',
    'times are on the viewer\'s clock, and a missing one says so');
time_page_check(time_amount(20, true) === '20 min, billable' && time_amount(80, false) === '1 h 20 min, not billable', 'an amount is minutes and whether it is billable');
time_page_check(time_person($names, 1) === 'Olive Owner' && time_person($names, '11') === 'Maya <Reyes>' && time_person($names, 99) === 'someone no longer on the team'
    && time_person($names, null) === 'someone no longer on the team', 'a person is a name, never a number');
time_page_check(strip_tags(time_tag_billable(true)) === 'Billable' && strip_tags(time_tag_billable(false)) === 'Not billable'
    && strip_tags(time_tag_status('pending')) === 'Waiting for review' && strip_tags(time_tag_status('approved')) === 'Approved'
    && strip_tags(time_tag_status('rejected')) === 'Rejected' && strip_tags(time_tag_status('bogus')) === 'Waiting for review', 'the tags are words in sentence case');
time_page_check(time_logged_lines($entry, $zone, $now) === ['Worked 9:30 AM.', 'Logged from the timer.', 'Measured from 9:10 AM to 9:30 AM.'],
    'how an entry was logged: when, from where, and its measured start and end');
time_page_check(time_logged_lines(array_replace($entry, ['source' => 'reply', 'started_at' => null, 'ended_at' => null]), $zone, $now)
    === ['Worked 9:30 AM.', 'Logged with a reply on the ticket.', 'No start and end were measured.']
    && time_logged_lines(array_replace($entry, ['source' => 'suggestion']), $zone, $now)[1] === 'Logged from a suggestion.'
    && time_logged_lines(array_replace($entry, ['source' => 'legacy']), $zone, $now)[1] === 'Logged before timers were recorded.'
    && time_logged_lines(array_replace($entry, ['source' => 'x']), $zone, $now)[1] === 'How it was logged is not recorded.', 'each source has its own words, and an unmeasured entry says so');

// ---- a person's own entry ----
$x = $dom(time_own_row($entry, null, $names, $zone, $now));
time_page_check($texts($x, '//*[' . $has('entry-min') . ']') === ['20 min'] && $texts($x, '//*[' . $has('entry-note') . ']') === ['Set up the <b>account</b>']
    && $x->query('//b')->length === 0, 'a row starts with how long and what, as text');
$link = $x->query('//a[' . $has('entry-ticket') . ']')->item(0);
time_page_check($link instanceof DOMElement && $link->getAttribute('href') === '/ticket.php?id=106' && trim($link->textContent) === '#106 Hygienist <account> setup', 'then its ticket, by number and title');
time_page_check($texts($x, '//*[' . $has('entry-tags') . ']/span') === ['Billable', 'Waiting for review'], 'then whether it is billable and where it stands');
time_page_check($x->query('//details[' . $has('entry-history') . '][not(@open)]/summary')->length === 1 && $texts($x, '//details/summary') === ['History']
    && $texts($x, '//details/ul/li') === ['Worked 9:30 AM.', 'Logged from the timer.', 'Measured from 9:10 AM to 9:30 AM.'], 'History is closed, and holds how the time was logged');
time_page_check(!str_contains(time_own_row($entry, null, $names, $zone, $now), 'UTC') && !str_contains(time_own_row($entry, null, $names, $zone, $now), 'user #'),
    'no UTC timestamps and no user numbers');

$approved = array_replace($entry, ['approval_status' => 'approved', 'reviewed_by_user_id' => 1, 'reviewed_at' => '2026-11-10 17:45:00']);
$x = $dom(time_own_row($approved, null, $names, $zone, $now));
time_page_check($texts($x, '//*[' . $has('entry-tags') . ']/span') === ['Billable', 'Approved']
    && in_array('Approved as 20 min, billable, by Olive Owner, 9:45 AM.', $texts($x, '//details/ul/li'), true), 'an approved entry says who approved it and when, by name');
$changed = ['version' => 2, 'effective_minutes' => 15, 'effective_billable' => 0, 'reason' => 'Goodwill <credit>'];
$x = $dom(time_own_row($approved, $changed, $names, $zone, $now));
time_page_check($texts($x, '//*[' . $has('entry-min') . ']') === ['15 min'] && $texts($x, '//*[' . $has('entry-tags') . ']/span') === ['Not billable', 'Approved'],
    'a changed approval shows what counts now');
time_page_check(in_array('Approved as 20 min, billable, by Olive Owner, 9:45 AM.', $texts($x, '//details/ul/li'), true)
    && in_array('Changed to 15 min, not billable (change 2). Reason: Goodwill <credit>', $texts($x, '//details/ul/li'), true) && $x->query('//credit')->length === 0,
    'and History keeps what was approved and why it changed');
$x = $dom(time_own_row($approved, $changed, [], $zone, $now));
time_page_check(in_array('Approved as 20 min, billable, by someone no longer on the team, 9:45 AM.', $texts($x, '//details/ul/li'), true), 'a reviewer who is gone is not a number either');

// A pending entry is never shown as changed, whatever is handed in.
$x = $dom(time_own_row($entry, $changed, $names, $zone, $now));
time_page_check($texts($x, '//*[' . $has('entry-min') . ']') === ['20 min'] && $texts($x, '//*[' . $has('entry-tags') . ']/span') === ['Billable', 'Waiting for review'],
    'only an approved entry can show a changed value');

// Rejected: the reviewer's note is on the row, and the way to correct it.
$rejected = array_replace($entry, ['approval_status' => 'rejected', 'reviewed_by_user_id' => 1, 'reviewed_at' => '2026-11-10 17:50:00', 'review_note' => 'Missing <customer> notes', 'billable' => 0]);
$x = $dom(time_own_row($rejected, null, $names, $zone, $now));
time_page_check($texts($x, '//*[' . $has('entry-review-note') . ']') === ['Review note: Missing <customer> notes'] && $x->query('//customer')->length === 0
    && $texts($x, '//*[' . $has('entry-tags') . ']/span') === ['Not billable', 'Rejected'], 'a rejected entry shows the reviewer\'s note on the row');
$button = $x->query('//button[' . $has('time-correct') . ']')->item(0);
time_page_check($button instanceof DOMElement && trim($button->textContent) === 'Correct and resubmit'
    && $button->getAttribute('data-rejected-entry-id') === '73' && $button->getAttribute('data-minutes') === '20' && $button->getAttribute('data-note') === 'Set up the <b>account</b>'
    && $button->getAttribute('data-billable') === '0' && $button->getAttribute('data-worked-at') === '2026-11-10T17:30:00Z'
    && $button->getAttribute('data-started-at') === '2026-11-10T17:10:00Z' && $button->getAttribute('data-ended-at') === '2026-11-10T17:30:00Z'
    && $button->getAttribute('data-measured') === '1', 'Correct and resubmit carries the rejected entry\'s exact facts, in UTC, for the script');
$unmeasured = $dom(time_own_row(array_replace($rejected, ['started_at' => null, 'ended_at' => null]), null, $names, $zone, $now))->query('//button[' . $has('time-correct') . ']')->item(0);
time_page_check($unmeasured->getAttribute('data-measured') === '0' && $unmeasured->getAttribute('data-started-at') === '' && $unmeasured->getAttribute('data-ended-at') === '',
    'an unmeasured entry says so to the script');
time_page_check(in_array('Rejected by Olive Owner, 9:50 AM.', $texts($x, '//details/ul/li'), true), 'History says who rejected it and when');
$x = $dom(time_own_row(array_replace($rejected, ['replacement_entry_id' => 80, 'replacement_status' => 'pending']), null, $names, $zone, $now));
time_page_check($x->query('//button[' . $has('time-correct') . ']')->length === 0
    && $texts($x, '//*[' . $has('entry-lineage') . ']') === ['A corrected entry was sent in its place. It is waiting for review.'], 'once corrected, it says where the new entry stands and offers no second correction');
$x = $dom(time_own_row(array_replace($entry, ['corrects_time_entry_id' => 51]), null, $names, $zone, $now));
time_page_check($texts($x, '//*[' . $has('entry-lineage') . ']') === ['Sent in place of rejected entry 51. The rejected entry is kept as it was.'], 'a replacement says which rejected entry it replaces, and that the old one is kept');

// ---- an entry waiting for review ----
$x = $dom(time_review_row($entry, $zone, $now));
time_page_check($x->query('//div[' . $has('review-row') . '][@data-time-entry-id="73"]')->length === 1
    && $texts($x, '//*[' . $has('entry-note') . ']') === ['Ana <Ruiz>, Bluefin <Realty>: Set up the <b>account</b>'] && $x->query('//ruiz')->length === 0,
    'a review row names the technician and the client, then the work, as text');
time_page_check($x->query('//details[' . $has('entry-history') . '][@open]')->length === 1
    && $texts($x, '//details/ul/li') === ['Worked 9:30 AM.', 'Logged from the timer.', 'Measured from 9:10 AM to 9:30 AM.'],
    'what a reviewer weighs is open on the row: when, how it was logged, and the measured start and end');
$buttons = [];
foreach ($x->query('//div[' . $has('review-actions') . ']/button') as $b) $buttons[] = [trim($b->textContent), $b->getAttribute('data-decision'), $b->getAttribute('class')];
time_page_check($buttons === [['Approve', 'approved', 'btn-primary time-review'], ['Reject', 'rejected', 'btn-secondary time-review']], 'Approve is the primary button, Reject the quiet one');
$x = $dom(time_review_row(array_replace($entry, ['corrects_time_entry_id' => 51, 'billable' => 0]), $zone, $now));
time_page_check(in_array('Sent in place of rejected entry 51.', $texts($x, '//details/ul/li'), true) && $texts($x, '//*[' . $has('entry-tags') . ']/span') === ['Not billable'],
    'a replacement says so to its reviewer');

// ---- an approved entry in the owners' and admins' list ----
$ledger = array_replace($approved, ['id' => 88, 'minutes' => 60, 'reviewer_name' => 'Olive Owner', 'review_note' => 'Checked with <Dana>']);
$billing = ['label' => 'Not sent to billing', 'action' => 'send', 'button' => 'Send to billing', 'invoice_url' => null];
$x = $dom(time_approved_row($ledger, [], $names, $billing, false, $zone, $now));
time_page_check($texts($x, '//*[' . $has('entry-min') . ']') === ['1 h'] && $texts($x, '//*[' . $has('entry-tags') . ']/span') === ['Billable']
    && $x->query('//*[' . $has('adjustment-effective') . ']')->length === 0, 'an approved entry that was never changed shows what was approved');
time_page_check($texts($x, '//details//*[' . $has('adjustment-original') . ']') === ['Original approval: 1 h, billable, by Olive Owner, 9:45 AM.'],
    'History starts with the original approval: how much, by whom, when');
$lines = $texts($x, '//details/ul/li');
time_page_check(in_array('Worked 9:30 AM.', $lines, true) && in_array('Logged from the timer.', $lines, true) && in_array('Measured from 9:10 AM to 9:30 AM.', $lines, true)
    && in_array('Review note: Checked with <Dana>', $lines, true) && in_array('Entry number 88.', $lines, true)
    && in_array('The original approval is never erased. To add time, log a new entry.', $lines, true) && $x->query('//dana')->length === 0,
    'then how it was logged, the review note, its number, and the standing rule');
$send = $x->query('//button[' . $has('time-billing') . ']')->item(0);
$change = $x->query('//button[' . $has('time-adjust') . ']')->item(0);
time_page_check($send instanceof DOMElement && trim($send->textContent) === 'Send to billing' && $send->getAttribute('data-entry-id') === '88' && $send->getAttribute('data-billing-action') === 'send'
    && $texts($x, '//*[@data-billing-label]') === ['Not sent to billing'], 'billing keeps its own words and its button');
time_page_check($change instanceof DOMElement && trim($change->textContent) === 'Change' && $change->getAttribute('data-entry-id') === '88'
    && $change->getAttribute('data-original-minutes') === '60' && $change->getAttribute('data-original-billable') === '1' && $change->getAttribute('data-effective-minutes') === '60'
    && $change->getAttribute('data-effective-billable') === '1' && $change->getAttribute('data-version') === '0', 'Change carries the original and the current value for the script');

$changes = [
    ['version' => 1, 'effective_minutes' => 50, 'effective_billable' => 1, 'reason' => 'Duplicate work', 'actor_user_id' => 11, 'created_at' => '2026-11-10 17:50:00'],
    ['version' => 2, 'effective_minutes' => 45, 'effective_billable' => 0, 'reason' => 'Customer <goodwill> credit', 'actor_user_id' => 12, 'created_at' => '2026-11-10 17:55:00'],
];
$billing = ['label' => 'In Coastmark · version 1 · adjustment waiting', 'action' => null, 'button' => '', 'invoice_url' => 'https://coastmark.example.test/invoices/7'];
$x = $dom(time_approved_row($ledger, $changes, $names, $billing, false, $zone, $now));
time_page_check($texts($x, '//*[' . $has('entry-min') . ']') === ['45 min'] && $texts($x, '//*[' . $has('entry-tags') . ']/span') === ['Not billable'],
    'a changed approval shows what counts now');
time_page_check($texts($x, '//*[' . $has('adjustment-effective') . ']') === ['Counts now as 45 min, not billable, after 2 changes. Approved as 1 h, billable.']
    && $texts($x, '//*[' . $has('adjustment-reason') . ']') === ['Reason for the latest change: Customer <goodwill> credit'] && $x->query('//goodwill')->length === 0,
    'and says on the row that it was changed, from what, and why');
time_page_check($texts($x, '//*[' . $has('entry-history-title') . ']') === ['Changes (2)']
    && $texts($x, '//li[' . $has('adjustment-history-item') . ']') === ['Change 1: 50 min, billable, by Maya <Reyes>, 9:50 AM.Reason: Duplicate work',
        'Change 2: 45 min, not billable, by someone no longer on the team, 9:55 AM.Reason: Customer <goodwill> credit'],
    'History lists every change in order: the value, who, when and why');
time_page_check($texts($x, '//details//*[' . $has('adjustment-original') . ']') === ['Original approval: 1 h, billable, by Olive Owner, 9:45 AM.'], 'the original approval is still there, unchanged');
$change = $x->query('//button[' . $has('time-adjust') . ']')->item(0);
time_page_check($change->getAttribute('data-original-minutes') === '60' && $change->getAttribute('data-effective-minutes') === '45' && $change->getAttribute('data-effective-billable') === '0'
    && $change->getAttribute('data-version') === '2', 'Change is told the latest value and its version');
time_page_check($texts($x, '//*[@data-billing-label]') === ['In Coastmark, version 1, adjustment waiting'] && $x->query('//button[' . $has('time-billing') . ']')->length === 0
    && $x->query('//a[@href="https://coastmark.example.test/invoices/7"][@rel="noopener"]')->length === 1, 'the billing state has no dots between its parts, and links to the invoice when there is one');
$x = $dom(time_approved_row($ledger, [], $names, ['label' => 'x', 'action' => 'send', 'button' => 'Send to billing', 'invoice_url' => null], true, $zone, $now));
time_page_check($texts($x, '//*[@data-billing-label]') === ['Coastmark is not connected'] && $x->query('//button[' . $has('time-billing') . ']')->length === 0
    && $x->query('//button[' . $has('time-adjust') . ']')->length === 1, 'when Coastmark cannot be read the row says so and offers no billing button');

// ---- the approved list's search ----
time_page_check(time_approved_search('#109') === ['text' => '#109', 'ticket' => 109, 'client' => ''] && time_approved_search(' 109 ') === ['text' => '109', 'ticket' => 109, 'client' => ''],
    'a number, with or without #, is a ticket');
time_page_check(time_approved_search('  Bay ') === ['text' => 'Bay', 'ticket' => 0, 'client' => 'Bay'] && time_approved_search('109 Main St') === ['text' => '109 Main St', 'ticket' => 0, 'client' => '109 Main St']
    && time_approved_search('0') === ['text' => '0', 'ticket' => 0, 'client' => '0'], 'anything else is part of a client\'s name');
time_page_check(time_approved_search('') === ['text' => '', 'ticket' => 0, 'client' => ''] && time_approved_search(['x']) === ['text' => '', 'ticket' => 0, 'client' => '']
    && time_approved_search(null)['text'] === '' && mb_strlen(time_approved_search(str_repeat('é', 300))['text']) === 100, 'nothing, or not text, searches for nothing; a long search is cut');
time_page_check(time_like('50%_off\\now') === '%50\\%\\_off\\\\now%' && time_like('Bay') === '%Bay%', 'a person\'s %, _ and \\ are ordinary characters in a client search');

if (in_array('--render', $argv, true)) {
    $mine = [
        array_replace($approved, ['id' => 71, 'tid' => 106, 'subject' => 'Hygienist account setup', 'note' => 'Hygienist account setup']),
        array_replace($entry, ['id' => 72, 'tid' => 102, 'subject' => 'Shared mailbox not syncing', 'note' => 'Mailbox fix and profile rebuild', 'minutes' => 35, 'source' => 'reply',
            'started_at' => null, 'ended_at' => null]),
    ];
    $earlier = [array_replace($rejected, ['id' => 51, 'tid' => 98, 'subject' => 'New starter laptop', 'note' => 'Laptop build', 'minutes' => 17, 'review_note' => 'Which laptop? Add the asset tag.',
        'started_at' => null, 'ended_at' => null, 'worked_at' => '2026-11-09 20:00:00', 'reviewed_at' => '2026-11-09 22:00:00'])];
    $review = [array_replace($entry, ['id' => 73, 'tid' => 102, 'subject' => 'Shared mailbox not syncing', 'note' => 'Mailbox fix and profile rebuild', 'minutes' => 35,
        'tech_name' => 'Ana Ruiz', 'client_name' => 'Bluefin Realty'])];
    $ledgerRows = [
        [array_replace($ledger, ['id' => 88, 'tid' => 109, 'subject' => 'SharePoint permissions', 'note' => 'SharePoint permission audit', 'tech_name' => 'Marcus Tran',
            'client_name' => 'Copper Kettle Bakery', 'review_note' => '']),
            [array_replace($changes[0], ['actor_user_id' => 1]), array_replace($changes[1], ['actor_user_id' => 1, 'reason' => 'Customer goodwill credit'])],
            ['label' => 'Not sent to billing', 'action' => 'send', 'button' => 'Send to billing', 'invoice_url' => null]],
        [array_replace($ledger, ['id' => 89, 'tid' => 110, 'subject' => 'Printer driver', 'note' => 'Driver reinstall', 'minutes' => 20, 'billable' => 0, 'tech_name' => 'Ana Ruiz',
            'client_name' => 'Harbor Dental', 'review_note' => '', 'started_at' => null, 'ended_at' => null, 'source' => 'suggestion']),
            [], ['label' => 'Not billable', 'action' => null, 'button' => '', 'invoice_url' => null]],
    ];
    $people = [1 => 'Olive Owner', 11 => 'Maya Reyes'];
    echo json_encode([
        'today' => implode('', array_map(static fn(array $e): string => time_own_row($e, null, $people, $zone, $now), $mine)),
        'earlier' => implode('', array_map(static fn(array $e): string => time_own_row($e, null, $people, $zone, $now), $earlier)),
        // A rejected entry that was measured by the timer, for the "Correct and resubmit" dialog's start and end.
        'measured' => time_own_row(array_replace($rejected, ['id' => 52, 'tid' => 98, 'subject' => 'New starter laptop', 'note' => 'Laptop imaging', 'review_note' => 'The end time looks wrong.']),
            null, $people, $zone, $now),
        'review' => implode('', array_map(static fn(array $e): string => time_review_row($e, $zone, $now), $review)),
        'ledger' => implode('', array_map(static fn(array $r): string => time_approved_row($r[0], $r[1], $people, $r[2], false, $zone, $now), $ledgerRows)),
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
} else {
    echo "Time page: {$checks} passed.\n";
}
