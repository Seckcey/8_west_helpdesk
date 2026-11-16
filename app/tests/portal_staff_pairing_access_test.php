<?php
/**
 * MSP staff on a client's computer: the signed pairing and resolve requests send only the actor,
 * a plain GET never switches the company, and automatic staff access keeps the 8 h web deadline.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__ . '/../lib/portal_desktop_sessions.php';
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
$answer = portal_access_for_pairing($principal, $pair, $reply(200, ['item' => $item, 'automatic' => true]));
staff_check($answer === ['item' => $item, 'automatic' => true], 'the computer\'s company access and its kind are returned as Milepost named them');
staff_check($sent[0]['endpoint'] === PORTAL_DEVICES_ENDPOINT && $sent[0]['body'] === ['action' => 'staff_pairing_scope',
    'scope' => ['subject' => 't1u7', 'session_version' => '1.1', 'identity_tenant_slug' => '8west', 'role' => 'tech'],
    'input' => ['session_id' => $pair]], 'only the signed actor and the pairing id are sent, never the current company');
staff_check(count(array_filter($sent[0]['headers'], static fn(string $h): bool => str_starts_with($h, 'X-Portal-Signature: '))) === 1, 'the request is signed');
$resolved = portal_access_resolve($principal, $item, $reply(200, ['item' => $item, 'automatic' => false]));
staff_check($resolved['item'] === $item && end($sent)['body'] === ['action' => 'access_resolve',
    'scope' => ['subject' => 't1u7', 'session_version' => '1.1', 'identity_tenant_slug' => '8west', 'role' => 'tech'],
    'input' => ['provider_slug' => '8west', 'customer_id' => $item['customer_id']]], 'one selected customer is rechecked without the list');
portal_access_resolve($principal, $item, $reply(200, ['item' => $item, 'automatic' => true]), true);
staff_check(end($sent)['body']['input'] === ['provider_slug' => '8west', 'customer_id' => $item['customer_id'], 'use' => true],
    'choosing a customer sends the audited recheck, so Milepost records the use once');
$selectSource = (string)file_get_contents(__DIR__ . '/../lib/portal_access.php');
staff_check(substr_count($selectSource, 'portal_access_resolve($principal,$choice,$transport,true)') === 1
    && substr_count($selectSource, 'portal_access_resolve($principal,$selected[\'access\'],$transport,true)') === 1,
    'both ways of choosing a customer (Connect and the picker) send the audited recheck');
$refused = static function (callable $transport) use ($principal, $pair): bool {
    try { portal_access_for_pairing($principal, $pair, $transport); return false; } catch (PortalDevicesException) { return true; }
};
staff_check($refused($reply(403, null)), 'a refusal is a refusal');
foreach ([
    'another staff role' => ['access' => ['identity_role' => 'admin'] + $item['access']] + $item,
    'another staff tenant' => ['access' => ['identity_tenant_slug' => 'other'] + $item['access']] + $item,
    'a non-client role' => ['role' => 'msp_admin'] + $item,
    'an extra key' => $item + ['client_id' => 2],
] as $name => $bad) staff_check($refused($reply(200, ['item' => $bad, 'automatic' => true])), 'an answer that does not fit this signer is refused: ' . $name);
staff_check($refused($reply(200, ['item' => $item])), 'an answer that does not say which kind of access it is, is refused');
staff_check($refused($reply(200, ['item' => $item, 'automatic' => 'yes'])), 'a malformed kind is refused');
staff_check($refused($reply(200, ['items' => [$item]])), 'a list instead of one answer is refused');
staff_check($refused(static fn(): array => ['status' => 200, 'body' => 'not json']), 'a malformed answer is refused');
$noCall = static fn(): array => throw new RuntimeException('no request may be sent');
try { portal_access_for_pairing($principal, 'not-a-pairing', $noCall); staff_check(false, 'a bad pairing id is refused before sending'); }
catch (PortalDevicesException) { staff_check(true, 'a bad pairing id is refused before sending'); }
staff_check(portal_access_principal($principal) === array_diff_key($principal, array_flip(['binding_id', 'tenant_id', 'client_id', 'customer_access'])),
    'the principal alone carries no selected company');

// Automatic staff access keeps the web sign-in's deadline, even with the Companion's proof.
$now = time();
$web = ['subject' => 't1u7', 'issued_at' => $now - 600, 'expires_at' => $now + 3600];
$staffEnvelope = portal_desktop_handoff_envelope($web, $now, true);
staff_check($staffEnvelope['authority'] === $web && $staffEnvelope['identity']['expires_at'] === $now + 1800,
    'an automatic staff approval carries only the web deadline');
staff_check(portal_desktop_handoff_grant($staffEnvelope, true)['authority']['expires_at'] === $web['expires_at'],
    'the Companion proof does not lengthen an automatic staff sign-in');
$lateStaff = portal_desktop_handoff_envelope(array_replace($web, ['expires_at' => $now + 60]), $now, true);
staff_check($lateStaff['identity']['expires_at'] === $now + 60, 'its lease never outlives the web sign-in');
staff_check(portal_desktop_handoff_envelope($web, $now)['authority']['expires_at'] === $now + PORTAL_DESKTOP_AUTHORITY_SECONDS,
    'everyone else keeps the 30-day Companion sign-in');

// The sign-in page shows the computer's company on GET and switches only on Connect.
$page = (string)file_get_contents(__DIR__ . '/../public/portal/desktop_authorize.php');
$post = substr($page, strpos($page, "==='POST'"), strpos($page, "!=='GET'") - strpos($page, "==='POST'"));
$get = substr($page, strpos($page, "!=='GET'"));
staff_check(str_contains($post, 'portal_access_select_for_pairing(') && str_contains($post, 'portal_desktop_handoff_approve(db(),$context,$id,$webOnly)')
    && str_contains($post, '$webOnly=$selected[\'automatic\'];')
    && str_contains($post, "\$webOnly=portal_access_resolve(portal_access_principal(\$context['identity']),\$context['identity']['customer_access'])['automatic'];")
    && str_contains($post, 'catch(PortalDevicesException){$webOnly=true;}'),
    'Connect switches to the computer\'s company and approves automatic staff access with the web deadline');
staff_check(!str_contains($get, 'portal_access_select') && str_contains($get, 'portal_access_preview_for_pairing('),
    'a plain GET only shows the computer\'s company and never switches the session');
echo "PASS $checks staff pairing access assertions\n";
