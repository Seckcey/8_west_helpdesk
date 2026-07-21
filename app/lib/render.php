<?php
/**
 * Layout + shared UI partials. Pages call page_top() … page_bottom().
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const STATUS_META = [
    'open'        => ['Open',        'blue'],
    'in_progress' => ['In Progress', 'cyan'],
    'waiting'     => ['Waiting',     'gold'],
    'resolved'    => ['Resolved',    'mint'],
];
const PRIORITY_META = [
    'low'    => ['Low',    1, 'muted'],
    'normal' => ['Normal', 2, 'blue'],
    'high'   => ['High',   3, 'gold'],
    'urgent' => ['Urgent', 4, 'rose'],
];

function status_chip(string $status): string
{
    [$label, $tone] = STATUS_META[$status];
    return '<span class="chip"><span class="chip-dot dot-' . $tone . '"></span>'
         . '<span class="chip-text-' . $tone . '">' . h($label) . '</span></span>';
}

function priority_glyph(string $priority, bool $withLabel = false): string
{
    [$label, $bars, $tone] = PRIORITY_META[$priority];
    $out = '<span class="pri" title="Priority: ' . h($label) . '"><span class="pri-bars">';
    for ($i = 1; $i <= 4; $i++) {
        $cls = $i <= $bars ? 'bar-' . $tone : 'bar-off';
        $out .= '<span class="pri-bar ' . $cls . '" style="height:' . (2 + $i * 3) . 'px"></span>';
    }
    $out .= '</span>';
    if ($withLabel) $out .= '<span class="pri-label">' . h($label) . '</span>';
    return $out . '</span>';
}

function sla_lamp(array $ticket): string
{
    $sla = sla_info($ticket);
    $pulse = in_array($sla['state'], ['at_risk', 'breached'], true) ? ' pulse' : '';
    return '<span class="sla sla-' . $sla['state'] . '">'
         . '<span class="sla-dot' . $pulse . '"></span>' . h($sla['label']) . '</span>';
}

function avatar(?array $user, int $size = 26): string
{
    if (!$user) {
        return '<span class="avatar avatar-none" style="width:' . $size . 'px;height:' . $size . 'px" title="Unassigned">—</span>';
    }
    return '<span class="avatar" style="width:' . $size . 'px;height:' . $size . 'px;background:' . h($user['color']) . '" title="' . h($user['full_name']) . '">'
         . h($user['initials']) . '</span>';
}

/** One queue row (also used on the client page). */
function ticket_row(array $t, bool $linkWrap = true): string
{
    $url = '/ticket.php?id=' . (int)$t['id'];
    return '<a href="' . $url . '" class="trow" data-ticket-id="' . (int)$t['id'] . '" data-status="' . h($t['status']) . '" data-priority="' . h($t['priority']) . '" role="row">'
        . '<span class="trow-pri">' . priority_glyph($t['priority']) . '</span>'
        . '<span class="trow-num">#' . (int)$t['id'] . '</span>'
        . '<span class="trow-main"><span class="trow-subject">' . h($t['subject']) . '</span>'
        . '<span class="trow-sub">' . h($t['client_name']) . ' · ' . h($t['channel']) . '</span></span>'
        . '<span class="trow-status">' . status_chip($t['status']) . '</span>'
        . '<span class="trow-tech">' . avatar($t['assignee_id'] ? ['full_name' => $t['assignee_name'], 'initials' => $t['assignee_initials'], 'color' => $t['assignee_color']] : null) . '</span>'
        . '<span class="trow-sla">' . sla_lamp($t) . '</span>'
        . '<span class="trow-age" title="Opened">' . rel_time($t['created_at']) . '</span>'
        . '</a>';
}

/** Full chrome: head + sidebar + topbar. */
function page_top(array $user, string $title, string $active): void
{
    $nav = [
        ['/',          'Queue',   'queue',   'G Q', '<path d="M2 4h12M2 8h12M2 12h7" stroke-linecap="round"/>'],
        ['/time.php',  'Time',    'time',    'G T', '<circle cx="8" cy="8" r="6"/><path d="M8 4.5V8l2.5 2" stroke-linecap="round"/>'],
        ['/clients.php', 'Clients', 'clients', 'G C', '<circle cx="5.5" cy="6" r="2.5"/><circle cx="11" cy="7" r="2"/><path d="M1.5 13.5c.6-2.3 2.2-3.5 4-3.5s3.4 1.2 4 3.5M9.5 12.6c.7-1.4 1.9-2.1 3-2.1 1.3 0 2.4.9 2.9 2.6" stroke-linecap="round"/>'],
        ['/users.php', 'Team',    'team',    '', '<circle cx="8" cy="5.5" r="2.5"/><path d="M3 13.5c.8-2.6 2.8-4 5-4s4.2 1.4 5 4M11.5 5.8a2 2 0 1 1 .01 0M11.6 9.6c1.6.4 2.8 1.6 3.3 3.4" stroke-linecap="round"/>'],
    ];
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Safeharbor</title>
<link rel="icon" type="image/svg+xml" href="/assets/brand/favicon.svg">
<link rel="icon" type="image/x-icon" href="/assets/brand/favicon.ico" sizes="16x16 32x32 48x48">
<link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
<meta name="theme-color" content="#061936">
<script>document.documentElement.dataset.theme=localStorage.getItem("safeharbor.theme")||"system";</script>
<link rel="stylesheet" href="/assets/css/app.css?v=2">
</head>
<body data-active="<?= h($active) ?>" data-csrf="<?= csrf_token() ?>">
<div class="shell">
  <aside class="sidebar">
    <a class="brand" href="/">
      <img src="/assets/brand/favicon.svg" alt="Safeharbor" class="brand-mark">
      <span class="brand-text">
        <span class="brand-name">Safeharbor</span>
        <span class="brand-sub">by 8 West IT, LLC</span>
      </span>
    </a>
    <nav class="nav">
      <?php foreach ($nav as [$href, $label, $key, $kbd, $icon]): ?>
      <a href="<?= $href ?>" class="nav-item<?= $active === $key ? ' nav-on' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><?= $icon ?></svg>
        <span class="nav-label"><?= h($label) ?></span>
        <kbd class="kbd nav-kbd"><?= $kbd ?></kbd>
      </a>
      <?php endforeach; ?>
    </nav>
    <div class="suite-label">8 West Suite</div>
    <div class="suite">
      <?php foreach (['Milepost', 'Coastmark'] as $name): ?>
      <div class="suite-item" title="<?= $name ?> integration arrives in Phase 2">
        <span class="suite-box"></span><span class="suite-name"><?= $name ?></span><span class="suite-tag">P2</span>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="theme-switch" role="group" aria-label="Theme">
      <button type="button" class="theme-btn" data-theme-opt="dark" title="Dark theme" aria-label="Dark theme">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M13.5 9.5A5.5 5.5 0 0 1 6.5 2.5a5.5 5.5 0 1 0 7 7Z" stroke-linejoin="round"/></svg>
      </button>
      <button type="button" class="theme-btn" data-theme-opt="light" title="Light theme" aria-label="Light theme">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="3"/><path d="M8 1.5v1.5M8 13v1.5M1.5 8H3M13 8h1.5M3.4 3.4l1 1M11.6 11.6l1 1M12.6 3.4l-1 1M4.4 11.6l-1 1" stroke-linecap="round"/></svg>
      </button>
      <button type="button" class="theme-btn" data-theme-opt="system" title="Match system theme" aria-label="Match system theme">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="8" rx="1.5"/><path d="M6 13.5h4" stroke-linecap="round"/></svg>
      </button>
    </div>
    <div class="sidebar-foot">
      <?= avatar($user, 30) ?>
      <span class="foot-text">
        <span class="foot-name"><?= h($user['full_name']) ?></span>
        <span class="foot-tenant">8 West IT, LLC</span>
      </span>
      <a class="foot-out" href="/logout.php" title="Sign out">Sign out</a>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button id="palette-trigger" class="search-trigger" type="button">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="4.5"/><path d="m10.5 10.5 3 3" stroke-linecap="round"/></svg>
        <span class="search-label">Search or command…</span>
        <kbd class="kbd">⌘K</kbd>
      </button>
      <span class="topbar-flex"></span>
      <a href="/time.php" id="timer-widget" class="timer-widget timer-idle" title="Time tracking">No timer running</a>
      <?= avatar($user, 30) ?>
    </header>
    <main class="content">
    <?php
}

/** Close chrome + inject palette data + JS. */
function page_bottom(array $paletteData = []): void
{
    ?>
    </main>
  </div>
</div>
<div id="palette-root"></div>
<div id="toasts" class="toasts"></div>
<script id="palette-data" type="application/json"><?= json_encode($paletteData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="/assets/js/app.js?v=2" defer></script>
</body>
</html>
    <?php
}

/** Palette data island: all tickets + clients for ⌘K fuzzy search. */
function palette_data(): array
{
    $tickets = db()->prepare(
        'SELECT t.id, t.subject, t.priority, c.name AS client_name
           FROM tickets t JOIN clients c ON c.id = t.client_id
          WHERE t.tenant_id = ? AND t.status != "resolved"
          ORDER BY t.updated_at DESC LIMIT 60'
    );
    $tickets->execute([tenant_id()]);
    $clients = db()->prepare('SELECT id, name, domain FROM clients WHERE tenant_id = ? ORDER BY name');
    $clients->execute([tenant_id()]);
    return [
        'tickets' => array_map(static fn($r) => [
            'id' => (int)$r['id'], 'subject' => $r['subject'],
            'priority' => $r['priority'], 'client' => $r['client_name'],
        ], $tickets->fetchAll()),
        'clients' => array_map(static fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'], 'domain' => $r['domain'],
        ], $clients->fetchAll()),
    ];
}
