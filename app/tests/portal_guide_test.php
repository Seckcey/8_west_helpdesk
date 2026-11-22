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
$approved = json_decode(file_get_contents(__DIR__.'/fixtures/portal_guide_approved_sha256.json'), true, 8, JSON_THROW_ON_ERROR);
$pilot = portal_guide_pilot_articles();
guide_check(array_keys($pilot) === array_keys($approved), 'all six approved articles are present');
foreach ($pilot as $id => $article) {
    $paragraphs = [];
    foreach ($article['blocks'] as $block) {
        $paragraphs[] = $block['type'] === 'steps'
            ? implode("\n", array_map(static fn($text, $index) => ($index+1).'. '.$text, $block['items'], array_keys($block['items'])))
            : $block['text'];
    }
    guide_check(hash('sha256', $article['title']."\n\n".implode("\n\n", $paragraphs)) === $approved[$id], $id.' exactly preserves approved title, paragraphs and ordered steps');
}
$legacy = portal_guide_legacy_articles();
$employee = ['sign-in','assigned-computers','requests','updates','private-computer-link'];
$viewer = ['assigned-computers','updates','private-computer-link'];
$owner = ['requests','updates','private-computer-link','give-computer-access'];
$cases = [
    'employee' => [['identity'=>['role'=>'client_staff']], $employee],
    'viewer' => [['identity'=>['role'=>'client_viewer']], $viewer],
    'owner' => [['identity'=>['role'=>'client_owner']], $owner],
    'admin' => [['identity'=>['role'=>'client_admin']], $owner],
    'staff' => [['identity'=>['role'=>'technician']], []],
    'delegated staff as owner' => [['identity'=>['role'=>'technician','customer_access'=>['role'=>'client_owner']]], []],
    'native owner with overlay' => [['identity'=>['role'=>'client_owner','customer_access'=>['role'=>'client_owner']]], []],
    'native admin with overlay' => [['identity'=>['role'=>'client_admin','customer_access'=>['role'=>'client_admin']]], []],
    'companion employee' => [['identity'=>['role'=>'client_staff'],'desktop_companion_pair'=>str_repeat('a',32)], []],
    'companion owner' => [['identity'=>['role'=>'client_owner'],'desktop_companion_pair'=>str_repeat('a',32)], []],
    'absent identity' => [[], []],
];
$_GET = $_POST = ['role'=>'client_owner','audience'=>'owner','application'=>'browser'];
$_SERVER['REQUEST_URI'] = '/portal/guide.php?role=client_owner&audience=owner';
foreach ($cases as $label => [$context, $ids]) {
    $articles = portal_guide_articles($context);
    $selected = array_keys(array_filter($articles, static fn($a) => isset($a['blocks'])));
    guide_check($selected === $ids, $label.' has only applicable pilot articles despite client role parameters');
    guide_check(array_diff(array_keys($legacy), array_keys($articles)) === [], $label.' retains legacy anchor IDs');
    foreach (['devices','response','summaries','privacy'] as $id) {
        guide_check($articles[$id] === $legacy[$id], $label.' retains '.$id.' material');
    }
    $index = portal_guide_westy_articles($context);
    guide_check(array_keys($index) === array_keys($articles), $label.' index IDs/order exactly match page');
    foreach ($ids as $id) {
        guide_check($index[$id]['title'] === $articles[$id]['title'] && $index[$id]['audience'] === $articles[$id]['audience']
            && $index[$id]['href'] === '/portal/guide.php#'.$id && !isset($index[$id]['blocks']), $label.' exact title/audience/anchor for '.$id);
    }
    $serialized = json_encode($index, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    foreach ([[], [['name'=>'exec_command']], [['name'=>'exec_command'],['name'=>'cua_window_state']]] as $tools) {
        $instructions = portal_westy_ai_instructions($tools, $context);
        guide_check(str_contains($instructions, 'Reviewed portal guide: '.$serialized), $label.' active provider guide matches page selection');
        guide_check(str_contains($instructions, 'Give an employee access to one computer') === in_array('give-computer-access',$ids,true), $label.' owner-only context does not leak');
    }
}
foreach ($cases as $label => [$context, $ids]) {
    $articles = portal_guide_articles($context);
    $json = json_encode($articles, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach (['drafted_by','approved_by','last_verified','fable-5.2','opus-5.5','gpt-6.2-astra','keywords','"approval"'] as $meta) {
        guide_check(!str_contains($json, $meta), $label.' guide excludes catalog metadata '.$meta);
    }
    $anchors = array_values(array_unique(array_merge($ids, array_keys($legacy))));
    guide_check(array_keys($articles) === $anchors, $label.' keeps every anchor in page order');
}
guide_check((portal_guide_articles($cases['viewer'][0])['requests']['catalog_id'] ?? null) === 'sh-requests-brief', 'viewer keeps the brief requests entry');
guide_check((portal_guide_articles($cases['employee'][0])['requests']['catalog_id'] ?? null) === 'sh-requests', 'employee gets the full requests article');

$rows = portal_guide_catalog_rows();
foreach (help_catalog_index()['articles'] as $row) {
    if ($row['app'] !== 'safeharbor') continue;
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
    $healthyWesty = portal_guide_westy_articles($employeeContext);
    help_catalog_use_dir($dir);
    guide_check(portal_guide_westy_articles($employeeContext) === $healthyWesty, 'Westy reads the intact catalog copy');
    $testIndex = help_catalog_index();
    if (file_put_contents($dir.'/customer-index.json', "\n", FILE_APPEND) !== 1) throw new RuntimeException('Could not alter guide fixture');
    help_catalog_use_dir($dir);
    guide_check(portal_guide_westy_articles($employeeContext) === [], 'Westy omits guide articles when the cached catalog is replaced by a damaged copy');
    guide_check(str_contains(portal_westy_ai_instructions([], $employeeContext), 'Reviewed portal guide: []'), 'Westy still builds its instructions with a damaged catalog');

    // A second valid catalog changes the audience and order without changing the display label.
    foreach ($testIndex['articles'] as &$row) {
        if ($row['id'] === 'sh-sign-in') {
            $row['audience'] = ['viewer'];
            $row['order'] = 90;
        } elseif ($row['id'] === 'sh-assigned-computers') {
            $row['app'] = 'milepost';
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
    guide_check(array_keys($visible) === ['updates','private-computer-link','sign-in'], 'viewer selection follows the new catalog audience and order');
    guide_check(!isset(portal_guide_articles($employeeContext)['sign-in']), 'former audience no longer sees the reassigned article');
} finally {
    help_catalog_use_dir(null);
    foreach (['help-manifest.json','customer-index.json'] as $file) {
        if (is_file($dir.'/'.$file)) unlink($dir.'/'.$file);
    }
    rmdir($dir);
}
guide_check(portal_guide_westy_articles($employeeContext) === $healthyWesty, 'Westy reads the vendored guide again after restoring the catalog directory');
$_SESSION['desktop_companion_session'] = str_repeat('b',32);
guide_check(portal_guide_articles(['identity'=>['role'=>'client_owner']]) === $legacy, 'server Companion session retains legacy guide');
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
