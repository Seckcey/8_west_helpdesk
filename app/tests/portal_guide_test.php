<?php
/** DB/provider-free checks for approved text, audience selection and active provider instructions. */
declare(strict_types=1);
require_once __DIR__.'/../lib/portal_guide.php';
require_once __DIR__.'/../lib/portal_westy_stream.php';
require_once __DIR__.'/../lib/portal_westy_tenant_ai.php';
require_once __DIR__.'/../lib/portal_westy_tools.php';
require_once __DIR__.'/../lib/portal_westy_terminal.php';
$checks = 0;
function guide_check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    echo 'ok '.++$checks.' - '.$label."\n";
}
$pilot = portal_guide_pilot_articles();
$indexRows = array_column(help_catalog_index()['articles'], null, 'id');
$expectedBodies = [];
foreach ($indexRows as $id => $row) {
    if ($row['legacy_guide'] || !in_array($row['app'], ['safeharbor', 'companion'], true)) continue;
    $html = '';
    foreach ($row['body']['blocks'] as $block) {
        if ($block['type'] === 'paragraph') {
            $html .= '<p>'.portal_guide_inline($block['text']).'</p>';
        } else {
            $items = array_map(static fn(string $item): string => '<li>'.portal_guide_inline($item).'</li>', $block['items']);
            $html .= '<ol>'.implode('', $items).'</ol>';
        }
    }
    $expectedBodies[$id] = $html;
}
require_once __DIR__.'/../lib/portal_westy_help.php';
require_once __DIR__.'/../lib/portal_render.php';
function portal_csrf_token(): string { return str_repeat('c', 64); }
function guide_widget_html(array $context): string {
    ob_start();
    try {
        portal_westy_widget($context);
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}
$helpTools = portal_westy_help_definitions();
$legacy = portal_guide_legacy_articles();
['anchors'=>$expected, 'cases'=>$cases] = json_decode(file_get_contents(__DIR__.'/fixtures/portal_guide_cases.json'), true, 8, JSON_THROW_ON_ERROR);
$cases['no context'] = [null, 'legacy'];
$_GET = $_POST = ['role'=>'client_owner','audience'=>'owner','application'=>'browser'];
$_SERVER['REQUEST_URI'] = '/portal/guide.php?role=client_owner&audience=owner';
$widget = guide_widget_html($cases['owner'][0]);
guide_check(preg_match('/<div id="portal-chat-root"[^>]*\bdata-help-labels="([^"]*)"/', $widget, $match) === 1,
    'the chat root carries the source labels');
$ownerLabels = portal_guide_source_labels($cases['owner'][0]);
guide_check(json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true, 8, JSON_THROW_ON_ERROR) === $ownerLabels,
    'the chat root exposes the owner labels as JSON');
guide_check(array_keys($ownerLabels) === array_column(portal_guide_articles($cases['owner'][0]), 'catalog_id'), 'labels cover every article id the person can see');
guide_check(!isset(portal_guide_source_labels($cases['employee'][0])['sh-give-computer-access']), 'labels are role filtered');
$rendered = [];
foreach ($cases as $label => [$context, $role]) {
    $articles = portal_guide_articles($context);
    $anchors = array_keys($articles);
    guide_check($anchors === $expected[$role], $label.' keeps exactly the expected anchors in page order despite role parameters');
    $catalogAnchors = array_map(static fn($a) => $indexRows[$a['catalog_id']]['anchor'], array_values($articles));
    guide_check($catalogAnchors === $anchors && count(array_unique($catalogAnchors)) === count($anchors)
        && !array_intersect(['contact', 'guide-q'], $anchors), $label.' has unique catalog anchors clear of the page controls');
    $labels = portal_guide_source_labels($context);
    guide_check(array_keys($labels) === array_column($articles, 'catalog_id'), $label.' labels match the page set');
    foreach ($articles as $anchor => $article) {
        $id = $article['catalog_id'];
        $row = $indexRows[$id];
        guide_check($labels[$id] === ['label'=>$article['title'], 'href'=>'/portal/guide.php#'.$anchor],
            $label.' source for '.$id.' has just the page title and anchor link');
        if ($role === 'legacy') {
            guide_check(array_diff(['owner_admin', 'employee', 'viewer'], $row['audience']) === [],
                $label.' sees only all-audience articles, including '.$id);
        }
        if (isset($expectedBodies[$id])) {
            guide_check($article['title'] === $row['title'] && portal_guide_article_html($article) === $expectedBodies[$id],
                $label.' renders the catalog title, paragraphs and ordered steps for '.$id);
            $rendered[$id] = true;
        }
    }
    foreach (['devices','response','privacy'] as $id) {
        guide_check($articles[$id] === $legacy[$id], $label.' retains '.$id.' material');
    }
    guide_check($articles['summaries'] === ($role === 'owner' ? $pilot['summaries'] : $legacy['summaries']),
        $label.' gets the applicable version of the service summary article');
    foreach ([[], [['name'=>'exec_command']], [['name'=>'exec_command'],['name'=>'cua_window_state']]] as $tools) {
        $instructions = portal_westy_ai_instructions([...$tools, ...$helpTools], $context);
        guide_check(str_contains($instructions, 'call search_help'), $label.' gets help instructions when search is offered');
        guide_check(!str_contains($instructions, 'Give an employee access to one computer'), $label.' instructions omit the owner article title');
    }
    guide_check(!str_contains(portal_westy_ai_instructions([['name'=>'exec_command']], $context), 'search_help'), $label.' gets no help instruction without search');
}
guide_check(array_diff_key($expectedBodies, $rendered) === [], 'every non-legacy Safeharbor and Companion article renders for an eligible reader');
$helpToolMessage = static fn(array $result): array => ['role'=>'tool','call_id'=>'c','content'=>[['type'=>'text',
    'text'=>json_encode($result, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]]];
$rejectedHelp = $helpToolMessage(portal_westy_help_call($cases['employee'][0], 'read_help_article', ['id'=>5]));
$foundHelp = $helpToolMessage(portal_westy_help_call($cases['employee'][0], 'read_help_article', ['id'=>'sh-sign-in']));
guide_check(portal_westy_ai_mistakes(array_fill(0, PORTAL_WESTY_AI_MISTAKE_LIMIT, $rejectedHelp)) === PORTAL_WESTY_AI_MISTAKE_LIMIT,
    'rejected help arguments count toward the mistake limit');
guide_check(portal_westy_ai_mistakes([$foundHelp, $foundHelp]) === 0, 'successful help reads are not mistakes');
foreach ($cases as $label => [$context]) {
    $articles = portal_guide_articles($context);
    $json = json_encode($articles, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach (['drafted_by','approved_by','last_verified','fable-5.2','opus-5.5','gpt-6.2-astra','keywords','"approval"'] as $meta) {
        guide_check(!str_contains($json, $meta), $label.' guide excludes catalog metadata '.$meta);
    }
}
guide_check((portal_guide_articles($cases['viewer'][0])['requests']['catalog_id'] ?? null) === 'sh-requests-brief', 'viewer keeps the brief requests entry');
guide_check((portal_guide_articles($cases['employee'][0])['requests']['catalog_id'] ?? null) === 'sh-requests', 'employee gets the full requests article');

$rows = portal_guide_catalog_rows();
foreach ($indexRows as $row) {
    if (!in_array($row['app'], ['safeharbor', 'companion'], true)) {
        guide_check(!isset($rows[$row['id']]), $row['id'].' is outside the page catalog');
        continue;
    }
    unset($row['approval'], $row['last_verified']);
    guide_check(($rows[$row['id']] ?? null) === $row, $row['id'].' joins to its catalog fields without editorial metadata');
}

$dir = sys_get_temp_dir().'/safeharbor-guide-'.bin2hex(random_bytes(12));
if (!mkdir($dir, 0700)) throw new RuntimeException('Could not create guide fixture directory');
try {
    foreach (['help-manifest.json','customer-index.json'] as $file) {
        if (!copy(__DIR__.'/../help/'.$file, $dir.'/'.$file)) throw new RuntimeException('Could not copy guide fixture');
    }
    $employeeContext = $cases['employee'][0];
    $readRequests = static fn() => portal_westy_help_call($employeeContext, 'read_help_article', ['id'=>'sh-requests']);
    $healthyWesty = $readRequests();
    help_catalog_use_dir($dir);
    guide_check($healthyWesty['untrusted_result']['state'] === 'found' && $readRequests() === $healthyWesty, 'Westy reads the intact catalog copy');
    $testIndex = help_catalog_index();
    if (file_put_contents($dir.'/customer-index.json', "\n", FILE_APPEND) !== 1) throw new RuntimeException('Could not alter guide fixture');
    help_catalog_use_dir($dir);
    guide_check($readRequests() === ['untrusted_result'=>['state'=>'unavailable']], 'Westy reports help unavailable when the cached catalog is damaged');
    guide_check(str_contains(portal_westy_ai_instructions($helpTools, $employeeContext), 'call search_help'), 'Westy still builds its instructions with a damaged catalog');
    guide_check(portal_guide_source_labels($employeeContext) === [], 'a damaged catalog returns an empty source map');
    $widget = guide_widget_html($employeeContext);
    guide_check(str_contains($widget, 'data-help-labels="{}"') && str_contains($widget, 'id="portal-chat-form"'),
        'the portal shell renders the chat form with an empty JSON object when the catalog is damaged');

    // A second valid catalog changes the audience and order without changing the display label.
    foreach ($testIndex['articles'] as &$row) {
        if ($row['id'] === 'sh-sign-in') {
            $row['audience'] = ['viewer'];
            $row['order'] = 300;
        } elseif ($row['id'] === 'sh-assigned-computers') {
            $row['app'] = 'milepost';
        } elseif ($row['id'] === 'co-chat') {
            $row['audience'] = ['viewer'];
        }
    }
    unset($row);
    $bytes = json_encode($testIndex, JSON_THROW_ON_ERROR)."\n";
    $manifestPath = $dir.'/help-manifest.json';
    $manifest = json_decode(file_get_contents($manifestPath), true, 8, JSON_THROW_ON_ERROR);
    $manifest['files']['customer-index.json'] = ['sha256'=>hash('sha256', $bytes), 'bytes'=>strlen($bytes)];
    file_put_contents($dir.'/customer-index.json', $bytes);
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR)."\n");
    help_catalog_use_dir($dir);
    guide_check(!isset(portal_guide_catalog_rows()['sh-assigned-computers']), 'catalog rows exclude a different app');
    $visible = array_filter(portal_guide_articles($cases['viewer'][0]), static fn($a) => isset($a['blocks']));
    guide_check(array_keys($visible) === ['updates','private-computer-link','request-status','find-request','westy-chat',
        'companion-connect','companion-chat','companion-sign-out','sign-in'], 'viewer selection follows the new catalog audience and order');
    guide_check(!isset(portal_guide_articles($employeeContext)['sign-in']), 'former audience no longer sees the reassigned article');
    foreach ($cases as $label => [$context, $role]) {
        if ($role === 'legacy') {
            guide_check(!isset(portal_guide_articles($context)['companion-chat']), $label.' cannot see a restricted Companion article');
        }
    }
} finally {
    help_catalog_use_dir(null);
    foreach (['help-manifest.json','customer-index.json'] as $file) {
        if (is_file($dir.'/'.$file)) unlink($dir.'/'.$file);
    }
    rmdir($dir);
}
guide_check($readRequests() === $healthyWesty, 'Westy reads the vendored guide again after restoring the catalog directory');
$_SESSION['desktop_companion_session'] = str_repeat('b',32);
guide_check(array_keys(portal_guide_articles(['identity'=>['role'=>'client_owner']])) === $expected['legacy'],
    'server Companion session sees the legacy guide and all-audience Companion articles');
unset($_SESSION['desktop_companion_session']);
guide_check(array_search('private-computer-link',array_keys(portal_guide_articles($cases['owner'][0])),true) < array_search('give-computer-access',array_keys(portal_guide_articles($cases['owner'][0])),true), 'employee reference appears above owner cross-reference');
guide_check($pilot['private-computer-link']['audience'] === 'Employee', 'owner reference retains original Employee audience');
guide_check(portal_guide_inline('**<img src=x onerror=alert(1)>** `<script>` &') === '<strong>&lt;img src=x onerror=alert(1)&gt;</strong> <code>&lt;script&gt;</code> &amp;', 'formatting cannot inject HTML');
guide_check(substr_count(portal_guide_article_html($pilot['requests']), '<li>') === 4, 'approved steps render as an ordered list');
foreach (['Publication verification','Authorship','SHA256','754c92d','HOLD FOR','Sources.','Astra'] as $internal) {
    guide_check(!str_contains(json_encode($pilot), $internal), 'internal metadata excluded: '.$internal);
}
// The ordinary admission body uses these real tool definitions before a tool run is created.
// Include a maximum-length new message and six modest prior user/assistant pairs.
$tools = array_map(static fn($tool) => ['name'=>$tool['name'],'description'=>$tool['description'],'input_schema'=>$tool['parameters']], portal_westy_tool_definitions());
array_push($tools, ...$helpTools);
$messages = [];
for ($i=0; $i<6; $i++) {
    $messages[] = ['role'=>'user','content'=>[['type'=>'text','text'=>str_repeat('u',500)]]];
    $messages[] = ['role'=>'assistant','content'=>[['type'=>'text','text'=>str_repeat('a',500)]]];
}
$messages[] = ['role'=>'user','content'=>[['type'=>'text','text'=>str_repeat('x',2000)]]];
foreach (['employee','viewer','owner','admin'] as $label) {
    $body = westy_tenant_ai_body(westy_tenant_ai_selection('openai','gpt-6.1-sol','medium'),
        portal_westy_ai_instructions($tools,$cases[$label][0]),$messages,['tools'=>$tools,'max_output_tokens'=>PORTAL_WESTY_AI_MAX_OUTPUT]);
    $bytes = strlen(json_encode($body,JSON_THROW_ON_ERROR));
    guide_check($bytes <= 28000, $label.' ordinary request plus history fits without trimming ('.$bytes.' bytes)');
}
foreach (['base'=>$tools, 'base+cua'=>[...$tools,...portal_westy_cua_definitions()],
    'base+terminal/recovery'=>[...$tools,...portal_westy_terminal_definitions(),...portal_westy_recovery_definitions()],
    'combined'=>[...$tools,...portal_westy_terminal_definitions(),...portal_westy_recovery_definitions(),...portal_westy_cua_definitions()]] as $variant=>$definitions) {
    $make = static fn($context,$input) => strlen(json_encode(westy_tenant_ai_body(
        westy_tenant_ai_selection('openai','gpt-6.1-sol','medium'),portal_westy_ai_instructions($definitions,$context),$input,
        ['tools'=>$definitions,'max_output_tokens'=>PORTAL_WESTY_AI_MAX_OUTPUT]),JSON_THROW_ON_ERROR));
    foreach (['first'=>[end($messages)],'history'=>$messages] as $inputName=>$input) {
        $baseline = $make(null,$input);
        foreach (['employee','viewer','owner'] as $label) {
            $bytes = $make($cases[$label][0],$input);
            guide_check($bytes-$baseline < 1100, "$variant/$inputName/$label compact guide overhead (baseline $baseline, candidate $bytes bytes)");
            if ($variant !== 'combined' && $inputName === 'first') guide_check($bytes < 28000, "$variant/$label retains ordinary first-message headroom");
        }
    }
}
echo "$checks guide checks passed\n";
