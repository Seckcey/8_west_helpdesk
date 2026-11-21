<?php
declare(strict_types=1);

function customer_summary_card(array $view, ?string $editedBody = null): string
{
    $latest = $view['latest'];
    ob_start();
    ?>
    <section class="card reply" id="customer-summary" aria-labelledby="customer-summary-heading">
      <h2 id="customer-summary-heading">Customer summary</h2>
      <?php if (($latest['state'] ?? '') === 'approved'): ?>
        <p class="msg-body"><?= h($latest['body']) ?></p>
        <?php if (!$view['approval_visible']): ?><p>This approved summary is currently hidden from the customer.</p><?php endif; ?>
        <?php if ($latest['email_result'] === 'submitted'): ?><p>The mail service accepted the summary.</p>
        <?php elseif ($latest['email_result'] !== null): ?><p>Email submission is unconfirmed. This ticket will not send another summary email.</p>
        <?php elseif ($view['delivery'] === 'email' && !$view['has_contact']): ?><p>No email was sent. This ticket has no contact with an eligible email address.</p><?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="customer_summary_action" value="revoke">
          <input type="hidden" name="review_id" value="<?= h((string)$latest['id']) ?>">
          <button class="btn-secondary" type="submit">Revoke summary</button>
        </form>
      <?php elseif ($view['draft']['body'] === null): ?>
        <p>The recorded steps or verification date are unsuitable for a customer summary. Review the work log below.</p>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="customer_summary_action" value="approve">
          <input type="hidden" name="review_id" value="<?= h((string)($latest['id'] ?? 0)) ?>">
          <input type="hidden" name="source_sha256" value="<?= h($view['draft']['source_sha256']) ?>">
          <label class="field" for="customer-summary-body">Text for the customer
            <textarea id="customer-summary-body" name="summary_body" rows="4" required maxlength="2000"><?= h($editedBody ?? $view['draft']['body']) ?></textarea>
          </label>
          <details>
            <summary>Review the steps customers will see</summary>
            <ol><?php foreach ($view['draft']['steps'] as $step): ?><li><?= h($step) ?></li><?php endforeach; ?></ol>
          </details>
          <p>The original steps appear below the summary in the portal. Approve only if both are suitable for the customer.</p>
          <?php if ($view['delivery'] === 'none'): ?><p>This client's settings keep the summary and steps hidden.</p>
          <?php elseif ($view['delivery'] === 'email' && !$view['has_contact']): ?><p>Approval publishes to the portal. This ticket has no contact with an eligible email address.</p>
          <?php elseif ($view['delivery'] === 'email'): ?><p>Approval publishes to the portal and emails the ticket contact once per ticket.</p>
          <?php else: ?><p>Approval publishes the summary and customer-visible steps to the portal.</p><?php endif; ?>
          <button class="btn-primary" type="submit">Approve summary</button>
        </form>
      <?php endif; ?>
      <a class="link" href="/customer_summaries.php">Summaries awaiting approval</a>
    </section>
    <?php
    return (string)ob_get_clean();
}
