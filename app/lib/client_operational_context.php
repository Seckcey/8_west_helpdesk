<?php
/** Last accepted shared metadata; this does not claim current Milepost state. */
declare(strict_types=1);

function client_operational_edit_url(string $customerId, int $version): ?string
{
    if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $customerId) !== 1
        || $version < 1 || $version > 1024) return null;
    return 'https://support.8westit.com/suite_customer_metadata.php?'
        . http_build_query(['customer_id' => $customerId, 'expected_version' => $version], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Milepost's provider + customer UUID device route for one shared client. The provider is the
 * binding tenant's own slug (the receiver binds only an exactly matching tenant); Milepost still
 * checks the signed-in user's tenant before showing anything.
 */
function client_operational_device_url(string $providerSlug, string $customerId): ?string
{
    if (preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $providerSlug) !== 1
        || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $customerId) !== 1) {
        return null;
    }
    return 'https://support.8westit.com/client.php?'
        . http_build_query(['provider' => $providerSlug, 'customer_id' => $customerId], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Coastmark's provider + customer UUID billing route for one shared client. Coastmark checks the
 * signed-in user's organization and its own audited binding before showing anything, and keeps
 * retained accounting history reachable whatever the shared active/inactive state.
 */
function client_operational_billing_url(string $providerSlug, string $customerId): ?string
{
    if (preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $providerSlug) !== 1
        || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $customerId) !== 1) {
        return null;
    }
    return 'https://coastmark.8westit.com/clients?'
        . http_build_query(['provider' => $providerSlug, 'customer_id' => $customerId], '', '&', PHP_QUERY_RFC3986);
}

/** This tenant's own slug, or null; only the device and billing links and the directory read depend on it. */
function client_operational_provider_slug(PDO $pdo, int $tenantId): ?string
{
    try {
        $query = $pdo->prepare('SELECT slug FROM tenants WHERE id = ?');
        $query->execute([$tenantId]);
        $slug = $query->fetchColumn();
        return is_string($slug) && $slug !== '' ? $slug : null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Shared metadata for every bound client of one tenant, keyed by local client id, using the same
 * receipt agreement as client_operational_context(). Unbound clients are absent from the map.
 * Null means the lookup failed, which proves nothing about any client's binding.
 *
 * @return array<int,array<string,mixed>>|null
 */
function client_operational_directory(PDO $pdo, int $tenantId): ?array
{
    if ($tenantId < 1) return null;
    try {
        $query = $pdo->prepare(
            'SELECT binding.*, receipt.id AS receipt_id,
                    receipt.binding_id AS receipt_binding, receipt.tenant_id AS receipt_tenant,
                    receipt.client_id AS receipt_client, receipt.customer_id AS receipt_customer,
                    receipt.source_version AS receipt_version, receipt.display_name AS receipt_name,
                    receipt.status AS receipt_status, receipt.occurred_at AS receipt_time
               FROM suite_customer_sync_bindings binding
               LEFT JOIN suite_customer_sync_events receipt ON receipt.event_id = binding.last_event_id
              WHERE binding.tenant_id = ?
              ORDER BY binding.client_id'
        );
        $query->execute([$tenantId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    $provider = client_operational_provider_slug($pdo, $tenantId);
    $directory = [];
    foreach ($rows as $binding) {
        if (!is_array($binding)) continue;
        $clientId = (int)($binding['client_id'] ?? 0);
        if ($clientId < 1) continue;
        $directory[$clientId] = client_operational_from_binding($binding, $tenantId, $clientId, $provider);
    }
    return $directory;
}

/**
 * One Clients-list row, in client_operational_context()'s shape. Only a successful lookup that
 * found no binding is unbound (false); a failed lookup leaves the binding unknown (null).
 *
 * @param array<int,array<string,mixed>>|null $directory
 * @return array<string,mixed>
 */
function client_operational_directory_entry(?array $directory, int $clientId): array
{
    if ($directory === null) return ['bound' => null, 'available' => false];
    return $directory[$clientId] ?? ['bound' => false, 'available' => false];
}

/** @return array<string,mixed> */
function client_operational_context(PDO $pdo, int $tenantId, int $clientId): array
{
    $unavailable = ['bound' => null, 'available' => false];
    if ($tenantId < 1 || $clientId < 1) return $unavailable;
    try {
        $query = $pdo->prepare(
            'SELECT binding.*, receipt.id AS receipt_id,
                    receipt.binding_id AS receipt_binding, receipt.tenant_id AS receipt_tenant,
                    receipt.client_id AS receipt_client, receipt.customer_id AS receipt_customer,
                    receipt.source_version AS receipt_version, receipt.display_name AS receipt_name,
                    receipt.status AS receipt_status, receipt.occurred_at AS receipt_time
               FROM suite_customer_sync_bindings binding
               LEFT JOIN suite_customer_sync_events receipt ON receipt.event_id = binding.last_event_id
              WHERE binding.tenant_id = ? AND binding.client_id = ?'
        );
        $query->execute([$tenantId, $clientId]);
        $binding = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($binding)) return ['bound' => false, 'available' => false];
        return client_operational_from_binding(
            $binding,
            $tenantId,
            $clientId,
            client_operational_provider_slug($pdo, $tenantId)
        );
    } catch (Throwable) {
        return $unavailable;
    }
}

/**
 * One bound client's last accepted shared metadata, only when the binding and its receipt agree.
 *
 * @param array<string,mixed> $binding
 * @return array<string,mixed>
 */
function client_operational_from_binding(array $binding, int $tenantId, int $clientId, ?string $provider): array
{
    $unavailable = ['bound' => true, 'available' => false];
    try {
        $version = (int)$binding['source_version'];
        $url = client_operational_edit_url((string)$binding['customer_id'], $version);
        if ($url === null || !isset($binding['receipt_id'])
            || (int)$binding['receipt_binding'] !== (int)$binding['id']
            || (int)$binding['receipt_tenant'] !== $tenantId
            || (int)$binding['receipt_client'] !== $clientId
            || (string)$binding['receipt_customer'] !== (string)$binding['customer_id']
            || (int)$binding['receipt_version'] !== $version
            || (string)$binding['receipt_name'] !== (string)$binding['display_name']
            || (string)$binding['receipt_status'] !== (string)$binding['status']
            || (string)$binding['receipt_time'] !== (string)$binding['last_occurred_at']
            || !in_array($binding['status'], ['active', 'inactive'], true)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', (string)$binding['last_event_id']) !== 1
            || !is_string($binding['display_name']) || trim($binding['display_name']) !== $binding['display_name']
            || preg_match('//u', $binding['display_name']) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $binding['display_name']) === 1
            || mb_strlen($binding['display_name'], 'UTF-8') < 1
            || mb_strlen($binding['display_name'], 'UTF-8') > 128) return $unavailable;
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)$binding['last_occurred_at'], new DateTimeZone('UTC'));
        if ($time === false || $time->format('Y-m-d H:i:s') !== $binding['last_occurred_at']) return $unavailable;
        return [
            'bound' => true, 'available' => true, 'customer_id' => $binding['customer_id'],
            'display_name' => $binding['display_name'], 'status' => $binding['status'],
            'source_version' => $version, 'event_id' => $binding['last_event_id'],
            'occurred_at' => $time->format('Y-m-d\TH:i:s\Z'), 'edit_url' => $url,
            'device_url' => $provider === null ? null
                : client_operational_device_url($provider, (string)$binding['customer_id']),
            'billing_url' => $provider === null ? null
                : client_operational_billing_url($provider, (string)$binding['customer_id']),
        ];
    } catch (Throwable) {
        return $unavailable;
    }
}

const CLIENT_OPERATIONAL_DIRECTORY_ENDPOINT = 'https://support.8westit.com/suite_customer_metadata.php';
const CLIENT_OPERATIONAL_DIRECTORY_CONTRACT = 'suite-client-directory-v1';
const CLIENT_OPERATIONAL_DIRECTORY_MAX_CUSTOMERS = 256;
const CLIENT_OPERATIONAL_DIRECTORY_MAX_BYTES = 524288;

/**
 * The expected issuer suite tenant for this provider. Every source that is present must agree, and
 * at least one must be present: the verified token's issuer-tenant claim, this tenant's managed
 * provider issuer_tenant_id, and for the house only the nonsecret id_report_contacts.tenant_bindings
 * slug mapping (its contact transport is never loaded). Nothing is inferred from a local numeric id
 * or a slug; a missing or conflicting source is unknown (null).
 *
 * @param array<string,mixed> $claims
 */
function client_operational_roster_issuer(PDO $pdo, int $tenantId, string $provider, array $claims, ?array $houseBindings): ?int
{
    $sources = [];
    if (array_key_exists('8west:tenant_id', $claims)) {
        $claim = $claims['8west:tenant_id'];
        if ((!is_string($claim) && !is_int($claim)) || preg_match('/\A[1-9][0-9]{0,9}\z/D', (string)$claim) !== 1) return null;
        $sources[] = (int)$claim;
    }
    try {
        $query = $pdo->prepare('SELECT issuer_tenant_id, provider_slug FROM suite_managed_providers WHERE tenant_id = ?');
        $query->execute([$tenantId]);
        $providers = $query->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $providers = [];
    }
    if (count($providers) > 1) return null;
    foreach ($providers as $row) {
        $issuer = $row['issuer_tenant_id'] ?? null;
        if ((string)($row['provider_slug'] ?? '') !== $provider
            || preg_match('/\A[1-9][0-9]{0,9}\z/D', (string)$issuer) !== 1) return null;
        $sources[] = (int)$issuer;
    }
    if ($provider === '8west' && is_array($houseBindings) && array_key_exists('8west', $houseBindings)) {
        if (!is_string($houseBindings['8west'])
            || preg_match('/\Aewid-t([1-9][0-9]{0,9})\z/D', $houseBindings['8west'], $match) !== 1) return null;
        $sources[] = (int)$match[1];
    }
    $sources = array_values(array_unique($sources));
    return count($sources) === 1 && $sources[0] <= 4294967295 ? $sources[0] : null;
}

/**
 * The request-current principal for the directory read: the verified suite cookie only when its
 * subject is this active Safeharbor account's own suite_subject and its tenant is this account's
 * tenant. A local-only account never borrows a browser cookie. Null means the roster is unavailable.
 *
 * @param array<string,mixed> $user
 * @param array<string,mixed>|null $claims
 * @return array{user_id:int,tenant_id:int,subject:string,provider:string,issuer:int}|null
 */
function client_operational_roster_principal(PDO $pdo, array $user, ?array $claims, string $token, ?array $houseBindings): ?array
{
    $userId = (int)($user['id'] ?? 0);
    $tenantId = (int)($user['tenant_id'] ?? 0);
    $subject = $user['suite_subject'] ?? null;
    if ($claims === null || $userId < 1 || $tenantId < 1 || !is_string($subject)
        || preg_match('/\At[1-9][0-9]*u[1-9][0-9]*\z/D', $subject) !== 1
        || !is_string($claims['sub'] ?? null) || !hash_equals($subject, $claims['sub'])
        || $token === '' || strlen($token) > 16384
        || preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D', $token) !== 1) return null;
    try {
        $current = $pdo->prepare('SELECT id, tenant_id, suite_subject, is_active FROM users WHERE id = ?');
        $current->execute([$userId]);
        $row = $current->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($row) || (int)$row['tenant_id'] !== $tenantId || (int)$row['is_active'] !== 1
        || !is_string($row['suite_subject']) || !hash_equals($subject, $row['suite_subject'])) return null;
    $provider = client_operational_provider_slug($pdo, $tenantId);
    if ($provider === null || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $provider) !== 1
        || !is_string($claims['8west:tenant'] ?? null) || !hash_equals($provider, $claims['8west:tenant'])) return null;
    $issuer = client_operational_roster_issuer($pdo, $tenantId, $provider, $claims, $houseBindings);
    if ($issuer === null) return null;
    return ['user_id' => $userId, 'tenant_id' => $tenantId, 'subject' => $subject, 'provider' => $provider, 'issuer' => $issuer];
}

/**
 * Bounded HTTPS POST to the fixed Milepost endpoint: no redirects or retries, 8 s total and 3 s to
 * connect, at most 512 KiB. The bearer token stays in this request's memory; nothing is logged.
 *
 * @return array{status:int,body:string}
 */
function client_operational_roster_transport(string $token, string $body): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('directory transport unavailable');
    $curl = curl_init(CLIENT_OPERATIONAL_DIRECTORY_ENDPOINT);
    $response = '';
    $tooLarge = false;
    curl_setopt_array($curl, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$tooLarge): int {
            if (strlen($response) + strlen($chunk) > CLIENT_OPERATIONAL_DIRECTORY_MAX_BYTES) { $tooLarge = true; return 0; }
            $response .= $chunk;
            return strlen($chunk);
        },
    ]);
    try {
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($ok === false || $tooLarge) throw new RuntimeException('directory transport unavailable');
        return ['status' => $status, 'body' => $response];
    } finally {
        curl_close($curl);
    }
}

/** The same display-name rules the binding receipt uses. */
function client_operational_display_name_valid(mixed $name): bool
{
    return is_string($name) && trim($name) === $name && preg_match('//u', $name) === 1
        && preg_match('/[\x00-\x1f\x7f]/', $name) !== 1
        && mb_strlen($name, 'UTF-8') >= 1 && mb_strlen($name, 'UTF-8') <= 128;
}

/**
 * Strict suite-client-directory-v1 reply for exactly this request: the A14 keys only, this principal,
 * provider, issuer and nonce, an integer read_at within the request window ±60 s, and a complete
 * roster of at most 256 byte-sorted unique customers. Duplicate keys, a partial or oversized body,
 * or any other value make the whole roster unavailable (null), never an empty or partial one.
 *
 * @param array{subject:string,provider:string,issuer:int} $principal
 * @return array{read_at:int,customers:array<string,array<string,mixed>>}|null
 */
function client_operational_roster_decode(string $body, array $principal, string $nonce, int $sentAt, int $receivedAt): ?array
{
    if ($body === '' || strlen($body) > CLIENT_OPERATIONAL_DIRECTORY_MAX_BYTES || !str_starts_with(ltrim($body), '{')) return null;
    // json_decode() keeps only the last duplicate key: count member separators outside strings.
    $quoted = false; $escape = false; $members = 0;
    for ($i = 0, $length = strlen($body); $i < $length; ++$i) {
        $character = $body[$i];
        if ($quoted) {
            if ($escape) $escape = false;
            elseif ($character === '\\') $escape = true;
            elseif ($character === '"') $quoted = false;
        } elseif ($character === '"') $quoted = true;
        elseif ($character === ':') ++$members;
    }
    try {
        $reply = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    $keys = ['actor_subject', 'contract', 'customers', 'issuer_suite_tenant_id', 'ok', 'provider_tenant_id',
        'provider_tenant_slug', 'read_at', 'read_nonce'];
    $itemKeys = ['client_exists', 'customer_id', 'display_name', 'event_id', 'source_version', 'status'];
    $uuid = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';
    if (!is_array($reply) || array_is_list($reply)) return null;
    $actual = array_keys($reply);
    sort($actual, SORT_STRING);
    if ($actual !== $keys || $reply['ok'] !== true || $reply['contract'] !== CLIENT_OPERATIONAL_DIRECTORY_CONTRACT
        || $reply['actor_subject'] !== $principal['subject']
        || !is_int($reply['provider_tenant_id']) || $reply['provider_tenant_id'] < 1
        || $reply['provider_tenant_slug'] !== $principal['provider']
        || $reply['issuer_suite_tenant_id'] !== $principal['issuer'] || $reply['read_nonce'] !== $nonce
        || !is_int($reply['read_at']) || $reply['read_at'] < $sentAt - 60 || $reply['read_at'] > $receivedAt + 60
        || !is_array($reply['customers']) || !array_is_list($reply['customers'])
        || count($reply['customers']) > CLIENT_OPERATIONAL_DIRECTORY_MAX_CUSTOMERS
        || $members !== count($keys) + count($itemKeys) * count($reply['customers'])) return null;
    $customers = [];
    $previous = null;
    foreach ($reply['customers'] as $item) {
        if (!is_array($item) || array_is_list($item)) return null;
        $actual = array_keys($item);
        sort($actual, SORT_STRING);
        if ($actual !== $itemKeys || !is_string($item['customer_id']) || preg_match($uuid, $item['customer_id']) !== 1
            || ($previous !== null && strcmp($previous, $item['customer_id']) >= 0)
            || !is_int($item['source_version']) || $item['source_version'] < 1 || $item['source_version'] > 1024
            || !is_string($item['event_id']) || preg_match($uuid, $item['event_id']) !== 1
            || !client_operational_display_name_valid($item['display_name'])
            || !in_array($item['status'], ['active', 'inactive'], true) || !is_bool($item['client_exists'])) return null;
        $previous = $item['customer_id'];
        $customers[$item['customer_id']] = $item;
    }
    return ['read_at' => $reply['read_at'], 'customers' => $customers];
}

/**
 * This request's complete Milepost client directory for the signed-in provider, or unavailable.
 * It writes nothing. A missing, expired or mismatched principal, a refusal, a timeout or any reply
 * that is not exactly valid is unavailable; local details, history and Support stay usable.
 *
 * @param array<string,mixed> $user
 * @param (callable(string,string):array{status:int,body:string})|null $transport
 * @return array{available:bool,read_at?:int,customers?:array<string,array<string,mixed>>}
 */
function client_operational_roster(
    PDO $pdo,
    array $user,
    ?callable $transport = null,
    ?array $claims = null,
    ?string $token = null,
    ?array $houseBindings = null,
): array {
    $unavailable = ['available' => false];
    try {
        if (func_num_args() < 4) {
            $claims = function_exists('suite_sso_claims') ? suite_sso_claims() : null;
            $token = function_exists('cfg') ? (string)($_COOKIE[(string)cfg('suite.cookie_name', 'ewid_token')] ?? '') : '';
            $houseBindings = function_exists('cfg') ? cfg('id_report_contacts.tenant_bindings', null) : null;
        }
        $token = (string)$token;
        $houseBindings = is_array($houseBindings) ? $houseBindings : null;
        $principal = client_operational_roster_principal($pdo, $user, $claims, $token, $houseBindings);
        if ($principal === null) return $unavailable;
        $hex = bin2hex(random_bytes(16));
        $hex[12] = '4';
        $hex[16] = dechex((hexdec($hex[16]) & 3) | 8);
        $nonce = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20);
        $request = json_encode(['contract' => CLIENT_OPERATIONAL_DIRECTORY_CONTRACT, 'action' => 'read',
            'expected_subject' => $principal['subject'], 'read_nonce' => $nonce], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sentAt = time();
        $reply = ($transport ?? 'client_operational_roster_transport')($token, $request);
        $receivedAt = time();
        if (!is_array($reply) || ($reply['status'] ?? null) !== 200 || !is_string($reply['body'] ?? null)) return $unavailable;
        $roster = client_operational_roster_decode($reply['body'], $principal, $nonce, $sentAt, $receivedAt);
        // The local principal and provider are read again after the I/O; any change is unavailable.
        if ($roster === null || client_operational_roster_principal($pdo, $user, $claims, $token, $houseBindings) !== $principal) {
            return $unavailable;
        }
        return ['available' => true] + $roster;
    } catch (Throwable) {
        return $unavailable;
    }
}

/**
 * Every customer UUID bound to one tenant, whatever its receipt state, or null when the lookup fails.
 *
 * @return array<string,true>|null
 */
function client_operational_bound_customers(PDO $pdo, int $tenantId): ?array
{
    if ($tenantId < 1) return null;
    try {
        $query = $pdo->prepare('SELECT customer_id FROM suite_customer_sync_bindings WHERE tenant_id = ?');
        $query->execute([$tenantId]);
        $bound = [];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $customerId) $bound[(string)$customerId] = true;
        return $bound;
    } catch (Throwable) {
        return null;
    }
}

/**
 * The Clients directory against one verified roster. Primary: a local client of this provider whose
 * receipt-agreed UUID the roster lists active with client_exists. Pending: a roster-primary UUID with
 * no local binding at all yet, shown from the roster and never created here. Every other local client
 * is retained exactly as before, whatever its label; nothing is inactivated by absence. With no
 * roster, or no usable local lookup, nothing is classified.
 *
 * @param array<int,array<string,mixed>>|null $directory
 * @param array<string,true>|null $boundCustomers
 * @param array<string,mixed> $roster
 * @return array{state:string,primary:array<int,array<string,mixed>>,pending:array<string,array<string,mixed>>}
 */
function client_operational_roster_classify(?array $directory, ?array $boundCustomers, array $roster): array
{
    if ($directory === null || $boundCustomers === null
        || ($roster['available'] ?? false) !== true || !is_array($roster['customers'] ?? null)) {
        return ['state' => 'unavailable', 'primary' => [], 'pending' => []];
    }
    $primary = [];
    foreach ($directory as $clientId => $entry) {
        if (($entry['available'] ?? false) !== true || !is_string($entry['customer_id'] ?? null)) continue;
        $item = $roster['customers'][$entry['customer_id']] ?? null;
        if (is_array($item) && $item['status'] === 'active' && $item['client_exists'] === true) $primary[(int)$clientId] = $item;
    }
    $pending = [];
    foreach ($roster['customers'] as $customerId => $item) {
        if ($item['status'] === 'active' && $item['client_exists'] === true && !isset($boundCustomers[$customerId])) {
            $pending[$customerId] = $item;
        }
    }
    return ['state' => $roster['customers'] === [] ? 'empty' : 'verified', 'primary' => $primary, 'pending' => $pending];
}

/**
 * Serialize with the existing sync receiver (tenant, binding, client locks).
 * No bound client's contacts may be removed before its FK refuses deletion.
 */
function client_operational_delete_local(PDO $pdo, int $tenantId, int $clientId): string
{
    if ($tenantId < 1 || $clientId < 1 || $pdo->inTransaction()) {
        throw new InvalidArgumentException('local client removal requires its own scoped transaction');
    }
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $tenant = $pdo->prepare('SELECT id FROM tenants WHERE id = ?' . $lock);
        $tenant->execute([$tenantId]);
        if ($tenant->fetchColumn() === false) throw new RuntimeException('client tenant unavailable');
        $binding = $pdo->prepare('SELECT id FROM suite_customer_sync_bindings WHERE tenant_id = ? AND client_id = ?' . $lock);
        $binding->execute([$tenantId, $clientId]);
        if ($binding->fetchColumn() !== false) {
            $pdo->commit();
            return 'bound';
        }
        $client = $pdo->prepare('SELECT id FROM clients WHERE tenant_id = ? AND id = ?' . $lock);
        $client->execute([$tenantId, $clientId]);
        if ($client->fetchColumn() === false) {
            $pdo->commit();
            return 'missing';
        }
        $tickets = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE client_id = ?');
        $tickets->execute([$clientId]);
        if ((int)$tickets->fetchColumn() !== 0) {
            $pdo->commit();
            return 'tickets';
        }
        $pdo->prepare('DELETE FROM contacts WHERE client_id = ?')->execute([$clientId]);
        $pdo->prepare('DELETE FROM clients WHERE id = ? AND tenant_id = ?')->execute([$clientId, $tenantId]);
        $pdo->commit();
        return 'deleted';
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
