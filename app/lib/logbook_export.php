<?php
/** Read-only solved-ticket source for Logbook's existing schema-1 importer. */
declare(strict_types=1);

const LOGBOOK_EXPORT_PATH = '/api/svc/logbook_export.php';
const LOGBOOK_EXPORT_UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';

function logbook_export_settings(array $settings): array
{
    if (!is_int($settings['tenant_id'] ?? null) || $settings['tenant_id'] < 1
        || !is_int($settings['suite_tenant_id'] ?? null) || $settings['suite_tenant_id'] < 1
        || !is_string($settings['secret'] ?? null)
        || preg_match('/\A[0-9a-fA-F]{64}\z/D', $settings['secret']) !== 1
        || $settings['secret'] === str_repeat('0', 64)
        || !is_array($settings['customer_ids'] ?? null) || $settings['customer_ids'] === []) {
        throw new RuntimeException('Logbook export is not configured.');
    }
    foreach ($settings['customer_ids'] as $id) {
        if (!is_string($id) || preg_match(LOGBOOK_EXPORT_UUID, $id) !== 1) {
            throw new RuntimeException('Logbook customer scope is not configured.');
        }
    }
    $settings['customer_ids'] = array_values(array_unique($settings['customer_ids']));
    return $settings;
}

/** Same dedicated header/body signature that Logbook already sends. */
function logbook_export_authenticated(array $settings, array $headers, string $body, ?int $now = null): bool
{
    $timestamp = $headers['timestamp'] ?? '';
    $nonce = $headers['nonce'] ?? '';
    $secret = $headers['secret'] ?? '';
    $signature = $headers['signature'] ?? '';
    if (!is_string($timestamp) || !ctype_digit($timestamp) || strlen($timestamp) > 12
        || abs(($now ?? time()) - (int)$timestamp) > 300
        || !is_string($nonce) || preg_match(LOGBOOK_EXPORT_UUID, $nonce) !== 1
        || !is_string($secret) || !hash_equals($settings['secret'], $secret)
        || !is_string($signature) || preg_match('/\A[0-9a-f]{64}\z/D', $signature) !== 1) {
        return false;
    }
    $base = 'POST'."\n".LOGBOOK_EXPORT_PATH."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body);
    return hash_equals(hash_hmac('sha256', $base, $settings['secret']), $signature);
}

function logbook_export_request(string $body): array
{
    if (strlen($body) > 16384) {
        throw new InvalidArgumentException('Invalid export request.');
    }
    $request = json_decode($body, true);
    if (!is_array($request) || count($request) !== 4
        || ($request['schema_version'] ?? null) !== 1 || ($request['kind'] ?? null) !== 'solved_tickets'
        || !is_int($request['cursor'] ?? null) || $request['cursor'] < 0
        || !is_int($request['limit'] ?? null) || $request['limit'] < 1 || $request['limit'] > 200) {
        throw new InvalidArgumentException('Invalid export request.');
    }
    return $request;
}

/**
 * SELECTs only: use existing tenant-scoped service registration and exact customer bindings.
 * There is no resolution field. The last public technician reply before resolution is quoted,
 * with low confidence; internal notes, customer messages and system text never become knowledge.
 */
function logbook_export_page(PDO $pdo, array $settings, array $request): array
{
    $tenant = $pdo->prepare(
        "SELECT t.slug FROM tenants t JOIN svc_identities s ON s.tenant_id=t.id
          WHERE t.id=? AND s.service='logbook-export' AND s.is_active=1"
    );
    $tenant->execute([$settings['tenant_id']]);
    $slug = $tenant->fetchColumn();
    if (!is_string($slug) || $slug === '') {
        throw new RuntimeException('Logbook service identity is unavailable.');
    }
    $placeholders = implode(',', array_fill(0, count($settings['customer_ids']), '?'));
    $query = $pdo->prepare(
        "SELECT t.id, t.subject, t.resolved_at, b.customer_id, m.body
           FROM tickets t
           JOIN clients c ON c.id=t.client_id AND c.tenant_id=t.tenant_id
           JOIN suite_customer_sync_bindings b ON b.client_id=c.id AND b.tenant_id=c.tenant_id
           JOIN messages m ON m.id=(SELECT reply.id FROM messages reply
                WHERE reply.ticket_id=t.id AND reply.kind='tech' AND reply.created_at<=t.resolved_at
                  AND TRIM(reply.body)<>'' ORDER BY reply.created_at DESC, reply.id DESC LIMIT 1)
          WHERE t.tenant_id=? AND t.status='resolved' AND t.resolved_at IS NOT NULL
            AND t.merged_into_id IS NULL AND b.status='active'
            AND b.customer_id IN ($placeholders) AND t.id>?
          ORDER BY t.id LIMIT ".($request['limit'] + 1)
    );
    $query->execute([$settings['tenant_id'], ...$settings['customer_ids'], $request['cursor']]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $request['limit'];
    $rows = array_slice($rows, 0, $request['limit']);
    $observedAt = gmdate('Y-m-d\TH:i:s\Z');
    $items = [];
    foreach ($rows as $row) {
        $resolution = trim(strip_tags((string)$row['body']));
        if ($resolution === '' || mb_strlen($resolution) > 20000) {
            continue;
        }
        $items[] = [
            'ticket_id' => (string)$row['id'], 'customer_id' => $row['customer_id'],
            'title' => $row['subject'], 'resolution' => $resolution, 'confidence' => 'low',
            'resolved_at' => str_replace(' ', 'T', $row['resolved_at']).'Z',
            'observed_at' => $observedAt,
        ];
    }
    return [
        'ok' => true, 'schema_version' => 1, 'suite_tenant_id' => $settings['suite_tenant_id'],
        'tenant_slug' => $slug, 'kind' => 'solved_tickets', 'items' => $items,
        'next_cursor' => $more ? (int)$rows[array_key_last($rows)]['id'] : null,
    ];
}
