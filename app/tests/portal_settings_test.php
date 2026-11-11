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
settings_check(!str_contains($html,'>Client Admin</strong>') && str_contains($html,'aria-label="Switch client"')
    && !portal_employee_workspace_default($c),'delegated member keeps the chooser without inheriting client administration or the employee landing page');
$c=$context; $c['binding']['identity_tenant_slug']='other-client';
$c['identity']['role']='admin'; $c['identity']['customer_access']=['role'=>'client_admin'];
ob_start(); portal_shell_start($c); $html=ob_get_clean();
settings_check(str_contains($html,'href="https://id.8westit.com/admin.php?panel=client_admin"'),'freshly selected delegated client admin has fixed ID entry without home-role rewrite');
$c=$context; unset($c['settings_context']);
ob_start(); portal_shell_start($c); $html=ob_get_clean();
settings_check(str_contains($html,'>Client Admin</strong>') && str_contains($html,'>My settings</strong>'),'verified native client admin entry does not depend on optional home settings claim');
$c['identity']['role']='client_staff';
ob_start(); portal_shell_start($c); $html=ob_get_clean();
settings_check(!str_contains($html,'>Client Admin</strong>') && !str_contains($html,'aria-label="Switch client"')
    && portal_employee_workspace_default($c),'direct employees land in Westy without a client chooser or administration');
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
    'tickets'=>[['id'=>124,'subject'=>'Synthetic waiting <request>','status'=>'waiting','priority'=>'high','updated_at'=>'2026-10-21 12:00:00',
            'last_message_kind'=>'tech','last_message_at'=>gmdate('Y-m-d H:i:s',time()-600),'first_response_at'=>'2026-10-21 11:30:00','sla_due_at'=>'2026-10-21 19:00:00'],
        ['id'=>123,'subject'=>'Synthetic request','status'=>'open','priority'=>'normal','updated_at'=>'2026-10-21 11:00:00',
            'last_message_kind'=>'client','last_message_at'=>'2026-10-21 11:00:00','first_response_at'=>null,'sla_due_at'=>gmdate('Y-m-d H:i:s',time()+3*3600)]]];
$homeDevices = ['items'=>[['reference'=>'1:'.str_repeat('a',64),'label'=>'Front desk <computer>','platform'=>'Windows 11','connection'=>'reporting',
    'connection_label'=>'Connected and reporting','connection_help'=>'Fresh check-in.','last_seen_at'=>'2026-10-21T12:00:00Z','troubleshooting'=>'support_request']],'next_after'=>null];
$home = static function (array $c, ?array $summary, ?array $devices, ?array $archives = []): string {
    ob_start(); portal_render_home($c, $summary, $devices, $archives); return (string)ob_get_clean();
};
foreach (['client_owner','client_admin','client_viewer'] as $role) {
    $c = $context; $c['identity']['role'] = $role;
    $admin = in_array($role, ['client_owner','client_admin'], true);
    $writer = $role !== 'client_viewer';
    $html = $home($c, $homeSummary, $homeDevices);
    settings_check(str_contains($html,'<title>Home · Safeharbor</title>') && str_contains($html,'href="/portal/" aria-current="page"')
        && !str_contains($html,'href="/portal/?view=westy" aria-current'), $role.' Home is the current destination');
    settings_check(str_contains($html,'<span>Home</span>'), $role.' navigation offers Home');
    settings_check(str_contains($html,'class="portal-overview-action is-primary" href="/portal/?view=westy"'), $role.' Ask Westy opens the unchanged workspace route');
    settings_check(substr_count($html,'href="/portal/new.php"') === ($writer ? 1 : 0)
        && str_contains($html,'<a class="portal-overview-action" href="/portal/new.php">New support request</a>') === $writer,
        $role.' Home offers New support request to exactly the roles that may create one, at the Support Requests destination');
    settings_check(str_contains($html,'>Needs your reply</h2>') === $writer && str_contains($html,'>Waiting for a reply</h2>') === !$writer
        && str_contains($html,'href="/portal/ticket.php?id=124#portal-reply"') === $writer, $role.' reply wording and the Reply button follow who may reply');
    settings_check(substr_count($html,'class="portal-overview-action is-primary"') === 1
        && !str_contains(substr($html, (int)strpos($html,'<main'), (int)strpos($html,'</main>') - (int)strpos($html,'<main')), 'btn-primary'), $role.' Home has one filled button, Ask Westy');
    settings_check(str_contains($html,'<h1 id="portal-home-heading">Hello, Synthetic</h1>') && str_contains($html,'How can we help Synthetic Client today?'), $role.' hero greets by first name and asks the one question');
    settings_check(!str_contains($html,'My settings in 8 West ID') && substr_count($html,'href="https://id.8westit.com/settings.php"') === 1, $role.' own settings stay in the sidebar, at the one fixed 8 West ID destination');
    settings_check(str_contains($html,'We replied 10 minutes ago and ' . ($writer ? 'need your answer.' : 'are waiting for an answer.'))
        && (bool)preg_match('/We received this\. We aim to reply by (today|tomorrow) at \d/', $html), $role.' each request says what is happening in one sentence');
    settings_check(str_contains($html,'<span class="portal-status portal-status-open">Received</span>')
        && str_contains($html,'<span class="portal-status portal-status-waiting">' . ($writer ? 'Waiting for your reply' : 'Waiting for a reply') . '</span>'), $role.' list tags read Received, and waiting is worded for who may reply');
    settings_check(!str_contains($html,'portal-counts') && !str_contains($html,'Active requests come first') && !str_contains($html,'#124'), $role.' Home has no counts strip, no ordering hint and no request numbers');
    settings_check(!str_contains($html,'href="/portal/reports.php"') && str_contains($html,'href="https://id.8westit.com/customer-billing.php"') === $admin
        && str_contains($html,'Manage computer access') === $admin, $role.' Home keeps authorized management and groups summaries under Support Requests');
    settings_check(str_contains($html, $admin ? 'You see every request for Synthetic Client.' : 'You see the requests opened under your account.')
        && !str_contains(substr($html, (int)strpos($html,'class="portal-overview-hero"'), 900), portal_role_label($role)), $role.' request scope is one quiet line under the list, and the role is not in the hero');
    settings_check(str_contains($html,'Showing 1 of 3.') && str_contains($html,'Home shows 2 of 4.'), $role.' counts come from the summary, not from the shortened list');
    settings_check(str_contains($html,'Synthetic waiting &lt;request&gt;') && str_contains($html,'Front desk &lt;computer&gt;') && !str_contains($html,'<computer>'), $role.' records are escaped');
    settings_check(preg_match('/(?:Warning:|Fatal error:|Notice:|Deprecated:)/',$html) !== 1, $role.' Home has no PHP diagnostics');
    if (is_string($out) && is_dir($out)) file_put_contents($out.'/settings-home-'.$role.'-light.html',$html);
}
$c=$context; $c['identity']['role']='admin'; $c['identity']['customer_access']=['role'=>'client_staff'];
$html = $home($c, $homeSummary, $homeDevices);
settings_check(!str_contains($html,'customer-billing.php') && !str_contains($html,'href="/portal/reports.php"') && str_contains($html,'You see the requests you opened.')
    && str_contains($html,'<a class="portal-overview-action" href="/portal/new.php">New support request</a>') && str_contains($html,'href="/portal/ticket.php?id=124#portal-reply"'),
    'delegated member Home uses the selected client role, never the home administrator role');
$c=$context; $c['identity']['role']='client_staff';
$html = $home($c, null, null, null);
settings_check(str_contains($html,'We can’t show your support requests right now.') && str_contains($html,'We can’t show your computers right now.')
    && str_contains($html,'>Open Your devices</a>') && !str_contains($html,'No support requests yet.') && !str_contains($html,'No computers are assigned'),
    'unavailable requests and computers are said so, never shown as none');
$c['identity']['role']='client_viewer';
$html = $home($c, ['client'=>['name'=>'Synthetic Client'],'counts'=>['open'=>0,'in_progress'=>0,'waiting'=>0,'resolved'=>0],'tickets'=>[]], ['items'=>[],'next_after'=>null]);
settings_check(!str_contains($html,'home-reply-heading') && str_contains($html,'No support requests yet.') && str_contains($html,'Requests opened under your account will appear here.')
    && str_contains($html,'No computers are assigned to you yet.') && !str_contains($html,'href="/portal/new.php"'),
    'viewer empty states are helpful and honest, with no empty reply section and no create action');
if (is_string($out) && is_dir($out)) file_put_contents($out.'/settings-home-empty-dark.html',str_replace('data-theme="light"','data-theme="dark"',$html));
$c=$context; $c['identity']['role']='tech'; $c['identity']['customer_access']=['role'=>'client_admin'];
$html = $home($c, $homeSummary, ['items'=>array_fill(0,4,$homeDevices['items'][0]),'next_after'=>9], null);
settings_check(str_contains($html,'Service summaries are temporarily unavailable.') && str_contains($html,'More than 4 computers') && substr_count($html,'Front desk &lt;computer&gt;') === 3
    && str_contains($html,'>See all computers</a>'), 'admin Home keeps summary failures and partial computer pages honest');
echo 'Portal settings: '.$checks." checks passed\n";
ob_end_flush();
