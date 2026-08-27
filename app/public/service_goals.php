<?php
/** Signed-in, tenant-scoped, read-only service-goal policy history. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/service_goal_policy_admin.php';

enforce_https();
$user = require_login();
if (! service_goal_policy_can_view_history($user)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Active Safeharbor staff role required.';
    exit;
}

$history = null;
$historyError = null;
try {
    $history = service_goal_policy_history(db(), (int) $user['tenant_id']);
} catch (Throwable $error) {
    error_log('service-goal history unavailable: ' . $error::class);
    $historyError = 'Policy history is temporarily unavailable. No policy data was changed.';
    http_response_code(503);
}

$stateLabels = [
    'current' => 'Current',
    'scheduled' => 'Scheduled',
    'superseded' => 'Superseded',
];

page_top($user, 'Service goals', 'service-goals');
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">Service goals</h1>
      <p class="page-sub">Immutable first-response policy history for <?= h((string) ($history['tenant']['name'] ?? 'your tenant')) ?></p>
    </div>
    <?php if (is_array($history)): ?>
      <span class="tier">Read-only · as of <?= h((string) $history['as_of_utc']) ?> UTC</span>
    <?php endif; ?>
  </div>

  <?php if ($historyError !== null): ?>
    <div class="form-error"><?= h($historyError) ?></div>
  <?php else: ?>
    <div class="stats goal-semantics" aria-label="Service-goal behavior">
      <div class="card stat"><div class="stat-k">Measures</div><div class="stat-v">First response</div><div class="stat-hint">Not resolution time</div></div>
      <div class="card stat"><div class="stat-k">Clock</div><div class="stat-v">Elapsed</div><div class="stat-hint">Every minute counts</div></div>
      <div class="card stat"><div class="stat-k">Time zone</div><div class="stat-v">UTC</div><div class="stat-hint">One shared clock</div></div>
      <div class="card stat"><div class="stat-k">Waiting</div><div class="stat-v">No pause</div><div class="stat-hint">No business calendar or resolution goal</div></div>
    </div>

    <p class="page-note">A ticket keeps the exact policy version captured when it opened. Changing a client tier, ticket priority, status, or a later policy never rewrites that history.</p>

    <div class="goal-policy-grid">
      <?php foreach ($history['policies'] as $policy): ?>
        <section class="card goal-policy" aria-labelledby="goal-policy-<?= h((string) $policy['policy_key']) ?>">
          <div class="goal-policy-head">
            <div>
              <h2 id="goal-policy-<?= h((string) $policy['policy_key']) ?>" class="goal-policy-title"><?= h((string) $policy['display_name']) ?></h2>
              <p class="goal-policy-sub"><?= count($policy['versions']) ?> immutable version<?= count($policy['versions']) === 1 ? '' : 's' ?></p>
            </div>
            <span class="tier <?= $policy['policy_key'] === 'premium' ? 'tier-premium' : '' ?>">
              <?= $policy['current_version'] === null ? 'Not initialized' : 'Current v' . (int) $policy['current_version'] ?>
            </span>
          </div>

          <?php if ($policy['versions'] === []): ?>
            <div class="empty"><p>No policy history exists yet. Viewing this page does not create one.</p></div>
          <?php endif; ?>

          <?php foreach (array_reverse($policy['versions']) as $version): ?>
            <?php $state = (string) $version['state']; ?>
            <article class="goal-version" data-policy-state="<?= h($state) ?>">
              <div class="goal-version-head">
                <div>
                  <h3 class="goal-version-title">Version <?= (int) $version['version_no'] ?></h3>
                  <p class="goal-version-time">Effective <?= h((string) $version['effective_from']) ?> UTC</p>
                </div>
                <span class="goal-state goal-state-<?= h($state) ?>"><?= h($stateLabels[$state] ?? 'Unknown') ?></span>
              </div>

              <div class="goal-targets" aria-label="Version <?= (int) $version['version_no'] ?> first-response targets">
                <?php foreach ($version['targets'] as $target): ?>
                  <?php $minutes = (int) $target['first_response_minutes']; ?>
                  <div class="goal-target">
                    <span class="goal-target-priority"><?= h(ucfirst((string) $target['priority'])) ?></span>
                    <strong class="goal-target-value"><?= h(service_goal_window_label($minutes)) ?></strong>
                    <span class="goal-target-minutes"><?= $minutes ?> minutes</span>
                  </div>
                <?php endforeach; ?>
              </div>

              <?php if ((int) $version['version_no'] === 1 && $version['created_by_user_id'] === null): ?>
                <div class="goal-attribution">
                  <strong>Legacy v1 attribution</strong>
                  <span>The original baseline recorded no historical approver or reason, so none is invented.</span>
                </div>
              <?php else: ?>
                <div class="goal-attribution">
                  <strong>Published by <?= h((string) ($version['created_by_name'] ?? ('user #' . (int) $version['created_by_user_id']))) ?></strong>
                  <span><?= h((string) ($version['reason'] ?? 'No publication reason recorded.')) ?></span>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
page_bottom(palette_data());
