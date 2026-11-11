<?php
/**
 * The queue: its views, filters, order and paging, each defined once.
 *
 * A view answers "what needs me": Needs attention (the default), Mine, Unassigned, Waiting on
 * client, All open, Resolved. Filters narrow any view by client, priority and owner. Everything is
 * an ordinary link in the address (?view=&client=&priority=&owner=&sort=&page=), so Back and
 * bookmarks work. Links from before the views existed (?f=all|open|in_progress|waiting|resolved)
 * keep their meaning: every ticket, or every ticket with that status, resolved included.
 *
 * Every query is scoped to the signed-in tenant and takes its values as bound parameters; the only
 * things written into SQL text are fixed fragments from this file and whole numbers.
 *
 * Needs render.php (h(), STATUS_META, PRIORITY_META) and db().
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
    'risk'       => 'At risk',
];

const QUEUE_SORTS = [
    'reply'    => 'First response due',
    'updated'  => 'Last updated',
    'priority' => 'Priority',
    'newest'   => 'Newest',
];

/**
 * Facts about one ticket that the views and orders use, as SQL over the alias t. The list reads
 * them straight from messages; the counts read them once per ticket into columns of the same name
 * (QUEUE_FACT_COLUMNS), so a view means the same thing in the list and in its count.
 *
 * {westy} is the rule lib/westy_workflow.php shows as the W avatar: an in-progress ticket no person
 * owns, with a working Westy workflow for the same tenant and client. Such a ticket is owned by
 * the assistant, not unassigned. Where the workflow table does not exist yet, nothing is.
 *
 * @return array<string,string>
 */
function queue_facts(PDO $pdo): array
{
    static $westy = null;
    if ($westy === null) {
        $westy = "(t.status = 'in_progress' AND t.assignee_id IS NULL AND EXISTS (SELECT 1 FROM westy_workflows w"
            . " WHERE w.ticket_id = t.id AND w.tenant_id = t.tenant_id AND w.client_id = t.client_id AND w.state = 'working'))";
        try {
            $pdo->query('SELECT 1 FROM westy_workflows LIMIT 1');
        } catch (PDOException $error) {
            $missing = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && (int)($error->errorInfo[1] ?? 0) === 1146)
                || ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && str_contains($error->getMessage(), 'no such table:'));
            if (!$missing) throw $error;
            $westy = '(0 = 1)';
        }
    }
    return [
        '{first_response}' => "(SELECT MIN(m.created_at) FROM messages m WHERE m.ticket_id = t.id AND m.kind = 'tech')",
        '{last_kind}'      => "(SELECT m.kind FROM messages m WHERE m.ticket_id = t.id AND m.kind IN ('client','tech') ORDER BY m.id DESC LIMIT 1)",
        '{last_message}'   => "(SELECT MAX(m.created_at) FROM messages m WHERE m.ticket_id = t.id AND m.kind <> 'system')",
        '{westy}'          => $westy,
    ];
}
const QUEUE_FACT_COLUMNS = [
    '{first_response}' => 't.first_response',
    '{last_kind}'      => 't.last_kind',
    '{last_message}'   => 't.last_message',
    '{westy}'          => 't.westy',
];

/**
 * What the address asks for, with anything unknown replaced by the default.
 *
 * @return array{view:string,status:string,client:int,priority:string,owner:string,sort:string,page:int,after:int,before:int}
 *         view: one of QUEUE_VIEWS, or 'all' for an old ?f= link, whose status is then 'all' or one
 *         status; owner: '' anyone, 'me', 'none', or a user id; sort: '' means the view's own order;
 *         after / before: the ticket the Next or Previous link was pressed beside (queue_page()).
 */
function queue_request(array $get): array
{
    $text = static fn(string $key): string => is_string($get[$key] ?? null) ? $get[$key] : '';
    $view = $text('view');
    $status = '';
    if (!isset(QUEUE_VIEWS[$view])) {
        // A link from before the views existed keeps its meaning: every ticket, or one status.
        $old = $text('f');
        $status = $old === 'all' || array_key_exists($old, STATUS_META) ? $old : '';
        $view = $status === '' ? 'needs' : 'all';
    }
    $number = static fn(string $value): int => ctype_digit($value) && strlen($value) <= 9 ? (int)$value : 0;
    $owner = $text('owner');
    if ($owner !== 'me' && $owner !== 'none') $owner = $number($owner) > 0 ? (string)$number($owner) : '';
    $sort = $text('sort');
    return [
        'view' => $view,
        'status' => $status,
        'client' => $number($text('client')),
        'priority' => array_key_exists($text('priority'), PRIORITY_META) ? $text('priority') : '',
        'owner' => $owner,
        'sort' => isset(QUEUE_SORTS[$sort]) && $sort !== queue_default_sort($view) ? $sort : '',
        'page' => max(1, $number($text('page'))),
        'after' => $number($text('after')),
        'before' => $number($text('before')),
    ];
}

/**
 * Open work is ordered by what is waiting on us; resolved work by when it was resolved; an old
 * "everything" link as it always was (resolved last, then priority, then due time).
 */
function queue_default_sort(string $view): string
{
    return match ($view) { 'resolved' => 'updated', 'all' => 'priority', default => 'reply' };
}

function queue_filtered(array $state): bool
{
    return $state['client'] > 0 || $state['priority'] !== '' || $state['owner'] !== '';
}

/** The name of the view on screen, for sentences ("every ticket in All open"). */
function queue_view_label(array $state): string
{
    if ($state['view'] !== 'all') return QUEUE_VIEWS[$state['view']];
    return $state['status'] === 'all' ? 'Every ticket' : 'Tickets with the status ' . STATUS_META[$state['status']][0];
}

/** What an old ?f= link is showing, in one sentence; '' for the views. */
function queue_legacy_note(array $state): string
{
    if ($state['view'] !== 'all') return '';
    return queue_view_label($state) . ($state['status'] === 'all' ? ', resolved included' : '')
        . ': this is how an older link listed the queue.';
}

/**
 * The address for a queue state; defaults are left out, so the plain queue is "/". The paging
 * anchors (after, before) are never carried over: only the link that sets one has one.
 */
function queue_url(array $state, array $change = []): string
{
    $next = array_replace($state, ['after' => 0, 'before' => 0], $change);
    $query = [];
    if ($next['view'] === 'all') $query['f'] = $next['status'] !== '' ? $next['status'] : 'all';
    elseif ($next['view'] !== 'needs') $query['view'] = $next['view'];
    if ($next['client'] > 0) $query['client'] = $next['client'];
    if ($next['priority'] !== '') $query['priority'] = $next['priority'];
    if ($next['owner'] !== '') $query['owner'] = $next['owner'];
    if ($next['sort'] !== '' && $next['sort'] !== queue_default_sort($next['view'])) $query['sort'] = $next['sort'];
    if ($next['page'] > 1) $query['page'] = $next['page'];
    if ($next['after'] > 0) $query['after'] = $next['after'];
    if ($next['before'] > 0) $query['before'] = $next['before'];
    return '/' . ($query === [] ? '' : '?' . http_build_query($query));
}

/**
 * Which tickets a view holds, as SQL over the alias t with the {facts} left in for the caller.
 * $me is written in as a whole number.
 *
 * Needs attention is every open ticket where the next step is ours:
 *   - unanswered and overdue or due within two hours, whoever owns it (a teammate's late ticket
 *     is everybody's to see);
 *   - owned by nobody: no person, and not the assistant while it is working;
 *   - mine, not parked as Waiting, and the customer wrote last.
 *
 * Late is late whoever owns it, the assistant included: a ticket Westy is working stays out of
 * "owned by nobody" but is in Needs attention once it is overdue or due within two hours. That is
 * decided (review of #311, 2026-11-09), so the first rule must not gain an "AND NOT {westy}".
 */
function queue_view_sql(string $view, int $me): string
{
    $dueSoon = '({first_response} IS NULL AND t.sla_due_at < DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . QUEUE_DUE_SOON_SECONDS . ' SECOND))';
    $unowned = '(t.assignee_id IS NULL AND NOT {westy})';
    return match ($view) {
        'needs' => "t.status <> 'resolved' AND ($dueSoon OR $unowned"
            . " OR (t.assignee_id = $me AND t.status <> 'waiting' AND {last_kind} <=> 'client'))",
        'mine' => "t.status <> 'resolved' AND t.assignee_id = $me",
        'unassigned' => "t.status <> 'resolved' AND $unowned",
        'waiting' => "t.status = 'waiting'",
        'open' => "t.status <> 'resolved'",
        'resolved' => "t.status = 'resolved'",
        'risk' => queue_risk_sql(),
        'all' => '1 = 1',
        default => throw new InvalidArgumentException('Unknown queue view'),
    };
}

/**
 * The Service goals list, its rail count and its queue destination share this rule.
 * As in sla_info(), a reply outside the ticket's lifetime is not a first response;
 * merged sources and survivors cannot be attributed. A missing target is not a known deadline.
 * The outer caller always supplies the signed-in tenant boundary over alias t.
 */
function queue_risk_sql(): string
{
    $reply = "(SELECT MIN(m.created_at) FROM messages m WHERE m.ticket_id = t.id AND m.kind = 'tech')";
    return "t.status <> 'resolved' AND t.sla_due_at IS NOT NULL"
        . ' AND t.sla_due_at < DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . QUEUE_DUE_SOON_SECONDS . ' SECOND)'
        . " AND ($reply IS NULL OR $reply < t.created_at OR $reply > UTC_TIMESTAMP())"
        . ' AND COALESCE(t.merged_into_id, 0) = 0'
        . ' AND NOT EXISTS (SELECT 1 FROM tickets merged_source'
        . ' WHERE merged_source.tenant_id = t.tenant_id AND merged_source.merged_into_id = t.id)';
}

function queue_risk_count(PDO $pdo, int $tenantId): int
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM tickets t WHERE t.tenant_id = ? AND (' . queue_risk_sql() . ')');
    $q->execute([$tenantId]);
    return (int)$q->fetchColumn();
}

/** The order of a view, as SQL over the alias t with the {facts} left in. */
function queue_order_sql(string $view, string $sort): string
{
    if ($view === 'risk' && $sort === 'reply') return 't.sla_due_at ASC, t.id ASC';
    if ($view === 'resolved' && $sort === 'updated') return 'COALESCE(t.resolved_at, t.created_at) DESC, t.id DESC';
    $order = match ($sort) {
        'updated' => 'COALESCE({last_message}, t.created_at) DESC, t.id DESC',
        'priority' => "FIELD(t.priority, 'urgent','high','normal','low'), t.sla_due_at ASC, t.id ASC",
        'newest' => 't.created_at DESC, t.id DESC',
        // Unanswered first, the soonest due on top; then tickets where the customer wrote last;
        // then the rest, the longest quiet first.
        'reply' => "({first_response} IS NULL AND t.status <> 'resolved') DESC,"
            . ' CASE WHEN {first_response} IS NULL THEN t.sla_due_at END ASC,'
            . " ({last_kind} <=> 'client') DESC, COALESCE({last_message}, t.created_at) ASC, t.id ASC",
        default => throw new InvalidArgumentException('Unknown queue order'),
    };
    // An old "everything" link lists resolved tickets last, as it always did.
    return $view === 'all' ? "(t.status = 'resolved') ASC, " . $order : $order;
}

/**
 * The client, priority and owner filters, as " AND ..." over the alias t (with {westy} left in)
 * plus their values. "Unassigned" means owned by nobody: no person, and not the working assistant.
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
        $sql .= ' AND t.assignee_id IS NULL AND NOT {westy}';
    } elseif ($state['owner'] !== '') {
        $sql .= ' AND t.assignee_id = ?';
        $params[] = $state['owner'] === 'me' ? $me : (int)$state['owner'];
    }
    return [$sql, $params];
}

/** An old link's one status, as " AND ..." plus its value; nothing for "all" or a view. */
function queue_status_sql(array $state): array
{
    return $state['view'] === 'all' && $state['status'] !== '' && $state['status'] !== 'all'
        ? [' AND t.status = ?', [$state['status']]] : ['', []];
}

/**
 * How many tickets each view holds under the current filters, plus numbers for the summary and
 * for saying what the page does not show. One pass over the tenant's open tickets and a count or
 * two of the rest.
 *
 * @return array<string,int> needs, mine, unassigned, waiting, open, resolved, unanswered, overdue,
 *         legacy (what an old ?f= link holds), everything (every ticket of the tenant, unfiltered)
 */
function queue_counts(PDO $pdo, int $tenantId, int $me, array $state): array
{
    $facts = queue_facts($pdo);
    [$filterSql, $filterParams] = queue_filter_sql($state, $me);
    $filterSql = strtr($filterSql, $facts);
    $sum = static fn(string $view): string =>
        'COALESCE(SUM(' . strtr(queue_view_sql($view, $me), QUEUE_FACT_COLUMNS) . '), 0) AS `' . $view . '`';
    $open = $pdo->prepare(
        'SELECT COUNT(*) AS `open`, ' . $sum('needs') . ', ' . $sum('mine') . ', ' . $sum('unassigned') . ', ' . $sum('waiting') . ',
                COALESCE(SUM(t.first_response IS NULL), 0) AS unanswered,
                COALESCE(SUM(t.first_response IS NULL AND t.sla_due_at <= UTC_TIMESTAMP()), 0) AS overdue
           FROM (SELECT t.status, t.assignee_id, t.sla_due_at,
                        ' . $facts['{first_response}'] . ' AS first_response,
                        ' . $facts['{last_kind}'] . ' AS last_kind,
                        ' . $facts['{westy}'] . " AS westy
                   FROM tickets t
                  WHERE t.tenant_id = ? AND t.status <> 'resolved'" . $filterSql . ') t'
    );
    $open->execute(array_merge([$tenantId], $filterParams));
    $counts = array_map('intval', $open->fetch());
    $count = static function (string $where, array $params) use ($pdo, $tenantId): int {
        $q = $pdo->prepare('SELECT COUNT(*) FROM tickets t WHERE t.tenant_id = ?' . $where);
        $q->execute(array_merge([$tenantId], $params));
        return (int)$q->fetchColumn();
    };
    $counts['resolved'] = $count(" AND t.status = 'resolved'" . $filterSql, $filterParams);
    $counts['risk'] = $count(' AND (' . queue_risk_sql() . ')' . $filterSql, $filterParams);
    [$statusSql, $statusParams] = queue_status_sql($state);
    $counts['legacy'] = $statusSql === '' ? $counts['open'] + $counts['resolved']
        : $count($filterSql . $statusSql, array_merge($filterParams, $statusParams));
    $counts['everything'] = queue_filtered($state) ? $count('', []) : $counts['open'] + $counts['resolved'];
    return $counts;
}

/**
 * One page of a view: the ids in order first (cheap for any view), then those rows in full with
 * what a row shows (client, owner, who asked, whether the customer wrote last, last activity).
 *
 * Pages are cut by position, so a ticket that arrives or changes while somebody pages can shift
 * the rows. The Next link carries the last ticket of its page (after) and the Previous link the
 * first (before); this reads one row across that edge and reports "changed" when the neighbour
 * is no longer the one the link was pressed beside, so the page can say so.
 *
 * @return array{rows:list<array<string,mixed>>,changed:bool}
 */
function queue_page(PDO $pdo, int $tenantId, int $me, array $state): array
{
    $facts = queue_facts($pdo);
    [$filterSql, $filterParams] = queue_filter_sql($state, $me);
    [$statusSql, $statusParams] = queue_status_sql($state);
    $sort = $state['sort'] !== '' ? $state['sort'] : queue_default_sort($state['view']);
    $offset = (max(1, (int)$state['page']) - 1) * QUEUE_PAGE_SIZE;
    $after = $offset > 0 ? (int)$state['after'] : 0;
    $before = $after === 0 ? (int)$state['before'] : 0;
    $find = $pdo->prepare(
        'SELECT t.id FROM tickets t
          WHERE t.tenant_id = ? AND (' . strtr(queue_view_sql($state['view'], $me), $facts) . ')' . strtr($filterSql, $facts) . $statusSql . '
          ORDER BY ' . strtr(queue_order_sql($state['view'], $sort), $facts) . '
          LIMIT ' . (QUEUE_PAGE_SIZE + ($after > 0 || $before > 0 ? 1 : 0)) . ' OFFSET ' . ($after > 0 ? $offset - 1 : $offset)
    );
    $find->execute(array_merge([$tenantId], $filterParams, $statusParams));
    $ids = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN));
    $changed = false;
    if ($after > 0) {
        $changed = ($ids[0] ?? 0) !== $after;
        array_shift($ids);
    } elseif ($before > 0) {
        $changed = ($ids[QUEUE_PAGE_SIZE] ?? 0) !== $before;
        $ids = array_slice($ids, 0, QUEUE_PAGE_SIZE);
    }
    if ($ids === []) return ['rows' => [], 'changed' => $changed];

    $read = $pdo->prepare(
        'SELECT t.*,
                ' . $facts['{first_response}'] . ' AS first_response_at,
                EXISTS (SELECT 1 FROM tickets merged_source WHERE merged_source.tenant_id = t.tenant_id AND merged_source.merged_into_id = t.id) AS has_merged_sources,
                ' . $facts['{last_kind}'] . ' AS last_kind,
                ' . $facts['{last_message}'] . " AS last_message_at,
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
    return ['rows' => $rows, 'changed' => $changed];
}

/** A view longer than this shows no "2 of 6" on a ticket: the place is not worth that read. */
const QUEUE_PLACE_LIMIT = 2000;

/**
 * What a row's link adds so the ticket page knows which list it was opened from:
 * "&in=open&client=3". The page is left out; the ticket finds its own place.
 */
function queue_ticket_query(array $state): string
{
    $query = ['in' => $state['view']];
    if ($state['view'] === 'all') $query['f'] = $state['status'] !== '' ? $state['status'] : 'all';
    if ($state['client'] > 0) $query['client'] = $state['client'];
    if ($state['priority'] !== '') $query['priority'] = $state['priority'];
    if ($state['owner'] !== '') $query['owner'] = $state['owner'];
    if ($state['sort'] !== '' && $state['sort'] !== queue_default_sort($state['view'])) $query['sort'] = $state['sort'];
    return '&' . http_build_query($query);
}

/**
 * The list a ticket link names, or null when it names none (a search result, a link in an email,
 * an old bookmark) or names one that does not exist.
 */
function queue_ticket_state(array $get): ?array
{
    $in = is_string($get['in'] ?? null) ? $get['in'] : '';
    if ($in === 'all') {
        $ask = $get;
        unset($ask['view']);
    } elseif (isset(QUEUE_VIEWS[$in])) {
        $ask = array_replace($get, ['view' => $in]);
        unset($ask['f']);
    } else {
        return null;
    }
    $state = queue_request($ask);
    if (($in === 'all') !== ($state['view'] === 'all')) return null;
    return array_replace($state, ['page' => 1, 'after' => 0, 'before' => 0]);
}

/**
 * Where a ticket stands in the list it was opened from: "2 of 6", the tickets before and after it,
 * and the way back to the page of the list that holds it.
 *
 * The list is read again, in the same order and under the same view and filters as the queue shows
 * it (the same SQL fragments, the same tenant), so the place is true now, not when the queue was
 * drawn. A ticket that has since left the list (answered, resolved, given to someone else) has no
 * place any more: position is 0, and $was, the place it had, still says which tickets were beside it.
 *
 * @return array{label:string,query:string,back:string,position:int,total:int,at:int,prev:?string,next:?string}|null
 *         null when the list is too long to place a ticket in.
 */
function queue_ticket_place(PDO $pdo, int $tenantId, int $me, array $state, int $ticketId, int $was = 0): ?array
{
    $facts = queue_facts($pdo);
    [$filterSql, $filterParams] = queue_filter_sql($state, $me);
    [$statusSql, $statusParams] = queue_status_sql($state);
    $sort = $state['sort'] !== '' ? $state['sort'] : queue_default_sort($state['view']);
    $find = $pdo->prepare(
        'SELECT t.id FROM tickets t
          WHERE t.tenant_id = ? AND (' . strtr(queue_view_sql($state['view'], $me), $facts) . ')' . strtr($filterSql, $facts) . $statusSql . '
          ORDER BY ' . strtr(queue_order_sql($state['view'], $sort), $facts) . '
          LIMIT ' . (QUEUE_PLACE_LIMIT + 1)
    );
    $find->execute(array_merge([$tenantId], $filterParams, $statusParams));
    $ids = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN));
    if (count($ids) > QUEUE_PLACE_LIMIT) return null;

    $query = queue_ticket_query($state);
    $link = static fn(?int $id): ?string => $id === null ? null : '/ticket.php?id=' . $id . $query;
    $index = array_search($ticketId, $ids, true);
    if ($index === false) {
        // Its old place, kept inside what the list holds now: the ticket that followed it stands there.
        $at = max(1, min($was > 0 ? $was : 1, count($ids) + 1));
        $position = 0;
        $prev = $ids[$at - 2] ?? null;
        $next = $ids[$at - 1] ?? null;
    } else {
        $at = $position = $index + 1;
        $prev = $ids[$index - 1] ?? null;
        $next = $ids[$index + 1] ?? null;
    }
    return [
        'label' => queue_view_label($state),
        'query' => $query,
        'back' => queue_url($state, ['page' => max(1, (int)ceil(min($at, max(1, count($ids))) / QUEUE_PAGE_SIZE))]),
        'position' => $position,
        'total' => count($ids),
        'at' => $at,
        'prev' => $link($prev),
        'next' => $link($next),
    ];
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
        // Service goals links here; retain the six usual views and their existing number keys.
        if ($view === 'risk') {
            if ($state['view'] === 'risk') {
                $out .= '<a href="' . h(queue_url($state, ['page' => 1])) . '" class="filter-pill pill-on" aria-current="page">'
                    . 'At risk<span class="pill-count">' . number_format((int)($counts['risk'] ?? 0)) . '</span></a>';
            }
            continue;
        }
        $key++;
        $on = $state['view'] === $view;
        $out .= '<a href="' . h(queue_url($state, ['view' => $view, 'status' => '', 'page' => 1])) . '" class="filter-pill' . ($on ? ' pill-on' : '') . '"'
            . ($on ? ' aria-current="page"' : '') . ' data-key="' . $key . '">' . h($label)
            // Resolved shows no number on the control; the line under the list says how many there are.
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
    // The label sits beside its select, not around it, so the control is named by that word alone.
    // data-default is the value that changes nothing; the script leaves such a choice out of the address.
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

    $where = match (true) {
        $state['view'] === 'all' => '<input type="hidden" name="f" value="' . h($state['status']) . '">',
        $state['view'] !== 'needs' => '<input type="hidden" name="view" value="' . h($state['view']) . '">',
        default => '',
    };
    return '<form class="queue-filters" id="queue-filters" method="get" action="/">' . $where
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

/** How many tickets the view on screen holds, from queue_counts(). */
function queue_total(array $state, array $counts): int
{
    return (int)$counts[$state['view'] === 'all' ? 'legacy' : $state['view']];
}

/** How many pages a view has; never fewer than one. */
function queue_pages(int $total): int
{
    return max(1, (int)ceil($total / QUEUE_PAGE_SIZE));
}

/**
 * The count line under the list, with Previous and Next once there is more than one page. Each
 * link carries the ticket at its edge of this page ($rows), which is how the next page notices
 * that the list moved (queue_page()).
 *
 * @param list<array<string,mixed>> $rows the rows on this page
 */
function queue_pager(array $state, int $total, array $rows = []): string
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
    $first = $rows ? (int)$rows[0]['id'] : 0;
    $last = $rows ? (int)$rows[count($rows) - 1]['id'] : 0;
    $step = static fn(string $label, string $rel, ?array $target): string => $target === null
        ? '<span class="btn-chip pager-off" aria-disabled="true">' . $label . '</span>'
        : '<a class="btn-chip" rel="' . $rel . '" href="' . h(queue_url($state, $target)) . '">' . $label . '</a>';
    return '<nav class="pager" aria-label="Queue pages"><span class="pager-count">Showing ' . number_format($from) . ' to '
        . number_format($to) . ' of ' . number_format($total) . '</span>'
        . $step('Previous', 'prev', $page > 1 ? ['page' => $page - 1, 'before' => $first] : null)
        . $step('Next', 'next', $page < $pages ? ['page' => $page + 1, 'after' => $last] : null) . '</nav>';
}

/**
 * What this view does not show, with one-click links to it: a view is a part of the queue, and
 * the page says how much is elsewhere. Empty when nothing is hidden.
 */
function queue_elsewhere(array $state, array $counts): string
{
    $view = $state['view'];
    $link = static fn(array $to, string $text): string => '<a class="link" href="' . h(queue_url($state, $to + ['page' => 1])) . '">' . h($text) . '</a>';
    $parts = [];
    $open = (int)$counts['open'];
    $resolved = (int)$counts['resolved'];
    if ($view === 'all') {
        $others = $open + $resolved - (int)$counts['legacy'];
        if ($others > 0) $parts[] = $link(['status' => 'all'], number_format($others) . ' more with another status');
    } else {
        $moreOpen = $view === 'resolved' ? $open : ($view === 'open' ? 0 : $open - (int)$counts[$view]);
        if ($moreOpen > 0) $parts[] = $link(['view' => 'open', 'status' => ''], number_format($moreOpen) . ($view === 'resolved' ? ' open' : ' more in All open'));
        if ($view !== 'resolved' && $resolved > 0) $parts[] = $link(['view' => 'resolved', 'status' => ''], number_format($resolved) . ' resolved');
    }
    $filtered = (int)$counts['everything'] - $open - $resolved;
    if ($filtered > 0) {
        $parts[] = $link(['client' => 0, 'priority' => '', 'owner' => ''], number_format($filtered) . ' more hidden by the filters');
    }
    if ($parts === []) return '';
    return '<p class="queue-elsewhere">Not in this view: ' . implode(', ', $parts) . '.</p>';
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
    $everything = ['Open the full queue', queue_url($state, ['view' => 'open', 'status' => '', 'page' => 1])];
    return match ($state['view']) {
        'needs' => ['Nothing needs you right now.', ...$everything],
        'mine' => ['No open tickets are assigned to you.', 'See unassigned tickets', queue_url($state, ['view' => 'unassigned', 'page' => 1])],
        'unassigned' => ['Every open ticket has an owner.', ...$everything],
        'waiting' => ['No tickets are waiting on a client.', ...$everything],
        'resolved' => ['No resolved tickets yet.', ...$everything],
        'risk' => ['No unanswered tickets are overdue or due in under two hours.', ...$everything],
        'all' => $state['status'] === 'all' ? ['No tickets yet.', 'New ticket', '/ticket_new.php']
            : ['No tickets have the status ' . STATUS_META[$state['status']][0] . '.', ...$everything],
        default => ['No open tickets.', 'New ticket', '/ticket_new.php'],
    };
}
