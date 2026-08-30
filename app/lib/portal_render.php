<?php
/** Dark-by-default presentation for the customer help portal. */
declare(strict_types=1);

function portal_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function portal_security_headers(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
}

function portal_page_start(string $title, string $bodyClass = ''): void
{
    portal_security_headers();
    ?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#061936">
<title><?= portal_h($title) ?> · Safeharbor</title>
<link rel="icon" type="image/svg+xml" href="/assets/brand/favicon.svg">
<link rel="stylesheet" href="/assets/css/app.css?v=4">
</head>
<body<?= $bodyClass !== '' ? ' class="' . portal_h($bodyClass) . '"' : '' ?>>
    <?php
}

function portal_page_end(): void
{
    echo "</body>\n</html>\n";
}

function portal_render_login(?string $message = null): void
{
    portal_page_start('Customer portal', 'login-body');
    ?>
<main class="login-wrap">
  <header class="login-head">
    <img class="login-mark" src="/assets/brand/favicon.svg" alt="">
    <h1 class="login-name">Safeharbor</h1>
    <p class="login-tag">Ask for help and follow every customer-visible update</p>
  </header>
  <section class="card login-card" aria-labelledby="portal-sign-in-heading">
    <h2 id="portal-sign-in-heading" class="page-title">Customer sign in</h2>
    <p class="page-sub">Continue with your organization’s 8 West ID account.</p>
    <?php if ($message !== null && $message !== ''): ?>
      <p class="login-error" role="alert"><?= portal_h($message) ?></p>
    <?php endif; ?>
    <div class="login-form">
      <a class="btn-primary" href="/portal/login.php">Continue with 8 West ID</a>
    </div>
  </section>
  <p class="login-foot">Use the portal to open, read, and reply to support tickets. Device control, internal notes, technician time, and billing stay private.</p>
</main>
    <?php
    portal_page_end();
}

function portal_render_error(int $status, string $title, string $message): void
{
    http_response_code($status);
    portal_page_start($title, 'login-body');
    ?>
<main class="login-wrap">
  <header class="login-head">
    <img class="login-mark" src="/assets/brand/favicon.svg" alt="">
    <h1 class="login-name">Safeharbor</h1>
  </header>
  <section class="card login-card">
    <h2 class="page-title"><?= portal_h($title) ?></h2>
    <p class="page-sub" role="alert"><?= portal_h($message) ?></p>
  </section>
</main>
    <?php
    portal_page_end();
}

function portal_status_label(string $status): string
{
    return match ($status) {
        'open' => 'Open',
        'in_progress' => 'In progress',
        'waiting' => 'Waiting',
        'resolved' => 'Resolved',
        default => 'Unknown',
    };
}

function portal_relative_time(string $utc): string
{
    $timestamp = strtotime($utc . ' UTC');
    if ($timestamp === false) return 'Unknown';
    $minutes = max(1, (int)floor((time() - $timestamp) / 60));
    if ($minutes < 60) return $minutes . 'm ago';
    $hours = intdiv($minutes, 60);
    if ($hours < 24) return $hours . 'h ago';
    return intdiv($hours, 24) . 'd ago';
}

function portal_format_utc(?string $utc): string
{
    if ($utc === null || $utc === '') return 'Not available';
    $timestamp = strtotime($utc . ' UTC');
    return $timestamp === false ? 'Not available' : gmdate('M j, Y · g:i A', $timestamp) . ' UTC';
}

function portal_role_label(string $role): string
{
    return match ($role) {
        'client_owner' => 'Customer owner',
        'client_admin' => 'Customer admin',
        'client_staff' => 'Customer staff',
        'client_viewer' => 'Customer viewer',
        default => 'Customer',
    };
}

function portal_priority_label(string $priority): string
{
    return match ($priority) {
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
        default => 'Normal',
    };
}

/**
 * @param array{identity:array<string,mixed>,binding:array<string,mixed>} $context
 * @param array{client:array<string,mixed>,counts:array<string,int>,tickets:list<array<string,mixed>>} $summary
 */
function portal_render_dashboard(array $context, array $summary): void
{
    $identity = $context['identity'];
    $client = $summary['client'];
    $counts = $summary['counts'];
    $canWrite = portal_role_can_write_tickets((string)($identity['role'] ?? ''));
    portal_page_start('Help center');
    ?>
<main class="page">
  <header class="page-head">
    <div>
      <p class="page-sub">Safeharbor help center</p>
      <h1 class="page-title"><?= portal_h($client['name']) ?></h1>
      <p class="page-sub">Signed in as <?= portal_h($identity['display_name']) ?> · <?= portal_h(portal_role_label((string)$identity['role'])) ?> · secured by 8 West ID</p>
    </div>
    <div class="page-actions">
      <?php if ($canWrite): ?><a class="btn-primary" href="/portal/new.php">Get help</a><?php endif; ?>
      <form method="post" action="/portal/logout.php">
        <input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>">
        <button type="submit" class="btn-ghost">Sign out</button>
      </form>
    </div>
  </header>

  <section class="stats" aria-label="Ticket counts">
    <?php foreach ([
        'open' => ['Open', 'stat-warn'],
        'in_progress' => ['In progress', 'stat-good'],
        'waiting' => ['Waiting', 'stat-dim'],
        'resolved' => ['Resolved', 'stat-good'],
    ] as $status => [$label, $class]): ?>
      <article class="card stat">
        <div class="stat-k"><?= portal_h($label) ?></div>
        <div class="stat-v <?= portal_h($class) ?>"><?= (int)($counts[$status] ?? 0) ?></div>
      </article>
    <?php endforeach; ?>
  </section>

  <section aria-labelledby="portal-ticket-heading">
    <div class="page-head">
      <div>
        <h2 id="portal-ticket-heading" class="page-title">Recent tickets</h2>
        <p class="page-sub">Open a ticket to read the customer-visible conversation</p>
      </div>
    </div>
    <div class="card rail-list">
      <?php if ($summary['tickets'] === []): ?>
        <div class="empty">No tickets are available for this business.</div>
      <?php else: ?>
        <?php foreach ($summary['tickets'] as $ticket): ?>
          <a class="rail-list-item portal-ticket-link" href="/portal/ticket.php?id=<?= (int)$ticket['id'] ?>">
            <h3 class="rail-list-name">#<?= (int)$ticket['id'] ?> · <?= portal_h($ticket['subject']) ?></h3>
            <p class="rail-list-sub">
              <?= portal_h(portal_status_label((string)$ticket['status'])) ?>
              · <?= portal_h(portal_priority_label((string)$ticket['priority'])) ?> priority
              · Updated <?= portal_h(portal_relative_time((string)$ticket['updated_at'])) ?>
            </p>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <p class="page-note">Only your business’s customer-visible messages appear here. Internal notes, attachments, technician time, billing, AI controls, and endpoint controls remain private.</p>
  </section>
</main>
    <?php
    portal_page_end();
}

/** @param array{identity:array<string,mixed>,binding:array<string,mixed>} $context */
function portal_render_new_ticket(array $context, ?string $error = null, array $values = []): void
{
    $clientName = (string)($context['binding']['client_name'] ?? 'Your business');
    $nonce = portal_action_nonce('ticket:create');
    portal_page_start('Get help');
    ?>
<main class="page page-narrow">
  <a href="/portal/" class="backlink">← Help center</a>
  <header class="page-head">
    <div>
      <p class="page-sub"><?= portal_h($clientName) ?></p>
      <h1 class="page-title">What can we help with?</h1>
      <p class="page-sub">Tell the support team what is happening. A human remains responsible for resolving the ticket.</p>
    </div>
  </header>
  <?php if ($error !== null && $error !== ''): ?><div class="form-error" role="alert"><?= portal_h($error) ?></div><?php endif; ?>
  <section class="card form-card">
    <form method="post" action="/portal/new.php" class="form-grid">
      <input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>">
      <input type="hidden" name="action_nonce" value="<?= portal_h($nonce) ?>">
      <label class="field span-2">Short summary
        <input type="text" name="subject" maxlength="190" required autofocus autocomplete="off"
               value="<?= portal_h($values['subject'] ?? '') ?>"
               placeholder="The front desk computer cannot print">
      </label>
      <label class="field">How urgent is it?
        <select name="priority">
          <?php foreach (PORTAL_TICKET_PRIORITIES as $priority): ?>
            <option value="<?= portal_h($priority) ?>"<?= (($values['priority'] ?? 'normal') === $priority) ? ' selected' : '' ?>><?= portal_h(portal_priority_label($priority)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="field portal-priority-help">
        <span>Priority guide</span>
        <p>Urgent means the business cannot operate. High means important work is blocked.</p>
      </div>
      <label class="field span-2">What happened?
        <textarea name="body" maxlength="<?= PORTAL_TICKET_BODY_MAX_CHARACTERS ?>" required
                  placeholder="What were you trying to do, what happened instead, and who is affected?"><?= portal_h($values['body'] ?? '') ?></textarea>
      </label>
      <div class="form-actions span-2">
        <button type="submit" class="btn-primary">Send request</button>
        <a class="btn-link" href="/portal/">Cancel</a>
      </div>
    </form>
  </section>
</main>
    <?php
    portal_page_end();
}

/**
 * @param array{identity:array<string,mixed>,binding:array<string,mixed>} $context
 * @param array{ticket:array<string,mixed>,messages:list<array<string,mixed>>} $detail
 */
function portal_render_ticket(
    array $context,
    array $detail,
    ?string $notice = null,
    ?string $error = null,
    string $draft = '',
): void {
    $identity = $context['identity'];
    $ticket = $detail['ticket'];
    $ticketId = (int)$ticket['id'];
    $canWrite = portal_role_can_write_tickets((string)($identity['role'] ?? ''));
    $canReply = $canWrite && ($ticket['status'] ?? '') !== 'resolved';
    $nonce = $canReply ? portal_action_nonce('ticket:reply:' . $ticketId) : '';
    portal_page_start('Ticket #' . $ticketId);
    ?>
<main class="page">
  <a href="/portal/" class="backlink">← Help center</a>
  <header class="page-head portal-ticket-head">
    <div>
      <div class="ticket-head">
        <span class="ticket-num">#<?= $ticketId ?></span>
        <span class="portal-status portal-status-<?= portal_h((string)$ticket['status']) ?>"><?= portal_h(portal_status_label((string)$ticket['status'])) ?></span>
      </div>
      <h1 class="ticket-subject"><?= portal_h($ticket['subject']) ?></h1>
      <p class="ticket-sub"><?= portal_h(portal_priority_label((string)$ticket['priority'])) ?> priority · Opened <?= portal_h(portal_format_utc((string)$ticket['created_at'])) ?></p>
    </div>
    <?php if ($canWrite): ?><a class="btn-primary" href="/portal/new.php">New request</a><?php endif; ?>
  </header>

  <?php if ($notice !== null && $notice !== ''): ?><div class="form-ok" role="status"><?= portal_h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null && $error !== ''): ?><div class="form-error" role="alert"><?= portal_h($error) ?></div><?php endif; ?>

  <div class="ticket-grid">
    <section class="thread" aria-labelledby="portal-conversation-heading">
      <h2 id="portal-conversation-heading" class="rail-label">Conversation</h2>
      <?php if ($detail['messages'] === []): ?>
        <div class="card empty">No customer-visible messages have been posted yet.</div>
      <?php else: ?>
        <?php foreach ($detail['messages'] as $message):
            $isTech = ($message['kind'] ?? '') === 'tech'; ?>
          <article class="card msg <?= $isTech ? 'msg-tech' : 'msg-client' ?>">
            <div class="msg-meta">
              <span class="<?= $isTech ? 'msg-author-tech' : 'msg-author' ?>"><?= portal_h($message['author_name']) ?></span>
              <span class="msg-when"><?= portal_h(portal_format_utc((string)$message['created_at'])) ?></span>
            </div>
            <div class="msg-body"><?= portal_h($message['body']) ?></div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($canReply): ?>
        <form method="post" action="/portal/ticket.php?id=<?= $ticketId ?>" class="card reply">
          <input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>">
          <input type="hidden" name="action_nonce" value="<?= portal_h($nonce) ?>">
          <label class="field" for="portal-reply">Reply to the support team</label>
          <textarea id="portal-reply" name="body" maxlength="<?= PORTAL_TICKET_BODY_MAX_CHARACTERS ?>" required
                    placeholder="Add an update or answer the technician’s question…"><?= portal_h($draft) ?></textarea>
          <div class="reply-foot">
            <span class="reply-hint">Your reply is visible to your business and the support team.</span>
            <button type="submit" class="btn-primary btn-sm">Send reply</button>
          </div>
        </form>
      <?php elseif (($ticket['status'] ?? '') === 'resolved'): ?>
        <div class="card portal-closed-note">This ticket is resolved. Open a new request if you still need help.</div>
      <?php else: ?>
        <div class="card portal-closed-note">Your viewer role can read this ticket but cannot send replies.</div>
      <?php endif; ?>
    </section>

    <aside class="rail" aria-label="Ticket details">
      <h2 class="rail-label">Ticket details</h2>
      <div class="card rail-card">
        <span class="rail-k">Status</span>
        <span class="rail-v"><?= portal_h(portal_status_label((string)$ticket['status'])) ?></span>
      </div>
      <div class="card rail-card">
        <span class="rail-k">Response goal</span>
        <?php if (! empty($ticket['first_response_at'])): ?>
          <span class="rail-v">First response sent</span>
          <span class="rail-note"><?= portal_h(portal_format_utc((string)$ticket['first_response_at'])) ?></span>
        <?php else: ?>
          <span class="rail-v">Due <?= portal_h(portal_format_utc((string)$ticket['sla_due_at'])) ?></span>
          <?php if (! empty($ticket['service_goal_policy_name'])): ?>
            <span class="rail-note"><?= portal_h($ticket['service_goal_policy_name']) ?> v<?= (int)$ticket['service_goal_version_no'] ?> · <?= (int)$ticket['service_goal_response_minutes'] ?> elapsed minutes</span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="card rail-card">
        <span class="rail-k">Privacy</span>
        <span class="rail-note">Internal notes, automation evidence, technician time, billing, and device controls are never shown here.</span>
      </div>
    </aside>
  </div>
</main>
    <?php
    portal_page_end();
}
