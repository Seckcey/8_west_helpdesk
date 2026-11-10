<?php
/** Dark-by-default presentation for the customer help portal. */
declare(strict_types=1);
require_once __DIR__ . '/portal_shell.php';
require_once __DIR__ . '/portal_preferences.php';
require_once __DIR__ . '/first_steps.php';

function portal_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 8 West ID's exact origin, with the same fixed fallback as logout.php if the configured issuer can't be used. */
function portal_issuer_origin(): string
{
    try {
        $issuer = function_exists('portal_configured_issuer') ? parse_url(portal_configured_issuer()) : [];
    } catch (PortalAuthConfigurationException) {
        $issuer = [];
    }
    if (empty($issuer['host'])) return 'https://id.8westit.com';
    return 'https://' . $issuer['host'] . (isset($issuer['port']) ? ':' . $issuer['port'] : '');
}

/**
 * Chromium and WebKit apply form-action to every redirect after a form is sent. Sign out ends at 8 West ID, and so
 * does a GET form sent once the session has ended, so only pages with the account menu allow its origin.
 */
function portal_security_headers(bool $accountMenu): void
{
    $formAction = "'self'" . ($accountMenu ? ' ' . portal_issuer_origin() : '');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; script-src 'self'; connect-src 'self'; form-action {$formAction}; base-uri 'none'; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
}

function portal_page_start(string $title, string $bodyClass = '', ?array $context = null): void
{
    $GLOBALS['portal_view_context'] = $context;
    $brand = portal_branding($context);
    $preferences = portal_personal_preferences($context['identity'] ?? []);
    $GLOBALS['portal_view_preferences'] = $preferences;
    if ($context !== null) $bodyClass = trim('portal-app ' . $bodyClass);
    portal_security_headers($context !== null);
    ?>
<!doctype html>
<html lang="<?= portal_h($preferences['locale']) ?>" data-theme="<?= portal_h($preferences['theme']) ?>"
      data-preferences-source="8west-id" data-density="<?= portal_h($preferences['density']) ?>"
      data-contrast="<?= portal_h($preferences['contrast']) ?>" data-motion="<?= portal_h($preferences['motion']) ?>"
      data-time-zone="<?= portal_h($preferences['time_zone']) ?>" data-date-format="<?= portal_h($preferences['date_format']) ?>"
      data-time-format="<?= portal_h($preferences['time_format']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#061936">
<title><?= portal_h($title) ?> · <?= portal_h($brand['name']) ?></title>
<link rel="icon" type="<?= portal_h($brand['favicon_type']) ?>" href="<?= portal_h($brand['favicon']) ?>">
<link rel="stylesheet" href="/assets/css/app.css?v=<?= portal_asset_version(__DIR__.'/../public/assets/css/app.css') ?>">
<link rel="stylesheet" href="/assets/css/portal.css?v=<?= portal_asset_version(__DIR__.'/../public/assets/css/portal.css') ?>">
<link rel="stylesheet" href="/assets/css/portal-devices.css?v=<?= portal_asset_version(__DIR__.'/../public/assets/css/portal-devices.css') ?>">
<link rel="stylesheet" href="/assets/css/portal-workspace.css?v=<?= portal_asset_version(__DIR__.'/../public/assets/css/portal-workspace.css') ?>">
<?php if(str_contains($bodyClass,'portal-security-page')): ?><link rel="stylesheet" href="/assets/css/portal-security.css?v=<?= portal_asset_version(__DIR__.'/../public/assets/css/portal-security.css') ?>"><?php endif; ?>
</head>
<body<?= $bodyClass !== '' ? ' class="' . portal_h($bodyClass) . '"' : '' ?>>
    <?php
    if ($context !== null) portal_shell_start($context);
}

function portal_page_end(): void
{
    if (is_array($GLOBALS['portal_view_context'] ?? null)) {
        echo '</div>';
        portal_westy_widget($GLOBALS['portal_view_context']);
    }
    echo "</body>\n</html>\n";
}

/** Start customer OIDC on GET; never replay a POST or automatically retry a failure. */
function portal_require_sign_in(?string $message = null): void
{
    if (portal_login_can_start_automatically($_SERVER['REQUEST_METHOD'] ?? '', $_COOKIE)) {
        try {
            portal_redirect_to_identity($_SERVER['REQUEST_URI'] ?? '/portal/');
        } catch (Throwable $error) {
            error_log('[safeharbor-portal] login_failed type=' . $error::class);
            portal_render_error(503, 'Sign-in unavailable', '8 West ID sign-in is temporarily unavailable. Try again shortly.');
            return;
        }
    }
    portal_render_login($message ?? 'Sign-in was not completed. Continue with 8 West ID to try again.');
}

function portal_render_login(?string $message = null): void
{
    $brand = portal_branding();
    $returnPath = portal_safe_return_path($_SERVER['REQUEST_URI'] ?? '/portal/');
    portal_page_start('Customer portal', 'login-body login-split-body');
    ?>
<div class="login-split">
<div class="login-brand">
  <header class="login-head">
    <img class="suite-login-logo" src="<?= portal_h($brand['logo']) ?>" alt="<?= portal_h($brand['alt']) ?>" width="<?= $brand['width'] ?>" height="<?= $brand['height'] ?>">
    <h1 class="login-name"><?= portal_h($brand['name']) ?></h1>
    <p class="login-tag">Ask for help and follow every customer-visible update</p>
  </header>
</div>
<main class="login-wrap">
  <section class="card login-card" aria-labelledby="portal-sign-in-heading">
    <h2 id="portal-sign-in-heading" class="page-title">Customer sign in</h2>
    <p class="page-sub">Continue with your organization’s 8 West ID account.</p>
    <?php if ($message !== null && $message !== ''): ?>
      <p class="login-error" role="alert"><?= portal_h($message) ?></p>
    <?php endif; ?>
    <div class="login-form">
      <a class="btn-primary" href="/portal/login.php?next=<?= portal_h(rawurlencode($returnPath)) ?>">Continue with 8 West ID</a>
    </div>
  </section>
  <p class="login-foot">Use the portal to open, read, and reply to support tickets. Device control, internal notes, technician time, and billing stay private.</p>
</main>
</div>
    <?php
    portal_page_end();
}

function portal_render_client_choices(array $principal,array $choices): void
{
    portal_page_start('Choose a client for Westy');
    ?>
<main class="page page-narrow"><header class="page-head"><div>
<h1 class="page-title">Choose a client for Westy</h1>
<p class="page-sub">Continue as <?=portal_h($principal['display_name'])?>. Your 8 West ID account and other apps stay signed in.</p>
</div></header>
<?php if(!$choices): ?><p role="status">No customer access is currently assigned to this account. Ask your workspace administrator to review your access.</p><?php endif; ?>
<div class="portal-report-list"><?php foreach($choices as $choice): ?>
<section class="card login-card" aria-label="<?=portal_h($choice['binding']['client_name'])?>">
<h2 class="portal-client-name"><?=portal_h($choice['binding']['client_name'])?></h2>
<p class="page-sub"><?=portal_h(portal_role_label($choice['access']['role']))?></p>
<form method="post" class="login-form"><input type="hidden" name="csrf" value="<?=portal_h(portal_csrf_token())?>">
<input type="hidden" name="reference" value="<?=portal_h($choice['access']['access']['reference'])?>">
<input type="hidden" name="generation" value="<?=portal_h($choice['access']['access']['generation'])?>">
<button class="btn-primary" type="submit">Use Westy for this client</button></form></section>
<?php endforeach; ?></div></main>
<?php portal_page_end();
}

function portal_render_error(int $status, string $title, string $message): void
{
    $brand = portal_branding();
    http_response_code($status);
    portal_page_start($title, 'login-body');
    ?>
<main class="login-wrap">
  <header class="login-head">
    <img class="suite-login-logo" src="<?= portal_h($brand['logo']) ?>" alt="<?= portal_h($brand['alt']) ?>" width="<?= $brand['width'] ?>" height="<?= $brand['height'] ?>">
    <h1 class="login-name"><?= portal_h($brand['name']) ?></h1>
  </header>
  <section class="card login-card">
    <h2 class="page-title"><?= portal_h($title) ?></h2>
    <p class="page-sub" role="alert"><?= portal_h($message) ?></p>
  </section>
</main>
    <?php
    portal_page_end();
}

/** Where a Companion window lands after Sign out: its shell stays on this origin, so this page does too. */
function portal_render_signed_out(bool $connectionOpen): void
{
    $brand = portal_branding();
    portal_page_start('Signed out', 'login-body');
    ?>
<main class="login-wrap">
  <header class="login-head">
    <img class="suite-login-logo" src="<?= portal_h($brand['logo']) ?>" alt="<?= portal_h($brand['alt']) ?>" width="<?= $brand['width'] ?>" height="<?= $brand['height'] ?>">
    <h1 class="login-name"><?= portal_h($brand['name']) ?></h1>
  </header>
  <section class="card login-card" aria-labelledby="portal-signed-out-heading">
    <h2 id="portal-signed-out-heading" class="page-title">You’re signed out of Westy</h2>
    <div class="login-form">
    <?php if ($connectionOpen): ?>
      <p class="page-sub" role="status">Your private chat is closed, but Westy could not confirm that this computer disconnected. The connection ends on its own within 30 minutes. To end it now, quit Westy from its icon (the system tray on Windows, the menu bar on a Mac).</p>
    <?php else: ?>
      <p class="page-sub" role="status">Your private chat is closed and this computer is no longer connected to your account. To connect again, open Westy from its icon (the system tray on Windows, the menu bar on a Mac).</p>
    <?php endif; ?>
      <p class="page-sub">Your web browser keeps its own sign-in. On a shared computer, sign out there too.</p>
      <a class="btn-ghost" target="_blank" rel="noopener noreferrer" href="/portal/">Open the customer portal in your browser</a>
    </div>
  </section>
</main>
    <?php
    portal_page_end();
}

/**
 * The signed-out page's answer to a browser that is still signed in. It names nobody, like the page it stands in for,
 * and claims only what this page knows: opening it ended nothing. 8 West ID may still have revoked the sign-in.
 */
function portal_render_still_signed_in(): void
{
    $brand = portal_branding();
    portal_page_start('Not signed out', 'login-body');
    ?>
<main class="login-wrap">
  <header class="login-head">
    <img class="suite-login-logo" src="<?= portal_h($brand['logo']) ?>" alt="<?= portal_h($brand['alt']) ?>" width="<?= $brand['width'] ?>" height="<?= $brand['height'] ?>">
    <h1 class="login-name"><?= portal_h($brand['name']) ?></h1>
  </header>
  <section class="card login-card" aria-labelledby="portal-still-signed-in-heading">
    <h2 id="portal-still-signed-in-heading" class="page-title">This page didn’t sign you out</h2>
    <div class="login-form">
      <p class="page-sub" role="status">Your portal sign-in is as it was. Go back to the portal to carry on.</p>
      <a class="btn-ghost" href="/portal/">Go to the customer portal</a>
    </div>
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
        'waiting' => 'Waiting for your reply',
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
    return $timestamp === false ? 'Not available' : portal_personal_datetime($utc,
        $GLOBALS['portal_view_preferences'] ?? suite_preferences_defaults());
}

/**
 * A time the way a person says it, in the viewer's own time zone and clock style: "today at 9:22 AM",
 * "Monday at 9:22 AM", "Nov 3 at 9:22 AM". Empty when the time cannot be read. $now is for tests.
 */
function portal_friendly_time(?string $utc, ?int $now = null): string
{
    if ($utc === null || $utc === '') return '';
    $preferences = $GLOBALS['portal_view_preferences'] ?? suite_preferences_defaults();
    try {
        $zone = new DateTimeZone((string)$preferences['time_zone']);
        $when = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($zone);
        $current = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($zone);
    } catch (Throwable) {
        return '';
    }
    $twentyFourHour = $preferences['time_format'] === '24h'
        || ($preferences['time_format'] === 'locale' && $preferences['locale'] === 'en-GB');
    $dayFirst = $preferences['date_format'] === 'dmy'
        || ($preferences['date_format'] === 'locale' && in_array($preferences['locale'], ['en-GB', 'es-US'], true));
    $days = (int)$current->setTime(0, 0)->diff($when->setTime(0, 0))->format('%r%a');
    $day = match (true) {
        $days === 0 => 'today',
        $days === -1 => 'yesterday',
        $days === 1 => 'tomorrow',
        abs($days) < 7 => $when->format('l'),
        default => $when->format(($dayFirst ? 'j M' : 'M j') . ($when->format('Y') === $current->format('Y') ? '' : ', Y')),
    };
    return $day . ' at ' . $when->format($twentyFourHour ? 'H:i' : 'g:i A');
}

/** The friendly time as a <time> element; the full date, time and zone show on hover. */
function portal_time_tag(?string $utc, bool $capital = false): string
{
    $text = portal_friendly_time($utc);
    $timestamp = $utc === null ? false : strtotime($utc . ' UTC');
    if ($text === '' || $timestamp === false) return portal_h(portal_format_utc($utc));
    return '<time datetime="' . gmdate('Y-m-d\TH:i:s\Z', $timestamp) . '" title="' . portal_h(portal_format_utc($utc)) . '">'
        . portal_h($capital ? ucfirst($text) : $text) . '</time>';
}

/**
 * Where a request stands, in the customer's words. The page, its tests and the previews all read this,
 * so the progress line and the sentences can never disagree.
 *
 * The four steps are the four status words exactly (the approved Guide tells people to check Status and
 * names "Open" and "Waiting for your reply"). The policy name, its version and its minutes are never used.
 *
 * @param array<string,mixed> $ticket
 * @param list<array<string,mixed>> $messages customer-visible messages, oldest first
 * @return array{step:int,label:string,steps:list<array{label:string,state:string}>,next:list<string>,goal:?string}
 *         each step's state is done, now, later, or skipped (passed without a record that it happened)
 */
function portal_request_progress(array $ticket, array $messages, bool $canWrite, ?int $now = null): array
{
    $now ??= time();
    $order = ['open', 'in_progress', 'waiting', 'resolved'];
    $status = in_array($ticket['status'] ?? '', $order, true) ? (string)$ticket['status'] : 'open';
    $step = array_search($status, $order, true) + 1;

    $lastKind = '';
    $lastTeamReply = null;
    foreach ($messages as $message) {
        $lastKind = (string)($message['kind'] ?? '');
        if ($lastKind === 'tech') $lastTeamReply = (string)($message['created_at'] ?? '');
    }
    $firstReply = (string)($ticket['first_response_at'] ?? '');
    $answered = $firstReply !== '' || $lastTeamReply !== null;
    $teamReplied = portal_friendly_time($lastTeamReply ?? $firstReply, $now);
    $weReplied = $teamReplied === '' ? 'We replied' : "We replied {$teamReplied}";
    // The newest message the customer can see is ours: they may have been asked something.
    $ourMove = $answered && $lastKind === 'tech';

    // A step is "done" only when the record shows it happened. Nothing records an earlier wait for the
    // customer, so that step is never claimed; a technician's reply is what moves a request to In progress.
    $happened = [1 => true, 2 => $status === 'in_progress' || $answered, 3 => false, 4 => $status === 'resolved'];
    $steps = [];
    foreach ($order as $index => $name) {
        $position = $index + 1;
        $steps[] = [
            'label' => portal_status_label($name),
            'state' => match (true) {
                // A resolved request has nothing left to do, so its last step is done, not "current".
                $position === $step => $status === 'resolved' ? 'done' : 'now',
                $position > $step => 'later',
                default => $happened[$position] ? 'done' : 'skipped',
            },
        ];
    }

    $next = match ($status) {
        'resolved' => [
            ($resolved = portal_friendly_time((string)($ticket['resolved_at'] ?? ''), $now)) === ''
                ? 'This request is resolved.' : "Resolved {$resolved}.",
            'Nothing more is needed from you.',
        ],
        'waiting' => $canWrite
            ? ['It is your turn.', $answered ? $weReplied . ' and need your answer to carry on.' : 'The support team needs an answer from you to carry on.']
            : ['We are waiting for an answer from your business.',
                $answered ? $weReplied . '. Someone who can reply needs to answer.' : 'Someone who can reply needs to answer.'],
        'in_progress' => match (true) {
            // Never "you do not need to do anything" straight after we wrote to them.
            $ourMove && $canWrite => [$weReplied . '.', 'You can answer below. A technician is working on this.'],
            $ourMove => [$weReplied . '.', 'A technician is working on this.'],
            default => [
                'A technician is working on this.',
                $canWrite ? 'You do not need to do anything now. If something changes, tell us below.' : 'You do not need to do anything now.',
            ],
        },
        default => match (true) {
            $ourMove && $canWrite => [$weReplied . '.', 'You can answer below.'],
            $ourMove => [$weReplied . '.', 'This request is with the support team.'],
            $answered && $lastKind === 'client' => ['We have your reply.', 'A technician will pick this up again. You do not need to do anything now.'],
            $answered => ['This request is back with the support team.', 'You do not need to do anything now.'],
            default => [
                'We have your request.',
                $canWrite ? 'A technician will answer here. You do not need to do anything now, and you can add details below.'
                    : 'A technician will answer here. You do not need to do anything now.',
            ],
        },
    };

    $goal = null;
    // "We replied Monday at 9:22 AM" already says it when that reply was the first one.
    $saidAbove = ($status === 'waiting' || ($ourMove && $status !== 'resolved'))
        && $firstReply !== '' && ($lastTeamReply ?? $firstReply) === $firstReply;
    if ($saidAbove) {
        $goal = null;
    } elseif ($firstReply !== '' && ($sent = portal_friendly_time($firstReply, $now)) !== '') {
        $goal = "First reply sent {$sent}.";
    } elseif (! $answered && $status !== 'resolved' && ($due = portal_friendly_time((string)($ticket['sla_due_at'] ?? ''), $now)) !== '') {
        $late = strtotime((string)$ticket['sla_due_at'] . ' UTC') < $now;
        $goal = $late
            ? "We aimed to send a first reply by {$due} and are running late. A technician will answer as soon as possible."
            : "We aim to send a first reply by {$due}.";
    }

    return ['step' => $step, 'label' => portal_status_label($status), 'steps' => $steps, 'next' => $next, 'goal' => $goal];
}

function portal_role_label(string $role): string
{
    return match ($role) {
        'client_owner' => 'Client owner',
        'client_admin' => 'Client administrator',
        'client_staff' => 'Member',
        'client_viewer' => 'Viewer',
        default => 'Client account',
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

/** "20 minutes ago", "3 hours ago", "yesterday", "5 days ago", then a date. Empty when the time cannot be read. */
function portal_ago(?string $utc, ?int $now = null): string
{
    $timestamp = $utc === null || $utc === '' ? false : strtotime($utc . ' UTC');
    if ($timestamp === false) return '';
    $minutes = (int)floor((($now ?? time()) - $timestamp) / 60);
    if ($minutes < 1) return 'just now';
    if ($minutes < 60) return $minutes . ($minutes === 1 ? ' minute ago' : ' minutes ago');
    $hours = intdiv($minutes, 60);
    if ($hours < 24) return $hours . ($hours === 1 ? ' hour ago' : ' hours ago');
    $days = intdiv($hours, 24);
    if ($days === 1) return 'yesterday';
    if ($days < 7) return $days . ' days ago';
    $date = portal_friendly_time($utc, $now);
    return $date === '' ? '' : 'on ' . explode(' at ', $date)[0];
}

/**
 * One sentence for a request in a list: what is happening, from its status and its newest visible
 * message. A reply-by time is given only while the request has a due time, no first response yet, and
 * that time is still ahead; no other time is ever promised.
 *
 * @param array<string,mixed> $ticket a row of portal_ticket_summary()
 */
function portal_request_line(array $ticket, bool $canWrite, ?int $now = null): string
{
    $now ??= time();
    $status = (string)($ticket['status'] ?? '');
    $lastKind = (string)($ticket['last_message_kind'] ?? '');
    $lastAt = portal_ago((string)($ticket['last_message_at'] ?? ''), $now);
    $updated = portal_ago((string)($ticket['updated_at'] ?? ''), $now);
    $answered = ! empty($ticket['first_response_at']) || $lastKind === 'tech';
    $weReplied = $lastKind === 'tech' && $lastAt !== '' ? "We replied {$lastAt}" : ($answered ? 'We replied' : '');
    $changed = $updated === '' ? '' : " Updated {$updated}.";

    if ($status === 'resolved') {
        $resolved = portal_ago((string)($ticket['resolved_at'] ?? ''), $now);
        return $resolved === '' ? 'Resolved.' : "Resolved {$resolved}.";
    }
    if ($status === 'waiting') {
        if ($weReplied === '') return $canWrite ? 'We need an answer from you.' : 'We are waiting for an answer.';
        return $weReplied . ($canWrite ? ' and need your answer.' : ' and are waiting for an answer.');
    }
    if ($status === 'in_progress') {
        return 'A technician is working on it.' . ($lastKind === 'tech' && $weReplied !== '' ? " {$weReplied}." : $changed);
    }
    if ($answered) {
        if ($lastKind === 'client') return 'We have your reply.' . $changed;
        return $weReplied === '' ? 'This is with the support team.' . $changed : "{$weReplied}.";
    }
    $dueAt = (string)($ticket['sla_due_at'] ?? '');
    $due = $dueAt === '' ? false : strtotime($dueAt . ' UTC');
    $by = $due !== false && $due > $now ? portal_friendly_time($dueAt, $now) : '';
    return 'We received this.' . ($by === '' ? '' : " We aim to reply by {$by}.");
}

/** The tag beside a request in a list. The detail page keeps portal_status_label()'s words. */
function portal_request_tag(string $status, bool $canWrite = true): string
{
    if ($status === 'open') return 'Received';
    // Someone who cannot reply is not told the request waits for "your" reply.
    return $status === 'waiting' && ! $canWrite ? 'Waiting for a reply' : portal_status_label($status);
}

/**
 * Requests as rows: the subject as the title, one sentence about what is happening, and a status tag.
 *
 * @param list<array<string,mixed>> $tickets rows of portal_ticket_summary()
 * @param bool $number show "#102" before the subject (the Support Requests list; the Guide finds a request by it)
 * @param bool $reply add a Reply button that lands on the request's reply box
 */
function portal_render_request_rows(array $tickets, bool $canWrite, bool $number = false, bool $reply = false): void
{
    ?>
<ul class="portal-request-rows">
  <?php foreach ($tickets as $ticket):
      $id = (int)$ticket['id'];
      $status = (string)($ticket['status'] ?? ''); ?>
  <li class="portal-request-row">
    <div class="portal-request-copy">
      <h3><a class="portal-request-link" href="/portal/ticket.php?id=<?= $id ?>"><?php if ($number): ?><span class="portal-request-number">#<?= $id ?></span> <?php endif; ?><?= portal_h($ticket['subject']) ?></a></h3>
      <p><?= portal_h(portal_request_line($ticket, $canWrite)) ?></p>
    </div>
    <span class="portal-status portal-status-<?= portal_h($status) ?>"><?= portal_h(portal_request_tag($status, $canWrite)) ?></span>
    <?php if ($reply): ?><a class="btn-ghost portal-request-reply" href="/portal/ticket.php?id=<?= $id ?>#portal-reply" aria-label="Reply to: <?= portal_h($ticket['subject']) ?>">Reply</a><?php endif; ?>
  </li>
  <?php endforeach; ?>
</ul>
    <?php
}

/** @param list<array<string,mixed>> $tickets @param list<string> $statuses */
function portal_tickets_with_status(array $tickets, array $statuses): array
{
    return array_values(array_filter(
        $tickets,
        static fn(array $ticket): bool => in_array((string)($ticket['status'] ?? ''), $statuses, true),
    ));
}

/**
 * A list of requests, or one line when there are none.
 *
 * @param list<array<string,mixed>> $tickets
 */
function portal_render_ticket_list(array $tickets, string $emptyMessage, bool $canWrite = true): void
{
    if ($tickets === []) {
        echo '<div class="card rail-list portal-ticket-list"><div class="empty">' . portal_h($emptyMessage) . '</div></div>';
        return;
    }
    portal_render_request_rows($tickets, $canWrite, true);
}

function portal_report_period_label(
    string $periodStart,
    string $periodEnd,
    string $scheduleTimezone = 'UTC',
): string
{
    try {
        $utc = new DateTimeZone('UTC');
        $timezone = new DateTimeZone($scheduleTimezone);
        $start = (new DateTimeImmutable($periodStart, $utc))->setTimezone($timezone);
        $endExclusive = (new DateTimeImmutable($periodEnd, $utc))->setTimezone($timezone);
        if ($endExclusive <= $start) return 'Archived week';
        return $start->format('M j') . ' – ' . $endExclusive->modify('-1 second')->format('M j, Y');
    } catch (Throwable) {
        return 'Archived week';
    }
}

/** @param array<string,mixed> $archive */
function portal_render_report_preview(array $archive, bool $latest = false): void
{
    $metrics = is_array($archive['metrics'] ?? null) ? $archive['metrics'] : [];
    $tickets = is_array($metrics['tickets'] ?? null) ? $metrics['tickets'] : [];
    $goal = is_array($metrics['service_goal'] ?? null) ? $metrics['service_goal'] : [];
    $period = is_array($metrics['period'] ?? null) ? $metrics['period'] : [];
    $attainment = $goal['attainment_percent'] ?? null;
    ?>
<a class="card portal-report-preview" href="/portal/reports.php?id=<?= (int)$archive['id'] ?>">
  <div class="portal-report-preview-head">
    <div>
      <p class="portal-report-kicker"><?= $latest ? 'Latest archived summary' : 'Archived summary' ?></p>
      <h3><?= portal_h(portal_report_period_label(
          (string)$archive['period_start'],
          (string)$archive['period_end'],
          (string)($period['schedule_timezone'] ?? 'UTC'),
      )) ?></h3>
    </div>
    <span class="portal-report-version">v<?= (int)$archive['definition_version'] ?></span>
  </div>
  <p class="page-sub">
    <?= (int)($tickets['opened'] ?? 0) ?> opened · <?= (int)($tickets['resolved'] ?? 0) ?> resolved
    · Service goal <?= $attainment === null ? 'not yet decided' : (int)$attainment . '%' ?>
  </p>
  <span class="portal-report-open">View summary →</span>
</a>
    <?php
}

/**
 * @param array{identity:array<string,mixed>,binding:array<string,mixed>} $context
 * @param array{client:array<string,mixed>,counts:array<string,int>,tickets:list<array<string,mixed>>} $summary
 * @param null|list<array<string,mixed>> $reportArchives
 */
function portal_render_dashboard(array $context, array $summary, ?array $reportArchives = []): void
{
    $identity = $context['identity'];
    $context['binding']['client_name'] = $summary['client']['name'];
    $direct = portal_direct_customer_browser($context);
    $canWrite = portal_role_can_write_tickets(portal_customer_role($identity));
    $waiting = portal_tickets_with_status($summary['tickets'], ['waiting']);
    $recent = $summary['tickets'];
    if ($direct && $waiting !== []) {
        $recent = array_values(array_filter($recent, static fn(array $ticket): bool => ($ticket['status'] ?? '') !== 'waiting'));
    }
    $emptyRecent = match (true) {
        $direct && $waiting !== [] => 'No other recent support requests.',
        $direct && !$canWrite => 'Requests opened under your account will appear here.',
        default => 'No support requests yet. When you need a hand, write a request and follow its progress here.',
    };
    if ($direct) $context['chat_button_placement'] = 'inline';
    portal_page_start('Your support', '', $context);
    ?>
<main class="portal-home">
 <div class="portal-main-column">
  <section class="portal-ask"<?= $direct ? ' id="contact"' : '' ?> aria-labelledby="portal-ask-heading">
   <h1 id="portal-ask-heading"><?= $direct ? 'Support Requests' : 'Support requests' ?></h1>
   <?php if ($direct): ?>
   <div class="portal-request-actions">
    <?php if ($canWrite): ?><a class="btn-primary" href="/portal/new.php">New support request</a><?php endif; ?>
    <?php portal_westy_button(); ?>
   </div>
   <?php else: ?>
   <div class="portal-shortcuts">
    <a href="/portal/?view=westy"><?= portal_icon('chat') ?>Ask Westy</a>
    <a href="/portal/guide.php"><?= portal_icon('guide') ?>How this portal works</a>
    <a href="/portal/devices.php"><?= portal_icon('device') ?>Your devices</a>
    <?php if ($canWrite): ?><a href="/portal/new.php"><?= portal_icon('edit') ?>Write a request</a><?php endif; ?>
   </div>
   <?php endif; ?>
   <p class="portal-hint">Do not include passwords or verification codes.</p>
  </section>
  <section class="portal-attention" aria-labelledby="attention-heading"><h2 id="attention-heading"><?= $direct && !$canWrite ? 'Waiting for a reply' : 'Needs your attention' ?></h2>
   <?php portal_render_ticket_list($waiting, $direct && !$canWrite ? 'No request is waiting for a reply.' : 'Nothing needs your reply right now.', $canWrite); ?>
  </section>
  <section class="portal-requests" id="requests" aria-labelledby="requests-heading">
   <div class="portal-section-title"><h2 id="requests-heading"><?= portal_client_admin($identity) ? 'Client support requests' : 'Your support requests' ?></h2><?php if (!$direct && $canWrite): ?><a href="/portal/new.php">Open support request <?= portal_icon('arrow') ?></a><?php endif; ?></div>
   <p class="portal-hint"><?= portal_client_admin($identity) ? 'Client-wide view.' : 'Requests you opened.' ?> Showing up to 50 recent requests.</p>
   <div class="portal-counts" aria-label="Ticket counts"><?php foreach (['open'=>'Open','in_progress'=>'In progress','waiting'=>$direct && !$canWrite ? 'Waiting for a reply' : 'Waiting on you','resolved'=>'Resolved'] as $status=>$label): ?><span><?= portal_h($label) ?> <strong><?= (int)($summary['counts'][$status] ?? 0) ?></strong></span><?php endforeach; ?></div>
   <?php portal_render_ticket_list($recent, $emptyRecent, $canWrite); ?>
  </section>
 </div>
 <aside class="portal-context-rail">
  <?php if (!$direct): ?><section id="contact"><h2>Need a person?</h2><p>Send a request directly to the support team.</p><a href="/portal/guide.php#contact">Contact support <?= portal_icon('arrow') ?></a></section><?php endif; ?>
  <?php if (portal_client_admin($identity)): ?><section><h2>Service summaries</h2>
   <?php if ($reportArchives === null): ?><p>Service summaries are temporarily unavailable. Your tickets still work normally.</p>
   <?php elseif ($reportArchives === []): ?><p>No weekly service summaries have been archived for this business yet.</p>
   <?php else: portal_render_report_preview($reportArchives[0], true); endif; ?>
   <a href="/portal/reports.php">View all archived summaries <?= portal_icon('arrow') ?></a>
  </section>
  <?php endif; ?><section><h2>Your privacy</h2><p>Your requests are visible to you, client owners/admins and the support team. Your Westy chat is private to you.</p><a href="/portal/guide.php#privacy">About your chat history</a></section>
 </aside>
</main>
    <?php
    portal_page_end();
}

/**
 * Home: one person's overview of their own requests, computers and next steps.
 * Every list comes from the controller's requester-safe reads. Null means the
 * read failed and is said so; it is never shown as zero.
 *
 * @param array{identity:array<string,mixed>,binding:array<string,mixed>} $context
 * @param null|array{client:array<string,mixed>,counts:array<string,int>,tickets:list<array<string,mixed>>} $summary
 * @param null|array{items:list<array<string,mixed>>,next_after:?int} $devices
 * @param null|list<array<string,mixed>> $reportArchives
 */
function portal_render_home(array $context, ?array $summary, ?array $devices, ?array $reportArchives = null): void
{
    $identity = $context['identity'];
    if ($summary !== null) $context['binding']['client_name'] = $summary['client']['name'];
    $client = trim((string)($context['binding']['client_name'] ?? '')) ?: 'Your account';
    $name = trim((string)($identity['display_name'] ?? ''));
    $role = portal_customer_role($identity);
    $canWrite = portal_role_can_write_tickets($role);
    $direct = portal_direct_customer_browser($context);
    // Owners and admins see the whole client; everyone else sees only their own records.
    $clientWide = portal_client_admin($identity);
    $avatar = portal_branding($context)['avatar'];
    $firstName = (string)(preg_split('/\s+/', $name)[0] ?? '');
    $count = static fn(int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
    // What this person's request list covers. It sits quietly under the list, not in the hero.
    $scope = $clientWide ? 'You see every request for ' . $client . '.'
        : ($canWrite ? 'You see the requests you opened.' : 'You see the requests opened under your account.');
    // The 8 West ID member invite: the same condition the Computer access page uses.
    $canInvite = in_array($identity['role'] ?? '', ['client_owner', 'client_admin'], true) && !isset($identity['customer_access']);
    // One filled button per page. A new business still has its first step to do, and that step keeps the
    // filled button; everywhere else it is Ask Westy.
    $setup = portal_first_steps_card($clientWide, $devices, $canInvite);
    // Westy's trigger closes the rail in normal flow, so it never covers requests or help text.
    $context['chat_button_placement'] = 'inline';
    portal_page_start('Home', '', $context);
    ?>
<main class="portal-home portal-overview">
 <section class="portal-overview-hero" aria-labelledby="portal-home-heading">
  <img class="portal-overview-avatar" src="<?= portal_h($avatar) ?>" alt="" width="64" height="64">
  <div class="portal-overview-intro">
   <h1 id="portal-home-heading"><?= $firstName === '' ? 'Hello' : 'Hello, ' . portal_h($firstName) ?></h1>
   <p class="portal-overview-question">How can we help <?= portal_h($client) ?> today?</p>
  </div>
  <?php // New support request: the same check (portal_role_can_write_tickets) and destination as the Support Requests page. ?>
  <nav class="portal-overview-actions" aria-label="Start here">
   <a class="portal-overview-action<?= $setup === '' ? ' is-primary' : '' ?>" href="/portal/?view=westy">Ask Westy</a>
   <?php if ($canWrite): ?><a class="portal-overview-action" href="/portal/new.php">New support request</a><?php endif; ?>
  </nav>
 </section>
 <div class="portal-main-column">
  <?= $setup ?>
  <?php if ($summary === null): ?>
  <section class="portal-overview-card" aria-labelledby="home-requests-heading">
   <h2 id="home-requests-heading">Your requests</h2>
   <p class="portal-overview-problem" role="status">We can’t show your support requests right now. Nothing about them has changed. Try again shortly.</p>
   <div class="portal-overview-buttons"><a class="portal-overview-button" href="/portal/">Try again</a></div>
  </section>
  <?php else:
      $counts = $summary['counts'];
      $waitingTotal = (int)($counts['waiting'] ?? 0);
      $total = 0;
      foreach (['open', 'in_progress', 'waiting', 'resolved'] as $status) $total += (int)($counts[$status] ?? 0);
      $waiting = portal_tickets_with_status($summary['tickets'], ['waiting']);
      $recent = portal_tickets_with_status($summary['tickets'], ['in_progress', 'open', 'resolved']); ?>
  <?php if ($waiting !== []): ?>
  <section class="portal-attention portal-overview-card" aria-labelledby="home-reply-heading">
   <h2 id="home-reply-heading"><?= $canWrite ? 'Needs your reply' : 'Waiting for a reply' ?></h2>
   <?php portal_render_request_rows($waiting, $canWrite, false, $canWrite); ?>
   <?php if ($waitingTotal > count($waiting)): ?><p class="portal-hint">Showing <?= count($waiting) ?> of <?= $waitingTotal ?>. <a href="/portal/requests.php">See every request</a></p><?php endif; ?>
  </section>
  <?php endif; ?>
  <section class="portal-requests portal-overview-card" aria-labelledby="home-recent-heading">
   <div class="portal-section-title"><h2 id="home-recent-heading">Your requests</h2><?php if ($total > 0): ?><a href="/portal/requests.php">All requests <?= portal_icon('arrow') ?></a><?php endif; ?></div>
   <?php if ($total === 0): ?>
   <div class="portal-overview-empty"><p><strong>No support requests yet.</strong></p><p><?= $canWrite ? 'When something isn’t working, ask Westy or send a support request. You can follow it here.' : 'Requests opened under your account will appear here.' ?></p></div>
   <?php elseif ($recent === []): ?>
   <div class="portal-overview-empty"><p>No other requests.</p></div>
   <?php else: portal_render_request_rows($recent, $canWrite); endif; ?>
   <p class="portal-hint portal-request-scope"><?= portal_h($scope) ?><?php if ($total > count($summary['tickets'])): ?> Home shows <?= count($summary['tickets']) ?> of <?= $total ?>.<?php endif; ?></p>
  </section>
  <?php endif; ?>
 </div>
 <aside class="portal-context-rail" aria-label="Computers and help">
  <section class="portal-overview-card" aria-labelledby="home-devices-heading">
   <h2 id="home-devices-heading"><?= $clientWide ? 'Computers' : 'Your computers' ?></h2>
   <?php if ($devices === null): ?>
   <p class="portal-overview-problem" role="status">We can’t show your computers right now. Nothing about them has changed.</p>
   <a class="portal-overview-button" href="/portal/devices.php">Open Your devices</a>
   <?php elseif (($devices['items'] ?? []) === []): ?>
   <p><?= $clientWide ? 'No computers are connected for ' . portal_h($client) . ' yet. Add a Windows computer from Your devices.' : 'No computers are assigned to you yet. Ask your business owner or administrator to add yours.' ?></p>
   <a class="portal-overview-button" href="/portal/devices.php">Open Your devices</a>
   <?php else:
       $items = $devices['items'];
       $more = ($devices['next_after'] ?? null) !== null; ?>
   <p class="portal-hint"><?= $more ? 'More than ' . count($items) . ' computers' : portal_h($count(count($items), 'computer', 'computers')) ?></p>
   <ul class="portal-overview-devices">
    <?php foreach (array_slice($items, 0, 3) as $device): ?>
    <li><strong><?= portal_h($device['label']) ?></strong><span class="portal-device-state<?= $device['connection'] === 'reporting' ? ' is-online' : '' ?>"><?= portal_h($device['connection_label']) ?></span><?php if (($device['last_seen_at'] ?? null) !== null): ?><span class="portal-hint">Last check-in <?= portal_h(portal_format_utc($device['last_seen_at'])) ?></span><?php endif; ?></li>
    <?php endforeach; ?>
   </ul>
   <a class="portal-overview-button" href="/portal/devices.php"><?= count($items) > 3 || $more ? 'See all computers' : 'Open Your devices' ?></a>
   <?php endif; ?>
  </section>
  <?php if ($clientWide && !$direct): ?>
  <section class="portal-overview-card" aria-labelledby="home-summaries-heading"><h2 id="home-summaries-heading">Service summaries</h2>
   <?php if ($reportArchives === null): ?><p>Service summaries are temporarily unavailable. Your tickets still work normally.</p>
   <?php elseif ($reportArchives === []): ?><p>No weekly service summaries have been archived for this business yet.</p>
   <?php else: portal_render_report_preview($reportArchives[0], true); endif; ?>
   <a href="/portal/reports.php">View all archived summaries <?= portal_icon('arrow') ?></a>
  </section>
  <?php endif; ?>
  <?php if ($clientWide): ?>
  <section class="portal-overview-card" aria-labelledby="home-manage-heading"><h2 id="home-manage-heading">Manage <?= portal_h($client) ?></h2>
   <p>People, plan and computers for <?= portal_h($client) ?>.</p>
   <div class="portal-overview-buttons">
    <a class="portal-overview-button" href="<?= portal_h(safeharbor_settings_panel_url('client_admin')) ?>">Client Admin in 8 West ID</a>
    <a class="portal-overview-button" href="/portal/devices.php">Manage computer access</a>
    <a class="portal-overview-button" href="https://id.8westit.com/customer-billing.php">Plan and billing in 8 West ID</a>
    <?php if ($canInvite): ?><a class="portal-overview-button" href="https://id.8westit.com/users.php#employee-invitations">Invite a member in 8 West ID</a><?php endif; ?>
   </div>
  </section>
  <?php endif; ?>
  <?php if (!$direct): ?><section class="portal-overview-card" aria-labelledby="home-help-heading"><h2 id="home-help-heading">Need a person?</h2>
   <p>Reach the support team directly, or read how this portal works.</p>
   <a href="/portal/guide.php#contact">Contact support <?= portal_icon('arrow') ?></a>
   <p class="portal-hint">Your requests are visible to you, client owners/admins and the support team. Your Westy chat is private to you. <a href="/portal/guide.php#privacy">About your privacy</a></p>
  </section><?php endif; ?>
  <?php portal_westy_button(); ?>
 </aside>
</main>
    <?php
    portal_page_end();
}

/**
 * "Get set up" on Home: for a client-wide viewer whose computer list loaded and is empty. It disappears
 * once a computer exists, and when the list could not be read (that state has its own message).
 */
function portal_first_steps_card(bool $clientWide, ?array $devices, bool $canInvite): string
{
    if (!$clientWide || $devices === null || ($devices['items'] ?? []) !== []) return '';
    return setup_steps_html('home-setup-heading', [
        ['Create your account', '', true, null, null],
        ['Add your first computer', 'About five minutes on a Windows PC. You need to be an administrator on that computer.',
            false, 'Open Your devices', '/portal/devices.php'],
        ['Invite your team', $canInvite ? 'Each person gets their own sign-in and can ask for help themselves.'
            : 'Your support provider can add people for you.',
            false, $canInvite ? 'Invite a member in 8 West ID' : null, $canInvite ? 'https://id.8westit.com/users.php#employee-invitations' : null],
    ], 'portal_h');
}

function portal_render_workspace(array $context): void
{
    $context['workspace']=true;
    portal_page_start('Westy','portal-workspace',$context);
    $support = portal_direct_customer_browser($context)
        ? '<a href="/portal/requests.php">open Support Requests</a>'
        : '<a href="/portal/new.php">contact support</a>';
    echo '<main id="portal-chat-home-slot" aria-label="Westy support workspace"><noscript><p>Enable JavaScript to chat with Westy, or ' . $support . '.</p></noscript></main>';
    portal_page_end();
}

function portal_render_support_back_link(array $context): void
{
    if (portal_direct_customer_browser($context)) {
        echo '<a href="/portal/requests.php" class="backlink">← Support Requests</a>';
    } else {
        echo '<a href="/portal/" class="backlink">← Home</a>';
    }
}

/** @param list<array<string,mixed>> $archives */
function portal_render_reports(array $context, array $archives): void
{
    $clientName = (string)($context['binding']['client_name'] ?? 'Your business');
    portal_page_start('Weekly service summaries', '', $context);
    ?>
<main class="page page-narrow">
  <?php portal_render_support_back_link($context); ?>
  <header class="page-head portal-ticket-head">
    <div>
      <p class="page-sub"><?= portal_h($clientName) ?></p>
      <h1 class="page-title">Weekly service summaries</h1>
      <p class="page-sub">Each card opens the exact verified archive saved for that week.</p>
    </div>
  </header>
  <div class="portal-report-list">
    <?php if ($archives === []): ?>
      <div class="card empty">No weekly service summaries have been archived for this business yet.</div>
    <?php else: ?>
      <?php foreach ($archives as $index => $archive): ?>
        <?php portal_render_report_preview($archive, $index === 0); ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <p class="page-note">These summaries contain customer-level service totals only. They do not show ticket messages, internal notes, technician entries or rates, recipients, delivery attempts, invoices, AI controls, or endpoint controls.</p>
</main>
    <?php
    portal_page_end();
}

/** @param array<string,mixed> $archive */
function portal_render_report(array $context, array $archive): void
{
    $metrics = $archive['metrics'];
    $tickets = $metrics['tickets'];
    $firstResponse = $metrics['first_response'];
    $goal = $metrics['service_goal'];
    $approvedTime = $metrics['approved_billable_time'];
    $csat = $metrics['csat'];
    $attainment = $goal['attainment_percent'];
    $responseAverage = $firstResponse['average_minutes'];
    $approvedMinutes = (int)$approvedTime['minutes'];
    $csatAverage = $csat['average_score_out_of_3'];
    portal_page_start('Weekly service summary', '', $context);
    ?>
<main class="page page-narrow">
  <a href="/portal/reports.php" class="backlink">← All service summaries</a>
  <header class="page-head portal-ticket-head">
    <div>
      <p class="portal-report-kicker">Verified archive · definition v<?= (int)$archive['definition_version'] ?></p>
      <h1 class="page-title"><?= portal_h(portal_report_period_label(
          (string)$archive['period_start'],
          (string)$archive['period_end'],
          (string)($metrics['period']['schedule_timezone'] ?? 'UTC'),
      )) ?></h1>
      <p class="page-sub"><?= portal_h((string)$metrics['source']['client_name']) ?> · generated <?= portal_h(portal_format_utc((string)$archive['generated_at'])) ?></p>
    </div>
  </header>

  <section class="stats portal-report-stats" aria-label="Weekly service totals">
    <article class="card stat"><div class="stat-k">Tickets opened</div><div class="stat-v"><?= (int)$tickets['opened'] ?></div></article>
    <article class="card stat"><div class="stat-k">Tickets resolved</div><div class="stat-v stat-good"><?= (int)$tickets['resolved'] ?></div></article>
    <article class="card stat"><div class="stat-k">Avg. first response</div><div class="stat-v"><?= $responseAverage === null ? '—' : (int)$responseAverage . ' min' ?></div></article>
    <article class="card stat"><div class="stat-k">Response goal</div><div class="stat-v <?= $attainment === null ? 'stat-dim' : 'stat-good' ?>"><?= $attainment === null ? '—' : (int)$attainment . '%' ?></div></article>
  </section>

  <section class="portal-report-detail-grid">
    <article class="card portal-report-fact">
      <span class="rail-k">Approved operational time</span>
      <strong><?= $approvedMinutes ?> minutes · <?= portal_h(number_format($approvedMinutes / 60, 2, '.', '')) ?> hours</strong>
      <p>Customer-level approved service time only. This is not an invoice or posting status.</p>
    </article>
    <article class="card portal-report-fact">
      <span class="rail-k">Customer satisfaction</span>
      <strong><?= $csatAverage === null ? 'No scored responses' : portal_h((string)$csatAverage) . ' / 3' ?></strong>
      <p><?= (int)$csat['responses_received_by_generated_at'] ?> responses from <?= (int)$csat['surveys_sent'] ?> surveys.</p>
    </article>
  </section>

  <details class="card portal-report-official">
    <summary>Read the exact archived summary</summary>
    <pre><?= portal_h((string)$archive['text']) ?></pre>
  </details>
  <p class="page-note">Archive SHA-256: <code><?= portal_h((string)$archive['content_sha256']) ?></code>. The portal verifies the immutable definition, canonical JSON/text, hash, tenant, customer, period, and generation time before showing this page.</p>
</main>
    <?php
    portal_page_end();
}

/** @param array{identity:array<string,mixed>,binding:array<string,mixed>} $context */
function portal_render_new_ticket(array $context, ?string $error = null, array $values = []): void
{
    $clientName = (string)($context['binding']['client_name'] ?? 'Your business');
    $nonce = portal_action_nonce('ticket:create');
    portal_page_start('Get help', '', $context);
    ?>
<main class="page page-narrow">
  <?php portal_render_support_back_link($context); ?>
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
      <p class="page-note span-2">This request will be visible to the requester, client owners/admins and the support team. Only the text in this form is sent. Do not include passwords, verification codes or secret keys.</p>
      <div class="form-actions span-2">
        <button type="submit" class="btn-primary">Send request</button>
        <a class="btn-link" href="<?= portal_direct_customer_browser($context) ? '/portal/requests.php' : '/portal/' ?>">Cancel</a>
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
    $canWrite = portal_role_can_write_tickets(portal_customer_role($identity));
    $status = (string)($ticket['status'] ?? '');
    $canReply = $canWrite && $status !== 'resolved';
    $nonce = $canReply ? portal_action_nonce('ticket:reply:' . $ticketId) : '';
    $direct = portal_direct_customer_browser($context);
    $clientName = trim((string)($context['binding']['client_name'] ?? '')) ?: 'your business';
    portal_page_start('Request #' . $ticketId, '', $context);
    // After the page starts, so every time is in the viewer's own time zone and clock style.
    $progress = portal_request_progress($ticket, $detail['messages'], $canWrite);
    ?>
<main class="page portal-request">
  <?php portal_render_support_back_link($context); ?>
  <header class="page-head portal-ticket-head">
    <div>
      <h1 class="ticket-subject"><?= portal_h($ticket['subject']) ?></h1>
      <p class="ticket-sub">Request #<?= $ticketId ?>, sent <?= portal_time_tag((string)$ticket['created_at']) ?>. <?= portal_h(portal_priority_label((string)$ticket['priority'])) ?> priority.</p>
    </div>
    <?php if ($canWrite && !$direct): ?><a class="btn-ghost portal-request-new" href="/portal/new.php">New support request</a><?php endif; ?>
  </header>

  <?php if ($notice !== null && $notice !== ''): ?><div class="form-ok" role="status"><?= portal_h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null && $error !== ''): ?><div class="form-error" role="alert"><?= portal_h($error) ?></div><?php endif; ?>

  <section class="portal-progress" aria-labelledby="portal-status-heading">
    <h2 id="portal-status-heading" class="portal-progress-title">Status</h2>
    <ol class="portal-progress-steps">
      <?php foreach ($progress['steps'] as $index => $step): ?>
        <li class="is-<?= portal_h($step['state']) ?>"<?= $index + 1 === $progress['step'] ? ' aria-current="step"' : '' ?>>
          <span class="portal-progress-mark" aria-hidden="true"><?= $step['state'] === 'done' ? portal_icon('check') : $index + 1 ?></span>
          <span class="portal-progress-label"><?= portal_h($step['label']) ?><?php if ($step['state'] === 'done'): ?><span class="sr-only">, done</span><?php endif; ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
    <p class="portal-progress-summary" aria-hidden="true">Step <?= (int)$progress['step'] ?> of <?= count($progress['steps']) ?>: <?= portal_h($progress['label']) ?></p>
  </section>

  <?php if ($status === 'waiting' && $canReply): ?>
    <div class="card portal-waiting-callout" role="status">
      <strong>The support team is waiting for your reply.</strong>
      <span>Answer below and a technician picks it up again.</span>
    </div>
  <?php endif; ?>

  <div class="ticket-grid">
    <section class="thread" aria-labelledby="portal-conversation-heading">
      <h2 id="portal-conversation-heading" class="rail-label">Conversation</h2>
      <?php if ($detail['messages'] === []): ?>
        <div class="card empty">No messages yet.</div>
      <?php else: ?>
        <?php foreach ($detail['messages'] as $message):
            $isTech = ($message['kind'] ?? '') === 'tech'; ?>
          <article class="card msg <?= $isTech ? 'msg-tech' : 'msg-client' ?>">
            <div class="msg-meta">
              <span class="<?= $isTech ? 'msg-author-tech' : 'msg-author' ?>"><?= portal_h($message['author_name']) ?></span>
              <span class="msg-when"><?= portal_time_tag((string)$message['created_at'], true) ?></span>
            </div>
            <div class="msg-body"><?= portal_h($message['body']) ?></div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($canReply): ?>
        <form method="post" action="/portal/ticket.php?id=<?= $ticketId ?>" class="card reply">
          <input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>">
          <input type="hidden" name="action_nonce" value="<?= portal_h($nonce) ?>">
          <label class="field" for="portal-reply"><?= $status === 'waiting' ? 'Send the update the support team needs' : 'Reply to the support team' ?></label>
          <textarea id="portal-reply" name="body" maxlength="<?= PORTAL_TICKET_BODY_MAX_CHARACTERS ?>" required
                    placeholder="Add an update or answer the technician’s question"><?= portal_h($draft) ?></textarea>
          <div class="reply-foot">
            <span class="reply-hint">Do not include passwords or verification codes.</span>
            <button type="submit" class="btn-primary">Send reply</button>
          </div>
        </form>
      <?php elseif ($status === 'resolved'): ?>
        <div class="card portal-closed-note">This request is resolved. Still not fixed? Send a new support request and mention request #<?= $ticketId ?>, so you do not have to explain it again.</div>
      <?php else: ?>
        <div class="card portal-closed-note">You can read this request but not reply to it.</div>
      <?php endif; ?>
    </section>

    <aside class="rail portal-next" aria-labelledby="portal-next-heading">
      <h2 id="portal-next-heading" class="rail-label">What happens next</h2>
      <div class="portal-next-body">
        <?php foreach ($progress['next'] as $index => $sentence): ?>
          <p<?= $index === 0 ? ' class="portal-next-lead"' : '' ?>><?= portal_h($sentence) ?></p>
        <?php endforeach; ?>
        <?php if ($progress['goal'] !== null): ?>
          <h3 class="portal-next-label">Response goal</h3>
          <p><?= portal_h($progress['goal']) ?></p>
        <?php endif; ?>
        <p class="portal-next-privacy">Seen by the person who sent it, the owners and admins of <?= portal_h($clientName) ?>, and the support team.</p>
      </div>
    </aside>
  </div>
</main>
    <?php
    portal_page_end();
}
