<?php
/** Read-only presentation of verified policy history and the existing queue's at-risk view. */
declare(strict_types=1);

require_once __DIR__ . '/service_goals.php';

/** Exact hours and minutes, without rounding a configured target. */
function service_goal_target_short(int $minutes): string
{
    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;
    return implode(' ', array_filter([$hours > 0 ? $hours . ' h' : '', $rest > 0 ? $rest . ' min' : '']));
}

/** All four priorities stay visible, including when a plan has no current targets. */
function service_goal_targets_table(array $policies): string
{
    $out = '<div class="goal-table-wrap"><table class="goal-target-table"><caption class="sr-only">Current first reply targets by plan and priority</caption>'
        . '<thead><tr><th scope="col">First reply due within</th>';
    foreach (['low', 'normal', 'high', 'urgent'] as $priority) $out .= '<th scope="col">' . ucfirst($priority) . '</th>';
    $out .= '</tr></thead><tbody>';
    foreach ($policies as $policy) {
        $targets = [];
        foreach ($policy['versions'] as $version) {
            if ($version['state'] !== 'current') continue;
            foreach ($version['targets'] as $target) $targets[$target['priority']] = (int)$target['first_response_minutes'];
        }
        $out .= '<tr><th scope="row">' . h($policy['display_name']) . ' plan</th>';
        foreach (['low', 'normal', 'high', 'urgent'] as $priority) {
            $out .= '<td>' . (isset($targets[$priority]) ? h(service_goal_target_short($targets[$priority])) : '<span class="goal-unavailable">Not set</span>') . '</td>';
        }
        $out .= '</tr>';
    }
    return $out . '</tbody></table></div>';
}

/** Native disclosure: no new script, mutation or fabricated legacy approver. */
function service_goal_history_html(array $policies, string $timeZone): string
{
    $count = array_sum(array_map(static fn(array $p): int => count($p['versions']), $policies));
    $out = '<details class="card goal-history"><summary>History of changes <span class="goal-history-count">(' . $count . ')</span></summary>';
    if ($count === 0) $out .= '<p class="goal-history-empty">No policy history has been recorded. Viewing this page does not create it.</p>';
    foreach ($policies as $policy) {
        foreach (array_reverse($policy['versions']) as $version) {
            $state = (string)$version['state'];
            $label = ['current' => 'Current', 'scheduled' => 'Scheduled', 'superseded' => 'Previous'][$state] ?? 'Unknown';
            $baseline = (string)$version['effective_from'] === '1970-01-01 00:00:00';
            $effective = $baseline ? 'In place since Safeharbor was set up.'
                : service_goal_effective_label((string)$version['effective_from'], $state, $timeZone);
            $out .= '<article class="goal-history-entry" data-policy-state="' . h($state) . '"><div class="goal-history-heading"><h3>'
                . h($policy['display_name']) . ' plan</h3><span class="goal-state goal-state-' . h($state) . '">' . h($label) . '</span></div>'
                . '<p>' . h($effective) . '</p><dl class="goal-history-targets">';
            foreach ($version['targets'] as $target) {
                $out .= '<div><dt>' . h(ucfirst($target['priority'])) . '</dt><dd>' . h(service_goal_target_short((int)$target['first_response_minutes'])) . '</dd></div>';
            }
            $out .= '</dl>';
            if ((int)$version['version_no'] === 1 && $version['created_by_user_id'] === null) {
                $out .= '<p class="goal-attribution">No approver or reason was recorded for the original targets.</p>';
            } else {
                $name = trim((string)($version['created_by_name'] ?? ''));
                $out .= '<p class="goal-attribution">' . ($name !== '' ? 'Published by ' . h($name) . '.' : 'The recorded publisher’s name is unavailable.')
                    . ' ' . h($version['reason'] ?? 'No publication reason recorded.') . '</p>';
            }
            $out .= '</article>';
        }
    }
    return $out . '</details>';
}

/** $risk is null when its read failed; never represent an unavailable list as zero. */
function service_goal_desk_html(?array $history, ?string $historyError, ?array $risk, string $timeZone, int $now): string
{
    $out = '<div class="page page-service-goals"><div class="page-head"><div><h1 class="page-title">Service goals</h1>'
        . '<p class="page-sub">How fast a first reply is due, by plan and priority.</p></div></div>';
    $out .= '<section class="card goal-risk" aria-labelledby="goal-risk-title"><div class="goal-section-head"><h2 id="goal-risk-title">At risk now'
        . ($risk === null ? '' : ' <span>(' . number_format($risk['total']) . ')</span>') . '</h2>'
        . '<a class="btn-secondary" href="/?view=risk">Open at-risk queue</a></div>';
    if ($risk === null) {
        $out .= '<p class="goal-risk-empty" role="status">At-risk tickets are temporarily unavailable. Try the queue or refresh this page.</p>';
    } elseif ($risk['total'] === 0) {
        $out .= '<p class="goal-risk-empty">No unanswered tickets are overdue or due in under two hours.</p>';
    } else {
        $out .= '<p class="goal-risk-caption">Unanswered tickets overdue or due in under two hours, soonest first.</p><ul class="goal-risk-list">';
        foreach ($risk['rows'] as $ticket) {
            $sla = sla_info($ticket, $now);
            $owner = ($ticket['westy_owned'] ?? false) === true && $ticket['status'] === 'in_progress' && empty($ticket['assignee_id'])
                ? 'Westy' : (!empty($ticket['assignee_id']) ? ($ticket['assignee_name'] ?: 'Assigned teammate') : 'Unassigned');
            $out .= '<li data-ticket-id="' . (int)$ticket['id'] . '"><div class="goal-risk-ticket"><a href="/ticket.php?id=' . (int)$ticket['id']
                . '&amp;in=risk"><span class="goal-ticket-number">#' . (int)$ticket['id'] . '</span> ' . h($ticket['subject']) . '</a>'
                . '<span class="goal-risk-client">' . h($ticket['client_name']) . '</span></div>'
                . '<span class="sla sla-' . h($sla['state']) . '"><span class="sla-dot"></span>' . h($sla['label']) . '</span>'
                . '<span class="goal-risk-owner">' . h($owner) . '</span></li>';
        }
        $out .= '</ul>';
        if ($risk['total'] > count($risk['rows'])) $out .= '<p class="goal-risk-caption">Showing ' . count($risk['rows']) . ' of ' . number_format($risk['total']) . '. Open the at-risk queue to see all tickets.</p>';
    }
    $out .= '</section>';
    if ($historyError !== null) {
        $out .= '<div class="form-error" role="status">' . h($historyError) . '</div>';
    } elseif ($history !== null) {
        $out .= '<section class="card goal-current" aria-label="Current targets">' . service_goal_targets_table($history['policies'])
            . '<p class="goal-clock-note">The clock counts every hour, including nights and weekends. Waiting on the client does not pause it. These targets measure the first reply, not resolution time.</p></section>'
            . '<p class="goal-captured-note">Each ticket keeps the targets it opened with. Later changes to a plan, priority or status do not rewrite them.</p>'
            . service_goal_history_html($history['policies'], $timeZone);
    }
    return $out . '</div>';
}
