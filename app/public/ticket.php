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

// ---- reply (POST → redirect → GET) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply'])) {
    csrf_check();
    $body = trim((string)$_POST['reply']);
    if ($body !== '') {
        db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
            ->execute([$id, $user['full_name'], 'tech', $body]);
        if ($ticket['status'] === 'open') {
            db()->prepare("UPDATE tickets SET status = 'in_progress' WHERE id = ?")->execute([$id]);
        }
        // notify the client contact by email (queued; cron delivers)
        if (!empty($ticket['contact_id'])) {
            $kq = db()->prepare('SELECT email FROM contacts WHERE id = ?');
            $kq->execute([(int)$ticket['contact_id']]);
            $contactEmail = (string)($kq->fetch()['email'] ?? '');
            if ($contactEmail !== '') {
                mail_notify_reply($ticket, $contactEmail, $user['full_name'], $body);
            }
        }
    }
    header('Location: /ticket.php?id=' . $id . '#reply');
    exit;
}

$mq = db()->prepare('SELECT * FROM messages WHERE ticket_id = ? ORDER BY created_at ASC, id ASC');
$mq->execute([$id]);
$thread = $mq->fetchAll();

$team = db()->prepare('SELECT full_name, initials, color FROM users WHERE tenant_id = ? AND is_active = 1 ORDER BY id');
$team->execute([tenant_id()]);

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
          <textarea name="reply" id="reply-box" rows="3" placeholder="Reply to client…  (⌘Enter to send)"></textarea>
          <div class="reply-foot">
            <span class="reply-hint">Replying moves Open → In Progress automatically</span>
            <button type="submit" class="btn-primary btn-sm">Send</button>
          </div>
        </form>
      </div>
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
