<?php
/** Versioned local provisioning transport. This is not an HTTP authentication mechanism. */
declare(strict_types=1);

function suite_workspace_manifest_validate(array $manifest, string $product): void
{
    $keys = array_keys($manifest); sort($keys);
    if ($keys !== ['name', 'owner', 'products', 'report_email', 'schema', 'slug', 'tenant_id']
        || $manifest['schema'] !== '8west.workspace.v1'
        || !is_int($manifest['tenant_id']) || $manifest['tenant_id'] < 1
        || !is_string($manifest['slug'])
        || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $manifest['slug']) !== 1
        || !is_string($manifest['name']) || trim($manifest['name']) !== $manifest['name']
        || $manifest['name'] === '' || mb_strlen($manifest['name']) > 128
        || preg_match('/[\x00-\x1f\x7f]/', $manifest['name'])
        || !is_string($manifest['report_email']) || !filter_var($manifest['report_email'], FILTER_VALIDATE_EMAIL)
        || !is_array($manifest['products']) || !array_is_list($manifest['products'])
        || !in_array($product, $manifest['products'], true)
        || !is_array($manifest['owner'])) {
        throw new InvalidArgumentException('invalid workspace');
    }
    $owner = $manifest['owner'];
    $ownerKeys = array_keys($owner); sort($ownerKeys);
    if ($ownerKeys !== ['email', 'id', 'name', 'role', 'subject']
        || !is_int($owner['id']) || $owner['id'] < 1
        || $owner['subject'] !== 't' . $manifest['tenant_id'] . 'u' . $owner['id']
        || !is_string($owner['email']) || !filter_var($owner['email'], FILTER_VALIDATE_EMAIL)
        || $owner['email'] !== mb_strtolower(trim($owner['email']))
        || !is_string($owner['name']) || $owner['name'] === '' || mb_strlen($owner['name']) > 190
        || preg_match('/[\x00-\x1f\x7f]/', $owner['name'])
        || !(($owner['role'] === 'msp_owner' && !in_array($manifest['slug'], ['8west', 'internal'], true))
            || ($owner['role'] === 'owner' && in_array($manifest['slug'], ['8west', 'internal'], true)))) {
        throw new InvalidArgumentException('invalid workspace owner');
    }
}

/** @return array{0:array,1:string,2:bool} */
function suite_workspace_manifest_read(string $product, array $argv): array
{
    if (PHP_SAPI !== 'cli' || !in_array($argv, [[$argv[0], '--apply'], [$argv[0], '--apply', '--adopt-house']], true)) {
        throw new InvalidArgumentException('local provisioning requires --apply');
    }
    $raw = trim((string)stream_get_contents(STDIN, 16385));
    if (strlen($raw) > 16384) throw new InvalidArgumentException('manifest too large');
    $manifest = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)) throw new InvalidArgumentException('manifest required');
    suite_workspace_manifest_validate($manifest, $product);
    $adopt = in_array('--adopt-house', $argv, true);
    if ($adopt && !in_array($manifest['slug'], ['8west', 'internal'], true)) {
        throw new InvalidArgumentException('house adoption cannot apply to a customer');
    }
    return [$manifest, hash('sha256', $raw), $adopt];
}

function suite_workspace_receipt(array $manifest, string $hash, string $product, array $requirements = [], ?string $localTenantKey = null): void
{
    $receipt = [
        'product' => $product, 'tenant_id' => $manifest['tenant_id'], 'manifest_sha256' => $hash,
        'status' => $requirements === [] ? 'ready' : 'waiting_for_input', 'requirements' => $requirements,
    ];
    if ($localTenantKey !== null) {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D', $localTenantKey) !== 1) {
            throw new InvalidArgumentException('invalid local workspace key');
        }
        $receipt['tenant_ai'] = ['contract' => 1, 'local_tenant_key' => $localTenantKey];
    }
    echo json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
