<?php
/** DB/provider-free checks for approved text, audience selection and both instruction builders. */
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
    guide_check(str_contains(portal_westy_workspace_body([],false,$context)['instructions'], $serialized), $label.' streaming body uses same guide');
}
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
