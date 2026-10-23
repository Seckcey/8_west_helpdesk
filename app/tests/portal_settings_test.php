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
    settings_check(str_contains($html,'>My settings</strong>'), $role.' has one personal settings destination');
    settings_check(str_contains($html,'>Client Admin</strong>') === ($role==='client_admin'), $role.' navigation follows freshly verified effective client role');
    settings_check(str_contains($html,'href="/portal/reports.php"') === ($role==='client_admin'), $role.' client-wide summaries are role scoped');
    $out = getenv('CUSTOMER_UI_CAPTURE_DIR');
    if (is_string($out) && is_dir($out)) file_put_contents($out.'/settings-'.$role.'-light.html',$html);
}
settings_check(portal_format_utc('2026-10-21 12:00:00') === '21/10/2026 · 13:00 BST','ticket timestamps honor personal time zone/date/clock');
$wrong = $identity; $wrong['preferences']['sub'] = 't9u52';
settings_check(portal_personal_preferences($wrong)['locale'] === 'en-US','another subject cannot supply personal preferences');
$c=$context; $c['identity']['customer_access']=['role'=>'client_staff'];
ob_start(); portal_shell_start($c); $html=ob_get_clean();
settings_check(!str_contains($html,'>Client Admin</strong>'),'delegated member role defeats home administrator label');
$c=$context; $c['binding']['identity_tenant_slug']='other-client';
$c['identity']['role']='admin'; $c['identity']['customer_access']=['role'=>'client_admin'];
ob_start(); portal_shell_start($c); $html=ob_get_clean();
settings_check(str_contains($html,'href="https://id.8westit.com/admin.php?panel=client_admin"'),'freshly selected delegated client admin has fixed ID entry without home-role rewrite');
$c=$context; unset($c['settings_context']);
ob_start(); portal_shell_start($c); $html=ob_get_clean();
settings_check(str_contains($html,'>Client Admin</strong>') && str_contains($html,'>My settings</strong>'),'verified native client admin entry does not depend on optional home settings claim');
$c['identity']['role']='client_staff';
ob_start(); portal_shell_start($c); $html=ob_get_clean();
settings_check(!str_contains($html,'>Client Admin</strong>'),'missing context does not turn an ordinary member into an administrator');
$c=$context; $c['identity']['preferences']['appearance']['theme']='dark';
ob_start(); portal_render_dashboard($c,$summary,[]); $html=ob_get_clean();
if (is_string($out) && is_dir($out)) file_put_contents($out.'/settings-admin-dark.html',$html);
foreach (['light','dark'] as $theme) {
    $_SERVER['REQUEST_URI']='/portal/';
    $c=$context; $c['identity']['preferences']['appearance']['theme']=$theme;
    ob_start(); portal_render_workspace($c); $html=ob_get_clean();
    if (is_string($out) && is_dir($out)) file_put_contents($out.'/settings-workspace-'.$theme.'.html',$html);
}
settings_check(str_contains($html,'href="/portal/?view=westy" aria-current="page"') && !str_contains($html,'href="/portal/" aria-current')
    && str_contains($html,'id="portal-chat-home-slot"') && str_contains($html,'data-workspace="1"'),'Westy is current because the workspace rendered, even at /portal/');

// Home: one person's overview from the controller's requester-safe reads. Synthetic records only.
$_SERVER['REQUEST_URI']='/portal/';
$homeSummary = ['client'=>['name'=>'Synthetic Client'],'counts'=>['open'=>1,'in_progress'=>0,'waiting'=>3,'resolved'=>0],
    'tickets'=>[['id'=>124,'subject'=>'Synthetic waiting <request>','status'=>'waiting','priority'=>'high','updated_at'=>'2026-10-21 12:00:00'],
        ['id'=>123,'subject'=>'Synthetic request','status'=>'open','priority'=>'normal','updated_at'=>'2026-10-21 11:00:00']]];
$homeDevices = ['items'=>[['reference'=>'1:'.str_repeat('a',64),'label'=>'Front desk <computer>','platform'=>'Windows 11','connection'=>'reporting',
    'connection_label'=>'Connected and reporting','connection_help'=>'Fresh check-in.','last_seen_at'=>'2026-10-21T12:00:00Z','troubleshooting'=>'support_request']],'next_after'=>null];
$home = static function (array $c, ?array $summary, ?array $devices, ?array $archives = []): string {
    ob_start(); portal_render_home($c, $summary, $devices, $archives); return (string)ob_get_clean();
};
foreach (['client_owner','client_admin','client_staff','client_viewer'] as $role) {
    $c = $context; $c['identity']['role'] = $role;
    $admin = in_array($role, ['client_owner','client_admin'], true);
    $writer = $role !== 'client_viewer';
    $html = $home($c, $homeSummary, $homeDevices);
    settings_check(str_contains($html,'<title>Home · Safeharbor</title>') && str_contains($html,'href="/portal/" aria-current="page"')
        && !str_contains($html,'href="/portal/?view=westy" aria-current'), $role.' Home is the current destination');
    settings_check(is_int(strpos($html,'<span>Home</span>')) && strpos($html,'<span>Home</span>') < (int)strpos($html,'<span>Westy</span>'), $role.' navigation starts at Home');
    settings_check(str_contains($html,'class="portal-overview-action is-primary" href="/portal/?view=westy"'), $role.' Ask Westy opens the unchanged workspace route');
    settings_check(str_contains($html,'>Write a request</strong>') === $writer && str_contains($html,'>Needs your reply</h2>') === $writer, $role.' write and reply wording follows the ticket-write role');
    settings_check(str_contains($html,'>My settings in 8 West ID</strong>') && substr_count($html,'href="https://id.8westit.com/settings.php"') === 2, $role.' own settings use the one fixed 8 West ID destination');
    settings_check(str_contains($html,'href="/portal/reports.php"') === $admin && str_contains($html,'href="https://id.8westit.com/customer-billing.php"') === $admin
        && str_contains($html,'Manage computer access') === $admin && str_contains($html,'>Service summaries</h2>') === $admin, $role.' summaries, billing and client management stay owner/admin only');
    settings_check(str_contains($html, $admin ? 'Client-wide view.' : 'Requests you opened.') && str_contains($html, $admin ? 'every support request and computer for Synthetic Client' : 'assigned to you'), $role.' request and computer scope is stated');
    settings_check(str_contains($html,'Showing 1 of 3.') && str_contains($html,'Home shows 2 of 4.'), $role.' counts come from the summary, not from the shortened list');
    settings_check(str_contains($html,'Synthetic waiting &lt;request&gt;') && str_contains($html,'Front desk &lt;computer&gt;') && !str_contains($html,'<computer>'), $role.' records are escaped');
    settings_check(preg_match('/(?:Warning:|Fatal error:|Notice:|Deprecated:)/',$html) !== 1, $role.' Home has no PHP diagnostics');
    if (is_string($out) && is_dir($out)) file_put_contents($out.'/settings-home-'.$role.'-light.html',$html);
}
$c=$context; $c['identity']['role']='admin'; $c['identity']['customer_access']=['role'=>'client_staff'];
$html = $home($c, $homeSummary, $homeDevices);
settings_check(!str_contains($html,'customer-billing.php') && !str_contains($html,'href="/portal/reports.php"') && str_contains($html,'Requests you opened.') && str_contains($html,'>Member</strong>'),
    'delegated member Home uses the selected client role, never the home administrator role');
$c=$context; $c['identity']['role']='client_staff';
$html = $home($c, null, null, null);
settings_check(str_contains($html,'We can’t show your support requests right now.') && str_contains($html,'We can’t show your computers right now.')
    && str_contains($html,'>Open Your devices</a>') && !str_contains($html,'No support requests yet.') && !str_contains($html,'No computers are assigned'),
    'unavailable requests and computers are said so, never shown as none');
$html = $home($c, ['client'=>['name'=>'Synthetic Client'],'counts'=>['open'=>0,'in_progress'=>0,'waiting'=>0,'resolved'=>0],'tickets'=>[]], ['items'=>[],'next_after'=>null]);
settings_check(str_contains($html,'Nothing needs your reply right now.') && str_contains($html,'No support requests yet.') && str_contains($html,'No computers are assigned to you yet.'),
    'member empty states are helpful and honest');
if (is_string($out) && is_dir($out)) file_put_contents($out.'/settings-home-empty-dark.html',str_replace('data-theme="light"','data-theme="dark"',$html));
$c=$context; $c['identity']['role']='client_admin';
$html = $home($c, $homeSummary, ['items'=>array_fill(0,4,$homeDevices['items'][0]),'next_after'=>9], null);
settings_check(str_contains($html,'Service summaries are temporarily unavailable.') && str_contains($html,'More than 4 computers') && substr_count($html,'Front desk &lt;computer&gt;') === 3
    && str_contains($html,'>See all computers</a>'), 'admin Home keeps summary failures and partial computer pages honest');
echo 'Portal settings: '.$checks." checks passed\n";
ob_end_flush();
