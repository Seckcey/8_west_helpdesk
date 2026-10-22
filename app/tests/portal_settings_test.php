<?php
/** Synthetic rendering and optional ID-context boundary checks. No accounts/providers. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
ob_start();
function portal_csrf_token(): string { return str_repeat('c', 64); }
function portal_action_nonce(string $purpose, ?int $now = null): string { return hash('sha256', $purpose); }
require_once __DIR__.'/../lib/portal_render.php';
require_once __DIR__.'/../lib/eightwestid/policy.php';
$checks = 0;
function settings_check(bool $ok, string $name): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    echo 'ok '.$checks.' - '.$name."\n";
}
$identity = ['subject'=>'t9u51', 'identity_tenant_slug'=>'synthetic-client', 'identity_tenant_id'=>'9',
    'role'=>'client_admin', 'session_version'=>'1.2', 'tenant_id'=>1, 'client_id'=>11, 'display_name'=>'Synthetic Employee'];
$doc = ['version'=>1, 'subject'=>'t9u51', 'workspace'=>['tenant_id'=>'9', 'tenant'=>'synthetic-client', 'kind'=>'client'],
    'panels'=>['my_settings','client_admin'], 'primary_owner_status'=>'verified',
    'capabilities'=>['manage_people'=>true,'appoint_client_admins'=>false,'close_subscription'=>false]];
settings_check(safeharbor_settings_context($doc, 't9u51', 'synthetic-client', '9') === $doc, 'frozen v1 context accepted for exact immutable identity/workspace');
foreach ([null, [...$doc,'version'=>2], [...$doc,'subject'=>'t9u52'], [...$doc,'future'=>true],
    [...$doc,'panels'=>['invented_admin']], [...$doc,'capabilities'=>['manage_people'=>'yes']],
    [...$doc,'workspace'=>['tenant_id'=>'10','tenant'=>'synthetic-client','kind'=>'client']]] as $invalid) {
    settings_check(safeharbor_settings_context($invalid, 't9u51', 'synthetic-client', '9') === [], 'missing, foreign, unknown or malformed context grants no panel');
}
$prefs = EightWest\Id\default_preferences('t9u51', 'Synthetic Employee', 'light', null);
$prefs['version'] = 3;
$prefs['regional'] = ['locale'=>'en-GB','time_zone'=>'Europe/London','date_format'=>'dmy','time_format'=>'24h'];
$prefs['appearance']['density'] = 'compact';
$prefs['appearance']['motion'] = 'reduce';
$identity += ['preferences'=>$prefs,'theme'=>'light'];
$context = ['identity'=>$identity,'settings_context'=>$doc,'binding'=>['client_name'=>'Synthetic Client','identity_tenant_slug'=>'synthetic-client']];
$_SERVER['REQUEST_URI']='/portal/requests.php';
$summary = ['client'=>['name'=>'Synthetic Client'],'counts'=>['open'=>1],
    'tickets'=>[['id'=>123,'subject'=>'Synthetic request','status'=>'open','priority'=>'normal','updated_at'=>'2026-10-21 12:00:00']]];
foreach (['client_admin','client_staff','client_viewer'] as $role) {
    $c = $context; $c['identity']['role'] = $role;
    ob_start(); portal_render_dashboard($c,$summary,[]); $html = ob_get_clean();
    settings_check(str_contains($html,'lang="en-GB" data-theme="light"') && str_contains($html,'data-density="compact"'), $role.' adopts verified appearance and regional preferences');
    settings_check(str_contains($html,'>My settings</a>'), $role.' has one personal settings destination');
    settings_check(str_contains($html,'>Client Admin</a>') === ($role==='client_admin'), $role.' navigation follows freshly verified effective client role');
    settings_check(str_contains($html,'href="/portal/reports.php"') === ($role==='client_admin'), $role.' client-wide summaries are role scoped');
    $out = getenv('CUSTOMER_UI_CAPTURE_DIR');
    if (is_string($out) && is_dir($out)) file_put_contents($out.'/settings-'.$role.'-light.html',$html);
}
settings_check(portal_format_utc('2026-10-21 12:00:00') === '21/10/2026 · 13:00 BST','ticket timestamps honor personal time zone/date/clock');
$wrong = $identity; $wrong['preferences']['sub'] = 't9u52';
settings_check(portal_personal_preferences($wrong)['locale'] === 'en-US','another subject cannot supply personal preferences');
$c=$context; $c['identity']['customer_access']=['role'=>'client_staff'];
ob_start(); portal_shell_start($c); $html=ob_get_clean();
settings_check(!str_contains($html,'>Client Admin</a>'),'delegated member role defeats home administrator label');
$c=$context; $c['binding']['identity_tenant_slug']='other-client';
$c['identity']['role']='admin'; $c['identity']['customer_access']=['role'=>'client_admin'];
ob_start(); portal_shell_start($c); $html=ob_get_clean();
settings_check(str_contains($html,'href="https://id.8westit.com/admin.php?panel=client_admin"'),'freshly selected delegated client admin has fixed ID entry without home-role rewrite');
$c=$context; unset($c['settings_context']);
ob_start(); portal_shell_start($c); $html=ob_get_clean();
settings_check(str_contains($html,'>Client Admin</a>') && str_contains($html,'>My settings</a>'),'verified native client admin entry does not depend on optional home settings claim');
$c['identity']['role']='client_staff';
ob_start(); portal_shell_start($c); $html=ob_get_clean();
settings_check(!str_contains($html,'>Client Admin</a>'),'missing context does not turn an ordinary member into an administrator');
$c=$context; $c['identity']['preferences']['appearance']['theme']='dark';
ob_start(); portal_render_dashboard($c,$summary,[]); $html=ob_get_clean();
if (is_string($out) && is_dir($out)) file_put_contents($out.'/settings-admin-dark.html',$html);
foreach (['light','dark'] as $theme) {
    $_SERVER['REQUEST_URI']='/portal/';
    $c=$context; $c['identity']['preferences']['appearance']['theme']=$theme;
    ob_start(); portal_render_workspace($c); $html=ob_get_clean();
    if (is_string($out) && is_dir($out)) file_put_contents($out.'/settings-workspace-'.$theme.'.html',$html);
}
echo 'Portal settings: '.$checks." checks passed\n";
ob_end_flush();
