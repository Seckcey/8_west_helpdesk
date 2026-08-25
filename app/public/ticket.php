<?php
/**
 * Ticket detail — conversation center, context right. Resolve without
 * ever leaving this view. Reply = POST back here (PRG).
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/attachments.php';
enforce_https();
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT t.*,
            (SELECT MIN(m.created_at) FROM messages m WHERE m.ticket_id = t.id AND m.kind = "tech") AS first_response_at,
            EXISTS (SELECT 1 FROM tickets merged_source WHERE merged_source.tenant_id = t.tenant_id AND merged_source.merged_into_id = t.id) AS has_merged_sources,
            c.name AS client_name, c.sla_tier,
            u.full_name AS assignee_name, u.initials AS assignee_initials, u.color AS assignee_color
       FROM tickets t
       JOIN clients c ON c.id = t.client_id
       LEFT JOIN users u ON u.id = t.assignee_id
      WHERE t.id = ? AND t.tenant_id = ?'
);
$stmt->execute([$id, tenant_id()]);
$ticket = $stmt->fetch();

if (!$ticket) {
    page_top($user, 'Not found', 'queue');
    echo '<div class="page"><div class="card empty"><p>Ticket #' . $id . ' drifted out of the harbor.</p><a class="link" href="/">← Back to queue</a></div></div>';
    page_bottom();
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
        if ($hasFiles) {
            att_store_uploads($id, (int)db()->lastInsertId(), $_FILES['files']);
        }
        if (!$isNote && $ticket['status'] === 'open') {
            db()->prepare("UPDATE tickets SET status = 'in_progress' WHERE id = ?")->execute([$id]);
        }
        // notify the client contact by email — replies only, never notes
        if (!$isNote && !empty($ticket['contact_id'])) {
            $kq = db()->prepare('SELECT email FROM contacts WHERE id = ?');
            $kq->execute([(int)$ticket['contact_id']]);
            $contactEmail = (string)($kq->fetch()['email'] ?? '');
            if ($contactEmail !== '') {
                mail_notify_reply($ticket, $contactEmail, $user['full_name'], $body);
            }
        }
    }
    // Time-at-reply: the running timer's minutes ride the Send click, so
    // time capture is a side effect of answering (never a Friday chore).
    if ($staleDraft === null) {
        $logMin = max(0, min(24 * 60, (int)($_POST['timer_minutes'] ?? 0)));
        if ($logMin > 0) {
            db()->prepare('INSERT INTO time_entries (ticket_id, user_id, minutes, note, billable) VALUES (?,?,?,?,?)')
                ->execute([$id, (int)$user['id'], $logMin,
                           ($isNote ? 'Noted on #' : 'Replied on #') . $id,
                           !empty($_POST['billable']) ? 1 : 0]);
        }
        header('Location: /ticket.php?id=' . $id . '#reply');
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
          WHERE tenant_id = ? AND contact_id = ? AND id != ? AND status != 'resolved'
            AND merged_into_id IS NULL
            AND ABS(TIMESTAMPDIFF(HOUR, created_at, ?)) <= 48
          ORDER BY id DESC LIMIT 1"
    );
    $dq->execute([tenant_id(), (int)$ticket['contact_id'], $id, $ticket['created_at']]);
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

$team = db()->prepare('SELECT full_name, initials, color FROM users WHERE tenant_id = ? AND is_active = 1 ORDER BY id');
$team->execute([tenant_id()]);

// Saved replies + the merge values "/" resolves at insert time
$cq2 = db()->prepare('SELECT id, title, body FROM canned_responses WHERE tenant_id = ? ORDER BY title');
$cq2->execute([tenant_id()]);
$cannedRows = $cq2->fetchAll();
$contactName = '';
if (!empty($ticket['contact_id'])) {
    $kq = db()->prepare('SELECT name FROM contacts WHERE id = ?');
    $kq->execute([(int)$ticket['contact_id']]);
    $contactName = (string)($kq->fetch()['name'] ?? '');
}
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
  <a href="/" class="backlink">← Queue</a>
  <?php if ($mergedInto): ?>
    <div class="banner banner-info">This ticket was merged into
      <a class="link" href="/ticket.php?id=<?= $mergedInto ?>">#<?= $mergedInto ?></a> — the conversation continues there.</div>
  <?php endif; ?>
  <?php if ($dupe): ?>
    <div class="banner banner-warn">Possible duplicate: same contact opened
      <a class="link" href="/ticket.php?id=<?= (int)$dupe['id'] ?>">#<?= (int)$dupe['id'] ?> <?= h(mb_substr($dupe['subject'], 0, 60)) ?></a>
      within 48h.
      <button type="button" class="btn-chip" id="merge-dupe-btn" data-src="<?= (int)$ticket['id'] ?>" data-dst="<?= (int)$dupe['id'] ?>">Merge this into #<?= (int)$dupe['id'] ?></button>
    </div>
  <?php endif; ?>
  <?php if ($staleDraft !== null): ?>
    <div class="banner banner-warn">⚠ The conversation changed while you were typing — <strong>nothing was sent</strong>.
      Review the new messages below; your draft is preserved in the composer.</div>
  <?php endif; ?>
  <div class="ticket-head">
    <span class="ticket-num">#<?= (int)$ticket['id'] ?></span>
    <h1 class="ticket-subject"><?= h($ticket['subject']) ?></h1>
    <span data-chip><?= status_chip($ticket['status']) ?></span>
    <span data-pri><?= priority_glyph($ticket['priority'], true) ?></span>
    <span class="presence-row" id="presence"></span>
  </div>
  <p class="ticket-sub">
    <a class="link" href="/client.php?id=<?= (int)$ticket['client_id'] ?>"><?= h($ticket['client_name']) ?></a>
    · opened <?= rel_time($ticket['created_at']) ?> ago via <?= h($ticket['channel']) ?>
  </p>

  <div class="ticket-grid">
    <div class="thread" id="thread" data-ticket-id="<?= (int)$ticket['id'] ?>">
      <?php if (!$thread): ?>
        <div class="card empty"><p>No replies yet. <span class="accent">Press R</span> to answer first.</p></div>
      <?php endif; ?>
      <?php if ($threadHidden > 0): ?>
        <div class="msg-system">Showing the latest <?= count($thread) ?> of <?= $threadHidden + count($thread) ?> messages</div>
      <?php endif; ?>
      <?php foreach ($thread as $msg): ?>
        <?php if ($msg['kind'] === 'system'): ?>
          <div class="msg-system"><?= h($msg['body']) ?> · <?= rel_time($msg['created_at']) ?></div>
        <?php elseif ($msg['kind'] === 'note'): ?>
          <div class="msg-note">
            <div class="msg-meta"><span class="msg-author-note"><?= h($msg['author_name']) ?></span><span class="msg-when">internal note · <?= rel_time($msg['created_at']) ?></span></div>
            <p class="msg-body"><?= h($msg['body']) ?></p>
            <?= att_chips($ticketAtts[(string)$msg['id']] ?? []) ?>
          </div>
        <?php else: $isTech = $msg['kind'] === 'tech'; ?>
          <div class="card msg <?= $isTech ? 'msg-tech' : '' ?>">
            <div class="msg-meta">
              <span class="<?= $isTech ? 'msg-author-tech' : 'msg-author' ?>"><?= h($msg['author_name']) ?></span>
              <span class="msg-when"><?= $isTech ? 'tech' : 'client' ?> · <?= rel_time($msg['created_at']) ?></span>
            </div>
            <p class="msg-body"><?= h($msg['body']) ?></p>
            <?= att_chips($ticketAtts[(string)$msg['id']] ?? []) ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>

      <div class="card reply" id="reply">
        <form method="post" action="/ticket.php?id=<?= (int)$ticket['id'] ?>" id="reply-form" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="mode" id="composer-mode" value="reply">
          <input type="hidden" name="timer_minutes" id="f-timer-minutes" value="0">
          <input type="hidden" name="last_message_id" value="<?= (int)($thread ? max(array_column($thread, 'id')) : 0) ?>">
          <div class="composer-tabs" role="tablist">
            <button type="button" class="composer-tab tab-on" data-mode="reply" role="tab">Reply <kbd class="kbd">R</kbd></button>
            <button type="button" class="composer-tab" data-mode="note" role="tab">Internal note <kbd class="kbd">N</kbd></button>
            <span class="composer-slash-hint"><kbd class="kbd">/</kbd> saved replies</span>
          </div>
          <div class="canned-pop" id="canned-pop" hidden></div>
          <textarea name="reply" id="reply-box" rows="3" placeholder="Reply to client…  (⌘Enter to send · / for saved replies)" data-stale-mode="<?= h($staleMode) ?>"><?= $staleDraft !== null ? h($staleDraft) : '' ?></textarea>
          <div class="reply-foot">
            <label class="att-pick" title="Attach files (up to 5, 15 MB each)">
              <input type="file" name="files[]" id="f-files" multiple hidden>
              <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M13 7.5 8.6 12a3.1 3.1 0 0 1-4.5-4.4l5-5a2.1 2.1 0 0 1 3 3l-5 5a1.1 1.1 0 0 1-1.6-1.5l4.5-4.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <span id="att-count"></span>
            </label>
            <span class="reply-hint" id="composer-hint">Replying emails the client and moves Open → In Progress</span>
            <label class="timer-log-chip" id="timer-log-chip" hidden>
              <input type="checkbox" id="f-billable" name="billable" value="1" checked>
              <span id="timer-log-text">log time</span>
            </label>
            <button type="submit" class="btn-primary btn-sm" id="composer-send">Send</button>
          </div>
        </form>
      </div>
      <script id="canned-data" type="application/json"><?= json_encode($cannedData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    </div>

    <div class="rail">
      <div class="rail-label">Details</div>
      <div class="rail-stack">
        <button class="rail-btn" data-action="status" data-id="<?= (int)$ticket['id'] ?>">
          <span><span class="rail-k">Status</span><span class="rail-v" data-chip><?= status_chip($ticket['status']) ?></span></span><kbd class="kbd">S</kbd>
        </button>
        <button class="rail-btn" data-action="priority" data-id="<?= (int)$ticket['id'] ?>">
          <span><span class="rail-k">Priority</span><span class="rail-v" data-pri><?= priority_glyph($ticket['priority'], true) ?></span></span><kbd class="kbd">P</kbd>
        </button>
        <button class="rail-btn" data-action="assignee" data-id="<?= (int)$ticket['id'] ?>">
          <span><span class="rail-k">Assigned to</span><span class="rail-v" data-assignee>
            <span class="assignee-cell"><?= avatar($ticket['assignee_id'] ? ['full_name' => $ticket['assignee_name'], 'initials' => $ticket['assignee_initials'], 'color' => $ticket['assignee_color']] : null, 22) ?><span class="assignee-name"><?= h($ticket['assignee_name'] ?? 'Unassigned') ?></span></span>
          </span></span><kbd class="kbd">A</kbd>
        </button>
      </div>

      <div class="rail-label">Response target</div>
      <div class="card rail-card"><span data-sla><?= sla_lamp($ticket) ?></span><span class="rail-note"><?= h($ticket['sla_tier']) ?> plan · elapsed time</span></div>

      <div class="rail-label">Time</div>
      <button class="rail-btn timer-btn" id="timer-btn" data-id="<?= (int)$ticket['id'] ?>">▶ Start timer&nbsp;&nbsp;(E)</button>

      <?php if (!$mergedInto): ?>
      <div class="rail-label">Merge</div>
      <button class="rail-btn" id="merge-btn" data-id="<?= (int)$ticket['id'] ?>">⇄ Merge into another ticket…</button>
      <?php endif; ?>

      <div class="rail-label">Suite</div>
      <div class="card rail-card rail-suite">
        <p><span class="suite-dot"></span>Milepost device context — arrives in Phase 2</p>
        <p><span class="suite-dot"></span>Coastmark invoice handoff — arrives in Phase 2</p>
      </div>

      <div class="rail-label">Team</div>
      <div class="team-row"><?php foreach ($team as $mate): ?><?= avatar($mate, 24) ?><?php endforeach; ?></div>
    </div>
  </div>
</div>
<?php
page_bottom(palette_data());
