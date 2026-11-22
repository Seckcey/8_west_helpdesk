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

$employee = ['identity'=>['role'=>'client_staff']];
$owner = ['identity'=>['role'=>'client_owner']];
$viewer = ['identity'=>['role'=>'client_viewer']];
$_GET = $_POST = ['role'=>'client_owner'];
$_SERVER['REQUEST_URI'] = '/portal/guide.php?role=client_owner';
check((portal_guide_search($employee, 'sign in')[0]['id'] ?? null) === 'sh-sign-in', 'employee finds sign-in first');
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
$topTwo = array_slice(array_column(portal_guide_search($employee, 'open request'), 'id'), 0, 2);
check(in_array('sh-requests', $topTwo, true), 'open request finds the full request article near the top');
check(in_array('sh-requests-brief', array_column(portal_guide_search($viewer, 'open request'), 'id'), true),
    'viewer finds the brief request article at the shared anchor');

$cases = [
    'employee' => $employee,
    'viewer' => $viewer,
    'owner' => $owner,
    'admin' => ['identity'=>['role'=>'client_admin']],
    'staff' => ['identity'=>['role'=>'technician']],
    'delegated staff as owner' => ['identity'=>['role'=>'technician','customer_access'=>['role'=>'client_owner']]],
    'native owner with overlay' => ['identity'=>['role'=>'client_owner','customer_access'=>['role'=>'client_owner']]],
    'native admin with overlay' => ['identity'=>['role'=>'client_admin','customer_access'=>['role'=>'client_admin']]],
    'companion employee' => ['identity'=>['role'=>'client_staff'],'desktop_companion_pair'=>str_repeat('a', 32)],
    'companion owner' => ['identity'=>['role'=>'client_owner'],'desktop_companion_pair'=>str_repeat('a', 32)],
    'absent identity' => [],
    'no context' => null,
];
$rows = portal_guide_catalog_rows();
foreach ($cases as $label => $context) {
    $articles = portal_guide_articles($context);
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

$hashes = json_decode(file_get_contents(__DIR__.'/fixtures/portal_guide_approved_sha256.json'), true, 8, JSON_THROW_ON_ERROR);
foreach (portal_guide_pilot_articles() as $anchor => $article) {
    check(hash('sha256', $article['title']."\n\n".portal_guide_article_plain($article)) === $hashes[$anchor],
        $anchor.' plain paragraphs and numbered steps match the approved bytes');
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
