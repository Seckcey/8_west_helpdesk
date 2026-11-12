<?php
/** CLI-only synthetic page for browser checks and inert previews; no application settings or network. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
function portal_csrf_token(): string { return 'synthetic-display-only'; }
require_once __DIR__.'/../../lib/portal_data.php';
require_once __DIR__.'/../../lib/portal_render.php';
require_once __DIR__.'/../portal_request_paging_cases.php';
parse_str($argv[1] ?? '', $query);
$role = $argv[2] ?? 'client_staff';
$theme = $argv[3] ?? 'light';
$_SERVER['REQUEST_URI'] = '/portal/requests.php';
$context = [
    'identity'=>['tenant_id'=>1,'client_id'=>11,'subject'=>'t9u51','identity_tenant_slug'=>'example-client',
        'role'=>$role,'display_name'=>'Example member','theme'=>$theme],
    'binding'=>['client_name'=>'Example client'],
];
try {
    $summary = portal_ticket_summary(portal_paging_sqlite(),1,11,50,$context['identity'],portal_request_list_options($query));
    portal_render_dashboard($context,$summary,[]);
} catch (PortalDataValidationException $error) {
    portal_render_error(400,'Check the request filters',$error->getMessage());
}
