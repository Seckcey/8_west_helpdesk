<?php
/**
 * Time — the weapon against Friday-afternoon batch entry. Timer state is
 * client-side (localStorage); stopping logs a real time_entries row via
 * api/timer.php. Suggestions are computed from activity without entries.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();

// Today's entries (UTC day)
$eq = db()->prepare(
    'SELECT e.*, t.subject, t.id AS tid
       FROM time_entries e JOIN tickets t ON t.id = e.ticket_id
      WHERE e.user_id = ? AND e.created_at >= UTC_DATE()
      ORDER BY e.created_at DESC'
);
$eq->execute([(int)$user['id']]);
$entries = $eq->fetchAll();
$totalMin = array_sum(array_column($entries, 'minutes'));
$billable = count(array_filter($entries, static fn($e) => (int)$e['billable'] === 1));

// Suggestions: active tickets with recent tech activity but no entry today.
$sq = db()->prepare(
    "SELECT t.id, t.subject,
            LEAST(45, GREATEST(10, TIMESTAMPDIFF(MINUTE, t.updated_at, UTC_TIMESTAMP()))) AS minutes,
            CONCAT('Active ', TIMESTAMPDIFF(MINUTE, t.updated_at, UTC_TIMESTAMP()), 'm ago, no time logged today') AS reason
       FROM tickets t
      WHERE t.tenant_id = ? AND t.status = 'in_progress' AND t.assignee_id = ?
        AND NOT EXISTS (
              SELECT 1 FROM time_entries e
               WHERE e.ticket_id = t.id AND e.user_id = ? AND e.created_at >= UTC_DATE())
      ORDER BY t.updated_at DESC LIMIT 3"
);
$sq->execute([tenant_id(), (int)$user['id'], (int)$user['id']]);
$suggestions = $sq->fetchAll();

page_top($user, 'Time', 'time');
?>
<div class="page page-time">
  <div class="page-head">
    <div>
      <h1 class="page-title">Time</h1>
      <p class="page-sub"><?= (int)$totalMin ?>m logged today · <?= $billable ?> billable entries</p>
    </div>
  </div>

  <div class="card timer-card" id="timer-card" data-state="idle">
    <span class="timer-dot" id="timer-dot"></span>
    <div class="timer-info">
      <div class="timer-title" id="timer-title">No timer running</div>
      <div class="timer-sub" id="timer-sub">Press <kbd class="kbd">E</kbd> on any ticket in the queue to start one — or open a ticket and hit Start timer.</div>
    </div>
    <span class="timer-clock" id="timer-clock"></span>
    <button class="btn-gold" id="timer-stop" hidden>Stop &amp; log</button>
  </div>

  <?php if ($suggestions): ?>
  <div class="rail-label">Suggested from your activity</div>
  <div class="card rail-list" id="suggestions">
    <?php foreach ($suggestions as $s): ?>
    <div class="sugg-row" data-ticket-id="<?= (int)$s['id'] ?>" data-minutes="<?= (int)$s['minutes'] ?>">
      <span class="sugg-dot"></span>
      <div class="sugg-main">
        <div class="sugg-title"><?= (int)$s['minutes'] ?>m on <a class="link" href="/ticket.php?id=<?= (int)$s['id'] ?>">#<?= (int)$s['id'] ?> <?= h($s['subject']) ?></a></div>
        <div class="sugg-reason"><?= h($s['reason']) ?></div>
      </div>
      <button class="btn-chip sugg-accept">Log it</button>
      <button class="btn-link sugg-dismiss">Dismiss</button>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="rail-label">Today's entries</div>
  <div class="card rail-list">
    <?php if (!$entries): ?>
      <div class="empty"><p>Nothing logged yet. Start a timer — future-you says thanks.</p></div>
    <?php endif; ?>
    <?php foreach ($entries as $e): ?>
    <div class="entry-row">
      <span class="entry-min"><?= (int)$e['minutes'] ?>m</span>
      <div class="entry-main">
        <div class="entry-note"><?= h($e['note']) ?></div>
        <a class="entry-ticket link" href="/ticket.php?id=<?= (int)$e['tid'] ?>">#<?= (int)$e['tid'] ?></a>
      </div>
      <span class="entry-badge <?= (int)$e['billable'] ? 'badge-billable' : '' ?>"><?= (int)$e['billable'] ? 'billable' : 'internal' ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="page-note">Phase 2: approved entries flow straight into the client's Coastmark invoice. No exports. No reconciliation.</p>
</div>
<?php
page_bottom(palette_data());
