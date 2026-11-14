<?php
/**
 * Layout + shared UI partials. Pages call page_top() … page_bottom().
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/westy.php';

const STATUS_META = [
    'open'        => ['Open',        'blue'],
    'in_progress' => ['In progress', 'cyan'],
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
    return '<span class="sla sla-' . $sla['state'] . '">'
         . '<span class="sla-dot"></span>' . h($sla['label']) . '</span>';
}

function avatar(?array $user, int $size = 26): string
{
    if (!$user) {
        return '<span class="avatar avatar-none" style="width:' . $size . 'px;height:' . $size . 'px" title="Unassigned">—</span>';
    }
    // 8 West ID avatar: the central profile picture follows the user across suite apps.
    if (!empty($_SESSION['suite_avatar'])
        && (string)($user['suite_subject'] ?? '') !== ''
        && hash_equals((string)($user['suite_subject'] ?? ''), (string)($_SESSION['suite_avatar_subject'] ?? ''))) {
        return '<img class="avatar avatar-img" style="width:' . $size . 'px;height:' . $size . 'px" src="' . h($_SESSION['suite_avatar']) . '" alt="" title="' . h($user['full_name']) . '">';
    }
    // The colour reaches the stylesheet as --avatar, which draws it solid in light and as a tint in
    // dark. Only a #RRGGBB colour is used; anything else gets the neutral fill.
    $color = (string)($user['color'] ?? '');
    $colorStyle = preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? ';--avatar:' . $color : '';
    return '<span class="avatar" style="width:' . $size . 'px;height:' . $size . 'px' . $colorStyle . '" title="' . h($user['full_name']) . '">'
         . h($user['initials']) . '</span>';
}

/** Westy is a service owner, never a fabricated human account. */
function ticket_owner_avatar(array $ticket, int $size = 26): string
{
    if (($ticket['westy_owned'] ?? false) === true && ($ticket['status'] ?? '') === 'in_progress' && empty($ticket['assignee_id'])) {
        $size = max(16, min(64, $size));
        return '<span class="avatar" style="width:' . $size . 'px;height:' . $size . 'px;background:var(--gold);color:var(--bg)" title="Westy" aria-label="Assigned to Westy">W</span>';
    }
    return avatar(!empty($ticket['assignee_id']) ? ['full_name'=>$ticket['assignee_name'], 'initials'=>$ticket['assignee_initials'], 'color'=>$ticket['assignee_color']] : null, $size);
}

/** How a ticket came in, in words. */
const CHANNEL_WORDS = [
    'email'  => 'by email',
    'portal' => 'from the portal',
    'phone'  => 'by phone',
    'alert'  => 'from a Milepost alert',
];

/**
 * A row's second line: the client, how the ticket came in and, when the row knows it, who asked
 * ("Bayside Realty, by phone from Dana Whitfield"). The queue supplies contact_name (the ticket's
 * contact) and opener_name (the author of its first customer message); other lists may not.
 * Plain text: the caller escapes it.
 *
 * $withClient false is for a list that is all one client's (the client page): the line then starts
 * with how the ticket came in ("By phone from Dana Whitfield").
 */
function ticket_origin(array $t, bool $withClient = true): string
{
    $channel = (string)($t['channel'] ?? '');
    $line = $withClient ? (string)$t['client_name'] : '';
    if (!isset(CHANNEL_WORDS[$channel])) return $line;
    $line = $withClient ? $line . ', ' . CHANNEL_WORDS[$channel] : ucfirst(CHANNEL_WORDS[$channel]);
    $who = ticket_asker($t);
    // An alert is written by a machine: there is nobody to name.
    if ($who === '' || $channel === 'alert') return $line;
    return $line . ($channel === 'portal' ? ' by ' : ' from ') . $who;
}

/**
 * Who asked: the ticket's contact, whatever their name. Only a ticket with no contact falls back to
 * the author of its opening message, and that author is nobody when it is the client's own name: a
 * call logged with no contact is stored in the client's name, and "from Bayside Realty" beside
 * "Bayside Realty" names nobody.
 *
 * @param array{contact_name?:?string,opener_name?:?string,client_name?:?string} $t
 */
function ticket_asker(array $t): string
{
    $contact = trim((string)($t['contact_name'] ?? ''));
    if ($contact !== '') return $contact;
    $opener = trim((string)($t['opener_name'] ?? ''));
    return strcasecmp($opener, trim((string)($t['client_name'] ?? ''))) === 0 ? '' : $opener;
}

/**
 * One queue row (also used on the client page). $from is queue_ticket_query()'s answer on the
 * queue, so the ticket page can say "2 of 6" and step to the next one; other lists pass nothing.
 */
function ticket_row(array $t, bool $linkWrap = true, string $from = '', bool $withClient = true): string
{
    $url = '/ticket.php?id=' . (int)$t['id'] . h($from);
    return '<a href="' . $url . '" class="trow" data-ticket-id="' . (int)$t['id'] . '" data-status="' . h($t['status']) . '" data-priority="' . h($t['priority']) . '"'
        . ' data-owner="' . (!empty($t['assignee_id']) ? (int)$t['assignee_id'] : '') . '" role="row">'
        . '<span class="trow-pri">' . priority_glyph($t['priority']) . '</span>'
        . '<span class="trow-num">#' . (int)$t['id'] . '</span>'
        . '<span class="trow-main"><span class="trow-subject" title="' . h($t['subject']) . '">' . h($t['subject']) . '</span>'
        . '<span class="trow-client-phone">' . h($withClient ? (string)($t['client_name'] ?? '') : ticket_origin($t, false)) . '</span>'
        . '<span class="trow-sub"><span class="trow-origin">' . h(ticket_origin($t, $withClient)) . '</span>'
        // The customer answered after we did: it is our turn again.
        . (($t['last_kind'] ?? '') === 'client' && !empty($t['first_response_at']) && ($t['status'] ?? '') !== 'resolved'
            ? '<span class="chip trow-flag"><span class="chip-dot dot-cyan"></span><span class="chip-text-cyan">Customer replied</span></span>' : '')
        . '</span></span>'
        . '<span class="trow-status">' . status_chip($t['status']) . '</span>'
        . '<span class="trow-tech">' . ticket_owner_avatar($t) . '</span>'
        . '<span class="trow-sla">' . sla_lamp($t) . '</span>'
        // Time since the last message when the row knows it (the queue); otherwise since it opened.
        . (!empty($t['last_message_at'])
            ? '<span class="trow-age" title="Last message">' . rel_time($t['last_message_at']) . '</span>'
            : '<span class="trow-age" title="Opened">' . rel_time($t['created_at']) . '</span>')
        . '</a>';
}

/**
 * What the status, priority and owner menus offer. The labels and tags are rendered here, once, so
 * the script never spells a status or a priority itself. $team is the tenant's active members.
 *
 * @param list<array{id:int|string,full_name:string,initials:string,color:string}> $team
 */
function ticket_menu_choices(array $team): array
{
    $status = [];
    foreach (STATUS_META as $value => [$label]) {
        $status[] = ['value' => $value, 'label' => $label, 'html' => status_chip($value)];
    }
    $priority = [];
    foreach (PRIORITY_META as $value => [$label]) {
        $priority[] = ['value' => $value, 'label' => $label, 'html' => priority_glyph($value, true)];
    }
    return [
        'status' => $status,
        'priority' => $priority,
        'team' => array_map(static fn(array $member): array => [
            'id' => (int)$member['id'], 'name' => (string)$member['full_name'],
            'initials' => (string)$member['initials'], 'color' => (string)$member['color'],
        ], $team),
    ];
}

/** The signed-in tenant's active members, A to Z: the people a ticket can be given to. */
function ticket_menu_team(): array
{
    $team = db()->prepare('SELECT id, full_name, initials, color FROM users WHERE tenant_id = ? AND is_active = 1 ORDER BY full_name, id');
    $team->execute([tenant_id()]);
    return $team->fetchAll();
}

/**
 * One row of the ticket's Details card: a label and a button that opens that field's menu.
 * $hook is the attribute the script repaints after a change (data-chip, data-pri, data-assignee);
 * $valueHtml is already-escaped markup from status_chip(), priority_glyph() or the owner cell.
 */
function ticket_menu_button(string $field, int $ticketId, string $label, string $key, string $hook, string $valueHtml): string
{
    $labelId = 'ticket-' . $field . '-label';
    $valueId = 'ticket-' . $field . '-value';
    return '<div class="rail-prop">'
        . '<span class="rail-k" id="' . h($labelId) . '">' . h($label) . '</span>'
        . '<button type="button" class="rail-btn menu-btn" data-action="' . h($field) . '" data-id="' . $ticketId . '"'
        . ' aria-haspopup="listbox" aria-expanded="false" aria-labelledby="' . h($labelId) . ' ' . h($valueId) . '">'
        . '<span class="rail-v" id="' . h($valueId) . '" ' . h($hook) . '>' . $valueHtml . '</span>'
        . '<kbd class="kbd" aria-hidden="true">' . h($key) . '</kbd>'
        . '<svg class="menu-chevron" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m4 6 4 4 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        . '</button></div>';
}

/** Resolve and its way back. Both are always in the page; the one that does not apply is hidden. */
function ticket_resolve_buttons(int $ticketId, string $status): string
{
    $resolved = $status === 'resolved';
    return '<button type="button" class="btn-secondary" id="resolve-btn" data-id="' . $ticketId . '" data-set-status="resolved"' . ($resolved ? ' hidden' : '') . '>'
        . '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m3 8.5 3.2 3.2L13 4.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        . 'Resolve</button>'
        . '<button type="button" class="btn-secondary" id="reopen-btn" data-id="' . $ticketId . '" data-set-status="open"' . ($resolved ? '' : ' hidden') . '>Reopen</button>';
}

/**
 * The composer's Send button and the two "send and set the next status" choices beside it. The
 * choices are ordinary submit buttons that carry after_status, so they work without script;
 * the script only opens the menu (they have no key of their own: they are also in the palette). $surveyOnResolve says whether resolving now
 * would ask the customer for a rating, so the menu can say so truthfully. $waitingDays is how long
 * a waiting ticket stays parked.
 */
function ticket_send_control(bool $surveyOnResolve, string $surveyName, int $waitingDays): string
{
    $who = $surveyName !== '' ? $surveyName : 'The customer';
    $choice = static fn(string $value, string $title, string $detail): string =>
        '<button type="submit" class="menu-item send-choice" name="after_status" value="' . h($value) . '" role="menuitem">'
        . '<span class="send-choice-text"><span class="send-choice-title">' . h($title) . '</span>'
        . '<span class="send-choice-detail">' . h($detail) . '</span></span></button>';
    return '<div class="send-split" id="send-split">'
        . '<button type="submit" class="btn-primary send-main" id="composer-send">Send reply</button>'
        . '<button type="button" class="btn-primary send-more" id="composer-send-more" aria-haspopup="menu" aria-expanded="false" aria-controls="send-menu" aria-label="More ways to send">'
        . '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m4 6 4 4 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        . '</button>'
        . '<div class="menu-pop send-menu" id="send-menu" role="menu" aria-label="Send and set the next status" hidden>'
        . $choice('waiting', 'Send and wait for the customer',
            'Comes back in ' . $waitingDays . ' days if they stay silent.')
        . $choice('resolved', 'Send and resolve',
            $surveyOnResolve ? $who . ' gets a short survey.' : 'No survey is sent.')
        . '</div></div>';
}

/**
 * A queue row with a selection checkbox, for owners and admins (bulk delete). The checkbox sits
 * beside the row's link, never inside it: a control inside an <a> is invalid and would navigate.
 */
function ticket_pick_row(array $t, string $from = ''): string
{
    $id = (int)$t['id'];
    // autocomplete=off: after a delete the rows shift, so a restored tick would land on another ticket.
    return '<div class="trow-pick"><label class="pick"><input type="checkbox" class="bulk-pick" value="' . $id . '"'
        . ' autocomplete="off" aria-label="Select ticket #' . $id . '"></label>' . ticket_row($t, true, $from) . '</div>';
}

/** The queue's column header, with "select every ticket in this view" when rows are pickable. */
function ticket_head_row(bool $pickable): string
{
    $head = '<div class="trow trow-head" role="row">'
        . '<span class="trow-pri"><span class="sr-only">Priority</span></span><span class="trow-num">No.</span><span class="trow-main">Ticket</span>'
        . '<span class="trow-status">Status</span><span class="trow-tech">Owner</span><span class="trow-sla">First response</span>'
        . '<span class="trow-age">Updated</span>'
        . '</div>';
    if (!$pickable) return $head;
    return '<div class="trow-pick trow-pick-head"><label class="pick"><input type="checkbox" id="bulk-all" autocomplete="off"'
        . ' aria-label="Select every ticket in this view"><span class="pick-all-label">Select all</span></label>' . $head . '</div>';
}

/**
 * The bar that appears once tickets are selected. A delete always names the tickets it means: the
 * ticked rows, or with "select all" every row this page shows, never a live filter.
 */
function ticket_bulk_bar(string $filterLabel, int $total): string
{
    return '<div id="bulk-bar" class="bulk-bar" hidden data-filter-label="' . h($filterLabel) . '" data-total="' . $total . '">'
        . '<span class="bulk-count" aria-live="polite"></span>'
        . '<button type="button" class="btn-danger btn-sm" id="bulk-delete">Delete…</button>'
        . '<button type="button" class="btn-link" id="bulk-clear">Clear selection</button>'
        . '</div>';
}

/**
 * The toolbar button that shows the checkboxes. They stay out of the way until somebody asks to
 * select (this button, or the X key), so the list reads as a list of tickets.
 */
function ticket_bulk_toggle(): string
{
    return '<button type="button" class="btn-secondary" id="bulk-toggle" aria-pressed="false">Select</button>';
}

/**
 * The 8 West IT 365 app drawer — the 3x3 squares, in the shared w365 markup that every suite app
 * renders in the same place (the right end of the page header, before the account menu).
 *
 * Always rendered for a signed-in technician, but its CONTENTS are only ever what Safeharbor
 * honestly knows. The three states come from suite_apps_drawer(); there is no fourth state that
 * guesses. It replaced a hard-coded sidebar list of two sister apps plus "All apps", which
 * advertised Milepost and Coastmark to people who may not be licensed for either.
 *
 * Tiles carry the product's SAME-ORIGIN mark from the vendored package, so no page load reaches
 * another host for artwork; the shared launcher swaps in the product's initial if a mark ever
 * fails to load. Presentation only — read-only links, no new permission, nothing executable.
 */
function suite_chrome_drawer(): void
{
    require_once __DIR__ . '/suite_apps.php';

    // The presence of the entitlement list is what makes this a suite session: auth.php writes it
    // from the VERIFIED token on every refresh (including as []) and removes it the moment the
    // token is gone, so a local password login never has one.
    $hasSuite = array_key_exists('suite_products', $_SESSION ?? []);
    $drawer = suite_apps_drawer($hasSuite, $_SESSION['suite_products'] ?? null, suite_app_registry());
    ?>
    <div class="w365-anchor" data-w365-drawer>
      <button type="button" class="w365-waffle" data-w365-drawer-button
              aria-label="Open 8 West IT 365 apps" aria-haspopup="true" aria-expanded="false"
              aria-controls="w365-app-drawer" title="8 West IT 365 apps">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><g fill="currentColor">
          <rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9.5" y="2" width="5" height="5" rx="1"/><rect x="17" y="2" width="5" height="5" rx="1"/>
          <rect x="2" y="9.5" width="5" height="5" rx="1"/><rect x="9.5" y="9.5" width="5" height="5" rx="1"/><rect x="17" y="9.5" width="5" height="5" rx="1"/>
          <rect x="2" y="17" width="5" height="5" rx="1"/><rect x="9.5" y="17" width="5" height="5" rx="1"/><rect x="17" y="17" width="5" height="5" rx="1"/>
        </g></svg>
      </button>
      <div class="w365-drawer" id="w365-app-drawer" data-w365-drawer-panel hidden>
        <div class="w365-drawer-head"><strong>8 West IT 365</strong><span>Your apps</span></div>
        <?php if ($drawer['state'] === 'apps'): ?>
          <nav class="w365-drawer-grid" aria-label="8 West IT 365 apps">
            <?php foreach ($drawer['apps'] as $app): ?>
              <a class="w365-tile" href="<?= h($app['url']) ?>" target="_blank" rel="noopener noreferrer"
                 title="<?= h($app['desc']) ?>">
                <span class="w365-tile-mark"><?php if (($app['mark'] ?? '') !== ''): ?><img src="/assets/w365/marks/<?= h($app['mark']) ?>?v=w365v040" alt="" width="48" height="48" loading="lazy" data-initial="<?= h(suite_app_initial($app['name'])) ?>"><?php else: ?><?= h(suite_app_initial($app['name'])) ?><?php endif; ?></span>
                <span class="w365-tile-name"><?= h($app['name']) ?></span>
              </a>
            <?php endforeach; ?>
          </nav>
        <?php elseif ($drawer['state'] === 'none'): ?>
          <p class="w365-drawer-note">No other 8 West IT 365 apps are licensed for your account.</p>
        <?php else: ?>
          <p class="w365-drawer-note">Sign in with 8 West ID to see the apps licensed for your account.</p>
          <a class="w365-drawer-link" href="https://id.8westit.com/" target="_blank" rel="noopener noreferrer">Go to 8 West ID</a>
        <?php endif; ?>
        <?php if ($drawer['state'] !== 'no_suite'): ?>
          <div class="w365-drawer-foot"><a href="https://id.8westit.com/?apps=1" target="_blank" rel="noopener noreferrer">8 West IT 365 home</a></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

/**
 * The account menu beside the app drawer (the shared w365 placement contract): who you are, Suite
 * settings when a suite session exists, Safeharbor's own profile page, and Sign out. It replaces
 * the identity block and the Sign out item that used to sit at the bottom of the sidebar, so
 * identity lives in the same place in every 8 West IT 365 app: top right, after the squares.
 *
 * WHO. With a suite session the menu shows the 8 West ID identity — the token's `name`, `email`
 * and the `8west:avatar` picture — so a person sees the same name and picture in every suite app.
 * A local Safeharbor password login shows the Safeharbor account name and a letter mark.
 *
 * THE PICTURE. Only the person's OWN picture, and only from 8 West ID: the avatar is rendered when
 * the session's avatar subject matches this user's `suite_subject` (the same subject binding
 * avatar() uses, so one technician's photo can never appear on another's account) AND the URL is
 * on https://id.8westit.com/ or same-origin. Anything else falls back to the initials circle.
 */
function suite_chrome_account_menu(array $user): void
{
    require_once __DIR__ . '/suite_apps.php';

    $hasSuite = array_key_exists('suite_products', $_SESSION ?? []);
    $suiteName = $hasSuite ? trim((string)($_SESSION['suite_name'] ?? '')) : '';
    $suiteEmail = $hasSuite ? trim((string)($_SESSION['suite_email'] ?? '')) : '';
    $name = $suiteName !== '' ? $suiteName : trim((string)($user['full_name'] ?? ''));
    $secondary = $suiteEmail !== '' ? $suiteEmail : (string)($user['email'] ?? '');
    $role = (string)($user['role'] ?? '');
    $roleLabel = ['owner' => 'Owner', 'admin' => 'Administrator', 'tech' => 'Technician'][$role]
        ?? ($role === '' ? '' : ucfirst($role));
    $initials = suite_name_initials($name);

    $avatar = (string)($_SESSION['suite_avatar'] ?? '');
    $subject = (string)($user['suite_subject'] ?? '');
    $avatarUsable = $avatar !== '' && $subject !== ''
        && hash_equals($subject, (string)($_SESSION['suite_avatar_subject'] ?? ''))
        && (str_starts_with($avatar, 'https://id.8westit.com/')
            || preg_match('~^(?![a-z][a-z0-9+.-]*:)(?!//)~i', $avatar) === 1);
    ?>
    <div class="w365-anchor" data-w365-account>
      <button type="button" class="w365-account-btn" data-w365-account-button aria-haspopup="menu" aria-expanded="false"
              aria-controls="w365-account-menu" aria-label="Account menu for <?= h($name) ?>">
        <?php if ($avatarUsable): ?><img class="w365-avatar" src="<?= h($avatar) ?>" alt="" width="32" height="32" referrerpolicy="no-referrer"><?php else: ?><span class="w365-avatar" aria-hidden="true"><?= h($initials) ?></span><?php endif; ?>
        <span class="w365-account-name"><?= h($name) ?></span>
        <svg viewBox="0 0 10 10" aria-hidden="true" focusable="false"><path d="M1 3l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      </button>
      <div class="w365-menu" id="w365-account-menu" data-w365-account-menu role="menu" hidden>
        <div class="w365-menu-who"><strong><?= h($name) ?></strong><span><?= h($secondary) ?><?= $roleLabel !== '' ? ' · ' . h($roleLabel) : '' ?></span></div>
        <div class="w365-menu-group" role="group" aria-label="Personal">
        <div class="w365-menu-label">Personal</div>
        <?php if ($hasSuite): ?>
          <a class="w365-menu-item" role="menuitem" href="https://id.8westit.com/settings.php" aria-label="My settings"><span>My settings</span><span class="w365-menu-hint">Your preferences · 8 West ID</span></a>
        <?php else: ?>
          <a class="w365-menu-item" role="menuitem" href="/profile.php" aria-label="My settings"<?= parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/profile.php' ? ' aria-current="page"' : '' ?>><span>My settings</span><span class="w365-menu-hint">Local Safeharbor account</span></a>
        <?php endif; ?>
        </div>
        <div class="w365-menu-group" role="group" aria-label="Workspaces">
        <div class="w365-menu-label">Workspaces</div>
        <div class="w365-menu-context">Your Safeharbor workspace</div>
        <a class="w365-menu-item" role="menuitem" href="/" aria-label="Technician Workspace"<?= in_array(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), ['/', '/index.php'], true) ? ' aria-current="page"' : '' ?>><span>Technician Workspace</span><span class="w365-menu-hint">Work queues · Safeharbor</span></a>
        <?php if ($hasSuite && in_array($role, ['owner', 'admin'], true)
            && in_array('msp_admin', $_SESSION['suite_settings_context']['panels'] ?? [], true)): ?>
          <a class="w365-menu-item" role="menuitem" href="https://id.8westit.com/admin.php?panel=msp_admin" aria-label="MSP Admin"><span>MSP Admin</span><span class="w365-menu-hint">MSP settings · 8 West ID</span></a>
        <?php endif; ?>
        </div>
        <hr class="w365-menu-sep">
        <form method="post" action="/logout.php"><?= csrf_field() ?><button type="submit" class="w365-menu-item" role="menuitem">Sign out</button></form>
      </div>
    </div>
    <?php
}

/** Full chrome: head + sidebar + topbar. */
function page_top(array $user, string $title, string $active, array $navCounts = []): void
{
    // A read-only rail count; failure leaves it unknown rather than falsely reporting zero.
    if (!array_key_exists('service-goals', $navCounts)) {
        $navCounts['service-goals'] = null;
        require_once __DIR__ . '/service_goal_policy_admin.php';
        require_once __DIR__ . '/ticket_queue.php';
        if (service_goal_policy_can_view_history($user)) {
            try {
                $navCounts['service-goals'] = queue_risk_count(db(), (int)$user['tenant_id']);
            } catch (Throwable $error) {
                error_log('service-goal rail count unavailable: ' . $error::class);
            }
        }
    }
    $nav = [
        ['/',          'Queue',   'queue',   'G Q', '<path d="M2 4h12M2 8h12M2 12h7" stroke-linecap="round"/>'],
        ['/time.php',  'Time',    'time',    'G T', '<circle cx="8" cy="8" r="6"/><path d="M8 4.5V8l2.5 2" stroke-linecap="round"/>'],
        ['/clients.php', 'Clients', 'clients', 'G C', '<circle cx="5.5" cy="6" r="2.5"/><circle cx="11" cy="7" r="2"/><path d="M1.5 13.5c.6-2.3 2.2-3.5 4-3.5s3.4 1.2 4 3.5M9.5 12.6c.7-1.4 1.9-2.1 3-2.1 1.3 0 2.4.9 2.9 2.6" stroke-linecap="round"/>'],
        ['/service_goals.php', 'Service goals', 'service-goals', 'G S', '<circle cx="8" cy="8" r="5.5"/><circle cx="8" cy="8" r="2"/><path d="M8 1.5V4M8 12v2.5M1.5 8H4M12 8h2.5" stroke-linecap="round"/>'],
        ['/reports.php', 'Reports', 'reports', 'G R', '<path d="M2.5 13.5h11M4 13V8.5M8 13V4.5M12 13V6.5" stroke-linecap="round"/>'],
        ['/users.php', 'Team',    'team',    'G U', '<circle cx="8" cy="5.5" r="2.5"/><path d="M3 13.5c.8-2.6 2.8-4 5-4s4.2 1.4 5 4M11.5 5.8a2 2 0 1 1 .01 0M11.6 9.6c1.6.4 2.8 1.6 3.3 3.4" stroke-linecap="round"/>'],
    ];
    header('Content-Type: text/html; charset=utf-8');
    ?>
<?php $suitePreferences = is_array($_SESSION['suite_preferences'] ?? null)
    ? $_SESSION['suite_preferences'] : suite_preferences_defaults(); ?>
<!doctype html>
<html lang="<?= h((string)$suitePreferences['locale']) ?>"
      data-preferences-source="8west-id"
      data-density="<?= h((string)$suitePreferences['density']) ?>"
      data-contrast="<?= h((string)$suitePreferences['contrast']) ?>"
      data-motion="<?= h((string)$suitePreferences['motion']) ?>"
      data-time-zone="<?= h((string)$suitePreferences['time_zone']) ?>"
      data-date-format="<?= h((string)$suitePreferences['date_format']) ?>"
      data-time-format="<?= h((string)$suitePreferences['time_format']) ?>"
      data-default-product="<?= h((string)($suitePreferences['default_product'] ?? '')) ?>"
      data-westy-detail="<?= h((string)$suitePreferences['westy_detail']) ?>"
      data-notifications-enabled="<?= $suitePreferences['notifications_enabled'] ? 'true' : 'false' ?>"
      data-quiet-hours-start="<?= h((string)($suitePreferences['quiet_hours_start'] ?? '')) ?>"
      data-quiet-hours-end="<?= h((string)($suitePreferences['quiet_hours_end'] ?? '')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Safeharbor</title>
<link rel="icon" type="image/svg+xml" href="/assets/brand/favicon.svg">
<link rel="icon" type="image/x-icon" href="/assets/brand/favicon.ico" sizes="16x16 32x32 48x48">
<link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
<meta name="theme-color" content="#061936">
<script>
document.documentElement.dataset.theme=localStorage.getItem("safeharbor.theme")||"system";
<?php if (!empty($_SESSION['suite_theme'])): ?>
(function(){var t=<?= json_encode($_SESSION['suite_theme']) ?>;localStorage.setItem("safeharbor.theme",t);document.documentElement.dataset.theme=t;})();
<?php endif; ?>
</script>
<?php /* The shared 8 West IT 365 suite chrome (w365), vendored under /assets/w365/ and served from
         THIS origin — nothing is hot-loaded from another host, so no Content-Security-Policy
         directive changes. It loads BEFORE app.css so Safeharbor's own stylesheet always wins, and
         the --w365-* hooks it reads are mapped to Safeharbor's semantic tokens there, which is how
         the two panels follow the dark / light / system themes without the package shipping a
         palette of its own. */ ?>
<link rel="stylesheet" href="/assets/w365/w365.css?v=w365v040">
<link rel="stylesheet" href="/assets/css/app.css?v=20261113-sh-desk2-floors-v1">
</head>
<body data-active="<?= h($active) ?>" data-csrf="<?= csrf_token() ?>"
      data-tenant-id="<?= (int)$user['tenant_id'] ?>" data-user-id="<?= (int)$user['id'] ?>">
<div class="shell">
  <aside class="sidebar" id="suite-sidebar">
    <button type="button" class="mobile-nav-close" aria-label="Close navigation">Close menu ×</button>
    <a class="brand" href="/" aria-label="Safeharbor home">
      <img src="/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png" alt="Safeharbor — 8 West IT 365" class="suite-brand-logo" width="1851" height="513">
    </a>
    <div class="horizon" aria-hidden="true"></div>
    <nav class="nav">
      <?php foreach ($nav as [$href, $label, $key, $kbd, $icon]): ?>
      <a href="<?= $href ?>" class="nav-item<?= $active === $key ? ' nav-on' : '' ?>">
        <svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><?= $icon ?></svg>
        <span class="nav-label"><?= h($label) ?></span>
        <?php if ($key === 'service-goals' && ($navCounts[$key] ?? 0) > 0): ?>
          <span class="nav-goal-count" aria-label="<?= (int)$navCounts[$key] ?> tickets at risk"><?= number_format((int)$navCounts[$key]) ?></span>
        <?php endif; ?>
        <kbd class="kbd nav-kbd"><?= $kbd ?></kbd>
      </a>
      <?php endforeach; ?>
    </nav>
    <?php /* The keys are listed in one place, the "?" card; this opens it for somebody who does not
             know that key yet. */ ?>
    <button type="button" class="nav-item nav-foot" id="shortcuts-open">
      <svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><rect x="1.5" y="4" width="13" height="8.5" rx="1.8"/><path d="M4.2 6.8h.1M6.7 6.8h.1M9.2 6.8h.1M11.7 6.8h.1M5 10h6" stroke-linecap="round"/></svg>
      <span class="nav-label">Keyboard shortcuts</span>
      <kbd class="kbd nav-kbd">?</kbd>
    </button>
    <?php /* The hard-coded "8 West Suite" list (Milepost, Coastmark, All apps) and the lower-left
             identity menu used to sit here. Both moved to the shared cluster at the right end of the
             topbar below: the drawer now lists the apps this person is actually licensed for, and
             the account menu is the single home for identity, suite settings and Sign out. */ ?>
  </aside>
  <button type="button" class="mobile-nav-backdrop" aria-label="Close navigation" tabindex="-1"></button>
  <div class="main">
    <header class="topbar">
      <button type="button" id="mobile-nav-toggle" class="mobile-nav-toggle" aria-controls="suite-sidebar" aria-expanded="false">☰ Menu</button>
      <button id="palette-trigger" class="search-trigger" type="button" aria-label="Search or run a command">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="4.5"/><path d="m10.5 10.5 3 3" stroke-linecap="round"/></svg>
        <span class="search-label">Search or command…</span>
        <span class="search-keys" aria-hidden="true"><kbd class="kbd" data-mod-key>Ctrl</kbd><kbd class="kbd">K</kbd></span>
      </button>
      <span class="topbar-flex"></span>
      <a href="/time.php" id="timer-widget" class="timer-widget timer-idle" title="Time tracking" aria-label="Time: no timer running"><span class="tw-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="10" cy="10" r="7.5"/><path d="M10 5.5V10l3 2" stroke-linecap="round"/></svg></span><span class="tw-idle-label">No timer running</span></a>
      <?php /* The shared suite cluster (w365): Safeharbor's own controls, then the app drawer, then
               the account menu — the same place, in the same order, in every 8 West IT 365 app. */ ?>
      <div class="w365-cluster">
        <?php suite_chrome_drawer(); ?>
        <?php suite_chrome_account_menu($user); ?>
      </div>
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
<div id="toasts" class="toasts" role="status" aria-live="polite"></div>
<?php westy_bubble_render(); ?>
<script id="palette-data" type="application/json"><?= json_encode($paletteData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php /* The shared launcher opens and closes the two panels. Vendored, same-origin, deferred; it
         injects no markup, fetches nothing and reads no storage. */ ?>
<script src="/assets/w365/w365-launcher.js?v=w365v040" defer></script>
<script src="/assets/js/app.js?v=20261113-sh-desk2-floors-v1" defer></script>
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
        // What the status, priority and owner menus offer (queue and ticket pages).
        'menus' => ticket_menu_choices(ticket_menu_team()),
    ];
}
