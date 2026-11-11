<?php
/**
 * The client page's own pieces: its head, the note, the number tiles, the Open / Resolved filter
 * over the ticket list with its pager, and a contact's row.
 *
 * Rendering only: plain markup from plain values, so a test can draw every piece without a
 * database. public/client.php reads the rows, each scoped to the signed-in tenant and this client;
 * nothing here decides who may see what.
 *
 * Needs h() (bootstrap.php) and ticket_minutes_label() (ticket_page.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/ticket_page.php';

/** How many resolved tickets one page of the client's list holds. */
const CLIENT_RESOLVED_PAGE_SIZE = 25;
/** The days the first-reply tile looks back over, and the days the billable-time tile does. */
const CLIENT_FIRST_REPLY_DAYS = 90;
const CLIENT_BILLABLE_DAYS = 30;

/** The service plan in words, as the ticket page says it: "Premium plan". */
function client_plan_label(string $tier): string
{
    return $tier === 'premium' ? 'Premium plan' : 'Standard plan';
}

/** The client page's own address, on one of its two ticket lists. */
function client_url(int $id, string $show = 'open', int $page = 1): string
{
    return '/client.php?id=' . $id . ($show === 'resolved' ? '&show=resolved' : '') . ($page > 1 ? '&page=' . $page : '');
}

/**
 * Which list and page the address asks for, with anything unknown replaced by the first page of
 * Open. The page is checked against the real count later (client_resolved_page()).
 *
 * @param array<string,mixed> $get
 * @return array{show:string,page:int}
 */
function client_list_state(array $get): array
{
    $show = ($get['show'] ?? '') === 'resolved' ? 'resolved' : 'open';
    $page = is_string($get['page'] ?? null) && preg_match('/^[1-9][0-9]{0,5}$/', $get['page']) === 1 ? (int)$get['page'] : 1;
    return ['show' => $show, 'page' => $show === 'resolved' ? $page : 1];
}

/** The page of resolved tickets to show: the one asked for, or the last one when there are fewer. */
function client_resolved_page(int $asked, int $total): int
{
    return min(max(1, $asked), max(1, (int)ceil($total / CLIENT_RESOLVED_PAGE_SIZE)));
}

/**
 * Name, plan, "Needs attention" when the client is being watched, domain, then New ticket and Edit.
 *
 * @param array{id:int|string,name:string,sla_tier?:?string,health?:?string,domain?:?string} $client
 */
function client_head(array $client): string
{
    $id = (int)$client['id'];
    $domain = trim((string)($client['domain'] ?? ''));
    return '<div class="client-head"><div class="client-title">'
        . '<h1 class="page-title">' . h($client['name']) . '</h1>'
        . '<span class="tier">' . client_plan_label((string)($client['sla_tier'] ?? '')) . '</span>'
        . (($client['health'] ?? '') === 'watch' ? '<span class="tier tag-caution">Needs attention</span>' : '')
        . ($domain !== '' ? '<span class="client-domain">' . h($domain) . '</span>' : '')
        . '</div><div class="client-actions">'
        // data-new-ticket: the page's key "c" opens this link, so the form starts with this client chosen.
        . '<a href="/ticket_new.php?client=' . $id . '" class="btn-primary" data-new-ticket>New ticket</a>'
        . '<a href="/client_edit.php?id=' . $id . '" class="btn-secondary">Edit</a>'
        . '</div></div>';
}

/** The note kept about the client, when there is one. Line breaks are kept; nothing in it is markup. */
function client_note(?string $notes): string
{
    $notes = trim((string)$notes);
    if ($notes === '') return '';
    return '<p class="client-note"><span class="client-note-k">Note</span> ' . nl2br(h($notes), false) . '</p>';
}

/**
 * The number tiles. Every tile is a number: a tile with nothing to count is left out.
 *
 * $firstReplyMinutes is the average wait for a first reply over $firstReplyTickets tickets opened in
 * the last CLIENT_FIRST_REPLY_DAYS days (null when none had a reply). $billableMinutes is approved
 * billable time worked in the last CLIENT_BILLABLE_DAYS days, the figure Reports shows for the client.
 */
function client_tiles(int $open, ?int $firstReplyMinutes, int $firstReplyTickets, int $billableMinutes): string
{
    $tile = static fn(string $label, string $value, string $hint = ''): string => '<div class="card stat"><div class="stat-k">' . $label . '</div>'
        . '<div class="stat-v">' . $value . '</div>' . ($hint !== '' ? '<div class="stat-hint">' . $hint . '</div>' : '') . '</div>';
    $html = '<div class="stats client-stats">' . $tile('Open tickets', number_format(max(0, $open)));
    if ($firstReplyMinutes !== null && $firstReplyTickets > 0) {
        $html .= $tile('First reply, ' . CLIENT_FIRST_REPLY_DAYS . ' days', ticket_minutes_label($firstReplyMinutes),
            'Average over ' . ($firstReplyTickets === 1 ? '1 ticket' : number_format($firstReplyTickets) . ' tickets'));
    }
    return $html . $tile('Billable time, ' . CLIENT_BILLABLE_DAYS . ' days', ticket_minutes_label($billableMinutes), 'Approved') . '</div>';
}

/** Open and Resolved as a segmented filter over the client's tickets, each with its count. */
function client_ticket_tabs(int $id, string $show, int $open, int $resolved): string
{
    $tab = static fn(string $key, string $label, int $count): string => '<a href="' . h(client_url($id, $key)) . '" class="filter-pill'
        . ($show === $key ? ' pill-on" aria-current="page"' : '"') . '>' . $label
        . '<span class="pill-count">' . number_format(max(0, $count)) . '</span></a>';
    return '<nav class="filters client-filters" aria-label="This client’s tickets">' . $tab('open', 'Open', $open) . $tab('resolved', 'Resolved', $resolved) . '</nav>';
}

/** What the list says when it has no rows. */
function client_tickets_empty(string $show, string $clientName, int $clientId): string
{
    if ($show === 'resolved') return '<div class="empty"><p>No resolved tickets for ' . h($clientName) . ' yet.</p></div>';
    return '<div class="empty"><p>No open tickets for ' . h($clientName) . '.</p>'
        . '<a class="btn-chip" href="/ticket_new.php?client=' . $clientId . '">New ticket</a></div>';
}

/** Under the resolved list, when it runs over one page: which rows these are, then Previous and Next. */
function client_resolved_pager(int $id, int $total, int $page): string
{
    if ($total <= CLIENT_RESOLVED_PAGE_SIZE) return '';
    $page = client_resolved_page($page, $total);
    $pages = (int)ceil($total / CLIENT_RESOLVED_PAGE_SIZE);
    $step = static fn(string $label, string $rel, ?int $to): string => $to === null
        ? '<span class="btn-chip pager-off" aria-disabled="true">' . $label . '</span>'
        : '<a class="btn-chip" rel="' . $rel . '" href="' . h(client_url($id, 'resolved', $to)) . '">' . $label . '</a>';
    return '<nav class="pager" aria-label="Resolved ticket pages"><span class="pager-count">Showing '
        . number_format(($page - 1) * CLIENT_RESOLVED_PAGE_SIZE + 1) . ' to ' . number_format(min($total, $page * CLIENT_RESOLVED_PAGE_SIZE))
        . ' of ' . number_format($total) . '</span>'
        . $step('Previous', 'prev', $page > 1 ? $page - 1 : null) . $step('Next', 'next', $page < $pages ? $page + 1 : null) . '</nav>';
}

/**
 * One contact: name, address, and a "More" menu that holds Remove. Removing asks first (the form's
 * data-confirm names the contact), and posts to the page that owns the contact.
 *
 * @param array{id:int|string,name:string,email?:?string} $contact
 * @param string $csrfField the already-rendered hidden CSRF field
 */
function client_contact_row(array $contact, int $clientId, string $csrfField): string
{
    $name = (string)$contact['name'];
    $email = trim((string)($contact['email'] ?? ''));
    return '<div class="rail-list-item contact-row"><div class="contact-main">'
        . '<div class="rail-list-name">' . h($name) . '</div>'
        . ($email !== '' ? '<div class="rail-list-sub">' . h($email) . '</div>' : '')
        . '</div><details class="contact-more">'
        . '<summary class="contact-more-btn" aria-label="' . h('More for ' . $name) . '" title="More">'
        . '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><circle cx="3" cy="8" r="1.4"/><circle cx="8" cy="8" r="1.4"/><circle cx="13" cy="8" r="1.4"/></svg></summary>'
        . '<form method="post" action="' . h(client_url($clientId)) . '" data-confirm="' . h('Remove ' . $name . '?') . '">'
        . $csrfField
        . '<input type="hidden" name="action" value="remove_contact">'
        . '<input type="hidden" name="contact_id" value="' . (int)$contact['id'] . '">'
        . '<button type="submit" class="btn-ghost contact-remove" aria-label="' . h('Remove ' . $name) . '">Remove</button>'
        . '</form></details></div>';
}
