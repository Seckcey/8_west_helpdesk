<?php
/**
 * The queue: its views, filters, order and paging, each defined once.
 *
 * A view answers "what needs me": Needs attention (the default), Mine, Unassigned, Waiting on
 * client, All open, Resolved. Filters narrow any view by client, priority and owner. Everything is
 * an ordinary link in the address (?view=&client=&priority=&owner=&sort=&page=), so Back and
 * bookmarks work.
 *
 * Every query is scoped to the signed-in tenant and takes its values as bound parameters; the only
 * things written into SQL text are fixed fragments from this file and whole numbers.
 *
 * Needs render.php (h(), PRIORITY_META) and db().
 */
declare(strict_types=1);

const QUEUE_PAGE_SIZE = 50;

/** "Due soon" is the same two hours that sla_info() calls at risk. */
const QUEUE_DUE_SOON_SECONDS = 7200;

const QUEUE_VIEWS = [
    'needs'      => 'Needs attention',
    'mine'       => 'Mine',
    'unassigned' => 'Unassigned',
    'waiting'    => 'Waiting on client',
    'open'       => 'All open',
    'resolved'   => 'Resolved',
];

const QUEUE_SORTS = [
    'reply'    => 'First response due',
    'updated'  => 'Last updated',
    'priority' => 'Priority',
    'newest'   => 'Newest',
];

/**
 * Facts about one ticket that the views and orders use, as SQL over the alias t. The queue reads
 * them straight from messages; the counts read them once per ticket into columns of the same name
 * (QUEUE_FACT_COLUMNS), so a view means the same thing in the list and in its count.
 */
const QUEUE_FACTS = [
    '{first_response}' => "(SELECT MIN(m.created_at) FROM messages m WHERE m.ticket_id = t.id AND m.kind = 'tech')",
    '{last_kind}'      => "(SELECT m.kind FROM messages m WHERE m.ticket_id = t.id AND m.kind IN ('client','tech') ORDER BY m.id DESC LIMIT 1)",
    '{last_message}'   => "(SELECT MAX(m.created_at) FROM messages m WHERE m.ticket_id = t.id AND m.kind <> 'system')",
];
const QUEUE_FACT_COLUMNS = [
    '{first_response}' => 't.first_response',
    '{last_kind}'      => 't.last_kind',
    '{last_message}'   => 't.last_message',
];

/**
 * What the address asks for, with anything unknown replaced by the default.
 *
 * @return array{view:string,client:int,priority:string,owner:string,sort:string,page:int}
 *         owner: '' anyone, 'me', 'none', or a user id; sort: '' means the view's own order.
 */
function queue_request(array $get): array
{
    $text = static fn(string $key): string => is_string($get[$key] ?? null) ? $get[$key] : '';
    $view = $text('view');
    if (!isset(QUEUE_VIEWS[$view])) {
        // Links from before the views existed filtered by status with ?f=.
        $view = match ($text('f')) {
            'waiting' => 'waiting',
            'resolved' => 'resolved',
            'all', 'open', 'in_progress' => 'open',
            default => 'needs',
        };
    }
    $number = static fn(string $value): int => ctype_digit($value) && strlen($value) <= 9 ? (int)$value : 0;
    $owner = $text('owner');
    if ($owner !== 'me' && $owner !== 'none') $owner = $number($owner) > 0 ? (string)$number($owner) : '';
    $sort = $text('sort');
    return [
        'view' => $view,
        'client' => $number($text('client')),
        'priority' => array_key_exists($text('priority'), PRIORITY_META) ? $text('priority') : '',
        'owner' => $owner,
        'sort' => isset(QUEUE_SORTS[$sort]) && $sort !== queue_default_sort($view) ? $sort : '',
        'page' => max(1, $number($text('page'))),
    ];
}

/** Open work is ordered by what is waiting on us; resolved work by when it was resolved. */
function queue_default_sort(string $view): string
{
    return $view === 'resolved' ? 'updated' : 'reply';
}

function queue_filtered(array $state): bool
{
    return $state['client'] > 0 || $state['priority'] !== '' || $state['owner'] !== '';
}

/** The address for a queue state; defaults are left out, so the plain queue is "/". */
function queue_url(array $state, array $change = []): string
{
    $next = array_replace($state, $change);
    $query = [];
    if ($next['view'] !== 'needs') $query['view'] = $next['view'];
    if ($next['client'] > 0) $query['client'] = $next['client'];
    if ($next['priority'] !== '') $query['priority'] = $next['priority'];
    if ($next['owner'] !== '') $query['owner'] = $next['owner'];
    if ($next['sort'] !== '' && $next['sort'] !== queue_default_sort($next['view'])) $query['sort'] = $next['sort'];
    if ($next['page'] > 1) $query['page'] = $next['page'];
    return '/' . ($query === [] ? '' : '?' . http_build_query($query));
}

/**
 * Which tickets a view holds, as SQL over the alias t with the {facts} left in for the caller.
 * $me is written in as a whole number.
 *
 * Needs attention: open work nobody owns, plus my own work that is waiting on us: unanswered and
 * overdue or due within two hours, or the customer wrote last. A ticket I parked as Waiting is
 * not waiting on me.
 */
function queue_view_sql(string $view, int $me): string
{
    $dueSoon = '({first_response} IS NULL AND t.sla_due_at < DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . QUEUE_DUE_SOON_SECONDS . ' SECOND))';
    return match ($view) {
        'needs' => "t.status <> 'resolved' AND (t.assignee_id IS NULL OR (t.assignee_id = $me AND t.status <> 'waiting'"
            . " AND ($dueSoon OR {last_kind} <=> 'client')))",
        'mine' => "t.status <> 'resolved' AND t.assignee_id = $me",
        'unassigned' => "t.status <> 'resolved' AND t.assignee_id IS NULL",
        'waiting' => "t.status = 'waiting'",
        'open' => "t.status <> 'resolved'",
        'resolved' => "t.status = 'resolved'",
        default => throw new InvalidArgumentException('Unknown queue view'),
    };
}

/** The order of a view, as SQL over the alias t with the {facts} left in. */
function queue_order_sql(string $view, string $sort): string
{
    if ($view === 'resolved' && $sort === 'updated') return 'COALESCE(t.resolved_at, t.created_at) DESC, t.id DESC';
    return match ($sort) {
        'updated' => 'COALESCE({last_message}, t.created_at) DESC, t.id DESC',
        'priority' => "FIELD(t.priority, 'urgent','high','normal','low'), t.sla_due_at ASC, t.id ASC",
        'newest' => 't.created_at DESC, t.id DESC',
        // Unanswered first, the soonest due on top; then tickets where the customer wrote last;
        // then the rest, the longest quiet first.
        'reply' => "({first_response} IS NULL AND t.status NOT IN ('waiting','resolved')) DESC,"
            . ' CASE WHEN {first_response} IS NULL THEN t.sla_due_at END ASC,'
            . " ({last_kind} <=> 'client') DESC, COALESCE({last_message}, t.created_at) ASC, t.id ASC",
        default => throw new InvalidArgumentException('Unknown queue order'),
    };
}

/**
 * The client, priority and owner filters, as " AND ..." over the alias t plus their values.
 *
 * @return array{0:string,1:list<int|string>}
 */
function queue_filter_sql(array $state, int $me): array
{
    $sql = '';
    $params = [];
    if ($state['client'] > 0) { $sql .= ' AND t.client_id = ?'; $params[] = $state['client']; }
    if ($state['priority'] !== '') { $sql .= ' AND t.priority = ?'; $params[] = $state['priority']; }
    if ($state['owner'] === 'none') {
        $sql .= ' AND t.assignee_id IS NULL';
    } elseif ($state['owner'] !== '') {
        $sql .= ' AND t.assignee_id = ?';
        $params[] = $state['owner'] === 'me' ? $me : (int)$state['owner'];
    }
    return [$sql, $params];
}

/**
 * How many tickets each view holds under the current filters, plus two numbers for the summary.
 * One pass over the tenant's open tickets, one count of its resolved ones.
 *
 * @return array{needs:int,mine:int,unassigned:int,waiting:int,open:int,resolved:int,unanswered:int,overdue:int}
 */
function queue_counts(PDO $pdo, int $tenantId, int $me, array $state): array
{
    [$filterSql, $filterParams] = queue_filter_sql($state, $me);
    $sum = static fn(string $view): string =>
        'COALESCE(SUM(' . strtr(queue_view_sql($view, $me), QUEUE_FACT_COLUMNS) . '), 0) AS `' . $view . '`';
    $open = $pdo->prepare(
        'SELECT COUNT(*) AS `open`, ' . $sum('needs') . ', ' . $sum('mine') . ', ' . $sum('unassigned') . ', ' . $sum('waiting') . ',
                COALESCE(SUM(t.first_response IS NULL), 0) AS unanswered,
                COALESCE(SUM(t.first_response IS NULL AND t.sla_due_at <= UTC_TIMESTAMP()), 0) AS overdue
           FROM (SELECT t.status, t.assignee_id, t.sla_due_at,
                        ' . QUEUE_FACTS['{first_response}'] . ' AS first_response,
                        ' . QUEUE_FACTS['{last_kind}'] . " AS last_kind
                   FROM tickets t
                  WHERE t.tenant_id = ? AND t.status <> 'resolved'" . $filterSql . ') t'
    );
    $open->execute(array_merge([$tenantId], $filterParams));
    $counts = array_map('intval', $open->fetch());
    $resolved = $pdo->prepare("SELECT COUNT(*) FROM tickets t WHERE t.tenant_id = ? AND t.status = 'resolved'" . $filterSql);
    $resolved->execute(array_merge([$tenantId], $filterParams));
    $counts['resolved'] = (int)$resolved->fetchColumn();
    return $counts;
}

/**
 * One page of a view: the ids in order first (cheap for any view), then those rows in full with
 * what a row shows (client, owner, who asked, whether the customer wrote last, last activity).
 *
 * @return list<array<string,mixed>>
 */
function queue_page(PDO $pdo, int $tenantId, int $me, array $state): array
{
    [$filterSql, $filterParams] = queue_filter_sql($state, $me);
    $sort = $state['sort'] !== '' ? $state['sort'] : queue_default_sort($state['view']);
    $offset = (max(1, (int)$state['page']) - 1) * QUEUE_PAGE_SIZE;
    $find = $pdo->prepare(
        'SELECT t.id FROM tickets t
          WHERE t.tenant_id = ? AND (' . strtr(queue_view_sql($state['view'], $me), QUEUE_FACTS) . ')' . $filterSql . '
          ORDER BY ' . strtr(queue_order_sql($state['view'], $sort), QUEUE_FACTS) . '
          LIMIT ' . QUEUE_PAGE_SIZE . ' OFFSET ' . $offset
    );
    $find->execute(array_merge([$tenantId], $filterParams));
    $ids = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN));
    if ($ids === []) return [];

    $read = $pdo->prepare(
        'SELECT t.*,
                ' . QUEUE_FACTS['{first_response}'] . ' AS first_response_at,
                EXISTS (SELECT 1 FROM tickets merged_source WHERE merged_source.tenant_id = t.tenant_id AND merged_source.merged_into_id = t.id) AS has_merged_sources,
                ' . QUEUE_FACTS['{last_kind}'] . ' AS last_kind,
                ' . QUEUE_FACTS['{last_message}'] . " AS last_message_at,
                (SELECT m.author_name FROM messages m WHERE m.ticket_id = t.id AND m.kind = 'client' ORDER BY m.id ASC LIMIT 1) AS opener_name,
                c.name AS client_name, k.name AS contact_name,
                u.full_name AS assignee_name, u.initials AS assignee_initials, u.color AS assignee_color
           FROM tickets t
           JOIN clients c ON c.id = t.client_id
           LEFT JOIN contacts k ON k.id = t.contact_id AND k.client_id = t.client_id
           LEFT JOIN users u ON u.id = t.assignee_id
          WHERE t.tenant_id = ? AND t.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')'
    );
    $read->execute(array_merge([$tenantId], $ids));
    $byId = [];
    foreach ($read->fetchAll() as $row) $byId[(int)$row['id']] = $row;
    $rows = [];
    foreach ($ids as $id) if (isset($byId[$id])) $rows[] = $byId[$id];
    return $rows;
}

/** The sentence under the page title. */
function queue_summary(array $counts): string
{
    $open = (int)$counts['open'];
    if ($open === 0) return 'No open tickets.';
    $head = $open === 1 ? '1 open ticket.' : number_format($open) . ' open tickets.';
    $unanswered = (int)$counts['unanswered'];
    $overdue = (int)$counts['overdue'];
    if ($unanswered === 0) return $head . ($open === 1 ? ' It has had a first reply.' : ' Every one has had a first reply.');
    $tail = $unanswered === 1 ? ' 1 is waiting for a first reply' : ' ' . number_format($unanswered) . ' are waiting for a first reply';
    if ($overdue === 0) return $head . $tail . '.';
    if ($unanswered === 1) return $head . $tail . ', and it is overdue.';
    return $head . $tail . ', ' . number_format($overdue) . ' of them overdue.';
}

/** The six views as a segmented filter. Keys 1 to 6 follow their order. */
function queue_tabs(array $state, array $counts): string
{
    $out = '<nav class="filters" aria-label="Queue views">';
    $key = 0;
    foreach (QUEUE_VIEWS as $view => $label) {
        $key++;
        $on = $state['view'] === $view;
        $out .= '<a href="' . h(queue_url($state, ['view' => $view, 'page' => 1])) . '" class="filter-pill' . ($on ? ' pill-on' : '') . '"'
            . ($on ? ' aria-current="page"' : '') . ' data-key="' . $key . '">' . h($label)
            // A long history's size helps nobody decide what to do next, so Resolved shows no number.
            . ($view === 'resolved' ? '' : '<span class="pill-count">' . number_format((int)($counts[$view] ?? 0)) . '</span>')
            . '<kbd class="kbd pill-kbd" aria-hidden="true">' . $key . '</kbd></a>';
    }
    return $out . '</nav>';
}

/**
 * Client, priority, owner and order as one small GET form. Each choice is a native select (so it
 * is searchable by typing and right on a phone); the script submits on change and hides Apply.
 *
 * @param list<array{id:int|string,name:string}> $clients
 * @param list<array{id:int|string,full_name:string}> $team
 */
function queue_filters(array $state, array $clients, array $team, int $me): string
{
    $option = static fn(string $value, string $label, bool $selected): string =>
        '<option value="' . h($value) . '"' . ($selected ? ' selected' : '') . '>' . h($label) . '</option>';
    // data-default is the value that changes nothing; the script leaves such a choice out of the address.
    // The label sits beside its select, not around it, so the control is named by that word alone.
    $select = static fn(string $name, string $label, string $options, string $default = ''): string =>
        '<span class="filter-select filter-' . h($name) . '"><label class="filter-select-k" for="queue-' . h($name) . '">' . h($label) . '</label>'
        . '<select name="' . h($name) . '" id="queue-' . h($name) . '" data-default="' . h($default) . '">' . $options . '</select></span>';

    $clientOptions = $option('', 'All', $state['client'] === 0);
    foreach ($clients as $client) $clientOptions .= $option((string)(int)$client['id'], (string)$client['name'], (int)$client['id'] === $state['client']);
    $priorityOptions = $option('', 'Any', $state['priority'] === '');
    foreach (array_reverse(PRIORITY_META, true) as $value => [$label]) $priorityOptions .= $option($value, $label, $state['priority'] === $value);
    $ownerOptions = $option('', 'Anyone', $state['owner'] === '') . $option('me', 'Me', $state['owner'] === 'me')
        . $option('none', 'Unassigned', $state['owner'] === 'none');
    foreach ($team as $member) {
        if ((int)$member['id'] === $me) continue;
        $ownerOptions .= $option((string)(int)$member['id'], (string)$member['full_name'], $state['owner'] === (string)(int)$member['id']);
    }
    $sort = $state['sort'] !== '' ? $state['sort'] : queue_default_sort($state['view']);
    $sortOptions = '';
    foreach (QUEUE_SORTS as $value => $label) $sortOptions .= $option($value, $label, $sort === $value);

    return '<form class="queue-filters" id="queue-filters" method="get" action="/">'
        . ($state['view'] === 'needs' ? '' : '<input type="hidden" name="view" value="' . h($state['view']) . '">')
        . $select('client', 'Client', $clientOptions)
        . $select('priority', 'Priority', $priorityOptions)
        . $select('owner', 'Owner', $ownerOptions)
        . $select('sort', 'Sort', $sortOptions, queue_default_sort($state['view']))
        . '<button type="submit" class="btn-chip" id="queue-apply">Apply</button>'
        . (queue_filtered($state)
            ? '<a class="btn-link" href="' . h(queue_url($state, ['client' => 0, 'priority' => '', 'owner' => '', 'page' => 1])) . '">Clear filters</a>'
            : '')
        . '</form>';
}

/** How many pages a view has; never fewer than one. */
function queue_pages(int $total): int
{
    return max(1, (int)ceil($total / QUEUE_PAGE_SIZE));
}

/** The count line under the list, with Previous and Next once there is more than one page. */
function queue_pager(array $state, int $total): string
{
    if ($total <= 0) return '';
    $pages = queue_pages($total);
    $page = min(max(1, (int)$state['page']), $pages);
    if ($pages === 1) {
        return '<nav class="pager" aria-label="Queue pages"><span class="pager-count">'
            . ($total === 1 ? '1 ticket' : number_format($total) . ' tickets') . '</span></nav>';
    }
    $from = ($page - 1) * QUEUE_PAGE_SIZE + 1;
    $to = min($total, $page * QUEUE_PAGE_SIZE);
    $step = static fn(string $label, string $rel, ?int $target): string => $target === null
        ? '<span class="btn-chip pager-off" aria-disabled="true">' . $label . '</span>'
        : '<a class="btn-chip" rel="' . $rel . '" href="' . h(queue_url($state, ['page' => $target])) . '">' . $label . '</a>';
    return '<nav class="pager" aria-label="Queue pages"><span class="pager-count">Showing ' . number_format($from) . ' to '
        . number_format($to) . ' of ' . number_format($total) . '</span>'
        . $step('Previous', 'prev', $page > 1 ? $page - 1 : null)
        . $step('Next', 'next', $page < $pages ? $page + 1 : null) . '</nav>';
}

/**
 * What an empty view says: one sentence and one way on.
 *
 * @return array{0:string,1:string,2:string} sentence, button label, button address
 */
function queue_empty(array $state): array
{
    if (queue_filtered($state)) {
        return ['No tickets match these filters.', 'Clear filters',
            queue_url($state, ['client' => 0, 'priority' => '', 'owner' => '', 'page' => 1])];
    }
    $everything = ['Open the full queue', queue_url($state, ['view' => 'open', 'page' => 1])];
    return match ($state['view']) {
        'needs' => ['Nothing needs you right now.', ...$everything],
        'mine' => ['No open tickets are assigned to you.', 'See unassigned tickets', queue_url($state, ['view' => 'unassigned', 'page' => 1])],
        'unassigned' => ['Every open ticket has an owner.', ...$everything],
        'waiting' => ['No tickets are waiting on a client.', ...$everything],
        'resolved' => ['No resolved tickets yet.', ...$everything],
        default => ['No open tickets.', 'New ticket', '/ticket_new.php'],
    };
}
