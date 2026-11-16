<?php
/** MSP staff on a client's computer: the signed pairing lookup sends only the actor and pairing id. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__ . '/../lib/portal_access.php';
require_once __DIR__ . '/../lib/portal_devices.php';
if (!function_exists('cfg')) {
    function cfg(string $key, mixed $default = null): mixed
    { $v = $GLOBALS['CONFIG'] ?? []; foreach (explode('.', $key) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) return $default; $v = $v[$k]; } return $v; }
}
$checks = 0;
function staff_check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL ' . $name); $checks++; }
$GLOBALS['CONFIG'] = ['portal_devices' => ['enabled' => true, 'endpoint' => PORTAL_DEVICES_ENDPOINT, 'secret' => str_repeat('ab', 32)]];
$principal = ['subject' => 't1u7', 'session_version' => '1.1', 'identity_tenant_slug' => '8west', 'role' => 'tech',
    'display_name' => 'Synthetic Tech', 'binding_id' => 4, 'tenant_id' => 1, 'client_id' => 2, 'customer_access' => ['x' => 1]];
$pair = str_repeat('a', 32);
$item = ['provider_slug' => '8west', 'customer_id' => '00000000-0000-4000-8000-000000000010', 'identity_tenant_slug' => 'customer-rpm',
    'role' => 'client_staff', 'access' => ['reference' => str_repeat('b', 32), 'generation' => 1, 'identity_tenant_slug' => '8west', 'identity_role' => 'tech']];
$sent = [];
$reply = static function (int $status, mixed $result) use (&$sent): callable {
    return static function (string $endpoint, string $body, array $headers) use (&$sent, $status, $result): array {
        $sent[] = ['endpoint' => $endpoint, 'body' => json_decode($body, true), 'headers' => $headers];
        return ['status' => $status, 'body' => json_encode(['contract' => PORTAL_DEVICES_CONTEXT, 'ok' => $status === 200, 'result' => $result])];
    };
};
$choice = portal_access_for_pairing($principal, $pair, $reply(200, ['item' => $item]));
staff_check($choice === $item, 'the computer\'s company access is returned as Milepost named it');
staff_check($sent[0]['endpoint'] === PORTAL_DEVICES_ENDPOINT && $sent[0]['body'] === ['action' => 'staff_pairing_scope',
    'scope' => ['subject' => 't1u7', 'session_version' => '1.1', 'identity_tenant_slug' => '8west', 'role' => 'tech'],
    'input' => ['session_id' => $pair]], 'only the signed actor and the pairing id are sent, never the current company');
staff_check(count(array_filter($sent[0]['headers'], static fn(string $h): bool => str_starts_with($h, 'X-Portal-Signature: '))) === 1, 'the request is signed');
$refused = static function (callable $transport) use ($principal, $pair): bool {
    try { portal_access_for_pairing($principal, $pair, $transport); return false; } catch (PortalDevicesException) { return true; }
};
staff_check($refused($reply(403, null)), 'a refusal is a refusal');
foreach ([
    'another staff role' => ['access' => ['identity_role' => 'admin'] + $item['access']] + $item,
    'another staff tenant' => ['access' => ['identity_tenant_slug' => 'other'] + $item['access']] + $item,
    'a non-client role' => ['role' => 'msp_admin'] + $item,
    'an extra key' => $item + ['client_id' => 2],
] as $name => $bad) staff_check($refused($reply(200, ['item' => $bad])), 'an answer that does not fit this signer is refused: ' . $name);
staff_check($refused($reply(200, ['items' => [$item]])), 'a list instead of one answer is refused');
staff_check($refused(static fn(): array => ['status' => 200, 'body' => 'not json']), 'a malformed answer is refused');
$noCall = static fn(): array => throw new RuntimeException('no request may be sent');
try { portal_access_for_pairing($principal, 'not-a-pairing', $noCall); staff_check(false, 'a bad pairing id is refused before sending'); }
catch (PortalDevicesException) { staff_check(true, 'a bad pairing id is refused before sending'); }
echo "PASS $checks staff pairing access assertions\n";
