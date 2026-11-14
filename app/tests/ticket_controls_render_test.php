<?php
/**
 * The ticket page's controls (lib/render.php) and the composer's sentence (lib/ticket_changes.php),
 * loaded with isolated dependency edges; no config, no database.
 *
 *   php app/tests/ticket_controls_render_test.php            runs the checks
 *   php app/tests/ticket_controls_render_test.php --render   prints a ticket page built from the
 *       same helpers, for tools/shots/ticket-menus.test.mjs (optional: --status=waiting,
 *       --audience=email|portal|none)
 */
declare(strict_types=1);
$checks = 0;
function controls_check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}

$temp = sys_get_temp_dir() . '/safeharbor-ticket-controls-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
try {
    copy(__DIR__ . '/../lib/render.php', $temp . '/render.php');
    file_put_contents($temp . '/auth.php', '<?php function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,"UTF-8"); } function sla_info($t): array { return ["state"=>"on_track","label"=>"On track"]; } function rel_time($v): string { return "2h"; }');
    file_put_contents($temp . '/westy.php', '<?php');
    require $temp . '/render.php';
    require __DIR__ . '/../lib/ticket_page.php';
    require __DIR__ . '/../lib/ticket_changes.php';

    $dom = static function (string $html): DOMXPath {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"><div>' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($doc);
    };
    $team = [
        ['id' => 7, 'full_name' => 'Maya Reyes', 'initials' => 'MR', 'color' => '#54A8FF'],
        ['id' => 9, 'full_name' => 'Jon <Sato>', 'initials' => 'JS', 'color' => '#62F6B0'],
    ];

    // The menus offer every status and priority there is, each once, with the tag the page shows.
    $choices = ticket_menu_choices($team);
    controls_check(array_column($choices['status'], 'value') === array_keys(STATUS_META), 'the status menu offers every status, in order');
    controls_check(array_column($choices['priority'], 'value') === array_keys(PRIORITY_META), 'the priority menu offers every priority, in order');
    foreach ($choices['status'] as $choice) {
        controls_check($choice['html'] === status_chip($choice['value']) && $choice['label'] === STATUS_META[$choice['value']][0],
            'status choice ' . $choice['value'] . ' carries the same tag as the page');
    }
    controls_check($choices['team'] === [
        ['id' => 7, 'name' => 'Maya Reyes', 'initials' => 'MR', 'color' => '#54A8FF'],
        ['id' => 9, 'name' => 'Jon <Sato>', 'initials' => 'JS', 'color' => '#62F6B0'],
    ], 'the owner menu gets each teammate as data, never as markup');

    // A menu button: a real button, named by its label and value, wired to its field and ticket.
    $button = ticket_menu_button('status', 42, 'Status', 'S', 'data-chip', status_chip('waiting'));
    $x = $dom($button);
    $node = $x->query('//button')->item(0);
    controls_check($node instanceof DOMElement && $node->getAttribute('type') === 'button'
        && $node->getAttribute('data-action') === 'status' && $node->getAttribute('data-id') === '42'
        && str_contains(' ' . $node->getAttribute('class') . ' ', ' rail-btn ') && str_contains(' ' . $node->getAttribute('class') . ' ', ' menu-btn '),
        'the status control is a button for its field and ticket');
    controls_check($node->getAttribute('aria-haspopup') === 'listbox' && $node->getAttribute('aria-expanded') === 'false',
        'it says it opens a list and starts closed');
    $named = array_map(static fn(string $id): string => trim((string)$x->query('//*[@id="' . $id . '"]')->item(0)?->textContent),
        explode(' ', $node->getAttribute('aria-labelledby')));
    controls_check($named === ['Status', 'Waiting'], 'its name is the label and the current value');
    controls_check($x->query('//button//*[@data-chip]')->length === 1, 'the value keeps the hook the script repaints');

    // Resolve and Reopen: exactly one shows, and each asks for one status.
    foreach (['open' => 'resolve-btn', 'waiting' => 'resolve-btn', 'resolved' => 'reopen-btn'] as $status => $shown) {
        $x = $dom(ticket_resolve_buttons(42, $status));
        $visible = [];
        foreach ($x->query('//button') as $b) if (!$b->hasAttribute('hidden')) $visible[] = $b->getAttribute('id');
        controls_check($visible === [$shown], "a ticket that is $status shows only $shown");
    }
    $x = $dom(ticket_resolve_buttons(42, 'open'));
    controls_check($x->query('//button[@id="resolve-btn"][@data-set-status="resolved"][@data-id="42"]')->length === 1
        && $x->query('//button[@id="reopen-btn"][@data-set-status="open"][@data-id="42"]')->length === 1,
        'Resolve asks for resolved and Reopen for open, on this ticket');

    // Send: one plain submit, and two submits that carry the next status, so they work without script.
    $x = $dom(ticket_send_control(true, 'Dana <b>', 3));
    $main = $x->query('//button[@id="composer-send"]')->item(0);
    controls_check($main instanceof DOMElement && $main->getAttribute('type') === 'submit' && !$main->hasAttribute('name'),
        'Send reply is a plain submit that carries no status');
    $after = [];
    foreach ($x->query('//button[@name="after_status"]') as $b) $after[$b->getAttribute('value')] = [$b->getAttribute('type'), trim($b->textContent)];
    controls_check(array_keys($after) === ['waiting', 'resolved'] && $after['waiting'][0] === 'submit' && $after['resolved'][0] === 'submit',
        'the two choices are submit buttons named after_status: waiting and resolved');
    controls_check(str_contains($after['waiting'][1], 'Send and wait for the customer') && str_contains($after['waiting'][1], 'Comes back in 3 days'),
        'Send and wait says when the ticket comes back');
    controls_check(str_contains($after['resolved'][1], 'Dana <b> gets a short survey.') && $x->query('//b')->length === 0,
        'Send and resolve names who gets the survey, as text');
    $x = $dom(ticket_send_control(false, 'Dana', 3));
    controls_check(str_contains((string)$x->query('//button[@value="resolved"]')->item(0)?->textContent, 'No survey is sent.'),
        'with no survey to send, Send and resolve says so');
    controls_check($x->query('//div[@id="send-menu"][@hidden]')->length === 1, 'the choices start closed');

    // The composer's sentence is true for each kind of ticket.
    controls_check(ticket_reply_hint(['via' => 'email', 'first_name' => 'Dana']) === 'Dana gets this by email.', 'a contact with an address is emailed');
    controls_check(ticket_reply_hint(['via' => 'portal', 'first_name' => 'Dana']) === 'Dana sees this in the portal. No email is sent.',
        'a portal request is answered in the portal, without email');
    controls_check(ticket_reply_hint(['via' => 'portal', 'first_name' => '']) === 'The customer sees this in the portal. No email is sent.',
        'a portal request without a name still says no email is sent');
    controls_check(ticket_reply_hint(['via' => 'none', 'first_name' => '']) === 'No customer is attached. This reply is saved on the ticket only.',
        'a ticket with nobody attached says the reply stays on the ticket');
    controls_check(ticket_reply_hint(['via' => 'unmailable', 'first_name' => 'Dana']) === 'Dana\'s address cannot receive mail, so no email is sent. This reply is saved on the ticket only.'
        && ticket_reply_hint(['via' => 'unmailable', 'first_name' => '']) === 'The contact\'s address cannot receive mail, so no email is sent. This reply is saved on the ticket only.',
        'a contact whose address is a machine\'s is named as that, not as nobody');
    controls_check(ticket_reply_hint(['via' => 'no_address', 'first_name' => 'Dana']) === 'Dana has no email address, so no email is sent. This reply is saved on the ticket only.',
        'a contact with no address is named as that');
    controls_check(!str_contains(ticket_send_control(true, 'Dana', 3), '<kbd'), 'the send choices carry no key of their own');
    foreach (['mailer-daemon@example.test', 'postmaster@example.test', 'no-reply@example.test', 'noreply@example.test',
        'donotreply@example.test', 'bounce@example.test', 'bounces@example.test', 'autoreply@example.test', 'NoReply@Example.Test'] as $machine) {
        controls_check(ticket_machine_address($machine), "$machine is a machine address");
    }
    controls_check(!ticket_machine_address('dana@example.test') && !ticket_machine_address('replying@example.test'), 'a person\'s address is not');

    // Queue rows carry the three current values the menus read.
    $row = ['id' => 101, 'tenant_id' => 1, 'client_id' => 11, 'status' => 'waiting', 'priority' => 'high', 'channel' => 'email',
        'subject' => 'Front desk PC will not print', 'client_name' => 'Fixture Dental', 'created_at' => '2026-10-30 08:00:00',
        'assignee_id' => 9, 'assignee_name' => 'Jon Sato', 'assignee_initials' => 'JS', 'assignee_color' => '#62F6B0', 'westy_owned' => false];
    $x = $dom(ticket_row($row));
    $a = $x->query('//a')->item(0);
    controls_check($a->getAttribute('data-status') === 'waiting' && $a->getAttribute('data-priority') === 'high' && $a->getAttribute('data-owner') === '9',
        'an owned row says its status, priority and owner');
    $a = $dom(ticket_row(array_replace($row, ['assignee_id' => null])))->query('//a')->item(0);
    controls_check($a->hasAttribute('data-owner') && $a->getAttribute('data-owner') === '', 'an unowned row says nobody owns it');

    if (in_array('--render', $argv, true)) {
        $option = static function (string $name, string $default) use ($argv): string {
            foreach ($argv as $arg) if (str_starts_with($arg, "--$name=")) return substr($arg, strlen($name) + 3);
            return $default;
        };
        $status = $option('status', 'open');
        $via = $option('audience', 'email');
        $audience = ['via' => $via, 'first_name' => $via === 'none' ? '' : 'Dana'];
        $hint = ticket_reply_hint($audience);
        $move = 'The ticket moves to ' . STATUS_META['in_progress'][0] . '.';
        // The ticket on screen, as ticket.php hands it to the head and to Details.
        $shown = array_replace($row, ['id' => 42, 'status' => $status, 'priority' => 'normal', 'assignee_id' => null,
            'created_at' => gmdate('Y-m-d H:i:s', time() - 20 * 60)]);
        $queue = [
            array_replace($row, ['id' => 101, 'status' => 'waiting', 'assignee_id' => null]),
            array_replace($row, ['id' => 102, 'status' => 'open', 'priority' => 'normal', 'subject' => 'New hire laptop setup']),
        ];
        echo json_encode([
            'menus' => ticket_menu_choices($team),
            'queue' => '<div class="card list ticket-list">' . ticket_head_row(false) . implode('', array_map('ticket_row', $queue)) . '</div>',
            'ticket' => '<div class="page page-ticket">'
                . ticket_page_head($shown, null, ticket_subtitle($shown, 'Dana Whitfield'), false)
                . '<div class="ticket-grid"><div class="thread" id="thread" data-ticket-id="42" data-status="' . h($status) . '" data-priority="normal" data-owner="">'
                . ticket_message(['kind' => 'client', 'author_name' => 'Dana Whitfield', 'body' => 'The front desk cannot print.', 'created_at' => $shown['created_at']], '', 'UTC')
                . '<div class="card reply" id="reply"><form method="post" action="/ticket.php?id=42" id="reply-form">'
                . '<input type="hidden" name="csrf" value="fixture"><input type="hidden" name="mode" id="composer-mode" value="reply">'
                . '<input type="hidden" name="last_message_id" value="1">'
                . '<div class="composer-top"><div class="composer-tabs" role="tablist">'
                . '<button type="button" class="composer-tab tab-on" data-mode="reply" role="tab" aria-selected="true">Reply <kbd class="kbd">R</kbd></button>'
                . '<button type="button" class="composer-tab" data-mode="note" role="tab" aria-selected="false">Internal note <kbd class="kbd">N</kbd></button></div>'
                . '<button type="button" class="btn-secondary composer-canned" id="canned-open" aria-controls="canned-pop" aria-expanded="false">Saved replies</button></div>'
                . '<div class="canned-pop" id="canned-pop" hidden></div>'
                . '<textarea name="reply" id="reply-box" rows="3" placeholder="Write your reply" aria-label="Reply" data-placeholder-reply="Write your reply" data-placeholder-note="Write an internal note" data-stale-mode="reply"></textarea>'
                . '<p class="composer-keys" id="composer-keys"><kbd class="kbd" data-mod-key>Ctrl</kbd><kbd class="kbd">Enter</kbd> to send. Type <kbd class="kbd">/</kbd> for a saved reply.</p>'
                . '<div class="reply-foot"><label class="att-pick"><input type="file" name="files[]" id="f-files" class="att-input" multiple><span>Attach a file</span><span id="att-count"></span></label>'
                . '<span class="reply-hint" id="composer-hint" data-audience="' . h($hint) . '" data-open-move="' . h($move) . '" data-note="Only your team sees an internal note.">'
                . h($hint . ($status === 'open' ? ' ' . $move : '')) . '</span>'
                . ticket_send_control($via === 'email', $audience['first_name'], intdiv(TICKET_WAITING_HOURS, 24))
                . '</div></form></div></div>'
                . ticket_details($shown, [
                    'menus' => ticket_menu_button('status', 42, 'Status', 'S', 'data-chip', status_chip($status))
                        . ticket_menu_button('priority', 42, 'Priority', 'P', 'data-pri', priority_glyph('normal', true))
                        . ticket_menu_button('assignee', 42, 'Assigned to', 'T', 'data-assignee',
                            '<span class="assignee-cell">' . avatar(null, 22) . '<span class="assignee-name">Unassigned</span></span>'),
                    'tag' => '<span class="sla sla-healthy"><span class="sla-dot"></span>Due in 6 h</span>',
                    'plan' => 'Standard plan: first reply within 8 hours.', 'minutes' => 0,
                    'contact' => ['name' => 'Dana Whitfield', 'email' => 'dana@example.test', 'portal' => false],
                    'note' => '', 'others' => [], 'device_url' => null,
                ])
                . '</div></div>',
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    } else {
        echo "Ticket controls render: {$checks} passed.\n";
    }
} finally {
    $actual = realpath($temp);
    $parent = realpath(sys_get_temp_dir());
    if (!$actual || !$parent || !str_starts_with($actual, $parent . DIRECTORY_SEPARATOR) || !str_starts_with(basename($actual), 'safeharbor-ticket-controls-')) {
        throw new RuntimeException('Fixture cleanup scope changed');
    }
    foreach (['render.php', 'auth.php', 'westy.php'] as $file) unlink($actual . '/' . $file);
    rmdir($actual);
}
