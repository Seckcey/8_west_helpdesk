<?php
/**
 * The client page's own pieces (lib/client_page.php), loaded with isolated dependency edges: no
 * config, no database. The page itself, with its rows read from tables, is rendered and checked by
 * app/tests/client_shared_directory_ui_test.php; these checks cover the edges of each piece.
 *
 *   php app/tests/client_page_test.php
 */
declare(strict_types=1);
$checks = 0;
function client_page_check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

require __DIR__ . '/../lib/client_page.php';

$dom = static function (string $html): DOMXPath {
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"><div>' . $html . '</div>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($doc);
};
$texts = static function (DOMXPath $x, string $query): array {
    $out = [];
    foreach ($x->query($query) as $node) $out[] = trim((string)preg_replace('/\s+/u', ' ', $node->textContent));
    return $out;
};
$attrs = static function (DOMXPath $x, string $query): array {
    $out = [];
    foreach ($x->query($query) as $node) $out[] = (string)$node->nodeValue;
    return $out;
};
$has = static fn(string $class): string => 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';

try {
    // ---- which list and page the address asks for ----
    foreach ([
        [[], ['show' => 'open', 'page' => 1]],
        [['show' => 'resolved'], ['show' => 'resolved', 'page' => 1]],
        [['show' => 'resolved', 'page' => '3'], ['show' => 'resolved', 'page' => 3]],
        [['show' => 'open', 'page' => '3'], ['show' => 'open', 'page' => 1]],             // Open is one list, never paged
        [['show' => 'Resolved', 'page' => '2'], ['show' => 'open', 'page' => 1]],
        [['show' => ['resolved'], 'page' => ['2']], ['show' => 'open', 'page' => 1]],     // an array is not a word or a number
        [['show' => 'resolved', 'page' => '0'], ['show' => 'resolved', 'page' => 1]],
        [['show' => 'resolved', 'page' => '-2'], ['show' => 'resolved', 'page' => 1]],
        [['show' => 'resolved', 'page' => '2.5'], ['show' => 'resolved', 'page' => 1]],
        [['show' => 'resolved', 'page' => '1e2'], ['show' => 'resolved', 'page' => 1]],
        [['show' => 'resolved', 'page' => ' 2'], ['show' => 'resolved', 'page' => 1]],
        [['show' => 'resolved', 'page' => '2 OR 1=1'], ['show' => 'resolved', 'page' => 1]],
        [['show' => 'resolved', 'page' => '99999999999999999999'], ['show' => 'resolved', 'page' => 1]],
    ] as $n => [$get, $state]) {
        client_page_check(client_list_state($get) === $state, "list state $n");
    }
    client_page_check(client_resolved_page(1, 0) === 1 && client_resolved_page(5, 0) === 1 && client_resolved_page(2, 25) === 1
        && client_resolved_page(2, 26) === 2 && client_resolved_page(99, 51) === 3 && client_resolved_page(-4, 51) === 1,
        'the page shown is the one asked for, or the last one there is');
    client_page_check(client_url(30) === '/client.php?id=30' && client_url(30, 'resolved') === '/client.php?id=30&show=resolved'
        && client_url(30, 'resolved', 2) === '/client.php?id=30&show=resolved&page=2' && client_url(30, 'open', 1) === '/client.php?id=30'
        && client_url(30, 'anything') === '/client.php?id=30', 'the page has one address per list and page');

    // ---- the head ----
    client_page_check(client_plan_label('premium') === 'Premium plan' && client_plan_label('standard') === 'Standard plan'
        && client_plan_label('') === 'Standard plan', 'the plan is said in words');
    $x = $dom(client_head(['id' => 30, 'name' => 'Harbor <Dental> & Sons', 'sla_tier' => 'premium', 'health' => 'watch', 'domain' => ' harbordental.example ']));
    client_page_check($texts($x, '//h1') === ['Harbor <Dental> & Sons'] && $x->query('//h1/*')->length === 0, 'the name is text, never markup');
    client_page_check($texts($x, '//div[' . $has('client-title') . ']/span') === ['Premium plan', 'Needs attention', 'harbordental.example'],
        'plan, then Needs attention for a watched client, then the domain');
    client_page_check($texts($x, '//div[' . $has('client-actions') . ']/a') === ['New ticket', 'Edit']
        && $attrs($x, '//div[' . $has('client-actions') . ']/a/@href') === ['/ticket_new.php?client=30', '/client_edit.php?id=30']
        && $attrs($x, '//div[' . $has('client-actions') . ']/a/@class') === ['btn-primary', 'btn-secondary']
        && $x->query('//a[@data-new-ticket]')->length === 1, 'New ticket is the main action and the one the key c opens; Edit is second');
    $x = $dom(client_head(['id' => 31, 'name' => 'Bayside Realty', 'sla_tier' => 'standard', 'health' => 'good', 'domain' => '']));
    client_page_check($texts($x, '//div[' . $has('client-title') . ']/span') === ['Standard plan'], 'a healthy client with no domain shows only its plan');
    client_page_check(!str_contains(client_head(['id' => 31, 'name' => 'A', 'health' => 'good']), 'health-dot'), 'no bare coloured dot stands for health');

    // ---- the note ----
    client_page_check(client_note(null) === '' && client_note('') === '' && client_note(" \n ") === '', 'no note, no box');
    $x = $dom(client_note("Call the office line before 9.\r\n<script>alert(1)</script> Ask for Dana."));
    client_page_check($texts($x, '//p[' . $has('client-note') . ']') === ['Note Call the office line before 9. <script>alert(1)</script> Ask for Dana.']
        && $x->query('//script')->length === 0 && $x->query('//p/br')->length === 1, 'the note keeps its line breaks and is text, never markup');

    // ---- the tiles ----
    $x = $dom(client_tiles(3, 42, 2, 200));
    client_page_check($texts($x, '//div[' . $has('stat-k') . ']') === ['Open tickets', 'First reply, 90 days', 'Billable time, 30 days']
        && $texts($x, '//div[' . $has('stat-v') . ']') === ['3', '42 min', '3 h 20 min']
        && $texts($x, '//div[' . $has('stat-hint') . ']') === ['Average over 2 tickets', 'Approved'], 'three tiles, each a number');
    $x = $dom(client_tiles(0, null, 0, 0));
    client_page_check($texts($x, '//div[' . $has('stat-k') . ']') === ['Open tickets', 'Billable time, 30 days']
        && $texts($x, '//div[' . $has('stat-v') . ']') === ['0', '0 min'], 'with no first reply to average, that tile is left out');
    client_page_check($texts($dom(client_tiles(1234, 60, 1, 59)), '//div[' . $has('stat-v') . ']') === ['1,234', '1 h', '59 min']
        && $texts($dom(client_tiles(1, 60, 1, 0)), '//div[' . $has('stat-hint') . ']')[0] === 'Average over 1 ticket',
        'large counts are grouped, whole hours have no minutes, and one ticket is singular');
    client_page_check(!preg_match('/Not shown here|—/u', client_tiles(0, null, 0, 0) . client_tiles(3, 42, 2, 200)), 'no tile holds a sentence or a dash in place of a number');

    // ---- Open / Resolved ----
    $x = $dom(client_ticket_tabs(30, 'open', 3, 1041));
    client_page_check($texts($x, '//nav/a') === ['Open3', 'Resolved1,041']
        && $attrs($x, '//nav/a/@href') === ['/client.php?id=30', '/client.php?id=30&show=resolved']
        && $attrs($x, '//nav/a[@aria-current="page"]/@href') === ['/client.php?id=30']
        && $attrs($x, '//nav/@aria-label') === ['This client’s tickets'], 'Open is the current list');
    client_page_check($attrs($dom(client_ticket_tabs(30, 'resolved', 3, 41)), '//nav/a[@aria-current="page"]/@href') === ['/client.php?id=30&show=resolved'],
        'Resolved is the current list');
    $x = $dom(client_tickets_empty('open', 'Harbor <Dental>', 30));
    client_page_check($texts($x, '//p') === ['No open tickets for Harbor <Dental>.'] && $attrs($x, '//a/@href') === ['/ticket_new.php?client=30'],
        'an empty Open list says so and offers New ticket');
    $x = $dom(client_tickets_empty('resolved', 'Harbor <Dental>', 30));
    client_page_check($texts($x, '//p') === ['No resolved tickets for Harbor <Dental> yet.'] && $x->query('//a')->length === 0, 'an empty Resolved list says so');

    // ---- the pager under Resolved ----
    client_page_check(client_resolved_pager(30, 0, 1) === '' && client_resolved_pager(30, 25, 1) === '', 'one page of resolved tickets needs no pager');
    $x = $dom(client_resolved_pager(30, 60, 2));
    client_page_check($texts($x, '//span[' . $has('pager-count') . ']') === ['Showing 26 to 50 of 60']
        && $attrs($x, '//a/@href') === ['/client.php?id=30&show=resolved', '/client.php?id=30&show=resolved&page=3']
        && $attrs($x, '//a/@rel') === ['prev', 'next'] && $texts($x, '//a') === ['Previous', 'Next'], 'a middle page goes both ways');
    $x = $dom(client_resolved_pager(30, 60, 1));
    client_page_check($texts($x, '//span[@aria-disabled="true"]') === ['Previous'] && $attrs($x, '//a/@href') === ['/client.php?id=30&show=resolved&page=2'],
        'the first page has no Previous');
    $x = $dom(client_resolved_pager(30, 60, 7));
    client_page_check($texts($x, '//span[' . $has('pager-count') . ']') === ['Showing 51 to 60 of 60'] && $texts($x, '//span[@aria-disabled="true"]') === ['Next']
        && $attrs($x, '//a/@href') === ['/client.php?id=30&show=resolved&page=2'], 'a page past the end is the last page, with no Next');

    // ---- a contact ----
    $name = 'Sam\'+(window.sh33=1)+\' "JJ" <b>Lee</b>';
    $x = $dom(client_contact_row(['id' => '61', 'name' => $name, 'email' => 'sam@harbordental.example'], 30, '<input type="hidden" name="csrf" value="synthetic">'));
    client_page_check($texts($x, '//div[' . $has('rail-list-name') . ']') === [$name] && $texts($x, '//div[' . $has('rail-list-sub') . ']') === ['sam@harbordental.example']
        && $x->query('//b')->length === 0, 'a contact shows its name and address as text');
    client_page_check($attrs($x, '//details/summary/@aria-label') === ['More for ' . $name]
        && $attrs($x, '//details/form[@method="post"]/@action') === ['/client.php?id=30']
        && $attrs($x, '//details/form/@data-confirm') === ['Remove ' . $name . '?']
        && $attrs($x, '//details/form/input/@name') === ['csrf', 'action', 'contact_id']
        && $attrs($x, '//details/form/input/@value') === ['synthetic', 'remove_contact', '61']
        && $texts($x, '//details/form/button[@type="submit"]') === ['Remove']
        && $attrs($x, '//details/form/button/@aria-label') === ['Remove ' . $name]
        && $x->query('//details[@open]')->length === 0,
        'Remove is a named button behind More, closed until asked for, and asks before it removes');
    $x = $dom(client_contact_row(['id' => 62, 'name' => 'Dr. Priya Nair', 'email' => ''], 30, ''));
    client_page_check($x->query('//div[' . $has('rail-list-sub') . ']')->length === 0, 'a contact with no address shows no empty line');

    echo "PASS: $checks client page checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
