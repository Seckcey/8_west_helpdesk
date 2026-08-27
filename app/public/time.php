<?php
/**
 * Time — reliable local capture plus an owner/admin approval queue. Timer
 * state stays client-side until an idempotent server acknowledgement;
 * suggestions are computed from activity without entries.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();
$user = require_login();
$tenantId = (int)$user['tenant_id'];
$canReview = in_array((string)$user['role'], ['owner', 'admin'], true);

// Today's entries (UTC day)
$eq = db()->prepare(
    'SELECT e.*, t.subject, t.id AS tid,
            replacement.id AS replacement_entry_id,
            replacement.approval_status AS replacement_status
       FROM time_entries e
       JOIN tickets t ON t.id = e.ticket_id AND t.tenant_id = e.tenant_id
       LEFT JOIN time_entries replacement
         ON replacement.tenant_id = e.tenant_id
        AND replacement.corrects_time_entry_id = e.id
      WHERE e.tenant_id = ? AND e.user_id = ? AND e.worked_at >= UTC_DATE()
      ORDER BY e.worked_at DESC, e.id DESC'
);
$eq->execute([$tenantId, (int)$user['id']]);
$entries = $eq->fetchAll();
$totalMin = array_sum(array_column($entries, 'minutes'));
$approvedBillable = count(array_filter(
    $entries,
    static fn($e) => (int)$e['billable'] === 1 && (string)$e['approval_status'] === 'approved'
));
$pendingCountQuery = db()->prepare(
    "SELECT COUNT(*) FROM time_entries
      WHERE tenant_id = ? AND user_id = ? AND approval_status = 'pending'"
);
$pendingCountQuery->execute([$tenantId, (int)$user['id']]);
$pendingOwn = (int)$pendingCountQuery->fetchColumn();

// Keep pending work and terminal decisions visible after the UTC day rolls
// over. This is the technician's durable status/correction trail, including
// rejection reasons among the most recent 100 earlier entries.
$historyQuery = db()->prepare(
    "SELECT e.*, t.subject, t.id AS tid,
            replacement.id AS replacement_entry_id,
            replacement.approval_status AS replacement_status
       FROM time_entries e
       JOIN tickets t ON t.id = e.ticket_id AND t.tenant_id = e.tenant_id
       LEFT JOIN time_entries replacement
         ON replacement.tenant_id = e.tenant_id
        AND replacement.corrects_time_entry_id = e.id
      WHERE e.tenant_id = ? AND e.user_id = ?
        AND e.worked_at < UTC_DATE()
      ORDER BY (e.approval_status = 'pending') DESC,
               COALESCE(e.reviewed_at, e.worked_at) DESC, e.id DESC
      LIMIT 100"
);
$historyQuery->execute([$tenantId, (int)$user['id']]);
$entryHistory = $historyQuery->fetchAll();

// Suggestions: active tickets with recent tech activity but no entry today.
$sq = db()->prepare(
    "SELECT t.id, t.subject,
            10 AS minutes,
            t.updated_at AS worked_at,
            'Recent activity today; conservative 10m suggestion' AS reason
       FROM tickets t
      WHERE t.tenant_id = ? AND t.status = 'in_progress' AND t.assignee_id = ?
        AND t.updated_at >= UTC_DATE()
        AND NOT EXISTS (
              SELECT 1 FROM time_entries e
               WHERE e.tenant_id = t.tenant_id AND e.ticket_id = t.id
                 AND e.user_id = ? AND e.worked_at >= UTC_DATE())
      ORDER BY t.updated_at DESC LIMIT 3"
);
$sq->execute([$tenantId, (int)$user['id'], (int)$user['id']]);
$suggestions = $sq->fetchAll();

$pendingReview = [];
if ($canReview) {
    $rq = db()->prepare(
        "SELECT e.*, t.subject, t.id AS tid, u.full_name AS tech_name, c.name AS client_name
           FROM time_entries e
           JOIN tickets t ON t.id = e.ticket_id AND t.tenant_id = e.tenant_id
           JOIN clients c ON c.id = e.client_id AND c.tenant_id = e.tenant_id
           JOIN users u ON u.id = e.user_id AND u.tenant_id = e.tenant_id
          WHERE e.tenant_id = ? AND e.approval_status = 'pending'
          ORDER BY e.worked_at ASC, e.id ASC"
    );
    $rq->execute([$tenantId]);
    $pendingReview = $rq->fetchAll();
}

$utcRfc3339 = static function (string $value): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    return $date ? $date->format('Y-m-d\\TH:i:s\\Z') : '';
};
$utcLabel = static function (?string $value): string {
    if ($value === null || $value === '') return 'not recorded';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    return $date ? $date->format('Y-m-d H:i:s') . ' UTC' : 'invalid timestamp';
};

$renderCorrectionLineage = static function (array $entry) use ($utcRfc3339): void {
    $correctionOf = isset($entry['corrects_time_entry_id'])
        ? (int)$entry['corrects_time_entry_id']
        : 0;
    $replacementId = isset($entry['replacement_entry_id'])
        ? (int)$entry['replacement_entry_id']
        : 0;
    if ($correctionOf > 0): ?>
      <div class="entry-lineage">Correction of rejected entry #<?= $correctionOf ?> · original history kept</div>
    <?php endif;
    if ((string)$entry['approval_status'] !== 'rejected') return;
    if ($replacementId > 0): ?>
      <div class="entry-lineage">Replacement #<?= $replacementId ?> is <?= h((string)$entry['replacement_status']) ?></div>
    <?php else:
      $startedAt = $entry['started_at'] === null ? '' : $utcRfc3339((string)$entry['started_at']);
      $endedAt = $entry['ended_at'] === null ? '' : $utcRfc3339((string)$entry['ended_at']);
    ?>
      <div class="correction-actions">
        <button type="button" class="btn-chip time-correct"
          data-rejected-entry-id="<?= (int)$entry['id'] ?>"
          data-minutes="<?= (int)$entry['minutes'] ?>"
          data-note="<?= h((string)$entry['note']) ?>"
          data-billable="<?= (int)$entry['billable'] ?>"
          data-worked-at="<?= h($utcRfc3339((string)$entry['worked_at'])) ?>"
          data-started-at="<?= h($startedAt) ?>"
          data-ended-at="<?= h($endedAt) ?>"
          data-measured="<?= $startedAt !== '' ? '1' : '0' ?>">Correct &amp; resubmit</button>
      </div>
    <?php endif;
};

page_top($user, 'Time', 'time');
?>
<div class="page page-time">
  <div class="page-head">
    <div>
      <h1 class="page-title">Time</h1>
      <p class="page-sub"><?= (int)$totalMin ?>m logged today · <?= $pendingOwn ?> pending · <?= $approvedBillable ?> approved billable</p>
    </div>
  </div>

  <div class="banner banner-warn" id="legacy-timer-quarantine" hidden>
    <span id="legacy-timer-message">An older timer has no technician identity and cannot be submitted automatically.</span>
    <button type="button" class="btn-chip" id="legacy-timer-claim">Claim as my timer</button>
    <button type="button" class="btn-link" id="legacy-timer-discard">Discard</button>
  </div>

  <div class="card timer-card" id="timer-card" data-state="idle">
    <span class="timer-dot" id="timer-dot"></span>
    <div class="timer-info">
      <div class="timer-title" id="timer-title">No timer running</div>
      <div class="timer-sub" id="timer-sub">Press <kbd class="kbd">E</kbd> on any ticket in the queue to start one — or open a ticket and hit Start timer.</div>
    </div>
    <span class="timer-clock" id="timer-clock"></span>
    <button class="btn-link timer-discard" id="timer-discard" hidden>Discard local timer</button>
    <button class="btn-gold" id="timer-stop" hidden>Stop &amp; log</button>
  </div>

  <?php if ($suggestions): ?>
  <div class="rail-label">Suggested from your activity</div>
  <div class="card rail-list" id="suggestions">
    <?php foreach ($suggestions as $s):
      $suggestionWorkedAt = $utcRfc3339((string)$s['worked_at']);
      $suggestionKey = 'suggestion:' . substr(hash('sha256', implode('|', [
          (string)$tenantId,
          (string)$user['id'],
          (string)$s['id'],
          (string)$s['worked_at'],
      ])), 0, 50);
    ?>
    <div class="sugg-row" data-ticket-id="<?= (int)$s['id'] ?>" data-minutes="<?= (int)$s['minutes'] ?>" data-entry-key="<?= h($suggestionKey) ?>" data-worked-at="<?= h($suggestionWorkedAt) ?>">
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
        <?php if ((string)$e['approval_status'] === 'rejected' && trim((string)$e['review_note']) !== ''): ?>
          <div class="entry-review-note">Review note: <?= h($e['review_note']) ?></div>
        <?php endif; ?>
        <?php $renderCorrectionLineage($e); ?>
      </div>
      <span class="entry-badge <?= (int)$e['billable'] ? 'badge-billable' : '' ?>"><?= (int)$e['billable'] ? 'billable' : 'internal' ?></span>
      <span class="entry-badge status-<?= h($e['approval_status']) ?>"><?= h($e['approval_status']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($entryHistory): ?>
  <div class="rail-label">Earlier entries</div>
  <div class="card rail-list" id="time-entry-history">
    <?php foreach ($entryHistory as $e): ?>
    <div class="entry-row">
      <span class="entry-min"><?= (int)$e['minutes'] ?>m</span>
      <div class="entry-main">
        <div class="entry-note"><?= h($e['note']) ?></div>
        <a class="entry-ticket link" href="/ticket.php?id=<?= (int)$e['tid'] ?>">#<?= (int)$e['tid'] ?> <?= h($e['subject']) ?></a>
        <?php if ((string)$e['approval_status'] === 'rejected' && trim((string)$e['review_note']) !== ''): ?>
          <div class="entry-review-note">Review note: <?= h($e['review_note']) ?></div>
        <?php endif; ?>
        <?php $renderCorrectionLineage($e); ?>
      </div>
      <span class="entry-badge status-<?= h($e['approval_status']) ?>"><?= h($e['approval_status']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($canReview): ?>
  <div class="rail-label">Pending review</div>
  <div class="card rail-list" id="time-review-queue">
    <?php if (!$pendingReview): ?>
      <div class="empty"><p>No technician time is waiting for review.</p></div>
    <?php endif; ?>
    <?php foreach ($pendingReview as $e): ?>
    <div class="entry-row review-row" data-time-entry-id="<?= (int)$e['id'] ?>">
      <span class="entry-min"><?= (int)$e['minutes'] ?>m</span>
      <div class="entry-main">
        <div class="entry-note"><?= h($e['tech_name']) ?> · <?= h($e['client_name']) ?> · <?= h($e['note']) ?></div>
        <a class="entry-ticket link" href="/ticket.php?id=<?= (int)$e['tid'] ?>">#<?= (int)$e['tid'] ?> <?= h($e['subject']) ?></a>
        <div class="entry-evidence">
          Worked <?= h($utcLabel((string)$e['worked_at'])) ?> · source <?= h($e['source']) ?> ·
          <?php if ($e['started_at'] !== null && $e['ended_at'] !== null): ?>
            measured <?= h($utcLabel((string)$e['started_at'])) ?> → <?= h($utcLabel((string)$e['ended_at'])) ?>
          <?php else: ?>
            no measured interval
          <?php endif; ?>
        </div>
        <?php if ($e['corrects_time_entry_id'] !== null): ?>
          <div class="entry-lineage">Replacement for rejected entry #<?= (int)$e['corrects_time_entry_id'] ?></div>
        <?php endif; ?>
      </div>
      <span class="entry-badge <?= (int)$e['billable'] ? 'badge-billable' : '' ?>"><?= (int)$e['billable'] ? 'billable' : 'internal' ?></span>
      <div class="review-actions">
        <button type="button" class="btn-chip time-review" data-decision="approved">Approve</button>
        <button type="button" class="btn-link time-review" data-decision="rejected">Reject</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <p class="page-note">Entries remain pending until an owner or admin reviews them. Approval does not post or invoice anything.</p>
</div>
<?php
page_bottom(palette_data());
