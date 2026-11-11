<?php
/**
 * Time — reliable local capture plus an owner/admin approval queue. Timer
 * state stays client-side until an idempotent server acknowledgement;
 * suggestions are computed from activity without entries.
 *
 * The rows are drawn by lib/time_page.php: how long, what, which ticket, billable or not, and
 * where it stands. Who approved it and when, how it was logged, the measured start and end, and
 * every change with its reason are under "History" on the row, with names and local time.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/coastmark_time_billing.php';
require_once __DIR__ . '/../lib/time_page.php';
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

$ownApprovedEntryIds = [];
foreach (array_merge($entries, $entryHistory) as $entry) {
    if ((string) ($entry['approval_status'] ?? '') === 'approved') {
        $ownApprovedEntryIds[] = (int) $entry['id'];
    }
}
$ownApprovedAdjustments = $ownApprovedEntryIds === []
    ? []
    : time_entry_adjustment_latest_by_entry(db(), $tenantId, array_values(array_unique($ownApprovedEntryIds)));
$approvedBillable = count(array_filter(
    $entries,
    static function (array $entry) use ($ownApprovedAdjustments): bool {
        if ((string) $entry['approval_status'] !== 'approved') return false;
        $latest = $ownApprovedAdjustments[(int) $entry['id']] ?? null;
        return $latest === null
            ? (int) $entry['billable'] === 1
            : (bool) $latest['effective_billable'];
    },
));

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

// Adjustment history is a manager-only tenant ledger. Recent approved rows
// are shown by default; an exact id lookup can retrieve an older approved row
// without widening the query to another tenant or exposing arbitrary search.
$approvedEntries = [];
$approvedAdjustments = [];
$approvedAdjustmentHistories = [];
$approvedEntryLookupRaw = trim((string) ($_GET['approved_entry_id'] ?? ''));
$approvedEntryLookupMessage = '';
// The search box: a ticket's number or part of a client's name. Same tenant, approved entries only,
// owners and admins only, as the list itself.
$approvedSearch = time_approved_search($_GET['approved'] ?? '');
if ($canReview) {
    $approvedSql =
        "SELECT e.*, t.subject, t.id AS tid,
                u.full_name AS tech_name, c.name AS client_name,
                reviewer.full_name AS reviewer_name
           FROM time_entries e
           JOIN tickets t ON t.id = e.ticket_id AND t.tenant_id = e.tenant_id
           JOIN clients c ON c.id = e.client_id AND c.tenant_id = e.tenant_id
           JOIN users u ON u.id = e.user_id AND u.tenant_id = e.tenant_id
           LEFT JOIN users reviewer
             ON reviewer.id = e.reviewed_by_user_id
            AND reviewer.tenant_id = e.tenant_id
          WHERE e.tenant_id = ? AND e.approval_status = 'approved'";
    $approvedWhere = '';
    $approvedParams = [$tenantId];
    if ($approvedSearch['ticket'] > 0) {
        $approvedWhere = ' AND e.ticket_id = ?';
        $approvedParams[] = $approvedSearch['ticket'];
    } elseif ($approvedSearch['client'] !== '') {
        $approvedWhere = ' AND c.name LIKE ?';
        $approvedParams[] = time_like($approvedSearch['client']);
    }
    $approvedQuery = db()->prepare(
        $approvedSql . $approvedWhere . ' ORDER BY e.reviewed_at DESC, e.id DESC LIMIT 50'
    );
    $approvedQuery->execute($approvedParams);
    $approvedEntries = $approvedQuery->fetchAll();

    if ($approvedEntryLookupRaw !== '') {
        if (preg_match('/\A[1-9][0-9]{0,9}\z/D', $approvedEntryLookupRaw) !== 1
            || (int) $approvedEntryLookupRaw > 4294967295
        ) {
            $approvedEntryLookupMessage = 'That entry number is not valid.';
        } else {
            $lookupId = (int) $approvedEntryLookupRaw;
            $alreadyVisible = array_filter(
                $approvedEntries,
                static fn(array $entry): bool => (int) $entry['id'] === $lookupId,
            );
            if ($alreadyVisible === []) {
                $lookupQuery = db()->prepare($approvedSql . ' AND e.id = ? LIMIT 1');
                $lookupQuery->execute([$tenantId, $lookupId]);
                $lookedUp = $lookupQuery->fetch();
                if (is_array($lookedUp)) {
                    array_unshift($approvedEntries, $lookedUp);
                } else {
                    $approvedEntryLookupMessage = 'No approved entry has that number.';
                }
            }
        }
    }

    $approvedEntryIds = array_map(
        static fn(array $entry): int => (int) $entry['id'],
        $approvedEntries,
    );
    $approvedAdjustmentHistories = $approvedEntryIds === []
        ? []
        : time_entry_adjustment_history_by_entry(db(), $tenantId, $approvedEntryIds);
    foreach ($approvedAdjustmentHistories as $entryId => $history) {
        if ($history !== []) {
            $approvedAdjustments[$entryId] = $history[array_key_last($history)];
        }
    }
}

$billingConfig = (array)cfg('coastmark_time_export', []);
$billingEntries = [];
$billingUnavailable = false;
if ($canReview && $approvedEntries !== []) {
    try {
        $billingDb = coastmark_time_export_database((array)cfg('db', []), $billingConfig);
        $billingEntries = coastmark_time_billing_entries($billingDb, $tenantId, $approvedEntryIds);
    } catch (Throwable $error) {
        $billingUnavailable = true;
        error_log('time billing display: ' . get_class($error));
    }
}

// Names, not numbers: whoever reviewed an entry on this page or changed an approved one.
$peopleIds = [];
foreach (array_merge($entries, $entryHistory, $approvedEntries) as $entry) {
    if ($entry['reviewed_by_user_id'] !== null) $peopleIds[(int)$entry['reviewed_by_user_id']] = true;
}
foreach ($approvedAdjustmentHistories as $history) {
    foreach ($history as $change) $peopleIds[(int)$change['actor_user_id']] = true;
}
$peopleNames = [];
if ($peopleIds !== []) {
    $peopleQuery = db()->prepare(
        'SELECT id, full_name FROM users WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($peopleIds), '?')) . ')'
    );
    $peopleQuery->execute(array_merge([$tenantId], array_keys($peopleIds)));
    foreach ($peopleQuery->fetchAll() as $person) $peopleNames[(int)$person['id']] = (string)$person['full_name'];
}

$suitePreferences = is_array($_SESSION['suite_preferences'] ?? null) ? $_SESSION['suite_preferences'] : suite_preferences_defaults();
$timeZone = (string)($suitePreferences['time_zone'] ?? 'UTC');

page_top($user, 'Time', 'time');
?>
<div class="page page-time">
  <div class="page-head">
    <div>
      <h1 class="page-title">Time</h1>
      <p class="page-sub"><?= h(ticket_minutes_label((int)$totalMin)) ?> logged today. <?= $pendingOwn ?> waiting for review. <?= $approvedBillable ?> approved and billable today.</p>
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
      <div class="timer-sub" id="timer-sub">Press <kbd class="kbd">E</kbd> on a ticket in the queue to start one, or open a ticket and press Start timer.</div>
    </div>
    <span class="timer-clock" id="timer-clock"></span>
    <button class="btn-link timer-discard" id="timer-discard" hidden>Discard</button>
    <button class="btn-gold" id="timer-stop" hidden>Stop and log</button>
  </div>

  <?php if ($suggestions): ?>
  <h2 class="rail-label">Suggested from your activity</h2>
  <div class="card rail-list" id="suggestions">
    <?php foreach ($suggestions as $s):
      $suggestionWorkedAt = time_rfc3339((string)$s['worked_at']);
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
        <div class="sugg-title"><?= h(ticket_minutes_label((int)$s['minutes'])) ?> on <a class="link" href="/ticket.php?id=<?= (int)$s['id'] ?>">#<?= (int)$s['id'] ?> <?= h($s['subject']) ?></a></div>
        <div class="sugg-reason">You worked on this ticket today and logged no time on it. <?= h(ticket_minutes_label((int)$s['minutes'])) ?> is a starting guess.</div>
      </div>
      <button class="btn-chip sugg-accept">Log it</button>
      <button class="btn-link sugg-dismiss">Dismiss</button>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <h2 class="rail-label">Today</h2>
  <div class="card rail-list">
    <?php if (!$entries): ?>
      <div class="empty"><p>Nothing logged today yet. Start a timer on a ticket, or press E on a row in the queue.</p></div>
    <?php endif; ?>
    <?php foreach ($entries as $e): ?>
      <?= time_own_row($e, $ownApprovedAdjustments[(int)$e['id']] ?? null, $peopleNames, $timeZone) ?>
    <?php endforeach; ?>
  </div>

  <?php if ($entryHistory): ?>
  <h2 class="rail-label">Earlier</h2>
  <div class="card rail-list" id="time-entry-history">
    <?php foreach ($entryHistory as $e): ?>
      <?= time_own_row($e, $ownApprovedAdjustments[(int)$e['id']] ?? null, $peopleNames, $timeZone) ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($canReview): ?>
  <h2 class="rail-label">To review</h2>
  <div class="card rail-list" id="time-review-queue">
    <?php if (!$pendingReview): ?>
      <div class="empty"><p>No time is waiting for review.</p></div>
    <?php endif; ?>
    <?php foreach ($pendingReview as $e): ?>
      <?= time_review_row($e, $timeZone) ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($canReview): ?>
  <div class="time-ledger-head">
    <h2 class="rail-label">Approved, ready for billing</h2>
    <form class="time-search" method="get" action="/time.php" id="approved-search" role="search">
      <label class="sr-only" for="approved-q">Search approved time by ticket number or client</label>
      <input type="search" name="approved" id="approved-q" value="<?= h($approvedSearch['text']) ?>" placeholder="Ticket number or client" autocomplete="off" maxlength="100">
      <button type="submit" class="btn-secondary">Search</button>
      <?php if ($approvedSearch['text'] !== '' || $approvedEntryLookupRaw !== ''): ?>
        <a class="btn-link" href="/time.php">Clear</a>
      <?php endif; ?>
    </form>
  </div>
  <?php if ($approvedSearch['text'] !== ''): ?>
    <p class="page-note" role="status"><?= $approvedEntries === []
        ? 'No approved time matches “' . h($approvedSearch['text']) . '”.'
        : 'Approved time for “' . h($approvedSearch['text']) . '”, the newest first' . (count($approvedEntries) >= 50 ? ' (the first 50)' : '') . '.' ?></p>
  <?php endif; ?>
  <?php if ($approvedEntryLookupMessage !== ''): ?>
    <div class="form-error" role="status"><?= h($approvedEntryLookupMessage) ?></div>
  <?php endif; ?>

  <div class="card rail-list" id="time-adjustment-ledger">
    <?php if ($approvedEntries === [] && $approvedSearch['text'] === ''): ?>
      <div class="empty"><p>No time has been approved yet.</p></div>
    <?php endif; ?>
    <?php foreach ($approvedEntries as $e):
      $adjustmentHistory = $approvedAdjustmentHistories[(int)$e['id']] ?? [];
      $adjustmentVersion = isset($approvedAdjustments[(int)$e['id']]) ? (int)$approvedAdjustments[(int)$e['id']]['version'] : 0;
      $billingState = coastmark_time_billing_state($e, $billingEntries[(int)$e['id']] ?? null, $adjustmentVersion, $billingConfig);
    ?>
      <?= time_approved_row($e, $adjustmentHistory, $peopleNames, $billingState, $billingUnavailable, $timeZone) ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <p class="page-note">Approving time does not send an invoice. Invoices are made in Coastmark.</p>
</div>
<?php
page_bottom(palette_data());
