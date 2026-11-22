<?php
/** Guide search uses the authenticated person's articles and never records their query. */
declare(strict_types=1);
require_once __DIR__.'/../lib/portal_guide.php';

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    echo 'ok '.++$checks.' - '.$label."\n";
}

['anchors'=>$expected, 'cases'=>$cases] = json_decode(file_get_contents(__DIR__.'/fixtures/portal_guide_cases.json'), true, 8, JSON_THROW_ON_ERROR);
$cases['no context'] = [null, 'legacy'];
$employee = $cases['employee'][0];
$owner = $cases['owner'][0];
$viewer = $cases['viewer'][0];
$_GET = $_POST = ['role'=>'client_owner'];
$_SERVER['REQUEST_URI'] = '/portal/guide.php?role=client_owner';
check(array_column(portal_guide_search($employee, 'sign in', 3), 'id') === ['co-connect', 'co-sign-out', 'sh-sign-in'],
    'employee finds the Companion and portal sign-in guides');
check((portal_guide_search($employee, 'sign in to Safeharbor')[0]['id'] ?? null) === 'sh-sign-in',
    'naming Safeharbor ranks its sign-in article first');
foreach (['give access', 'give access computer'] as $query) {
    check(!in_array('sh-give-computer-access', array_column(portal_guide_search($employee, $query), 'id'), true),
        'employee cannot find the owner article despite role parameters for '.$query);
}
check((portal_guide_search($owner, 'give access computer')[0]['id'] ?? null) === 'sh-give-computer-access', 'owner finds the access article first');
check(!in_array('sh-sign-in', array_column(portal_guide_search($viewer, 'sign in'), 'id'), true), 'viewer has no sign-in article');
check(portal_guide_search($employee, '') === [] && portal_guide_search($employee, 'a') === [], 'empty and one-letter queries return nothing');
check(count(portal_guide_search($owner, 'request computer support follow', 5)) === 5, 'portal search honors a five-result limit');
$longQuery = str_repeat('request ', 40);
check(portal_guide_search($employee, $longQuery) === portal_guide_search($employee, mb_substr($longQuery, 0, 100, 'UTF-8')),
    'portal search caps the query at 100 characters');
check(portal_guide_search($employee, "\xFF") === [], 'invalid UTF-8 is no query');
check(array_column(portal_guide_search($employee, 'open request', 3), 'id') === ['sh-request-status', 'sh-find-request', 'sh-requests'],
    'open request finds the status, find and full request articles');
check(in_array('sh-requests-brief', array_column(portal_guide_search($viewer, 'open request'), 'id'), true),
    'viewer finds the brief request article at the shared anchor');

$rows = portal_guide_catalog_rows();
foreach ($cases as $label => [$context, $role]) {
    $articles = portal_guide_articles($context);
    check(array_keys($articles) === $expected[$role], $label.' searches the expected page articles');
    check(in_array('co-connect', array_column(portal_guide_search($context, 'Connect your Westy window'), 'id'), true),
        $label.' can find the Companion connection guide');
    $results = portal_guide_search($context, 'request computer sign access follow support');
    $ids = array_column($results, 'id');
    check($ids !== [] && count($ids) <= 10 && array_diff($ids, array_column($articles, 'catalog_id')) === [],
        $label.' search stays inside the page set');
    foreach ($results as $hit) {
        $row = $rows[$hit['id']];
        if (($articles[$hit['anchor']]['catalog_id'] ?? null) !== $hit['id'] || $hit !== [
            'id'=>$row['id'], 'anchor'=>$row['anchor'], 'title'=>$row['title'], 'summary'=>$row['summary'],
        ]) throw new RuntimeException($label.' result did not match its page article and public catalog fields');
    }
    check(count(array_unique($ids)) === count($ids), $label.' gets distinct links to the matching articles');
}
$_SESSION['desktop_companion_session'] = str_repeat('b', 32);
check(portal_guide_search($owner, 'give access') === portal_guide_search([], 'give access'),
    'server Companion session retains the legacy search set');
unset($_SESSION['desktop_companion_session']);

function doc(string $id, string $title = '', array $keywords = [], string $summary = '', string $body = ''): array {
    return ['id'=>$id, 'anchor'=>$id, 'title'=>$title, 'summary'=>$summary, 'keywords'=>$keywords, 'body'=>$body];
}
$docs = [
    doc('a', 'Other', body:'printer'),
    doc('b', 'Printer help', body:'printer'),
    doc('c', 'Printers', body:'printer printer'),
    doc('d', 'Email', body:'mail'),
];
check(help_catalog_search($docs, 'print', 10) === ['b','c','a'],
    'title prefix outranks body, repeated field words count once, ties keep page order and zero scores drop');
check(help_catalog_search($docs, 'print', 2) === ['b','c'], 'limit applies after ranking');
check(help_catalog_search($docs, 'print', 0) === [] && help_catalog_search($docs, 'print', -2) === [], 'nonpositive limits return nothing');
check(help_catalog_search($docs, 'rint', 10) === [], 'a substring inside a word does not match');
$fields = [
    doc('body', body:'printer'),
    doc('summary', summary:'printer'),
    doc('summary-body', summary:'printer', body:'printer'),
    doc('keyword', keywords:['printer printer','printing']),
    doc('keyword-body', keywords:['printing'], body:'printer'),
    doc('title', 'printer printer'),
    doc('all', 'printer', ['printer'], 'printer', 'printer'),
];
check(help_catalog_search($fields, 'print', 10) === ['all','keyword-body','title','summary-body','keyword','summary','body'],
    'title 5, keywords 4, summary 3 and body 1 add once per matching field');
check(help_catalog_search([doc('email', 'Email'), doc('print', keywords:['printer'])], 'print PRINT email', 10) === ['email','print'],
    'any query word can match and repeating a query token adds no weight');
check(help_catalog_search([doc('one', 'printer'), doc('two', 'printing presses')], 'print press', 10) === ['two','one'],
    'two different query tokens can each score in one field');

$words = [doc('accent', 'ÉCOLE'), doc('number', '42'), doc('last', 'ii'), doc('eighth', 'hh')];
check(help_catalog_search($words, 'éc—42;é', 10) === ['accent','number'], 'Unicode letters, numbers, case folding and punctuation');
check(help_catalog_search($words, 'é', 10) === [], 'a single multibyte letter is too short');
check(help_catalog_search($words, 'a aa AA bb cc dd ee ff gg hh ii', 10) === ['eighth'],
    'the first eight distinct eligible query tokens keep their original order');
check(help_catalog_search($words, str_repeat('é', 97).' 42 école', 10) === ['number'], '100-character cutoff counts Unicode characters before tokenizing');
check(help_catalog_search($words, '42'.str_repeat(' ', 100)."\xFF", 10) === [], 'invalid UTF-8 beyond the cutoff still makes the query empty');
check(help_catalog_search([doc('field', body:'a aa AA bb cc dd ee ff gg hh ii')], 'hh', 1) === ['field']
    && help_catalog_search([doc('field', body:'a aa AA bb cc dd ee ff gg hh ii')], 'ii', 1) === ['field'],
    'a document field matches all of its words, not only the first eight');
check(array_column(portal_guide_search(['identity'=>['role'=>'client_owner']], 'revocation'), 'id') === ['sh-give-computer-access'],
    'a word in the last paragraph of an article body is found');

$pilot = portal_guide_pilot_articles();
foreach (help_catalog_index()['articles'] as $row) {
    if ($row['legacy_guide'] || !in_array($row['app'], ['safeharbor', 'companion'], true)) continue;
    $paragraphs = [];
    foreach ($row['body']['blocks'] as $block) {
        if ($block['type'] === 'paragraph') {
            $paragraphs[] = $block['text'];
        } else {
            $steps = [];
            foreach ($block['items'] as $i => $item) $steps[] = ($i + 1).'. '.$item;
            $paragraphs[] = implode("\n", $steps);
        }
    }
    $article = $pilot[$row['anchor']];
    check($article['catalog_id'] === $row['id'] && portal_guide_article_plain($article) === implode("\n\n", $paragraphs),
        $row['id'].' searchable paragraphs and ordered steps match the vendored index');
}
foreach (portal_guide_legacy_articles() as $anchor => $article) {
    check(portal_guide_article_plain($article) === $article['text'], $anchor.' legacy text is returned verbatim');
}

$log = tempnam(sys_get_temp_dir(), 'sh-guide-log-');
if ($log === false) throw new RuntimeException('Could not allocate a test log');
$originalLog = ini_get('error_log');
try {
    if (ini_set('error_log', $log) === false) throw new RuntimeException('Could not capture PHP logging');
    $session = $_SESSION;
    ob_start();
    try {
        $healthy = portal_guide_search($employee, 'open request');
        help_catalog_use_dir(__DIR__.'/fixtures'); // No catalog manifest or index here.
        $unavailable = portal_guide_search($employee, 'open request');
        $independent = help_catalog_search($docs, 'print', 2);
    } finally {
        $output = ob_get_clean();
    }
    check($healthy !== [] && $unavailable === [], 'a catalog failure returns an empty search even after a successful load');
    check($independent === ['b','c'], 'the pure scorer works from supplied documents with the catalog unavailable');
    check($output === '' && file_get_contents($log) === '', 'healthy and unavailable searches produce no output or PHP log entry');
    check($_SESSION === $session, 'search leaves the session untouched');
} finally {
    help_catalog_use_dir(null);
    ini_set('error_log', $originalLog);
    unlink($log);
}
echo "$checks guide search checks passed\n";
