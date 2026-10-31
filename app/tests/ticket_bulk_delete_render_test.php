<?php
/** Queue markup for bulk delete (lib/render.php), loaded with isolated dependency edges; no config. */
declare(strict_types=1);
$checks = 0;
function bulk_render_check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}

$temp = sys_get_temp_dir() . '/safeharbor-bulk-render-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
try {
    copy(__DIR__ . '/../lib/render.php', $temp . '/render.php');
    file_put_contents($temp . '/auth.php', '<?php function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,"UTF-8"); } function sla_info($t): array { return ["state"=>"on_track","label"=>"On track"]; } function rel_time($v): string { return "2h"; }');
    file_put_contents($temp . '/westy.php', '<?php');
    require $temp . '/render.php';

    $base = ['tenant_id' => 1, 'client_id' => 11, 'status' => 'open', 'priority' => 'normal', 'channel' => 'alert',
        'assignee_id' => null, 'client_name' => 'Fixture Dental', 'created_at' => '2026-10-30 08:00:00', 'westy_owned' => false];
    $rows = [
        array_replace($base, ['id' => 101, 'subject' => 'Server backup alert: <b>repository</b> 87% full']),
        array_replace($base, ['id' => 102, 'subject' => 'Front desk PC will not print', 'channel' => 'email', 'priority' => 'high']),
        array_replace($base, ['id' => 103, 'subject' => 'Disk space low on FD-01', 'status' => 'resolved', 'priority' => 'low']),
    ];

    $plain = ticket_row($rows[0]);
    $picked = ticket_pick_row($rows[0]);
    bulk_render_check(str_contains($picked, $plain), 'a pick row wraps the unchanged queue row');
    bulk_render_check(strpos($picked, '<input') < strpos($picked, '<a ') && strpos($picked, '</label>') < strpos($picked, '<a '),
        'the checkbox sits before and outside the row link');
    bulk_render_check(!preg_match('~<a [^>]*>(?:(?!</a>).)*<input~s', $picked), 'no control inside the link');
    bulk_render_check(str_contains($picked, 'aria-label="Select ticket #101"') && str_contains($picked, 'value="101"'),
        'each checkbox names its ticket and carries its number');
    bulk_render_check(str_contains($picked, 'autocomplete="off"'), 'the browser may not restore a tick onto a row that has shifted');
    bulk_render_check(str_contains($picked, '&lt;b&gt;repository&lt;/b&gt;') && !str_contains($picked, '<b>'), 'subjects stay escaped');

    $head = ticket_head_row(false);
    bulk_render_check(str_starts_with($head, '<div class="trow trow-head" role="row">') && !str_contains($head, 'type="checkbox"'),
        'technicians get the plain column header');
    preg_match_all('~<span class="(trow-[a-z]+)">~', $head, $columns);
    bulk_render_check($columns[1] === ['trow-pri', 'trow-num', 'trow-main', 'trow-status', 'trow-tech', 'trow-sla', 'trow-age'],
        'the header keeps its seven columns in order');
    $pickHead = ticket_head_row(true);
    bulk_render_check(str_contains($pickHead, 'id="bulk-all"') && str_contains($pickHead, 'aria-label="Select every ticket in this view"')
        && str_contains($pickHead, 'autocomplete="off"') && str_contains($pickHead, $head), 'owners get a select-all box beside the same header');

    $bar = ticket_bulk_bar('In <Progress>', 3);
    bulk_render_check(str_contains($bar, 'data-filter-label="In &lt;Progress&gt;"') && str_contains($bar, 'data-total="3"')
        && str_contains($bar, ' hidden ') && !str_contains($bar, 'data-filter="') && !str_contains($bar, 'through'),
        'the bulk bar starts hidden, escapes its label, and carries no live filter');
    bulk_render_check(str_contains($bar, 'aria-live="polite"') && str_contains($bar, 'id="bulk-delete"'), 'the bar announces its count and offers Delete');

    if (($argv[1] ?? '') === '--render') {
        echo '<div class="card list">' . ticket_head_row(true) . implode('', array_map('ticket_pick_row', $rows)) . '</div>'
            . ticket_bulk_bar('All', count($rows));
    } else {
        echo "Ticket bulk delete render: {$checks} passed.\n";
    }
} finally {
    $actual = realpath($temp);
    $parent = realpath(sys_get_temp_dir());
    if (!$actual || !$parent || !str_starts_with($actual, $parent . DIRECTORY_SEPARATOR) || !str_starts_with(basename($actual), 'safeharbor-bulk-render-')) {
        throw new RuntimeException('Fixture cleanup scope changed');
    }
    foreach (['render.php', 'auth.php', 'westy.php'] as $file) unlink($actual . '/' . $file);
    rmdir($actual);
}
