<?php
/**
 * Explicit customer-portal mapping operator.
 *
 * Prepare always creates a disabled binding. Inspect its exact identity slug,
 * provider tenant, and client before a separate enable command. No command
 * accepts email, domain, name, source_key, or an identity numeric tenant claim.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit('CLI only.');

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/portal_data.php';

function portal_cli_usage(): never
{
    $script = 'php db/manage_portal_client.php';
    fwrite(STDERR, <<<TEXT
Usage:
  {$script} prepare --identity-tenant=slug --provider-tenant-id=N --client-id=N --actor-user-id=N --reason="..."
  {$script} inspect --binding-id=N --identity-tenant=slug --provider-tenant-id=N --client-id=N
  {$script} enable  --binding-id=N --identity-tenant=slug --provider-tenant-id=N --client-id=N --actor-user-id=N --reason="..."
  {$script} disable --binding-id=N --identity-tenant=slug --provider-tenant-id=N --client-id=N --actor-user-id=N --reason="..."

Prepare is always disabled. Inspect, then explicitly enable. The global
portal.enabled switch remains separate and dark by default.
TEXT);
    exit(2);
}

/** @return array<string,string> */
function portal_cli_options(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (! is_string($argument)
            || preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/D', $argument, $match) !== 1
            || array_key_exists($match[1], $options)) {
            portal_cli_usage();
        }
        $options[$match[1]] = $match[2];
    }
    return $options;
}

function portal_cli_positive_id(array $options, string $name): int
{
    $raw = $options[$name] ?? '';
    if (! is_string($raw) || preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
        throw new PortalDataValidationException("--{$name} must be a positive integer.");
    }
    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (! is_int($value)) {
        throw new PortalDataValidationException("--{$name} is outside the supported integer range.");
    }
    return $value;
}

/** @param list<string> $required */
function portal_cli_require_exact_options(array $options, array $required): void
{
    $provided = array_keys($options);
    sort($provided);
    $expected = $required;
    sort($expected);
    if ($provided !== $expected) portal_cli_usage();
}

/** @param array<string,mixed> $binding */
function portal_cli_output(string $phase, array $binding, array $events): never
{
    echo json_encode([
        'phase' => $phase,
        'binding' => [
            'id' => (int)$binding['id'],
            'identity_tenant_slug' => (string)$binding['identity_tenant_slug'],
            'provider_tenant_id' => (int)$binding['tenant_id'],
            'provider_tenant_slug' => (string)$binding['provider_tenant_slug'],
            'client_id' => (int)$binding['client_id'],
            'client_name' => (string)$binding['client_name'],
            'status' => (string)$binding['status'],
            'prepared_by_user_id' => (int)$binding['prepared_by_user_id'],
            'prepared_at' => (string)$binding['prepared_at'],
            'last_changed_by_user_id' => (int)$binding['last_changed_by_user_id'],
            'status_changed_at' => (string)$binding['status_changed_at'],
            'status_reason' => (string)$binding['status_reason'],
        ],
        'events' => $events,
        'global_portal_enabled' => cfg('portal.enabled', false) === true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$command = $argv[1] ?? '';
if (! is_string($command) || ! in_array($command, ['prepare', 'inspect', 'enable', 'disable'], true)) {
    portal_cli_usage();
}
$options = portal_cli_options(array_slice($argv, 2));

try {
    if ($command === 'prepare') {
        portal_cli_require_exact_options($options, [
            'identity-tenant', 'provider-tenant-id', 'client-id', 'actor-user-id', 'reason',
        ]);
        $binding = portal_prepare_binding(
            db(),
            $options['identity-tenant'],
            portal_cli_positive_id($options, 'provider-tenant-id'),
            portal_cli_positive_id($options, 'client-id'),
            portal_cli_positive_id($options, 'actor-user-id'),
            $options['reason'],
        );
        portal_cli_output(
            'prepared_disabled',
            $binding,
            portal_binding_events(db(), (int)$binding['id'], (int)$binding['tenant_id'], (int)$binding['client_id']),
        );
    }

    $required = ['binding-id', 'identity-tenant', 'provider-tenant-id', 'client-id'];
    if ($command !== 'inspect') array_push($required, 'actor-user-id', 'reason');
    portal_cli_require_exact_options($options, $required);
    $bindingId = portal_cli_positive_id($options, 'binding-id');
    $tenantId = portal_cli_positive_id($options, 'provider-tenant-id');
    $clientId = portal_cli_positive_id($options, 'client-id');
    $slug = $options['identity-tenant'];

    if ($command === 'inspect') {
        $binding = portal_inspect_binding(db(), $bindingId, $slug, $tenantId, $clientId);
        portal_cli_output(
            'inspected',
            $binding,
            portal_binding_events(db(), $bindingId, $tenantId, $clientId),
        );
    }

    $binding = portal_transition_binding(
        db(),
        $bindingId,
        $slug,
        $tenantId,
        $clientId,
        portal_cli_positive_id($options, 'actor-user-id'),
        $command === 'enable' ? 'active' : 'disabled',
        $options['reason'],
    );
    portal_cli_output(
        $command === 'enable' ? 'enabled' : 'disabled',
        $binding,
        portal_binding_events(db(), $bindingId, $tenantId, $clientId),
    );
} catch (PortalDataException $error) {
    fwrite(STDERR, 'Portal mapping refused: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    error_log('[safeharbor-portal-cli] failed type=' . $error::class);
    fwrite(STDERR, "Portal mapping failed. Inspect server logs; no change should be assumed.\n");
    exit(1);
}
