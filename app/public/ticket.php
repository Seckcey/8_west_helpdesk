<?php
/**
 * Ticket detail: the conversation in the centre, the customer beside it (lib/ticket_page.php).
 * Resolve without ever leaving this view. Reply = POST back here (PRG).
 *
 * Opened from the queue, the link names that list (?in=...), so the head can say "2 of 6" and step
 * to the previous and next ticket; opened any other way there is no counter and no arrows.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/ticket_changes.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/attachments.php';
require_once __DIR__ . '/../lib/westy_workflow.php';
require_once __DIR__ . '/../lib/westy_workflow_grants.php';
require_once __DIR__ . '/../lib/ticket_queue.php';
require_once __DIR__ . '/../lib/ticket_page.php';
require_once __DIR__ . '/../lib/client_operational_context.php';
enforce_https();
$user = require_login();

// A reply can carry the local timer through a full-page submit. The server
// acknowledges the exact idempotency key once after PRG; app.js clears local
// state only when that key matches the timer still stored in this browser.
$timeEntryAck = null;
$timeEntryNotice = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['time_entry_ack'])) {
    $timeEntryAck = (string)$_SESSION['time_entry_ack'];
    unset($_SESSION['time_entry_ack']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['time_entry_notice'])) {
    $timeEntryNotice = (string)$_SESSION['time_entry_notice'];
    unset($_SESSION['time_entry_notice']);
}

$id = (int)($_GET['id'] ?? 0);
// The list this ticket was opened from, and the place it had there when the page was last drawn.
$listState = queue_ticket_state($_GET);
$listAt = is_string($_GET['at'] ?? null) && ctype_digit($_GET['at']) && strlen($_GET['at']) <= 6 ? (int)$_GET['at'] : 0;
$listQuery = $listState !== null ? queue_ticket_query($listState) : '';
// "Send and resolve" and "Send and wait" say what they did after the redirect, with a way back.
// A ticket just created from the New ticket form arrives the same way: "Created and resolved", or
// a request to start the person's timer on it.
$ticketFlash = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['ticket_flash'])) {
    $flash = $_SESSION['ticket_flash'];
    unset($_SESSION['ticket_flash']);
    if (is_array($flash) && (int)($flash['ticket_id'] ?? 0) === $id) $ticketFlash = $flash;
}
$stmt = db()->prepare(
    'SELECT t.*,
            (SELECT MIN(m.created_at) FROM messages m WHERE m.ticket_id = t.id AND m.kind = "tech") AS first_response_at,
            EXISTS (SELECT 1 FROM tickets merged_source WHERE merged_source.tenant_id = t.tenant_id AND merged_source.merged_into_id = t.id) AS has_merged_sources,
            (SELECT m.author_name FROM messages m WHERE m.ticket_id = t.id AND m.kind = "client" ORDER BY m.id ASC LIMIT 1) AS opener_name,
            c.name AS client_name, c.sla_tier, c.notes AS client_notes,
            goal_policy.display_name AS service_goal_policy_name,
            goal_policy.version_no AS service_goal_version_no,
            goal_policy.clock_mode AS service_goal_clock_mode,
            goal_target.priority AS service_goal_priority,
            goal_target.first_response_minutes AS service_goal_response_minutes,
            u.full_name AS assignee_name, u.initials AS assignee_initials, u.color AS assignee_color
       FROM tickets t
       JOIN clients c ON c.id = t.client_id
       LEFT JOIN service_goal_policy_targets goal_target
         ON goal_target.tenant_id = t.tenant_id
        AND goal_target.id = t.service_goal_target_id
       LEFT JOIN service_goal_policy_versions goal_policy
         ON goal_policy.tenant_id = t.tenant_id
        AND goal_policy.id = goal_target.policy_version_id
       LEFT JOIN users u ON u.id = t.assignee_id
      WHERE t.id = ? AND t.tenant_id = ?'
);
$stmt->execute([$id, tenant_id()]);
$ticket = $stmt->fetch();
$westyWorkflow = null;
// Disabling new automation must leave existing ownership and receipts visible.
if ($ticket) {
    try {
        $westyWorkflow = westy_workflow_ticket(db(), (int)$user['tenant_id'], $id);
        if ($westyWorkflow) {
            $westyWorkflow['ticket_id'] = $id;
            $westyWorkflow += westy_workflow_grant_controls(db(),$westyWorkflow,$ticket,$user,cfg('westy_workflow.enabled',false) === true);
        }
        $ticket['westy_owned'] = ($westyWorkflow['state'] ?? '') === 'working';
    } catch (Throwable $error) {
        error_log('Westy workflow card unavailable: ' . $error::class);
    }
}

if (!$ticket) {
    page_top($user, 'Not found', 'queue');
    echo '<div class="page"><div class="card empty"><p>Ticket #' . $id . ' drifted out of the harbor.</p><a class="link" href="/">← Back to queue</a></div></div>';
    page_bottom(palette_data());
    exit;
}

// ---- composer (POST → redirect → GET): reply to client OR internal note ----
$staleDraft = null;
$staleMode  = 'reply';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply'])) {
    csrf_check();
    $body   = utf8_clean(trim((string)$_POST['reply']));
    $isNote = (($_POST['mode'] ?? 'reply') === 'note');
    $hasFiles = !empty($_FILES['files']['name'][0] ?? '');
    $messageSaved = false;
    if ($body === '' && $hasFiles) $body = '(attached files)';

    // Collision guard: if the thread grew since this form was rendered,
    // BLOCK the send and show the new messages above the preserved draft
    // (Help Scout's stale-send protection).
    $lastSeen = (int)($_POST['last_message_id'] ?? 0);
    $maxQ = db()->prepare('SELECT COALESCE(MAX(id), 0) FROM messages WHERE ticket_id = ?');
    $maxQ->execute([$id]);
    if ($body !== '' && $lastSeen > 0 && (int)$maxQ->fetchColumn() > $lastSeen) {
        $staleDraft = $body;
        $staleMode  = $isNote ? 'note' : 'reply';
        $body = '';   // fall through to render — nothing was saved
    }
    if ($body !== '') {
        db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
            ->execute([$id, $user['full_name'], $isNote ? 'note' : 'tech', $body]);
        $messageSaved = true;
        if ($hasFiles) {
            att_store_uploads($id, (int)db()->lastInsertId(), $_FILES['files']);
        }
        if (!$isNote) {
            // Send and set the next status in one act (replies only). A plain Send keeps its
            // rule: an Open ticket becomes In progress, any other status stays as it is.
            $after = (string)($_POST['after_status'] ?? '');
            $before = (string)$ticket['status'];
            if ($after === 'waiting' || $after === 'resolved') {
                // The person pressed "Send and resolve" (or "... wait"): that is a chosen status.
                $change = ticket_change_status($ticket, $after, true);
                $_SESSION['ticket_flash'] = [
                    'ticket_id' => $id,
                    'toast' => 'Reply sent. ' . $change['toast'],
                    // Undo puts the status where a plain Send would have left it.
                    'undo_status' => !$change['changed'] ? '' : ($before === 'open' ? 'in_progress' : $before),
                ];
            } elseif ($before === 'open') {
                ticket_change_status($ticket, 'in_progress', false);
            }
            // notify the client contact by email — replies only, never notes. The composer's
            // hint is built from the same answer, so it cannot promise an email that is not sent.
            $replyContact = ticket_reply_audience($ticket)['contact'];
            if ($replyContact !== null) {
                mail_notify_reply($ticket, $replyContact['email'], $user['full_name'], $body);
            }
        }
    }
    // Time-at-reply: the running timer's immutable key and timestamps ride
    // the Send click. A stale send never reaches this block, so it neither
    // logs nor acknowledges the retained local timer.
    if ($staleDraft === null) {
        $logMin = max(0, min(24 * 60, (int)($_POST['timer_minutes'] ?? 0)));
        $entryKey = trim((string)($_POST['entry_key'] ?? ''));
        if ($messageSaved && $logMin > 0 && $entryKey !== '') {
            $timeInput = [
                'ticket_id' => $id,
                'entry_key' => $entryKey,
                'source' => 'reply',
                'minutes' => $logMin,
                'note' => ($isNote ? 'Noted on #' : 'Replied on #') . $id,
                'billable' => !empty($_POST['billable']) ? 1 : 0,
                'started_at' => (string)($_POST['started_at'] ?? ''),
                'ended_at' => (string)($_POST['ended_at'] ?? ''),
                'worked_at' => (string)($_POST['worked_at'] ?? ''),
            ];
            // The message can commit before timer validation. Store an exact,
            // actor-bound request digest first so a transient clock-window
            // refusal does not strand an otherwise recoverable local timer.
            $_SESSION['time_entry_retry'] = [
                'tenant_id' => (int)$user['tenant_id'],
                'user_id' => (int)$user['id'],
                'entry_key' => $entryKey,
                'request_fingerprint' => time_entry_retry_request_fingerprint($timeInput),
            ];
            try {
                $normalizedTime = time_entry_validate_create_input($timeInput);
                // Retain the canonical digest too. The raw and normalized
                // digests authorize only the same facts, never a replacement.
                $_SESSION['time_entry_retry']['fingerprint'] =
                    time_entry_retry_fingerprint($normalizedTime);
                $entry = time_entry_create(db(), (int)$user['tenant_id'], (int)$user['id'], $timeInput);
                $ackKey = (string)($entry['entry_key'] ?? '');
                if ($ackKey !== '' && hash_equals($entryKey, $ackKey)) {
                    $_SESSION['time_entry_ack'] = $ackKey;
                    unset($_SESSION['time_entry_retry']);
                }
            } catch (Throwable $error) {
                // The reply is already saved. Do not turn that success into a
                // 500 or acknowledge the browser timer; it remains locally
                // retryable with the same idempotency key.
                error_log('Safeharbor reply-time capture refused: ' . $error::class);
                $_SESSION['time_entry_notice'] = ($isNote ? 'Note saved' : 'Reply sent')
                    . ', but its time entry stayed local. Use Retry logging; do not send it again.';
            }
        }
        header('Location: /ticket.php?id=' . $id . $listQuery . ($listQuery !== '' && $listAt > 0 ? '&at=' . $listAt : '') . '#reply');
        exit;
    }
}

// Merged stub? Point at the survivor.
$mergedInto = (int)($ticket['merged_into_id'] ?? 0);

// Possible duplicate: another open ticket from the same contact within 48h
$dupe = null;
if (!$mergedInto && !empty($ticket['contact_id']) && $ticket['status'] !== 'resolved') {
    $dq = db()->prepare(
        "SELECT id, subject FROM tickets
          WHERE tenant_id = ? AND client_id = ? AND contact_id = ? AND id != ? AND status != 'resolved'
            AND merged_into_id IS NULL
            AND ABS(TIMESTAMPDIFF(HOUR, created_at, ?)) <= 48
          ORDER BY id DESC LIMIT 1"
    );
    $dq->execute([tenant_id(), (int)$ticket['client_id'], (int)$ticket['contact_id'], $id, $ticket['created_at']]);
    $dupe = $dq->fetch() ?: null;
}

// Thread, capped: a runaway thread (e.g. a mail loop) must never OOM the
// page. Show the newest 300 messages in chronological order + an honest note.
$cq = db()->prepare('SELECT COUNT(*) FROM messages WHERE ticket_id = ?');
$cq->execute([$id]);
$threadTotal = (int)$cq->fetchColumn();
$mq = db()->prepare(
    'SELECT * FROM (
        SELECT * FROM messages WHERE ticket_id = ? ORDER BY created_at DESC, id DESC LIMIT 300
     ) latest ORDER BY created_at ASC, id ASC'
);
$mq->execute([$id]);
$thread = $mq->fetchAll();
$threadHidden = max(0, $threadTotal - count($thread));
$ticketAtts = att_for_ticket($id);

// Who a reply reaches and how, and whether resolving would ask them for a rating: the composer
// says both in plain words before anything is sent.
$replyAudience = ticket_reply_audience($ticket);
$surveyOnResolve = ticket_survey_would_send($ticket, $replyAudience['contact']);
$replyHint = ticket_reply_hint($replyAudience);
$replyOpenMove = 'The ticket moves to ' . STATUS_META['in_progress'][0] . '.';

// Saved replies + the merge values "/" resolves at insert time
$cq2 = db()->prepare('SELECT id, title, body FROM canned_responses WHERE tenant_id = ? ORDER BY title');
$cq2->execute([tenant_id()]);
$cannedRows = $cq2->fetchAll();
// The contact, read inside this tenant (ticket_contact()). A request from the portal has no
// contact row: its opening message carries the requester's name.
$contact = ticket_contact($ticket);
$contactName = $contact !== null ? $contact['name'] : '';
$asker = ticket_asker(['contact_name' => $contactName, 'opener_name' => $ticket['opener_name'] ?? '', 'client_name' => $ticket['client_name']]);
$railContact = $contact !== null && $contactName !== ''
    ? ['name' => $contactName, 'email' => $contact['email'], 'portal' => false]
    : ($replyAudience['via'] === 'portal' && $asker !== '' ? ['name' => $asker, 'email' => '', 'portal' => true] : null);

$suitePreferences = is_array($_SESSION['suite_preferences'] ?? null) ? $_SESSION['suite_preferences'] : suite_preferences_defaults();
$timeZone = (string)($suitePreferences['time_zone'] ?? 'UTC');

// Where this ticket stands in the list it was opened from.
$where = $listState !== null ? queue_ticket_place(db(), tenant_id(), (int)$user['id'], $listState, $id, $listAt) : null;
$selfQuery = $listQuery . ($where !== null ? '&at=' . $where['at'] : '');

// Beside the conversation: the client's other open tickets (also what Merge can offer), the time
// this person has logged here, and the client's computers in Milepost when the client is linked.
$others = $mergedInto ? null : ticket_other_open(db(), tenant_id(), (int)$ticket['client_id'], $id);
$ownMinutes = ticket_own_minutes(db(), tenant_id(), $id, (int)$user['id']);
$operational = client_operational_context(db(), tenant_id(), (int)$ticket['client_id']);
$deviceUrl = $operational['available'] && $operational['status'] === 'active' && is_string($operational['device_url'] ?? null)
    ? $operational['device_url']
    : null;
$mergeData = [
    'ticket' => ['id' => $id, 'subject' => (string)$ticket['subject']],
    'client' => (string)$ticket['client_name'],
    'choices' => ticket_merge_choices($others ?? [], null, $timeZone),
    'more' => count($others ?? []) > TICKET_OTHER_OPEN_LIMIT,
];
$cannedData = [
    'snippets' => array_map(static fn($r) => [
        'id' => (int)$r['id'], 'title' => $r['title'], 'body' => $r['body'],
    ], $cannedRows),
    'merge' => [
        'ticket.id'          => (string)$ticket['id'],
        'client.name'        => (string)$ticket['client_name'],
        'contact.first_name' => $contactName !== '' ? explode(' ', trim($contactName))[0] : 'there',
        'tech.first_name'    => explode(' ', trim((string)$user['full_name']))[0],
    ],
];

page_top($user, '#' . $id, 'queue');
?>
<div class="page page-ticket">
  <?php if ($timeEntryAck !== null): ?>
    <span id="time-entry-ack" data-entry-key="<?= h($timeEntryAck) ?>" hidden></span>
  <?php endif; ?>
  <?= ticket_page_head($ticket, $where, ticket_subtitle($ticket, $asker, null, $timeZone), (bool)$mergedInto) ?>
  <?php if ($mergedInto): ?>
    <?= ticket_callout('info', 'Merged', 'into <a class="link" href="/ticket.php?id=' . $mergedInto . '">#' . $mergedInto . '</a>. The conversation continues there.') ?>
  <?php endif; ?>
  <?php if ($dupe): ?>
    <?= ticket_callout('caution', 'Possible duplicate.',
        'The same contact opened <a class="link" href="/ticket.php?id=' . (int)$dupe['id'] . '">#' . (int)$dupe['id'] . ' ' . h(mb_substr($dupe['subject'], 0, 60)) . '</a> within 48 hours of this one.',
        '<button type="button" class="btn-secondary ticket-callout-btn" id="merge-dupe-btn" data-src="' . (int)$ticket['id'] . '" data-dst="' . (int)$dupe['id'] . '"'
        . ' data-subject="' . h(mb_substr($dupe['subject'], 0, 120)) . '">Merge into #' . (int)$dupe['id'] . '</button>') ?>
  <?php endif; ?>
  <?php if ($staleDraft !== null): ?>
    <?= ticket_callout('caution', 'Nothing was sent.', 'The conversation changed while you were typing. Read the new messages below; your draft is still in the box.') ?>
  <?php endif; ?>
  <?php if ($timeEntryNotice !== null): ?>
    <?= ticket_callout('caution', 'Time not logged.', h($timeEntryNotice)) ?>
  <?php endif; ?>
  <?php if ($ticketFlash !== null): ?>
    <span id="ticket-flash" data-toast="<?= h((string)($ticketFlash['toast'] ?? '')) ?>" data-undo-status="<?= h((string)($ticketFlash['undo_status'] ?? '')) ?>"<?= !empty($ticketFlash['start_timer']) ? ' data-start-timer="1"' : '' ?> hidden></span>
  <?php endif; ?>

  <div class="ticket-grid">
    <div class="thread" id="thread" data-ticket-id="<?= (int)$ticket['id'] ?>" data-status="<?= h($ticket['status']) ?>" data-priority="<?= h($ticket['priority']) ?>" data-owner="<?= !empty($ticket['assignee_id']) ? (int)$ticket['assignee_id'] : '' ?>">
      <?php if ($westyWorkflow): ?><?= westy_workflow_card($westyWorkflow) ?><?php endif; ?>
      <?php if ($westyWorkflow && cfg('westy_email.enabled', false) === true): ?>
        <p><a class="btn-chip" href="/<?= cfg('westy_mail.enabled',false)===true?'westy_mail':'westy_email' ?>.php?ticket_id=<?= (int)$ticket['id'] ?>">Review Westy advice email</a></p>
      <?php endif; ?>
      <?php if (!$thread): ?>
        <div class="card empty"><p>No replies yet. <span class="accent">Press R</span> to answer first.</p></div>
      <?php endif; ?>
      <?php if ($threadHidden > 0): ?>
        <div class="msg-system">Showing the latest <?= count($thread) ?> of <?= $threadHidden + count($thread) ?> messages</div>
      <?php endif; ?>
      <?php foreach ($thread as $msg): ?>
        <?= ticket_message($msg, $msg['kind'] === 'system' ? '' : att_chips($ticketAtts[(string)$msg['id']] ?? []), $timeZone) ?>
      <?php endforeach; ?>

      <div class="card reply" id="reply">
        <form method="post" action="/ticket.php?id=<?= (int)$ticket['id'] ?><?= h($selfQuery) ?>" id="reply-form" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="mode" id="composer-mode" value="reply">
          <input type="hidden" name="timer_minutes" id="f-timer-minutes" value="0">
          <input type="hidden" name="entry_key" id="f-timer-entry-key" value="">
          <input type="hidden" name="started_at" id="f-timer-started-at" value="">
          <input type="hidden" name="ended_at" id="f-timer-ended-at" value="">
          <input type="hidden" name="worked_at" id="f-timer-worked-at" value="">
          <input type="hidden" name="last_message_id" value="<?= (int)($thread ? max(array_column($thread, 'id')) : 0) ?>">
          <div class="composer-top">
            <div class="composer-tabs" role="tablist">
              <button type="button" class="composer-tab tab-on" data-mode="reply" role="tab" aria-selected="true">Reply <kbd class="kbd">R</kbd></button>
              <button type="button" class="composer-tab" data-mode="note" role="tab" aria-selected="false">Internal note <kbd class="kbd">N</kbd></button>
            </div>
            <button type="button" class="btn-secondary composer-canned" id="canned-open" aria-controls="canned-pop" aria-expanded="false">Saved replies</button>
          </div>
          <div class="canned-pop" id="canned-pop" hidden></div>
          <textarea name="reply" id="reply-box" rows="3" placeholder="Write your reply" aria-label="Reply" aria-describedby="composer-keys composer-hint"
                    data-placeholder-reply="Write your reply" data-placeholder-note="Write an internal note" data-stale-mode="<?= h($staleMode) ?>"><?= $staleDraft !== null ? h($staleDraft) : '' ?></textarea>
          <p class="composer-keys" id="composer-keys"><kbd class="kbd" data-mod-key>Ctrl</kbd><kbd class="kbd">Enter</kbd> to send. Type <kbd class="kbd">/</kbd> for a saved reply.</p>
          <div class="reply-foot">
            <label class="att-pick" title="Attach files (up to 5, 15 MB each)">
              <input type="file" name="files[]" id="f-files" class="att-input" multiple>
              <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M13 7.5 8.6 12a3.1 3.1 0 0 1-4.5-4.4l5-5a2.1 2.1 0 0 1 3 3l-5 5a1.1 1.1 0 0 1-1.6-1.5l4.5-4.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <span>Attach a file</span>
              <span id="att-count"></span>
            </label>
            <span class="reply-hint" id="composer-hint" data-audience="<?= h($replyHint) ?>" data-open-move="<?= h($replyOpenMove) ?>"
                  data-note="Only your team sees an internal note."><?= h($replyHint . ($ticket['status'] === 'open' ? ' ' . $replyOpenMove : '')) ?></span>
            <label class="timer-log-chip" id="timer-log-chip" hidden>
              <input type="checkbox" id="f-billable" name="billable" value="1" checked>
              <span id="timer-log-text">log time</span>
            </label>
            <?= ticket_send_control($surveyOnResolve, $replyAudience['first_name'], intdiv(TICKET_WAITING_HOURS, 24)) ?>
          </div>
        </form>
      </div>
      <script id="canned-data" type="application/json"><?= json_encode($cannedData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    </div>

    <?= ticket_details($ticket, [
        'menus' => ticket_menu_button('status', (int)$ticket['id'], 'Status', 'S', 'data-chip', status_chip($ticket['status']))
            . ticket_menu_button('priority', (int)$ticket['id'], 'Priority', 'P', 'data-pri', priority_glyph($ticket['priority'], true))
            . ticket_menu_button('assignee', (int)$ticket['id'], 'Assigned to', 'T', 'data-assignee',
                '<span class="assignee-cell">' . ticket_owner_avatar($ticket, 22) . '<span class="assignee-name">'
                . h(($westyWorkflow['state'] ?? '') === 'working' ? 'Westy' : ($ticket['assignee_name'] ?? 'Unassigned')) . '</span></span>'),
        'tag' => sla_lamp($ticket),
        'plan' => service_goal_ticket_policy_label($ticket),
        'minutes' => $ownMinutes,
        'contact' => $railContact,
        'note' => (string)($ticket['client_notes'] ?? ''),
        'others' => $others,
        'device_url' => $deviceUrl,
    ]) ?>
  </div>
  <?php if (!$mergedInto): ?>
    <script id="merge-data" type="application/json"><?= json_encode($mergeData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php endif; ?>
</div>
<?php
page_bottom(palette_data());
