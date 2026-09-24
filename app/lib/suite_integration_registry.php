<?php
declare(strict_types=1);

/** Read one bounded, root-owned, short-lived integration registration. */
function suite_integration_read(string $directory, string $id): ?array
{
    if (preg_match('/\A(?:[1-9][0-9]*|[0-9a-f]{64})\z/D', $id) !== 1) {
        throw new RuntimeException('Invalid integration identity.');
    }
    $path = $directory.'/'.$id.'.json';
    clearstatcache(true, $path);
    if (!file_exists($path)) return null;
    $parent = lstat($directory);
    $info = lstat($path);
    if ($parent === false || $parent['uid'] !== 0 || ($parent['mode'] & 0022) !== 0
        || ($parent['mode'] & 0170000) !== 0040000
        || $info === false || $info['uid'] !== 0 || ($info['mode'] & 0022) !== 0
        || ($info['mode'] & 0170000) !== 0100000 || $info['size'] > 1048576) {
        throw new RuntimeException('Untrusted integration registration.');
    }
    $raw = file_get_contents($path, false, null, 0, 1048577);
    $row = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
    if (!is_array($row) || ($row['schema'] ?? null) !== '8west.integration.v1'
        || !is_int($row['lease_until'] ?? null) || $row['lease_until'] <= time()
        || $row['lease_until'] > time() + 300
        || !is_int($row['tenant_id'] ?? null) || $row['tenant_id'] < 1
        || !is_int($row['local_id'] ?? null) || $row['local_id'] < 1
        || !is_string($row['slug'] ?? null) || in_array($row['slug'], ['8west','internal'], true)
        || !is_string($row['owner_subject'] ?? null)
        || preg_match('/\At'.$row['tenant_id'].'u[1-9][0-9]*\z/D', $row['owner_subject']) !== 1
        || !is_array($row['customers'] ?? null) || !array_is_list($row['customers'])) {
        throw new RuntimeException('Integration registration is unavailable.');
    }
    foreach ($row['customers'] as $customer) {
        if (!is_string($customer)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $customer) !== 1
            || $customer === '4ebaeefa-b101-47f8-ac76-e49ab309d272') {
            throw new RuntimeException('Invalid integration customer.');
        }
    }
    return $row;
}

function suite_integration_for_key(string $directory, string $key): ?array
{
    if (preg_match('/\A[0-9a-f]{64}\z/D', $key) !== 1) return null;
    $row = suite_integration_read($directory, hash('sha256', $key));
    if ($row !== null && (!is_string($row['secret'] ?? null) || !hash_equals($row['secret'], $key))) {
        throw new RuntimeException('Integration credential differs.');
    }
    return $row;
}
