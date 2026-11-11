<?php
/**
 * The customer's request page: where the request stands, what happens next, and plain times.
 *
 * Synthetic data only. No database, no settings file, no sign-in.
 * Run:  php app/tests/portal_request_page_test.php
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
function request_check(string $name, bool $ok): void
{
    global $checks, $failures;
    $checks++;
    if (! $ok) $failures++;
    echo ($ok ? 'ok ' : 'FAIL ') . $checks . ' - ' . $name . "\n";
}

/* ---- Times, as a person says them, in the viewer's own zone and clock ------------------------- */
$GLOBALS['portal_view_preferences'] = ['time_zone' => 'America/New_York', 'time_format' => '12h', 'date_format' => 'mdy', 'locale' => 'en-US'];
$now = strtotime('2026-11-10 17:00:00 UTC');   // Tuesday, noon in New York
request_check('a time today, yesterday and tomorrow is named by its day',
    portal_friendly_time('2026-11-10 14:22:00', $now) === 'today at 9:22 AM'
    && portal_friendly_time('2026-11-09 20:05:00', $now) === 'yesterday at 3:05 PM'
    && portal_friendly_time('2026-11-11 20:00:00', $now) === 'tomorrow at 3:00 PM');
request_check('a time this week uses the weekday; an older one uses the date, with the year only when it differs',
    portal_friendly_time('2026-11-06 14:14:00', $now) === 'Friday at 9:14 AM'
    && portal_friendly_time('2026-11-03 14:22:00', $now) === 'Nov 3 at 9:22 AM'
    && portal_friendly_time('2025-12-30 15:00:00', $now) === 'Dec 30, 2025 at 10:00 AM');
request_check('the day is the viewer\'s day, not the server\'s',
    portal_friendly_time('2026-11-10 03:30:00', $now) === 'yesterday at 10:30 PM');
$GLOBALS['portal_view_preferences'] = ['time_zone' => 'Europe/London', 'time_format' => '24h', 'date_format' => 'dmy', 'locale' => 'en-GB'];
request_check('a 24-hour, day-first viewer gets their own clock and date order',
    portal_friendly_time('2026-11-03 14:22:00', $now) === '3 Nov at 14:22'
    && portal_friendly_time('2026-11-10 09:05:00', $now) === 'today at 09:05');
request_check('a missing or unreadable time gives nothing rather than a wrong time',
    portal_friendly_time(null, $now) === '' && portal_friendly_time('', $now) === '' && portal_friendly_time('not a time', $now) === '');
$GLOBALS['portal_view_preferences'] = ['time_zone' => 'America/New_York', 'time_format' => '12h', 'date_format' => 'mdy', 'locale' => 'en-US'];

/* ---- Where the request stands ------------------------------------------------------------------ */
$asked = ['kind' => 'client', 'author_name' => 'Dana Whitfield', 'created_at' => '2026-11-10 14:14:00', 'body' => 'The front desk cannot print.'];
$answer = ['kind' => 'tech', 'author_name' => 'Harbor IT support', 'created_at' => '2026-11-10 14:22:00', 'body' => 'Is the blue power light on?'];
$base = ['id' => 102, 'subject' => 'Front desk printer is offline', 'priority' => 'high',
    'created_at' => '2026-11-10 14:14:00', 'sla_due_at' => '2026-11-10 22:14:00', 'first_response_at' => null, 'resolved_at' => null,
    'service_goal_policy_name' => 'Premium', 'service_goal_version_no' => 2, 'service_goal_response_minutes' => 480];
$states = static fn(array $progress): string => implode(',', array_column($progress['steps'], 'state'));

$open = portal_request_progress(['status' => 'open'] + $base, [$asked], true, $now);
request_check('a new request is at step 1 of 4, and the four steps are the four status words',
    $open['step'] === 1 && $states($open) === 'now,later,later,later'
    && array_column($open['steps'], 'label') === ['Open', 'In progress', 'Waiting for your reply', 'Resolved']);
request_check('a new request says we have it, that nothing is needed now, and when a first reply is aimed for',
    $open['next'][0] === 'We have your request.'
    && str_contains($open['next'][1], 'You do not need to do anything now')
    && $open['goal'] === 'We aim to send a first reply by today at 5:14 PM.');
$late = portal_request_progress(['status' => 'open', 'sla_due_at' => '2026-11-10 16:00:00'] + $base, [$asked], true, $now);
request_check('an unanswered request past its goal says so honestly instead of promising a time that has gone',
    str_starts_with((string)$late['goal'], 'We aimed to send a first reply by today at 11:00 AM and are running late.'));
$viewerOpen = portal_request_progress(['status' => 'open'] + $base, [$asked], false, $now);
request_check('someone who cannot reply is not told to add details below',
    ! str_contains(implode(' ', $viewerOpen['next']), 'below'));

// A technician's reply moves an Open request to In progress, so this is the usual state right after we write.
$working = portal_request_progress(['status' => 'in_progress', 'first_response_at' => '2026-11-10 14:22:00'] + $base, [$asked, $answer], true, $now);
request_check('in progress with our message newest: says we replied and when, and that they can answer; never "do nothing"',
    $working['step'] === 2 && $states($working) === 'done,now,later,later'
    && $working['next'] === ['We replied today at 9:22 AM.', 'You can answer below. A technician is working on this.']
    && ! str_contains(implode(' ', $working['next']), 'do not need to do anything')
    && $working['goal'] === null);
$workingViewer = portal_request_progress(['status' => 'in_progress', 'first_response_at' => '2026-11-10 14:22:00'] + $base, [$asked, $answer], false, $now);
request_check('the same state for a viewer says we replied, without inviting a reply they cannot send',
    $workingViewer['next'] === ['We replied today at 9:22 AM.', 'A technician is working on this.']);
$workingOn = portal_request_progress(['status' => 'in_progress', 'first_response_at' => '2026-11-10 14:22:00'] + $base, [$asked, $answer, $asked], true, $now);
request_check('in progress with the customer\'s message newest: a technician is working on it and nothing is needed',
    $workingOn['next'][0] === 'A technician is working on this.' && str_contains($workingOn['next'][1], 'You do not need to do anything now')
    && $workingOn['goal'] === 'First reply sent today at 9:22 AM.');
$picked = portal_request_progress(['status' => 'in_progress'] + $base, [$asked], true, $now);
request_check('in progress before any reply: working on it, with the first-reply aim still shown',
    $picked['next'][0] === 'A technician is working on this.' && $picked['goal'] === 'We aim to send a first reply by today at 5:14 PM.');
$reopened = portal_request_progress(['status' => 'open', 'first_response_at' => '2026-11-10 14:22:00'] + $base, [$asked, $answer], true, $now);
request_check('open again with our message newest: says we replied and that they can answer',
    $reopened['step'] === 1 && $reopened['next'] === ['We replied today at 9:22 AM.', 'You can answer below.']);

$waiting = portal_request_progress(['status' => 'waiting', 'first_response_at' => '2026-11-10 14:22:00'] + $base, [$asked, $answer], true, $now);
request_check('a request waiting on the customer is at step 3 and says whose turn it is and when we replied',
    $waiting['step'] === 3 && $states($waiting) === 'done,done,now,later'
    && $waiting['next'] === ['It is your turn.', 'We replied today at 9:22 AM and need your answer to carry on.']);
request_check('the first-reply time is not repeated when the sentence above already gives it',
    $waiting['goal'] === null);
$parked = portal_request_progress(['status' => 'waiting'] + $base, [$asked], true, $now);
request_check('waiting with no message from us: asks for an answer without claiming a reply that was never sent',
    $parked['next'] === ['It is your turn.', 'The support team needs an answer from you to carry on.']
    && ! str_contains(json_encode($parked), 'We replied') && $states($parked) === 'done,skipped,now,later');
$parkedViewer = portal_request_progress(['status' => 'waiting'] + $base, [$asked], false, $now);
request_check('the same state for a viewer claims no reply either',
    $parkedViewer['next'] === ['We are waiting for an answer from your business.', 'Someone who can reply needs to answer.']);
$waitingViewer = portal_request_progress(['status' => 'waiting', 'first_response_at' => '2026-11-10 14:22:00'] + $base, [$asked, $answer], false, $now);
request_check('a viewer of a waiting request is not told it is their turn',
    $waitingViewer['next'][0] === 'We are waiting for an answer from your business.'
    && ! str_contains(implode(' ', $waitingViewer['next']), 'your turn'));

$later = ['kind' => 'tech', 'author_name' => 'Harbor IT support', 'created_at' => '2026-11-10 16:30:00', 'body' => 'Please try printing a test page.'];
$waitingAgain = portal_request_progress(['status' => 'waiting', 'first_response_at' => '2026-11-10 14:22:00'] + $base, [$asked, $answer, $asked, $later], true, $now);
request_check('after several replies the sentence gives the latest one and the goal line keeps the first',
    $waitingAgain['next'][1] === 'We replied today at 11:30 AM and need your answer to carry on.'
    && $waitingAgain['goal'] === 'First reply sent today at 9:22 AM.');

$replied = portal_request_progress(['status' => 'open', 'first_response_at' => '2026-11-10 14:22:00'] + $base, [$asked, $answer, $asked], true, $now);
request_check('after the customer answers, the request is Open again and the page says a technician picks it up',
    $replied['step'] === 1 && $replied['label'] === 'Open'
    && $replied['next'][0] === 'We have your reply.' && str_contains($replied['next'][1], 'A technician will pick this up again.'));

$resolved = portal_request_progress(['status' => 'resolved', 'first_response_at' => '2026-11-10 14:22:00', 'resolved_at' => '2026-11-10 16:10:00'] + $base, [$asked, $answer], true, $now);
request_check('a resolved request says when it was resolved, and marks done only the steps the record shows',
    $resolved['step'] === 4 && $states($resolved) === 'done,done,skipped,done'
    && $resolved['next'] === ['Resolved today at 11:10 AM.', 'Nothing more is needed from you.']);
$closedUnanswered = portal_request_progress(['status' => 'resolved', 'resolved_at' => '2026-11-10 16:10:00'] + $base, [$asked], true, $now);
request_check('a request resolved without a reply from us does not claim it was ever in progress or waiting',
    $states($closedUnanswered) === 'done,skipped,skipped,done');
$unknown = portal_request_progress(['status' => 'archived'] + $base, [], true, $now);
request_check('a status this page does not know is shown as received, never as an error or a raw value',
    $unknown['step'] === 1 && $unknown['next'][0] === 'We have your request.');
$everything = json_encode([$open, $late, $working, $waiting, $replied, $resolved]);
request_check('no sentence carries the policy name, its version or its minutes',
    ! str_contains($everything, 'Premium') && ! str_contains($everything, '480') && ! preg_match('/\bv2\b/', $everything));

/* ---- The page ---------------------------------------------------------------------------------- */
$_SERVER['REQUEST_URI'] = '/portal/ticket.php?id=102';
$context = ['identity' => ['display_name' => 'Dana Whitfield', 'role' => 'client_staff'], 'binding' => ['client_name' => 'Harbor Dental']];
$page = static function (array $context, array $ticket, array $messages): string {
    ob_start();
    portal_render_ticket($context, ['ticket' => $ticket, 'messages' => $messages]);
    return (string)ob_get_clean();
};
$main = static fn(string $html): string => (string)substr($html, (int)strpos($html, '<main'), (int)strpos($html, '</main>') - (int)strpos($html, '<main'));

$waitingHtml = $page($context, ['status' => 'waiting', 'first_response_at' => '2026-11-10 14:22:00'] + $base, [$asked, $answer]);
$waitingMain = $main($waitingHtml);
request_check('the page is titled and worded as a request, never a ticket',
    str_contains($waitingHtml, '<title>Request #102 ') && str_contains($waitingMain, 'Request #102, sent ')
    && stripos(strip_tags($waitingMain), 'ticket') === false);
request_check('the status line marks exactly one current step and names the done ones for screen readers',
    substr_count($waitingMain, 'aria-current="step"') === 1
    && (bool)preg_match('/<li class="is-now" aria-current="step">.*?Waiting for your reply/s', $waitingMain)
    && substr_count($waitingMain, '<span class="sr-only">, done</span>') === 2
    && substr_count($waitingMain, '<li class="is-done">') === 2
    && str_contains($waitingMain, 'Step 3 of 4: Waiting for your reply'));
request_check('the approved Guide still matches: Status, Conversation and the waiting words are on the page',
    str_contains($waitingMain, '>Status</h2>') && str_contains($waitingMain, '>Conversation</h2>')
    && str_contains($waitingMain, 'The support team is waiting for your reply.')
    && str_contains($waitingMain, 'Send the update the support team needs') && str_contains($waitingMain, '>Send reply</button>'));
request_check('one panel says what happens next',
    substr_count($waitingMain, '>What happens next</h2>') === 1 && str_contains($waitingMain, 'It is your turn.'));
request_check('the policy name, its version and its minutes are not shown to a customer',
    ! str_contains($waitingMain, 'Premium') && ! str_contains($waitingMain, 'elapsed minutes') && ! str_contains($waitingMain, '480')
    && ! preg_match('/\bv2\b/', $waitingMain));
request_check('privacy is one line that names who sees the request',
    substr_count($waitingMain, 'portal-next-privacy') === 1
    && str_contains($waitingMain, 'Seen by the person who sent it, the owners and admins of Harbor Dental, and the support team.')
    && ! str_contains($waitingMain, 'never shown here'));
request_check('each time is a machine-readable time with the full date on hover',
    (bool)preg_match('/<time datetime="2026-11-10T14:22:00Z" title="[^"]+">/', $waitingMain));
request_check('the page carries no inline style or script, as its Content Security Policy requires',
    ! preg_match('/\sstyle\s*=|<style|<script(?![^>]*\ssrc=)|\son[a-z]+\s*=/i', $waitingHtml));

// The page reads the real clock, so this request's reply goal has to be ahead of it. With the fixed
// goal in $base the check below failed from the moment that time passed.
$openHtml = $main($page($context, ['status' => 'open', 'sla_due_at' => gmdate('Y-m-d H:i:s', time() + 8 * 3600)] + $base, [$asked]));
request_check('a new request shows the reply goal in words under "Response goal" and the ordinary reply box',
    str_contains($openHtml, '>Response goal</h3>') && str_contains($openHtml, 'We aim to send a first reply by ')
    && str_contains($openHtml, 'Reply to the support team') && ! str_contains($openHtml, 'portal-waiting-callout'));

$resolvedHtml = $main($page($context, ['status' => 'resolved', 'first_response_at' => '2026-11-10 14:22:00', 'resolved_at' => '2026-11-10 16:10:00'] + $base, [$asked, $answer]));
request_check('on a resolved page the step that was never recorded is drawn as not reached: no check, no "done"',
    (bool)preg_match('~<li class="is-skipped">\s*<span class="portal-progress-mark" aria-hidden="true">3</span>\s*<span class="portal-progress-label">Waiting for your reply</span>~', $resolvedHtml)
    && substr_count($resolvedHtml, '<span class="sr-only">, done</span>') === 3);
request_check('a resolved request has no reply box and says how to carry on without retelling the story',
    ! str_contains($resolvedHtml, '<form') && str_contains($resolvedHtml, 'This request is resolved. Still not fixed?')
    && str_contains($resolvedHtml, 'mention request #102'));
request_check('a directly signed-in customer still gets no second create action on this page',
    ! str_contains($resolvedHtml, 'href="/portal/new.php"') && ! str_contains($openHtml, 'href="/portal/new.php"'));

$delegated = $context;
$delegated['identity']['customer_access'] = ['role' => 'client_staff'];
$delegatedHtml = $main($page($delegated, ['status' => 'open'] + $base, [$asked]));
request_check('where the page already offered a new request, it is a secondary button so Send reply stays the one filled button',
    str_contains($delegatedHtml, '<a class="btn-ghost portal-request-new" href="/portal/new.php">New support request</a>')
    && substr_count($delegatedHtml, 'btn-primary') === 1);

$viewer = $context;
$viewer['identity']['role'] = 'client_viewer';
$viewerHtml = $main($page($viewer, ['status' => 'waiting', 'first_response_at' => '2026-11-10 14:22:00'] + $base, [$asked, $answer]));
request_check('a viewer can read the request, is told plainly that they cannot reply, and gets no form or alert to answer',
    str_contains($viewerHtml, 'Is the blue power light on?') && ! str_contains($viewerHtml, '<form')
    && str_contains($viewerHtml, 'You can read this request but not reply to it.')
    && ! str_contains($viewerHtml, 'portal-waiting-callout') && ! str_contains($viewerHtml, 'It is your turn'));

$hostile = $main($page($context, ['status' => 'open', 'subject' => '<img src=x onerror=alert(1)>'] + $base,
    [['kind' => 'client', 'author_name' => '<b>Dana</b>', 'created_at' => '2026-11-10 14:14:00', 'body' => '<script>alert(1)</script>']]));
request_check('subject, author and message text are escaped',
    ! str_contains($hostile, '<img src=x') && ! str_contains($hostile, '<b>Dana</b>') && ! str_contains($hostile, '<script>alert(1)</script>')
    && str_contains($hostile, '&lt;script&gt;alert(1)&lt;/script&gt;'));
$empty = $main($page($context, ['status' => 'open', 'sla_due_at' => null] + $base, []));
request_check('a request with no messages and no goal still renders, without an empty "Response goal"',
    str_contains($empty, 'No messages yet.') && ! str_contains($empty, 'Response goal'));

ob_end_flush();
echo "Portal request page: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
