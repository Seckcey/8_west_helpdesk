<?php
/**
 * A request in a list (Home and Support Requests): one sentence about what is happening, and its tag.
 *
 * Synthetic data only. No database, no settings file, no sign-in.
 * Run:  php app/tests/portal_request_list_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
ob_start();
function portal_csrf_token(): string { return str_repeat('c', 64); }
function portal_action_nonce(string $purpose, ?int $now = null): string { return hash('sha256', $purpose); }
require_once __DIR__ . '/../lib/portal_data.php';
require_once __DIR__ . '/../lib/portal_render.php';

$checks = 0;
$failures = 0;
function list_check(string $name, bool $ok): void
{
    global $checks, $failures;
    $checks++;
    if (! $ok) $failures++;
    echo ($ok ? 'ok ' : 'FAIL ') . $checks . ' - ' . $name . "\n";
}

$GLOBALS['portal_view_preferences'] = ['time_zone' => 'America/New_York', 'time_format' => '12h', 'date_format' => 'mdy', 'locale' => 'en-US'];
$now = strtotime('2026-11-10 17:00:00 UTC');   // Tuesday, noon in New York
$at = static fn(int $minutesAgo): string => gmdate('Y-m-d H:i:s', $now - $minutesAgo * 60);

/* ---- How long ago ------------------------------------------------------------------------------ */
list_check('a time is said the way a person says it, with whole words',
    portal_ago($at(0), $now) === 'just now' && portal_ago($at(1), $now) === '1 minute ago' && portal_ago($at(20), $now) === '20 minutes ago'
    && portal_ago($at(60), $now) === '1 hour ago' && portal_ago($at(5 * 60), $now) === '5 hours ago'
    && portal_ago($at(26 * 60), $now) === 'yesterday' && portal_ago($at(3 * 1440), $now) === '3 days ago');
list_check('older than a week it is a date, and an unreadable time is nothing rather than a wrong time',
    portal_ago('2026-10-20 15:00:00', $now) === 'on Oct 20' && portal_ago('', $now) === '' && portal_ago(null, $now) === '' && portal_ago('not a time', $now) === '');

/* ---- The sentence ------------------------------------------------------------------------------ */
$row = ['id' => 7, 'subject' => 'Printer', 'status' => 'open', 'priority' => 'normal', 'created_at' => $at(30), 'updated_at' => $at(30),
    'resolved_at' => null, 'sla_due_at' => gmdate('Y-m-d H:i:s', $now + 3 * 3600), 'first_response_at' => null,
    'last_message_kind' => 'client', 'last_message_at' => $at(30)];
list_check('received, not answered, with a goal still ahead: says so and when we aim to reply',
    portal_request_line($row, true, $now) === 'We received this. We aim to reply by today at 3:00 PM.');
list_check('no goal on the request: no time is promised',
    portal_request_line(['sla_due_at' => null] + $row, true, $now) === 'We received this.'
    && portal_request_line(['sla_due_at' => ''] + $row, true, $now) === 'We received this.');
list_check('a goal that has already passed is never offered as a time to expect',
    portal_request_line(['sla_due_at' => $at(60)] + $row, true, $now) === 'We received this.');
list_check('once a first reply exists the aim is never shown, whatever the due time',
    ! str_contains(portal_request_line(['first_response_at' => $at(10), 'last_message_kind' => 'tech', 'last_message_at' => $at(10)] + $row, true, $now), 'aim to reply'));
list_check('open with our message newest: we replied, and when',
    portal_request_line(['first_response_at' => $at(10), 'last_message_kind' => 'tech', 'last_message_at' => $at(10)] + $row, true, $now) === 'We replied 10 minutes ago.');
list_check('open again after the customer answered: we have the reply',
    portal_request_line(['first_response_at' => $at(90), 'last_message_kind' => 'client', 'last_message_at' => $at(20), 'updated_at' => $at(20)] + $row, true, $now)
        === 'We have your reply. Updated 20 minutes ago.');
$working = ['status' => 'in_progress', 'first_response_at' => $at(90)] + $row;
list_check('in progress with the customer\'s message newest: a technician is working on it',
    portal_request_line(['last_message_kind' => 'client', 'updated_at' => $at(20)] + $working, true, $now) === 'A technician is working on it. Updated 20 minutes ago.');
list_check('in progress with our message newest: it also says we replied',
    portal_request_line(['last_message_kind' => 'tech', 'last_message_at' => $at(15)] + $working, true, $now) === 'A technician is working on it. We replied 15 minutes ago.');
$waiting = ['status' => 'waiting', 'first_response_at' => $at(40), 'last_message_kind' => 'tech', 'last_message_at' => $at(10)] + $row;
list_check('waiting on the customer: we replied and need their answer',
    portal_request_line($waiting, true, $now) === 'We replied 10 minutes ago and need your answer.');
list_check('the same request for someone who cannot reply does not ask them to',
    portal_request_line($waiting, false, $now) === 'We replied 10 minutes ago and are waiting for an answer.');
list_check('waiting with no message from us claims no reply',
    portal_request_line(['first_response_at' => null, 'last_message_kind' => 'client'] + $waiting, true, $now) === 'We need an answer from you.'
    && portal_request_line(['first_response_at' => null, 'last_message_kind' => 'client'] + $waiting, false, $now) === 'We are waiting for an answer.');
list_check('resolved says when, or just that it is, when the time is missing',
    portal_request_line(['status' => 'resolved', 'resolved_at' => $at(26 * 60)] + $row, true, $now) === 'Resolved yesterday.'
    && portal_request_line(['status' => 'resolved', 'resolved_at' => null] + $row, true, $now) === 'Resolved.');
list_check('a row from an older caller, without the newer fields, still gives a true sentence',
    portal_request_line(['id' => 1, 'subject' => 'x', 'status' => 'open', 'updated_at' => $at(5)], true, $now) === 'We received this.'
    && portal_request_line(['id' => 1, 'subject' => 'x', 'status' => 'in_progress', 'updated_at' => $at(5)], true, $now) === 'A technician is working on it. Updated 5 minutes ago.');

/* ---- The tag ----------------------------------------------------------------------------------- */
list_check('list tags: Open reads Received; the other three keep the status words',
    portal_request_tag('open') === 'Received' && portal_request_tag('in_progress') === 'In progress'
    && portal_request_tag('waiting') === 'Waiting for your reply' && portal_request_tag('resolved') === 'Resolved');
list_check('someone who cannot reply is not told a request waits for "your" reply',
    portal_request_tag('waiting', false) === 'Waiting for a reply');
list_check('the detail page words are unchanged', portal_status_label('open') === 'Open' && portal_status_label('waiting') === 'Waiting for your reply');

/* ---- The rows ---------------------------------------------------------------------------------- */
$rows = static function (array $tickets, bool $canWrite, bool $number, bool $reply): string {
    ob_start();
    portal_render_request_rows($tickets, $canWrite, $number, $reply);
    return (string)ob_get_clean();
};
$hostile = ['subject' => '<img src=x onerror=alert(1)>'] + $waiting;
$home = $rows([$hostile], true, false, true);
list_check('a Home row: the subject is the title and the link, with no request number',
    str_contains($home, '<h3><a class="portal-request-link" href="/portal/ticket.php?id=7">&lt;img src=x onerror=alert(1)&gt;</a></h3>') && ! str_contains($home, '#7'));
list_check('Reply lands on that request\'s reply box and says which request it is for',
    str_contains($home, 'href="/portal/ticket.php?id=7#portal-reply" aria-label="Reply to: &lt;img src=x onerror=alert(1)&gt;">Reply</a>'));
list_check('subject text is escaped everywhere it appears', ! str_contains($home, '<img src=x'));
$list = $rows([$row], true, true, false);
list_check('a Support Requests row keeps the request number in the link, and has no Reply button',
    str_contains($list, '<span class="portal-request-number">#7</span> Printer</a>') && ! str_contains($list, 'portal-reply'));
list_check('no inline style or handler in a row', ! preg_match('/\sstyle\s*=|\son[a-z]+\s*=/i', $rows([$waiting], true, false, true) . $list));

ob_end_flush();
echo "Portal request list: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
