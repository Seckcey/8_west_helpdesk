<?php
/**
 * The Reports page: one period for the whole page, number tiles that say how they changed, the
 * team as a table, the tickets open longest, and approved time by client.
 *
 * The sums here are plain arithmetic over rows public/reports.php has already read for the
 * signed-in tenant; the rest is rendering. Nothing here reads a table or decides who may see what.
 *
 * Needs h() (bootstrap.php), and ticket_minutes_label() and ticket_ago() (ticket_page.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/ticket_page.php';

/** The periods the page offers, in days. The middle one is where the page opens. */
const REPORT_PERIODS = [7, 30, 90];
const REPORT_DEFAULT_PERIOD = 30;
/** How many of the tickets open longest are listed. */
const REPORT_OPEN_LONGEST = 5;

/**
 * The period the address asks for. Anything that is not exactly one of the offered periods is the
 * default: the number goes into date arithmetic, so it is never taken as typed.
 *
 * @param array<string,mixed> $get
 */
function report_period(array $get): int
{
    $asked = $get['days'] ?? null;
    foreach (REPORT_PERIODS as $days) {
        if ($asked === (string)$days) return $days;
    }
    return REPORT_DEFAULT_PERIOD;
}

function report_url(int $days): string
{
    return '/reports.php' . ($days === REPORT_DEFAULT_PERIOD ? '' : '?days=' . $days);
}

/** "the last 30 days" and "the 30 days before": the two windows every change line compares. */
function report_period_words(int $days): string { return 'the last ' . $days . ' days'; }
function report_before_words(int $days): string { return 'the ' . $days . ' days before'; }

/** The period as a segmented filter. */
function report_period_picker(int $days): string
{
    $html = '<nav class="filters report-period" aria-label="Period">';
    foreach (REPORT_PERIODS as $period) {
        $html .= '<a href="' . h(report_url($period)) . '" class="filter-pill' . ($period === $days ? ' pill-on" aria-current="page"' : '"')
            . '>Last ' . $period . ' days</a>';
    }
    return $html . '</nav>';
}

/**
 * The middle value, so that one very late ticket does not speak for all of them. With an even
 * number of values it is the mean of the middle two, rounded to a whole minute.
 *
 * @param list<int> $minutes
 */
function report_median(array $minutes): ?int
{
    if ($minutes === []) return null;
    sort($minutes, SORT_NUMERIC);
    $n = count($minutes);
    $middle = intdiv($n, 2);
    return $n % 2 === 1 ? (int)$minutes[$middle] : (int)round(($minutes[$middle - 1] + $minutes[$middle]) / 2);
}

/**
 * First-reply facts for a set of tickets, all together and by current owner.
 *
 * The rule for "on time" is the one service_goal_response_attainment() counts by: a ticket merged
 * into another, or one others were merged into, is left out (a merge moves messages without keeping
 * who answered first); a ticket is decided once it has a first reply, was resolved, or its due time
 * has passed; it is on time when the first reply came at or before the due time.
 *
 * @param list<array<string,mixed>> $rows id, assignee_id, status, created_at, resolved_at, sla_due_at,
 *        merged_into_id, has_merged_sources, first_response_at (UTC, "Y-m-d H:i:s"), and westy_held
 *        (1 when no person owns the ticket and the assistant is working it or resolved it: report_westy_owned_sql())
 * @return array{all:array{replied:int,median:?int,decided:int,met:int,pct:?int},owners:array<int|string,array{replied:int,median:?int,decided:int,met:int,pct:?int}>}
 *         owners is keyed by the owner's user id, 'westy' for a ticket the assistant holds, '' for one nobody owns
 */
function report_outcomes(array $rows, int $now): array
{
    $nowUtc = gmdate('Y-m-d H:i:s', $now);
    $groups = [];
    foreach ($rows as $row) {
        if ($row['merged_into_id'] !== null || (int)$row['has_merged_sources'] !== 0) continue;
        $created = (string)$row['created_at'];
        $due = (string)$row['sla_due_at'];
        $first = $row['first_response_at'] !== null && (string)$row['first_response_at'] <= $nowUtc ? (string)$row['first_response_at'] : null;
        $resolved = $row['resolved_at'] !== null ? (string)$row['resolved_at'] : null;
        $decided = $first !== null
            || ((string)$row['status'] === 'resolved' && $resolved !== null && $resolved >= $created && $resolved <= $nowUtc)
            || $due <= $nowUtc;
        $owner = $row['assignee_id'] !== null ? (string)(int)$row['assignee_id'] : ((int)($row['westy_held'] ?? 0) === 1 ? 'westy' : '');
        foreach (['all', 'owner:' . $owner] as $key) {
            $groups[$key] ??= ['minutes' => [], 'decided' => 0, 'met' => 0];
            if ($first !== null) {
                $groups[$key]['minutes'][] = max(0, intdiv((int)strtotime($first . ' UTC') - (int)strtotime($created . ' UTC'), 60));
            }
            if ($decided) {
                $groups[$key]['decided']++;
                if ($first !== null && $first <= $due) $groups[$key]['met']++;
            }
        }
    }
    $sum = static fn(?array $group): array => [
        'replied' => count($group['minutes'] ?? []),
        'median' => report_median($group['minutes'] ?? []),
        'decided' => (int)($group['decided'] ?? 0),
        'met' => (int)($group['met'] ?? 0),
        'pct' => ($group['decided'] ?? 0) > 0 ? (int)round(100 * $group['met'] / $group['decided']) : null,
    ];
    $owners = [];
    foreach ($groups as $key => $group) {
        if (str_starts_with($key, 'owner:')) $owners[substr($key, 6)] = $sum($group);
    }
    return ['all' => $sum($groups['all'] ?? null), 'owners' => $owners];
}

/**
 * SQL over the alias t: is this ticket Westy's? It is while nobody owns it and the assistant holds it
 * ($held, the queue's own rule for an open ticket), and it stays Westy's once resolved if the
 * assistant's own workflow resolved it. A ticket a person owns is that person's. Where the workflow
 * table does not exist yet ($held is the queue's "never"), no ticket is.
 */
function report_westy_owned_sql(string $held): string
{
    if ($held === '(0 = 1)') return $held;
    return "(t.assignee_id IS NULL AND ($held OR (t.status = 'resolved' AND EXISTS (SELECT 1 FROM westy_workflows done"
        . " WHERE done.ticket_id = t.id AND done.tenant_id = t.tenant_id AND done.client_id = t.client_id AND done.state = 'resolved'))))";
}

/** How a count moved: "3 more than the 30 days before". */
function report_change_count(int $now, int $before, int $days): string
{
    $diff = $now - $before;
    if ($diff === 0) return 'Same as ' . report_before_words($days);
    return number_format(abs($diff)) . ($diff > 0 ? ' more than ' : ' fewer than ') . report_before_words($days);
}

/** How a wait moved: "8 min quicker than the 30 days before". Nothing to say with no wait now. */
function report_change_minutes(?int $now, ?int $before, int $days): string
{
    if ($now === null) return '';
    if ($before === null) return 'No first replies in ' . report_before_words($days);
    if ($now === $before) return 'Same as ' . report_before_words($days);
    return ticket_minutes_label(abs($now - $before)) . ($now < $before ? ' quicker than ' : ' slower than ') . report_before_words($days);
}

/** How a percentage moved, in points: "4 points up on the 30 days before". */
function report_change_points(?int $now, ?int $before, int $days): string
{
    if ($now === null) return '';
    if ($before === null) return 'Nothing was due in ' . report_before_words($days);
    if ($now === $before) return 'Same as ' . report_before_words($days);
    $diff = abs($now - $before);
    return $diff . ($diff === 1 ? ' point ' : ' points ') . ($now > $before ? 'up on ' : 'down on ') . report_before_words($days);
}

/**
 * The five tiles, one row. Each holds a number for the period, what it is counted over, and how it
 * moved against the period before.
 *
 * @param array{new:int,new_before:int,resolved:int,resolved_before:int,median:?int,replied:int,median_before:?int,
 *              on_time:array{n:int,met:int,pct:?int},on_time_before:array{n:int,met:int,pct:?int},
 *              surveys:int,rated:int,great:int} $facts
 */
function report_tiles(array $facts, int $days): string
{
    $tile = static function (string $label, string $value, array $lines, string $extra = '', bool $none = false): string {
        $html = '<div class="card stat"><div class="stat-k">' . $label . '</div><div class="stat-v' . ($none ? ' stat-none' : '') . '">' . $value . '</div>' . $extra;
        foreach ($lines as $line) {
            if ($line !== '') $html .= '<div class="stat-hint">' . h($line) . '</div>';
        }
        return $html . '</div>';
    };
    $tickets = static fn(int $n): string => $n === 1 ? '1 ticket' : number_format($n) . ' tickets';
    $onTime = $facts['on_time'];
    $html = '<div class="stats report-stats">'
        . $tile('New tickets', number_format($facts['new']), [report_change_count($facts['new'], $facts['new_before'], $days)])
        . $tile('Resolved', number_format($facts['resolved']), [report_change_count($facts['resolved'], $facts['resolved_before'], $days)]);
    $html .= $facts['median'] === null
        ? $tile('First reply (median)', 'No replies', ['No ticket opened in ' . report_period_words($days) . ' has a reply yet'], '', true)
        : $tile('First reply (median)', ticket_minutes_label($facts['median']),
            ['Over ' . $tickets($facts['replied']), report_change_minutes($facts['median'], $facts['median_before'], $days)]);
    $html .= $onTime['pct'] === null
        ? $tile('On time', 'Nothing due', ['No first reply was due in ' . report_period_words($days)], '', true)
        : $tile('On time', $onTime['pct'] . '%',
            [number_format($onTime['met']) . ' of ' . number_format($onTime['n']) . ' first replies', report_change_points($onTime['pct'], $facts['on_time_before']['pct'], $days)],
            '<progress class="stat-meter" max="100" value="' . $onTime['pct'] . '" aria-label="' . $onTime['pct'] . '% of first replies on time"></progress>');
    $html .= $facts['rated'] === 0
        ? $tile('Rated Great', 'No ratings', [$facts['surveys'] === 0 ? 'No survey was sent in ' . report_period_words($days)
            : ($facts['surveys'] === 1 ? '1 survey sent, not answered yet' : number_format($facts['surveys']) . ' surveys sent, none answered yet')], '', true)
        : $tile('Rated Great', number_format($facts['great']) . ' of ' . number_format($facts['rated']),
            [number_format($facts['rated']) . ' of ' . ($facts['surveys'] === 1 ? '1 survey' : number_format($facts['surveys']) . ' surveys') . ' answered']);
    return $html . '</div>';
}

/**
 * The team, a row a person. Open now links to the queue's own list of those tickets; the other
 * numbers are for the period and have no such list to open.
 *
 * $everyone false is the table a technician gets: their own row and the whole team's, no colleague's.
 * Which rows that is was decided by the page; this only words the caption and the line under it.
 *
 * @param list<array{name:string,avatar:string,open:int,open_url:string,resolved:int,median:?int,pct:?int,decided:int,met:int,minutes:?int,before_corrections:?int}> $rows
 *        avatar is already-rendered markup; minutes null means the row is not a person who logs time;
 *        before_corrections is set when a correction changed the person's approved time
 */
function report_team_table(array $rows, int $days, bool $everyone = true): string
{
    // The value is one element, so a narrow screen can set it beside its column's name. An empty cell stays empty.
    $cell = static fn(string $label, string $value): string => '<td data-label="' . $label . '">' . ($value !== '' ? '<span class="report-cell">' . $value . '</span>' : '') . '</td>';
    $none = '<span class="report-none">None</span>';
    $html = '<div class="card report-table-card"><table class="report-table"><caption class="sr-only">'
        . ($everyone ? 'The team in ' : 'You and the whole team in ') . report_period_words($days) . '</caption>'
        . '<thead><tr><th scope="col">Technician</th><th scope="col">Open now</th><th scope="col">Resolved</th><th scope="col">First reply</th>'
        . '<th scope="col">On time</th><th scope="col">Billable time</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><th scope="row"><span class="report-person">' . $row['avatar'] . '<span>' . h($row['name']) . '</span></span></th>'
            . $cell('Open now', $row['open'] > 0 && $row['open_url'] !== ''
                ? '<a class="link" href="' . h($row['open_url']) . '">' . number_format($row['open']) . '</a>' : number_format($row['open']))
            . $cell('Resolved', number_format($row['resolved']))
            . $cell('First reply', $row['median'] === null ? $none : ticket_minutes_label($row['median']))
            . $cell('On time', $row['pct'] === null ? $none
                : $row['pct'] . '% <span class="report-of">' . number_format($row['met']) . ' of ' . number_format($row['decided']) . '</span>')
            . $cell('Billable time', $row['minutes'] === null ? '' : ticket_minutes_label($row['minutes'])
                . ($row['before_corrections'] !== null ? ' <span class="report-of">' . ticket_minutes_label($row['before_corrections']) . ' before corrections</span>' : ''))
            . '</tr>';
    }
    return $html . '</tbody></table></div>'
        . ($everyone
            ? '<p class="page-note">Resolved, First reply and On time go by who owns the ticket now. First reply is the median wait. '
                . 'Billable time is approved time by the person who logged it. Corrections are included.</p>'
            : '<p class="page-note">Your row counts the tickets you own now and the approved time you logged. Whole team counts every ticket '
                . 'and everyone’s approved time. First reply is the median wait. Corrections are included.</p>');
}

/**
 * The open tickets that have been open longest, each a link to the ticket.
 *
 * @param list<array{id:int|string,subject:string,client_name:string,assignee_name:?string,created_at:string,westy_held?:int|string}> $tickets
 *        westy_held 1 is a ticket no person owns that the assistant is working: it is Westy's, as in the queue
 */
function report_open_longest(array $tickets, int $now, string $timeZone = 'UTC'): string
{
    $html = '<div class="card rail-list">';
    if ($tickets === []) $html .= '<div class="empty"><p>No open tickets.</p></div>';
    foreach ($tickets as $ticket) {
        $owner = trim((string)($ticket['assignee_name'] ?? ''));
        $html .= '<a class="report-row" href="/ticket.php?id=' . (int)$ticket['id'] . '">'
            . '<span class="report-row-main"><span class="report-row-title">#' . (int)$ticket['id'] . ' ' . h($ticket['subject']) . '</span>'
            . '<span class="report-row-sub">' . h($ticket['client_name'] . ', '
                . ($owner !== '' ? $owner : ((int)($ticket['westy_held'] ?? 0) === 1 ? 'Westy' : 'unassigned'))) . '</span></span>'
            . '<span class="report-row-value">Opened ' . h(ticket_ago((string)$ticket['created_at'], $now, $timeZone)) . '</span></a>';
    }
    return $html . '</div>';
}

/**
 * Approved billable time by client, most first, each a link to the client.
 *
 * @param list<array{id:int|string,name:string,min_total:int|string,original_min_total:int|string,adjusted_count:int|string}> $rows
 */
function report_time_by_client(array $rows, int $days): string
{
    $html = '<div class="card rail-list">';
    if ($rows === []) $html .= '<div class="empty"><p>No approved billable time in ' . report_period_words($days) . '.</p></div>';
    foreach ($rows as $row) {
        $html .= '<a class="report-row" href="/client.php?id=' . (int)$row['id'] . '">'
            . '<span class="report-row-main"><span class="report-row-title">' . h($row['name']) . '</span>'
            . ((int)$row['adjusted_count'] > 0 ? '<span class="report-row-sub">' . ticket_minutes_label((int)$row['original_min_total']) . ' before corrections</span>' : '')
            . '</span><span class="report-row-value">' . ticket_minutes_label((int)$row['min_total']) . '</span></a>';
    }
    return $html . '</div><p class="page-note">Approved billable time. Corrections are included.</p>';
}
