<?php
/**
 * Ticket detail — conversation center, context right. Resolve without
 * ever leaving this view. Reply = POST back here (PRG).
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/mailer.php';
enforce_https();
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT t.*, c.name AS client_name, c.sla_tier,
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply'])) {
    csrf_check();
    $body   = utf8_clean(trim((string)$_POST['reply']));
    $isNote = (($_POST['mode'] ?? 'reply') === 'note');
    if ($body !== '') {
        db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
            ->execute([$id, $user['full_name'], $isNote ? 'note' : 'tech', $body]);
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
  <div class="ticket-head">
    <span class="ticket-num">#<?= (int)$ticket['id'] ?></span>
    <h1 class="ticket-subject"><?= h($ticket['subject']) ?></h1>
    <span data-chip><?= status_chip($ticket['status']) ?></span>
    <span data-pri><?= priority_glyph($ticket['priority'], true) ?></span>
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
          </div>
        <?php else: $isTech = $msg['kind'] === 'tech'; ?>
          <div class="card msg <?= $isTech ? 'msg-tech' : '' ?>">
            <div class="msg-meta">
              <span class="<?= $isTech ? 'msg-author-tech' : 'msg-author' ?>"><?= h($msg['author_name']) ?></span>
              <span class="msg-when"><?= $isTech ? 'tech' : 'client' ?> · <?= rel_time($msg['created_at']) ?></span>
            </div>
            <p class="msg-body"><?= h($msg['body']) ?></p>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>

      <div class="card reply" id="reply">
        <form method="post" action="/ticket.php?id=<?= (int)$ticket['id'] ?>" id="reply-form">
          <?= csrf_field() ?>
          <input type="hidden" name="mode" id="composer-mode" value="reply">
          <input type="hidden" name="timer_minutes" id="f-timer-minutes" value="0">
          <div class="composer-tabs" role="tablist">
            <button type="button" class="composer-tab tab-on" data-mode="reply" role="tab">Reply <kbd class="kbd">R</kbd></button>
            <button type="button" class="composer-tab" data-mode="note" role="tab">Internal note <kbd class="kbd">N</kbd></button>
            <span class="composer-slash-hint"><kbd class="kbd">/</kbd> saved replies</span>
          </div>
          <div class="canned-pop" id="canned-pop" hidden></div>
          <textarea name="reply" id="reply-box" rows="3" placeholder="Reply to client…  (⌘Enter to send · / for saved replies)"></textarea>
          <div class="reply-foot">
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

      <div class="rail-label">SLA</div>
      <div class="card rail-card"><?= sla_lamp($ticket) ?><span class="rail-note"><?= h($ticket['sla_tier']) ?> plan · business hours 8a–6p</span></div>

      <div class="rail-label">Time</div>
      <button class="rail-btn timer-btn" id="timer-btn" data-id="<?= (int)$ticket['id'] ?>">▶ Start timer&nbsp;&nbsp;(E)</button>

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
