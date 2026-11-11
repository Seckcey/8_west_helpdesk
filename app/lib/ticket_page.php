<?php
/**
 * The ticket page: its head, the conversation's message cards, and the Details column with the
 * customer beside the conversation (contact, client, the client's note, their other open tickets).
 *
 * Rendering only, except ticket_other_open() and ticket_own_minutes(), which read. Both are scoped
 * to the signed-in tenant and to the ticket's own client or ticket, the same scope ticket.php and
 * client.php already read with: nothing here shows a person more than those pages do.
 *
 * Needs render.php (h(), sla_lamp(), CHANNEL_WORDS, STATUS_META) and ticket_queue.php.
 */
declare(strict_types=1);

/** How many other open tickets the Merge dialog can list, and how many the Details column shows. */
const TICKET_OTHER_OPEN_LIMIT = 100;
const TICKET_OTHER_OPEN_SHOWN = 5;

/** "20 minutes ago", "3 hours ago", "yesterday", "5 days ago", then "on Nov 3, 2026". */
function ticket_ago(string $utc, ?int $now = null, string $timeZone = 'UTC'): string
{
    $then = strtotime(trim($utc) . ' UTC');
    if ($then === false) return '';
    $minutes = max(1, intdiv(max(0, ($now ?? time()) - $then), 60));
    if ($minutes < 60) return $minutes . ($minutes === 1 ? ' minute ago' : ' minutes ago');
    $hours = intdiv($minutes, 60);
    if ($hours < 24) return $hours . ($hours === 1 ? ' hour ago' : ' hours ago');
    $days = intdiv($hours, 24);
    if ($days === 1) return 'yesterday';
    if ($days < 30) return $days . ' days ago';
    return 'on ' . (new DateTimeImmutable('@' . $then))->setTimezone(ticket_zone($timeZone))->format('M j, Y');
}

function ticket_zone(string $timeZone): DateTimeZone
{
    try {
        return new DateTimeZone($timeZone);
    } catch (Throwable) {
        return new DateTimeZone('UTC');
    }
}

/**
 * When a message was written, on the desk's clock: "10:42 AM" today, "Nov 3, 10:42 AM" this year,
 * "Nov 3, 2025, 10:42 AM" before that.
 */
function ticket_clock(string $utc, string $timeZone, ?int $now = null): string
{
    $then = strtotime(trim($utc) . ' UTC');
    if ($then === false) return '';
    $zone = ticket_zone($timeZone);
    $at = (new DateTimeImmutable('@' . $then))->setTimezone($zone);
    $today = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($zone);
    if ($at->format('Y-m-d') === $today->format('Y-m-d')) return $at->format('g:i A');
    return $at->format($at->format('Y') === $today->format('Y') ? 'M j, g:i A' : 'M j, Y, g:i A');
}

/** "12 min", "1 h", "1 h 20 min". */
function ticket_minutes_label(int $minutes): string
{
    $minutes = max(0, $minutes);
    if ($minutes < 60) return $minutes . ' min';
    return intdiv($minutes, 60) . ' h' . ($minutes % 60 > 0 ? ' ' . ($minutes % 60) . ' min' : '');
}

/** Two letters for a message's avatar, from the author's name. */
function ticket_initials(string $name): string
{
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($words === []) return '';
    $letters = mb_substr($words[0], 0, 1);
    if (count($words) > 1) $letters .= mb_substr($words[count($words) - 1], 0, 1);
    return mb_strtoupper($letters);
}

/**
 * The sentence under the title: "#1041 for Bayside Realty, opened 20 minutes ago by phone from
 * Dana Whitfield". The client is a link; everything else is plain words.
 */
function ticket_subtitle(array $ticket, string $who, ?int $now = null, string $timeZone = 'UTC'): string
{
    $channel = (string)($ticket['channel'] ?? '');
    $line = '#' . (int)$ticket['id'] . ' for <a class="link" href="/client.php?id=' . (int)$ticket['client_id'] . '">'
        . h($ticket['client_name']) . '</a>';
    $ago = ticket_ago((string)$ticket['created_at'], $now, $timeZone);
    if ($ago !== '') $line .= ', opened ' . h($ago);
    if (isset(CHANNEL_WORDS[$channel])) {
        $line .= ' ' . h(CHANNEL_WORDS[$channel]);
        // An alert is written by a machine: there is nobody to name. Nor is there when the
        // opening message is in the client's own name (a call logged with no contact).
        $who = trim($who);
        if ($who !== '' && $channel !== 'alert' && strcasecmp($who, trim((string)$ticket['client_name'])) !== 0) {
            $line .= ($channel === 'portal' ? ' by ' : ' from ') . h($who);
        }
    }
    return $line;
}

/**
 * The top of the ticket page: the way back, the place in the list the person came from with
 * previous and next, who else is here, Merge and Resolve; then the title and the sentence under it.
 *
 * $where is queue_ticket_place()'s answer, or null when the ticket was opened without a list (a
 * search, a link in an email): then there is no counter and no arrows, only the way back.
 */
function ticket_page_head(array $ticket, ?array $where, string $subtitle, bool $merged): string
{
    $id = (int)$ticket['id'];
    $chevron = static fn(string $path): string => '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" aria-hidden="true"><path d="' . $path . '" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    $html = '<div class="ticket-bar"><a href="' . h($where['back'] ?? '/') . '" class="backlink">' . $chevron('M10 3.5 5.5 8l4.5 4.5') . 'Queue</a>';
    if ($where !== null) {
        $list = (string)$where['label'];
        $html .= '<span class="ticket-place">'
            . '<span class="ticket-pos">' . h($where['position'] > 0
                ? $where['position'] . ' of ' . $where['total']
                : 'No longer in ' . $list) . '</span>';
        foreach ([['prev', 'Previous', '[', 'M4 10l4-4 4 4'], ['next', 'Next', ']', 'M4 6l4 4 4-4']] as [$key, $word, $press, $path]) {
            $label = $word . ' ticket in ' . $list;
            $html .= $where[$key] !== null
                ? '<a class="ticket-step" href="' . h($where[$key]) . '" data-step="' . $key . '" aria-label="' . h($label) . '"'
                    . ' title="' . h($label) . ' (' . $press . ')">' . $chevron($path) . '</a>'
                // The first ticket has nothing before it and the last nothing after: shown, but not a link.
                : '<span class="ticket-step ticket-step-off" aria-hidden="true">' . $chevron($path) . '</span>';
        }
        $html .= '</span>';
    }
    $html .= '<span class="presence-row" id="presence"></span><span class="ticket-tools">';
    if (!$merged) {
        $html .= '<button type="button" class="btn-secondary" id="merge-btn" data-id="' . $id . '">Merge</button>'
            . '<span class="ticket-actions">' . ticket_resolve_buttons($id, (string)$ticket['status']) . '</span>';
    }
    return $html . '</span></div>'
        . '<h1 class="ticket-subject">' . h($ticket['subject']) . '</h1>'
        . '<p class="ticket-sub">' . $subtitle . '</p>';
}

/**
 * A caution or info callout under the head: a bold first line, a plain sentence, and its button
 * at the right. $sentence and $button are HTML the caller has already escaped.
 */
function ticket_callout(string $kind, string $title, string $sentence, string $button = ''): string
{
    $icon = $kind === 'caution'
        ? '<path d="M8 2.2 14.3 13H1.7L8 2.2Z" stroke-linejoin="round"/><path d="M8 6.5v3.2M8 11.4v.1" stroke-linecap="round"/>'
        : '<circle cx="8" cy="8" r="6.2"/><path d="M8 7.3v3.6M8 5.1v.1" stroke-linecap="round"/>';
    return '<div class="ticket-callout ticket-callout-' . h($kind) . '" role="note">'
        . '<svg class="ticket-callout-icon" width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">' . $icon . '</svg>'
        . '<p class="ticket-callout-text"><strong>' . h($title) . '</strong> ' . $sentence . '</p>'
        . $button . '</div>';
}

/** Who wrote a message, as a word a person would use. */
const TICKET_MESSAGE_ROLES = [
    'client' => ['Customer', 'msg-client'],
    'tech'   => ['Support', 'msg-tech'],
    'note'   => ['Internal note', 'msg-note'],
];

/**
 * One message in the conversation: a full-width card with the writer's initials and name, a tag
 * that says who they are, and when (the clock time, with "20 minutes ago" on hover). A system line
 * (a merge, an automatic close) stays a quiet line between the cards.
 *
 * $attachments is att_chips()'s HTML for this message.
 */
function ticket_message(array $message, string $attachments, string $timeZone, ?int $now = null): string
{
    $written = (string)$message['created_at'];
    $stamp = strtotime($written . ' UTC');
    $when = '<time class="msg-when"' . ($stamp !== false ? ' datetime="' . gmdate('Y-m-d\TH:i:s\Z', $stamp) . '"' : '')
        . ' title="' . h(ticket_ago($written, $now, $timeZone)) . '">' . h(ticket_clock($written, $timeZone, $now)) . '</time>';
    $kind = (string)$message['kind'];
    if ($kind === 'system') {
        return '<div class="msg-system"><span>' . h($message['body']) . '</span> ' . $when . '</div>';
    }
    [$role, $class] = TICKET_MESSAGE_ROLES[$kind] ?? TICKET_MESSAGE_ROLES['client'];
    $name = trim((string)$message['author_name']);
    $initials = ticket_initials($name);
    return '<article class="card msg ' . $class . '">'
        . '<header class="msg-meta">'
        . '<span class="msg-avatar" aria-hidden="true">' . h($initials !== '' ? $initials : '?') . '</span>'
        . '<span class="msg-author">' . h($name !== '' ? $name : $role) . '</span>'
        . '<span class="msg-role">' . h($role) . '</span>' . $when
        . '</header>'
        . '<p class="msg-body">' . h($message['body']) . '</p>'
        . $attachments
        . '</article>';
}

/**
 * The client's other open tickets, the most pressing first: what the Details column lists and what
 * the Merge dialog offers. Same tenant and same client as the ticket on screen, never merged stubs.
 *
 * @return list<array<string,mixed>>
 */
function ticket_other_open(PDO $pdo, int $tenantId, int $clientId, int $ticketId): array
{
    $query = $pdo->prepare(
        "SELECT t.id, t.tenant_id, t.client_id, t.subject, t.status, t.priority, t.channel, t.created_at, t.sla_due_at,
                t.resolved_at, t.merged_into_id, t.contact_id,
                (SELECT MIN(m.created_at) FROM messages m WHERE m.ticket_id = t.id AND m.kind = 'tech') AS first_response_at,
                EXISTS (SELECT 1 FROM tickets merged_source WHERE merged_source.tenant_id = t.tenant_id AND merged_source.merged_into_id = t.id) AS has_merged_sources,
                c.name AS client_name, k.name AS contact_name
           FROM tickets t
           JOIN clients c ON c.id = t.client_id AND c.tenant_id = t.tenant_id
           LEFT JOIN contacts k ON k.id = t.contact_id AND k.client_id = t.client_id
          WHERE t.tenant_id = ? AND t.client_id = ? AND t.id <> ?
            AND t.status <> 'resolved' AND t.merged_into_id IS NULL
          ORDER BY FIELD(t.priority, 'urgent','high','normal','low'), t.sla_due_at ASC, t.id ASC
          LIMIT " . (TICKET_OTHER_OPEN_LIMIT + 1)
    );
    $query->execute([$tenantId, $clientId, $ticketId]);
    return $query->fetchAll();
}

/**
 * The minutes the signed-in person has logged on this ticket, rejected entries left out. Only
 * their own: the Time page shows a technician nobody else's entries, and neither does this.
 */
function ticket_own_minutes(PDO $pdo, int $tenantId, int $ticketId, int $userId): int
{
    $query = $pdo->prepare(
        "SELECT COALESCE(SUM(minutes), 0) FROM time_entries
          WHERE tenant_id = ? AND ticket_id = ? AND user_id = ? AND approval_status <> 'rejected'"
    );
    $query->execute([$tenantId, $ticketId, $userId]);
    return (int)$query->fetchColumn();
}

/**
 * What the Merge dialog needs about each ticket it can offer: the number, the title, and one line
 * that says what it is ("Open, opened 5 days ago by email from Dana Whitfield").
 *
 * @param list<array<string,mixed>> $others
 * @return list<array{id:int,subject:string,summary:string}>
 */
function ticket_merge_choices(array $others, ?int $now = null, string $timeZone = 'UTC'): array
{
    $choices = [];
    foreach (array_slice($others, 0, TICKET_OTHER_OPEN_LIMIT) as $other) {
        $channel = (string)($other['channel'] ?? '');
        $who = trim((string)($other['contact_name'] ?? ''));
        $summary = (STATUS_META[(string)$other['status']][0] ?? 'Open') . ', opened ' . ticket_ago((string)$other['created_at'], $now, $timeZone);
        if (isset(CHANNEL_WORDS[$channel])) {
            $summary .= ' ' . CHANNEL_WORDS[$channel];
            if ($who !== '' && $channel !== 'alert') $summary .= ($channel === 'portal' ? ' by ' : ' from ') . $who;
        }
        $choices[] = ['id' => (int)$other['id'], 'subject' => (string)$other['subject'], 'summary' => $summary . '.'];
    }
    return $choices;
}

/**
 * The Details column. $menus is the three ticket_menu_button()s; $tag is sla_lamp()'s HTML.
 *
 * @param array{menus:string,tag:string,plan:string,minutes:int,contact:?array{name:string,email:string,portal?:bool},
 *              note:string,others:?list<array<string,mixed>>,device_url:?string} $parts
 *        contact: null when nobody is attached; portal says the request came from the portal, which
 *        keeps no contact row. others: null on a merged ticket, which lists none.
 */
function ticket_details(array $ticket, array $parts): string
{
    $id = (int)$ticket['id'];
    $clientId = (int)$ticket['client_id'];
    $client = (string)$ticket['client_name'];
    $html = '<aside class="rail" aria-label="Details"><h2 class="rail-label">Details</h2>'
        . '<div class="card rail-card rail-sections">'
        . '<div class="rail-section"><div class="rail-props">' . $parts['menus'] . '</div>'
        . '<div class="rail-prop"><span class="rail-k">First response</span>'
        . '<span class="rail-v"><span data-sla>' . $parts['tag'] . '</span></span></div>'
        . ($parts['plan'] !== '' ? '<p class="rail-note">' . h($parts['plan']) . '</p>' : '')
        . '<div class="rail-prop rail-time"><span class="rail-k">Your time</span>'
        . '<span class="rail-v"><span class="rail-minutes">' . h(ticket_minutes_label((int)$parts['minutes'])) . '</span>'
        . '<button type="button" class="btn-secondary timer-btn" id="timer-btn" data-id="' . $id . '">Start timer<kbd class="kbd" aria-hidden="true">E</kbd></button></span></div>'
        . '</div>';

    // The customer: who asked, how to reach them, whose they are, and what the team wrote about that client.
    $contact = $parts['contact'];
    $html .= '<div class="rail-section rail-customer">';
    if ($contact !== null && trim($contact['name']) !== '') {
        $html .= '<p class="rail-person">' . h($contact['name']) . '</p>';
        if (trim($contact['email']) !== '') {
            $html .= '<p class="rail-line"><span class="rail-email">' . h($contact['email']) . '</span>'
                . '<button type="button" class="btn-chip rail-copy" data-copy="' . h($contact['email']) . '"'
                . ' aria-label="Copy ' . h($contact['name']) . '\'s email address">Copy</button></p>';
        } else {
            $html .= '<p class="rail-line rail-quiet">' . (!empty($contact['portal']) ? 'Asked from the portal.' : 'No email address on file.') . '</p>';
        }
    } else {
        $html .= '<p class="rail-line rail-quiet">No contact is attached to this ticket.</p>';
    }
    $html .= '<p class="rail-line"><span class="rail-client">' . h($client) . '</span>'
        . '<a class="link" href="/client.php?id=' . $clientId . '">Open client</a></p>';
    $note = trim($parts['note']);
    if ($note !== '') {
        $html .= '<p class="rail-client-note"><span class="rail-k">Note about ' . h($client) . '</span>' . h($note) . '</p>';
    }
    $html .= '</div>';

    // Their other open tickets, the most pressing first. A merged ticket lists none: its
    // conversation lives on the ticket it was merged into.
    $others = $parts['others'];
    if ($others === null) $others = [];
    $more = count($others) > TICKET_OTHER_OPEN_LIMIT;
    $count = min(count($others), TICKET_OTHER_OPEN_LIMIT);
    if ($parts['others'] !== null) $html .= '<div class="rail-section rail-others"><h3 class="rail-k">Other open tickets for ' . h($client)
        . ' (' . $count . ($more ? '+' : '') . ')</h3>';
    if ($parts['others'] === null) {
        // nothing to list
    } elseif ($others === []) {
        $html .= '<p class="rail-line rail-quiet">None. This is their only open ticket.</p>';
    } else {
        $html .= '<ul class="rail-tickets">';
        foreach (array_slice($others, 0, TICKET_OTHER_OPEN_SHOWN) as $other) {
            $html .= '<li><a class="rail-ticket" href="/ticket.php?id=' . (int)$other['id'] . '">'
                . '<span class="rail-ticket-name"><span class="rail-ticket-num">#' . (int)$other['id'] . '</span> ' . h($other['subject']) . '</span>'
                . sla_lamp($other) . '</a></li>';
        }
        $html .= '</ul>';
        if ($count > TICKET_OTHER_OPEN_SHOWN) {
            $html .= '<p class="rail-line"><a class="link" href="/?view=open&amp;client=' . $clientId . '">See all '
                . $count . ($more ? ' or more' : '') . ' in the queue</a></p>';
        }
    }
    if ($parts['others'] !== null) $html .= '</div>';

    if ($parts['device_url'] !== null) {
        $html .= '<div class="rail-section"><p class="rail-line"><span class="rail-k">Computers</span>'
            . '<a class="link" href="' . h($parts['device_url']) . '" target="_blank" rel="noopener">Open in Milepost</a></p></div>';
    }
    return $html . '</div></aside>';
}
