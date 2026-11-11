<?php
/**
 * The Time page's rows, in plain words.
 *
 * A row says how long, what, which ticket and whether it is billable and approved. Everything else
 * the page has always shown (who approved and when, how the time was logged, the measured start and
 * end, every change and its reason) is still printed, under "History" on the row, with people's
 * names and the viewer's local time. Nothing here reads or writes: public/time.php runs the same
 * queries as before and hands the rows over.
 *
 * Needs render.php (h()) and ticket_page.php (ticket_clock(), ticket_minutes_label()).
 */
declare(strict_types=1);

require_once __DIR__ . '/ticket_page.php';

/** How an entry came to be, in words. */
const TIME_SOURCE_WORDS = [
    'timer' => 'Logged from the timer.',
    'reply' => 'Logged with a reply on the ticket.',
    'suggestion' => 'Logged from a suggestion.',
    'legacy' => 'Logged before timers were recorded.',
];

/** Where an entry stands with its reviewer. */
const TIME_STATUS_WORDS = [
    'pending' => 'Waiting for review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];

/** A stored UTC time as the scripts expect it (2026-08-29T10:00:00Z); '' when it is not one. */
function time_rfc3339(?string $value): string
{
    if ($value === null || $value === '') return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    return $date ? $date->format('Y-m-d\TH:i:s\Z') : '';
}

/** A stored UTC time on the viewer's clock: "2:46 PM" today, "Nov 10, 2:46 PM" before that. */
function time_when(?string $utc, string $timeZone, ?int $now = null): string
{
    if ($utc === null || trim($utc) === '') return 'a time that was not recorded';
    $clock = ticket_clock($utc, $timeZone, $now);
    return $clock !== '' ? $clock : 'a time that cannot be read';
}

/** "20 min, billable" / "45 min, not billable". */
function time_amount(int $minutes, bool $billable): string
{
    return ticket_minutes_label($minutes) . ', ' . ($billable ? 'billable' : 'not billable');
}

function time_tag_billable(bool $billable): string
{
    return '<span class="entry-badge ' . ($billable ? 'badge-billable' : 'badge-plain') . '">' . ($billable ? 'Billable' : 'Not billable') . '</span>';
}

function time_tag_status(string $status): string
{
    $known = isset(TIME_STATUS_WORDS[$status]) ? $status : 'pending';
    return '<span class="entry-badge status-' . $known . '">' . h(TIME_STATUS_WORDS[$known]) . '</span>';
}

/** A person's name for a user id, from the names the page looked up. */
function time_person(array $names, mixed $userId): string
{
    $name = trim((string)($names[(int)$userId] ?? ''));
    return $name !== '' ? $name : 'someone no longer on the team';
}

/**
 * How the time was recorded: when the work was done, where the entry came from, and the measured
 * start and end when there is one. One sentence each, already escaped.
 *
 * @return list<string>
 */
function time_logged_lines(array $entry, string $timeZone, ?int $now = null): array
{
    $lines = ['Worked ' . h(time_when((string)($entry['worked_at'] ?? ''), $timeZone, $now)) . '.'];
    $lines[] = h(TIME_SOURCE_WORDS[(string)($entry['source'] ?? '')] ?? 'How it was logged is not recorded.');
    $lines[] = ($entry['started_at'] ?? null) !== null && ($entry['ended_at'] ?? null) !== null
        ? 'Measured from ' . h(time_when((string)$entry['started_at'], $timeZone, $now)) . ' to ' . h(time_when((string)$entry['ended_at'], $timeZone, $now)) . '.'
        : 'No start and end were measured.';
    return $lines;
}

/**
 * "History" on a row: everything that used to be printed inline. $lines are escaped HTML; $extra is
 * escaped HTML placed under the list (the list of changes on an approved row).
 */
function time_history(array $lines, bool $open = false, string $extra = ''): string
{
    $html = '<details class="entry-history"' . ($open ? ' open' : '') . '><summary>History</summary><ul>';
    foreach ($lines as $line) $html .= '<li>' . $line . '</li>';
    return $html . '</ul>' . $extra . '</details>';
}

/** The link to a row's ticket: "#106 Hygienist account setup". */
function time_ticket_link(array $entry): string
{
    return '<a class="entry-ticket link" href="/ticket.php?id=' . (int)$entry['tid'] . '">#' . (int)$entry['tid'] . ' ' . h((string)($entry['subject'] ?? '')) . '</a>';
}

/**
 * What a rejected entry and its replacement say about each other. A rejected entry is never
 * edited: one new entry is sent in its place and both are kept.
 */
function time_correction_lines(array $entry): string
{
    $html = '';
    $replaces = (int)($entry['corrects_time_entry_id'] ?? 0);
    if ($replaces > 0) {
        $html .= '<div class="entry-lineage">Sent in place of rejected entry ' . $replaces . '. The rejected entry is kept as it was.</div>';
    }
    if ((string)$entry['approval_status'] !== 'rejected') return $html;
    $replacement = (int)($entry['replacement_entry_id'] ?? 0);
    if ($replacement > 0) {
        $state = (string)($entry['replacement_status'] ?? '');
        return $html . '<div class="entry-lineage">A corrected entry was sent in its place. It is '
            . h(isset(TIME_STATUS_WORDS[$state]) ? lcfirst(TIME_STATUS_WORDS[$state]) : 'recorded') . '.</div>';
    }
    $startedAt = time_rfc3339($entry['started_at'] ?? null);
    return $html . '<div class="correction-actions"><button type="button" class="btn-secondary time-correct"'
        . ' data-rejected-entry-id="' . (int)$entry['id'] . '"'
        . ' data-minutes="' . (int)$entry['minutes'] . '"'
        . ' data-note="' . h((string)$entry['note']) . '"'
        . ' data-billable="' . (int)$entry['billable'] . '"'
        . ' data-worked-at="' . h(time_rfc3339((string)$entry['worked_at'])) . '"'
        . ' data-started-at="' . h($startedAt) . '"'
        . ' data-ended-at="' . h(time_rfc3339($entry['ended_at'] ?? null)) . '"'
        . ' data-measured="' . ($startedAt !== '' ? '1' : '0') . '">Correct and resubmit</button></div>';
}

/**
 * One of the signed-in person's own entries (Today, Earlier). $latest is the newest change to an
 * approved entry, or null: the row then shows what counts now, and History says what was approved.
 *
 * @param array<int,string> $names  user id => full name, for whoever reviewed or changed it
 */
function time_own_row(array $entry, ?array $latest, array $names, string $timeZone, ?int $now = null): string
{
    $status = (string)$entry['approval_status'];
    $approved = $status === 'approved';
    $minutes = $approved && $latest !== null ? (int)$latest['effective_minutes'] : (int)$entry['minutes'];
    $billable = $approved && $latest !== null ? (bool)$latest['effective_billable'] : (int)$entry['billable'] === 1;

    $history = time_logged_lines($entry, $timeZone, $now);
    if ($approved) {
        $history[] = 'Approved as ' . h(time_amount((int)$entry['minutes'], (int)$entry['billable'] === 1)) . ', by '
            . h(time_person($names, $entry['reviewed_by_user_id'] ?? 0)) . ', ' . h(time_when($entry['reviewed_at'] ?? null, $timeZone, $now)) . '.';
        if ($latest !== null) {
            $history[] = 'Changed to ' . h(time_amount($minutes, $billable)) . ' (change ' . (int)$latest['version'] . '). Reason: ' . h((string)$latest['reason']);
        }
    } elseif ($status === 'rejected') {
        $history[] = 'Rejected by ' . h(time_person($names, $entry['reviewed_by_user_id'] ?? 0)) . ', ' . h(time_when($entry['reviewed_at'] ?? null, $timeZone, $now)) . '.';
    }

    return '<div class="entry-row">'
        . '<span class="entry-min">' . h(ticket_minutes_label($minutes)) . '</span>'
        . '<div class="entry-main">'
        . '<div class="entry-note">' . h((string)$entry['note']) . '</div>'
        . time_ticket_link($entry)
        . ($status === 'rejected' && trim((string)$entry['review_note']) !== ''
            ? '<div class="entry-review-note">Review note: ' . h((string)$entry['review_note']) . '</div>' : '')
        . time_correction_lines($entry)
        . time_history($history)
        . '</div>'
        . '<span class="entry-tags">' . time_tag_billable($billable) . time_tag_status($status) . '</span>'
        . '</div>';
}

/**
 * One entry waiting for an owner or admin to decide. What a reviewer weighs (when the work was
 * done, how it was logged, the measured start and end) is open on the row, not behind a click.
 */
function time_review_row(array $entry, string $timeZone, ?int $now = null): string
{
    $history = time_logged_lines($entry, $timeZone, $now);
    if (($entry['corrects_time_entry_id'] ?? null) !== null) {
        $history[] = 'Sent in place of rejected entry ' . (int)$entry['corrects_time_entry_id'] . '.';
    }
    return '<div class="entry-row review-row" data-time-entry-id="' . (int)$entry['id'] . '">'
        . '<span class="entry-min">' . h(ticket_minutes_label((int)$entry['minutes'])) . '</span>'
        . '<div class="entry-main">'
        . '<div class="entry-note">' . h((string)$entry['tech_name']) . ', ' . h((string)$entry['client_name']) . ': ' . h((string)$entry['note']) . '</div>'
        . time_ticket_link($entry)
        . time_history($history, true)
        . '</div>'
        . '<span class="entry-tags">' . time_tag_billable((int)$entry['billable'] === 1) . '</span>'
        . '<div class="review-actions">'
        . '<button type="button" class="btn-primary time-review" data-decision="approved">Approve</button>'
        . '<button type="button" class="btn-secondary time-review" data-decision="rejected">Reject</button>'
        . '</div></div>';
}

/**
 * One approved entry in the owners' and admins' ledger. The row shows what counts now; History
 * keeps the original approval, how the time was logged and every change with its reason.
 *
 * @param list<array<string,mixed>> $changes  every change to this entry, oldest first
 * @param array<int,string> $names  user id => full name
 * @param array{label:string,action:?string,button:string,invoice_url:?string} $billing  coastmark_time_billing_state()
 * @param bool $billingDown  Coastmark could not be read for this page
 */
function time_approved_row(array $entry, array $changes, array $names, array $billing, bool $billingDown, string $timeZone, ?int $now = null): string
{
    $id = (int)$entry['id'];
    $originalMinutes = (int)$entry['minutes'];
    $originalBillable = (int)$entry['billable'] === 1;
    $latest = $changes === [] ? null : $changes[array_key_last($changes)];
    $minutes = $latest === null ? $originalMinutes : (int)$latest['effective_minutes'];
    $billable = $latest === null ? $originalBillable : (bool)$latest['effective_billable'];
    $version = $latest === null ? 0 : (int)$latest['version'];

    $history = ['<span class="adjustment-original">Original approval: ' . h(time_amount($originalMinutes, $originalBillable)) . ', by '
        . h(trim((string)($entry['reviewer_name'] ?? '')) !== '' ? (string)$entry['reviewer_name'] : time_person($names, $entry['reviewed_by_user_id'] ?? 0))
        . ', ' . h(time_when($entry['reviewed_at'] ?? null, $timeZone, $now)) . '.</span>'];
    array_push($history, ...time_logged_lines($entry, $timeZone, $now));
    if (trim((string)$entry['review_note']) !== '') $history[] = 'Review note: ' . h((string)$entry['review_note']);
    $history[] = 'Entry number ' . $id . '.';
    $history[] = 'The original approval is never erased. To add time, log a new entry.';

    $list = '';
    if ($changes !== []) {
        $list = '<div class="adjustment-history"><p class="entry-history-title">Changes (' . count($changes) . ')</p><ol>';
        foreach ($changes as $change) {
            $list .= '<li class="adjustment-history-item">Change ' . (int)$change['version'] . ': '
                . h(time_amount((int)$change['effective_minutes'], (bool)$change['effective_billable'])) . ', by '
                . h(time_person($names, $change['actor_user_id'] ?? 0)) . ', ' . h(time_when((string)$change['created_at'], $timeZone, $now))
                . '.<div>Reason: ' . h((string)$change['reason']) . '</div></li>';
        }
        $list .= '</ol></div>';
    }

    $html = '<div class="entry-row adjustment-row" data-time-entry-id="' . $id . '">'
        . '<span class="entry-min">' . h(ticket_minutes_label($minutes)) . '</span>'
        . '<div class="entry-main">'
        . '<div class="entry-note">' . h((string)$entry['tech_name']) . ', ' . h((string)$entry['client_name']) . ': ' . h((string)$entry['note']) . '</div>'
        . time_ticket_link($entry);
    if ($latest !== null) {
        $html .= '<div class="entry-lineage adjustment-effective">Counts now as ' . h(time_amount($minutes, $billable)) . ', after '
            . $version . ($version === 1 ? ' change' : ' changes') . '. Approved as ' . h(time_amount($originalMinutes, $originalBillable)) . '.</div>'
            . '<div class="entry-lineage adjustment-reason">Reason for the latest change: ' . h((string)$latest['reason']) . '</div>';
    }
    $html .= time_history($history, false, $list) . '</div>'
        . '<span class="entry-tags">' . time_tag_billable($billable) . '</span>'
        . '<div class="review-actions billing-actions">';
    if ($billingDown) {
        $html .= '<span class="entry-badge status-pending" data-billing-label>Coastmark is not connected</span>';
    } else {
        // The billing words come from the billing rules (lib/coastmark_time_billing.php); only their
        // separators are changed here.
        $html .= '<span class="billing-state" data-billing-label>' . h(str_replace(' · ', ', ', (string)$billing['label'])) . '</span>';
        if ($billing['action'] !== null) {
            $html .= '<button type="button" class="btn-secondary time-billing" data-entry-id="' . $id . '" data-billing-action="' . h((string)$billing['action']) . '">'
                . h((string)$billing['button']) . '</button>';
        }
        if ($billing['invoice_url'] !== null) {
            $html .= '<a class="btn-secondary" href="' . h((string)$billing['invoice_url']) . '" target="_blank" rel="noopener">Open in Coastmark</a>';
        }
    }
    return $html . '<button type="button" class="btn-secondary time-adjust"'
        . ' data-entry-id="' . $id . '"'
        . ' data-original-minutes="' . $originalMinutes . '"'
        . ' data-original-billable="' . ($originalBillable ? '1' : '0') . '"'
        . ' data-effective-minutes="' . $minutes . '"'
        . ' data-effective-billable="' . ($billable ? '1' : '0') . '"'
        . ' data-version="' . $version . '">Change</button>'
        . '</div></div>';
}

/**
 * What the approved list's search box asked for: a ticket's number ("#109", "109"), or part of a
 * client's name, or nothing. The text is cut to a sane length; it is only ever a bound value.
 *
 * @return array{text:string,ticket:int,client:string}
 */
function time_approved_search(mixed $raw): array
{
    $text = is_string($raw) ? trim(mb_substr(trim($raw), 0, 100)) : '';
    if (preg_match('/\A#?([1-9][0-9]{0,8})\z/', $text, $number) === 1) {
        return ['text' => $text, 'ticket' => (int)$number[1], 'client' => ''];
    }
    return ['text' => $text, 'ticket' => 0, 'client' => $text];
}

/** A client-name search as a LIKE pattern: the person's %, _ and \ are ordinary characters. */
function time_like(string $text): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text) . '%';
}
