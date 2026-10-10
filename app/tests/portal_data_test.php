<?php
/** Hermetic tenant-boundary, scope, and rendered-output portal tests. */
declare(strict_types=1);

$PORTAL_DATA_TEST_CONFIG = [
    'portal' => ['reserved_identity_tenant_slugs' => []],
];

function cfg(string $key, mixed $default = null): mixed
{
    global $PORTAL_DATA_TEST_CONFIG;
    $value = $PORTAL_DATA_TEST_CONFIG;
    foreach (explode('.', $key) as $part) {
        if (! is_array($value) || ! array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

function portal_csrf_token(): string
{
    return str_repeat('c', 64);
}

require_once __DIR__ . '/../lib/portal_data.php';
require_once __DIR__ . '/../lib/portal_render.php';

$checks = 0;
$failures = 0;

function portal_data_check(string $name, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "ok {$checks} - {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL {$checks} - {$name}\n";
}

function portal_data_expect(string $name, string $class, callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        portal_data_check($name, $error instanceof $class);
        return;
    }
    portal_data_check($name, false);
}

portal_data_check('lowercase ASCII tenant slug validates',
    portal_identity_tenant_slug('client-42') === 'client-42');
portal_data_expect('input is not silently normalized', PortalDataValidationException::class,
    fn() => portal_identity_tenant_slug(' client-42 '));
portal_data_expect('blank slug is refused', PortalDataValidationException::class,
    fn() => portal_identity_tenant_slug(''));
portal_data_expect('8west slug is fixed-reserved', PortalDataValidationException::class,
    fn() => portal_identity_tenant_slug('8west'));
portal_data_expect('internal slug is fixed-reserved', PortalDataValidationException::class,
    fn() => portal_identity_tenant_slug('internal'));
portal_data_check('future configured reservation extends but does not replace fixed set',
    portal_reserved_identity_tenant_slugs(['future']) === ['8west', 'internal', 'future']);
portal_data_expect('malformed configured reservation fails closed', PortalDataValidationException::class,
    fn() => portal_reserved_identity_tenant_slugs(['Future']));
portal_data_check('operator transition reason is trimmed',
    portal_transition_reason('  explicitly reviewed  ') === 'explicitly reviewed');
portal_data_expect('blank operator reason is refused', PortalDataValidationException::class,
    fn() => portal_transition_reason('   '));
portal_data_expect('oversized operator reason is refused', PortalDataValidationException::class,
    fn() => portal_transition_reason(str_repeat('r', 501)));

$dataSource = file_get_contents(__DIR__ . '/../lib/portal_data.php');
$summaryStart = is_string($dataSource) ? strpos($dataSource, 'function portal_ticket_summary') : false;
$summaryEnd = $summaryStart === false ? false : strpos($dataSource, 'function portal_report_archive_select_sql', $summaryStart);
$summarySource = ($summaryStart === false || $summaryEnd === false)
    ? ''
    : substr($dataSource, $summaryStart, $summaryEnd - $summaryStart);
portal_data_check('ticket summary has exactly two top-level read-only ticket queries',
    substr_count($summarySource, 'FROM tickets t') === 2);
portal_data_check('every ticket-summary query binds provider tenant and client',
    substr_count($summarySource, 'tenant_id = :tenant_id') >= 3
    && substr_count($summarySource, 't.client_id = :client_id') === 2
    && str_contains($summarySource, 'AND id = :client_id'));
portal_data_check('ticket summary excludes merged stubs',
    substr_count($summarySource, 'merged_into_id IS NULL') === 2);
portal_data_check('ticket summary fails closed on every merged survivor',
    substr_count($summarySource, 'FROM tickets merged_source') === 2
    && substr_count($summarySource, 'merged_source.merged_into_id = t.id') === 2);
portal_data_check('ticket summary reads no message, attachment, time, AI, or billing tables',
    preg_match('/\b(?:messages|attachments|time_entries|assistant_log|invoices?|payments?)\b/i', $summarySource) !== 1);
portal_data_check('ticket summary selects a closed summary field list',
    str_contains($summarySource, 'SELECT t.id, t.subject, t.status, t.priority, t.created_at, t.updated_at, t.resolved_at')
    && !str_contains($summarySource, 'SELECT *'));
portal_data_check('ticket summary keeps waiting and active work ahead of resolved history',
    str_contains($summarySource, "WHEN 'waiting' THEN 0")
    && str_contains($summarySource, "WHEN 'in_progress' THEN 1")
    && str_contains($summarySource, "WHEN 'open' THEN 2")
    && str_contains($summarySource, "WHEN 'resolved' THEN 3"));

$recheckStart = is_string($dataSource) ? strpos($dataSource, 'function portal_active_binding_recheck') : false;
$recheckEnd = $recheckStart === false ? false : strpos($dataSource, 'function portal_inspect_binding', $recheckStart);
$recheckSource = ($recheckStart === false || $recheckEnd === false)
    ? ''
    : substr($dataSource, $recheckStart, $recheckEnd - $recheckStart);
portal_data_check('active binding is rechecked against exact slug, tenant, client, and id',
    str_contains($recheckSource, 'b.id = :binding_id')
    && str_contains($recheckSource, 'b.identity_tenant_slug = :identity_tenant_slug')
    && str_contains($recheckSource, 'b.tenant_id = :tenant_id')
    && str_contains($recheckSource, 'b.client_id = :client_id')
    && str_contains($recheckSource, "b.status = 'active'"));

$prepareStart = is_string($dataSource) ? strpos($dataSource, 'function portal_prepare_binding') : false;
$prepareEnd = $prepareStart === false ? false : strpos($dataSource, 'function portal_transition_binding', $prepareStart);
$prepareSource = ($prepareStart === false || $prepareEnd === false)
    ? ''
    : substr($dataSource, $prepareStart, $prepareEnd - $prepareStart);
portal_data_check('native PDO prepare uses distinct actor placeholders',
    str_contains($prepareSource, ':prepared_by_user_id')
    && str_contains($prepareSource, ':last_changed_by_user_id')
    && !str_contains($prepareSource, ':actor_user_id, :actor_user_id'));

$publicSources = '';
foreach (glob(__DIR__ . '/../public/portal/*.php') ?: [] as $file) {
    $source = file_get_contents($file);
    if (is_string($source)) $publicSources .= "\n" . $source;
}
portal_data_check('customer-facing portal endpoints contain no raw SQL mutations',
    preg_match('/->\s*(?:exec|query|prepare)\s*\(/i', $publicSources) !== 1);
portal_data_check('portal mutation routes delegate to tenant-bound workflow functions',
    str_contains($publicSources, 'portal_create_ticket(')
    && str_contains($publicSources, 'portal_reply_to_ticket(')
    && str_contains($publicSources, 'portal_devices_request(')
    && preg_match('#/(?:api|attachment|westy|invoice|payment|device|endpoint)[A-Za-z0-9_./-]*#i', str_replace(['https://developers.openai.com/api/docs/guides/your-data','/portal/devices.php','/portal/device_help.php'],'',$publicSources)) !== 1);
portal_data_check('only customer owner, admin, and staff roles can write tickets',
    portal_role_can_write_tickets('client_owner')
    && portal_role_can_write_tickets('client_admin')
    && portal_role_can_write_tickets('client_staff')
    && ! portal_role_can_write_tickets('client_viewer')
    && ! portal_role_can_write_tickets('msp_owner'));

$callbackSource = file_get_contents(__DIR__ . '/../public/portal/callback.php');
portal_data_check('callback creates no tenant, client, or user records',
    is_string($callbackSource)
    && preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $callbackSource) !== 1
    && str_contains($callbackSource, 'portal_establish_identity'));

$cliSource = file_get_contents(__DIR__ . '/../db/manage_portal_client.php');
portal_data_check('operator lifecycle is prepare, inspect, enable, disable only',
    is_string($cliSource)
    && str_contains($cliSource, "['prepare', 'inspect', 'enable', 'disable']")
    && str_contains($cliSource, "'prepared_disabled'"));
portal_data_check('operator requires exact provider tenant and client ids',
    is_string($cliSource)
    && substr_count($cliSource, "'provider-tenant-id'") >= 3
    && substr_count($cliSource, "'client-id'") >= 3);

$migration = file_get_contents(__DIR__ . '/../db/migrations/012_customer_portal.sql');
$schema = file_get_contents(__DIR__ . '/../db/schema.sql');
foreach (['migration' => $migration, 'fresh schema' => $schema] as $sourceName => $source) {
    portal_data_check("{$sourceName} has globally unique identity slug",
        is_string($source) && str_contains($source, 'uq_customer_portal_identity_slug'));
    portal_data_check("{$sourceName} has one exact provider tenant/client binding",
        is_string($source) && str_contains($source, 'uq_customer_portal_client (tenant_id, client_id)'));
    portal_data_check("{$sourceName} uses composite provider tenant/client FK",
        is_string($source)
        && str_contains($source, 'FOREIGN KEY (tenant_id, client_id)')
        && str_contains($source, 'REFERENCES clients (tenant_id, id)'));
    portal_data_check("{$sourceName} preserves binding and event history",
        is_string($source)
        && str_contains($source, 'Customer portal bindings cannot be deleted')
        && str_contains($source, 'Customer portal binding events are immutable'));
    portal_data_check("{$sourceName} prepares bindings disabled",
        is_string($source)
        && str_contains($source, "status                   ENUM('disabled','active') NOT NULL DEFAULT 'disabled'")
        && str_contains($source, "NEW.status <> 'disabled'"));
}
if (is_string($migration) && is_string($schema)) {
    $tablesStart = strpos($migration, 'CREATE TABLE IF NOT EXISTS customer_portal_bindings');
    $tablesEnd = strpos($migration, '-- Refuse to treat', (int)$tablesStart);
    $triggersStart = strpos($migration, '-- Prove TRIGGER privilege', (int)$tablesEnd);
    $tableContract = ($tablesStart !== false && $tablesEnd !== false)
        ? trim(substr($migration, $tablesStart, $tablesEnd - $tablesStart))
        : '';
    $triggerContract = $triggersStart !== false ? trim(substr($migration, $triggersStart)) : '';
    portal_data_check('fresh schema carries the exact migration 012 tables',
        $tableContract !== '' && str_contains($schema, $tableContract));
    portal_data_check('fresh schema carries the exact migration 012 trigger contract',
        $triggerContract !== '' && str_contains($schema, $triggerContract));
} else {
    portal_data_check('fresh schema carries the exact migration 012 tables', false);
    portal_data_check('fresh schema carries the exact migration 012 trigger contract', false);
}

$context = [
    'identity' => [
        'display_name' => '<script>alert(1)</script>',
        'role' => 'client_staff',
    ],
    'binding' => [],
];
$summary = [
    'client' => ['name' => 'Acme <img src=x onerror=alert(1)>'],
    'counts' => ['open' => 1, 'in_progress' => 0, 'waiting' => 0, 'resolved' => 0],
    'tickets' => [[
        'id' => 42,
        'subject' => '<script>ticket</script>',
        'status' => 'open',
        'priority' => 'high',
        'created_at' => '2026-08-25 12:00:00',
        'updated_at' => '2026-08-26 12:00:00',
        'resolved_at' => null,
    ]],
];
ob_start();
portal_render_dashboard($context, $summary);
$rendered = (string)ob_get_clean();
portal_data_check('rendered tenant, identity, and subject text is escaped',
    !str_contains($rendered, '<script>alert(1)</script>')
    && !str_contains($rendered, '<script>ticket</script>')
    && !str_contains($rendered, '<img src=x onerror=alert(1)>')
    && str_contains($rendered, '&lt;script&gt;ticket&lt;/script&gt;'));
portal_data_check('rendered ticket links only to the customer portal detail route',
    str_contains($rendered, '#42')
    && str_contains($rendered, '/portal/ticket.php?id=42')
    && !str_contains($rendered, 'href="/ticket.php'));
portal_data_check('rendered portal has sign-out and the private chat composer',
    substr_count($rendered, '<form') === 2
    && str_contains($rendered, 'id="portal-chat-form"')
    && str_contains($rendered, 'action="/portal/logout.php"')
    && str_contains($rendered, 'name="csrf"'));
portal_data_check('writer dashboard offers a customer-scoped help action',
    str_contains($rendered, 'href="/portal/new.php"')
    && str_contains($rendered, 'Open support request'));
portal_data_check('dashboard separates open, waiting, and recently resolved work',
    str_contains($rendered, 'Business support requests')
    && str_contains($rendered, 'Needs your attention')
    && str_contains($rendered, 'Resolved')
    && str_contains($rendered, 'Waiting on you'));
portal_data_check('dashboard links only to verified portal report pages',
    str_contains($rendered, 'Service summaries')
    && !str_contains($rendered, 'href="/reports.php')
    && !str_contains($rendered, 'recipient_email'));
preg_match_all('/<script\b[^>]*>.*?<\/script\s*>/is', $rendered, $renderedScripts);
portal_data_check('rendered portal is dark and loads only its exact customer chat, clock and desktop scripts',
    str_contains($rendered, '<html lang="en" data-theme="dark">')
    && preg_match_all('/<script\b/i', $rendered) === 3
    && $renderedScripts[0] === [
        '<script src="/assets/js/portal-desktop.js?v='.substr(hash_file('sha256',__DIR__.'/../public/assets/js/portal-desktop.js'),0,20).'" defer></script>',
        '<script src="/assets/js/portal-computer-time.js?v='.substr(hash_file('sha256',__DIR__.'/../public/assets/js/portal-computer-time.js'),0,20).'" defer></script>',
        '<script src="/assets/js/portal-westy.js?v='.substr(hash_file('sha256',__DIR__.'/../public/assets/js/portal-westy.js'),0,20).'" defer></script>',
    ]);

$asset=tempnam(sys_get_temp_dir(),'portal-cache-');
try{
    file_put_contents($asset,'first script');touch($asset,1700000000);$firstVersion=portal_asset_version($asset);
    file_put_contents($asset,'other script');touch($asset,1700000000);$secondVersion=portal_asset_version($asset);
    portal_data_check('same-size asset replacement with preserved timestamp changes the cache version',$firstVersion!==$secondVersion);
    portal_data_check('unchanged bytes retain a stable cache version',$secondVersion===portal_asset_version($asset));
}finally{unlink($asset);}

echo "Portal data: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
