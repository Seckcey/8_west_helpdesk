<?php
/** Private JWKS trust and rollover: synthetic transport, no network. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid')) { fwrite(STDERR, "Unix PHP CLI required\n"); exit(1); }
require_once __DIR__ . '/../lib/trusted_jwks.php';
use EightWest\Id\Security\PrivateFileCache;
use EightWest\Id\Security\TrustedJwks;
$passed = 0; $failed = 0;
function jw_check(string $name, bool $ok): void {
    global $passed, $failed;
    if ($ok) $passed++; else { $failed++; fwrite(STDERR, "FAIL: $name\n"); }
}
$directory = sys_get_temp_dir() . '/eightwest-jwks-security-' . bin2hex(random_bytes(12));
mkdir($directory, 0700); $path = $directory . '/jwks.json';
$url = 'https://issuer.example.invalid/.well-known/jwks.json'; $now = 2000000000;
$keys = ['keys' => [['kty' => 'RSA', 'kid' => 'first', 'n' => 'synthetic_modulus', 'e' => 'AQAB', 'alg' => 'RS256']]];
$calls = 0;
$fetch = static function (string $source) use (&$calls, &$keys): array { $calls++; return ['status' => 200, 'body' => json_encode($keys)]; };
$down = static fn(string $source) => null;
$load = static fn(bool $force = false, ?callable $transport = null, ?int $at = null) => TrustedJwks::load($url, $path, 3600, $force, $transport ?? $fetch, $at ?? $now);
try {
    jw_check('first failure has no cache authority', $load(false, $down) === null);
    file_put_contents($path, json_encode($keys)); chmod($path, 0600); touch($path, $now);
    jw_check('mtime-fresh raw legacy cache never trusted', $load(false, $down) === null);
    jw_check('fresh trusted network creates envelope', $load() === $keys && $calls === 1);
    jw_check('verified cache avoids repeat fetch', $load() === $keys && $calls === 1);
    $old = $keys;
    $keys['keys'][0]['kid'] = 'rotated';
    jw_check('forced key rollover refreshes', $load(true) === $keys && $calls === 2);
    jw_check('bounded outage grace preserved', $load(false, $down, $now + 3601) === $keys);
    jw_check('maximum stale cache fails closed', $load(false, $down, $now + 86401) === null);
    jw_check('future timestamp fails closed', $load(false, $down, $now - 1) === null);
    jw_check('cache bound to exact URL', TrustedJwks::load('https://other.example.invalid/.well-known/jwks.json', $path, 3600, false, $down, $now) === null);
    foreach (['http://issuer.example.invalid/jwks', 'file:///tmp/jwks', 'https://u:p@issuer.example.invalid/jwks', $url . '?x=1', $url . '#fragment'] as $bad) {
        jw_check('unsafe source refused before transport', TrustedJwks::load($bad, $path, 3600, false, $fetch, $now) === null && $calls === 2);
    }
    jw_check('same-origin validation', TrustedJwks::sameOrigin($url, 'https://issuer.example.invalid'));
    jw_check('foreign origin refused', !TrustedJwks::sameOrigin($url, 'https://other.example.invalid'));
    jw_check('foreign port refused', !TrustedJwks::sameOrigin($url, 'https://issuer.example.invalid:8443'));
    unlink($path);
    jw_check('redirect is not key authority', $load(false, static fn($u) => ['status' => 302, 'body' => json_encode($old)]) === null);
    jw_check('oversized response refused', $load(false, static fn($u) => ['status' => 200, 'body' => str_repeat('x', 262145)]) === null);
    $keys['keys'][] = $keys['keys'][0];
    jw_check('duplicate key IDs refused', $load() === null);
    $keys = ['keys' => []]; jw_check('empty keyset refused', $load() === null);
    $keys = $old; chmod($directory, 0777); $beforeCalls = $calls;
    jw_check('unsafe root never adopted', $load() === null && $calls === $beforeCalls);
    chmod($directory, 0700);
    $victim = $directory . '/victim'; file_put_contents($victim, 'sentinel'); chmod($victim, 0600); symlink($victim, $path);
    jw_check('poisoned link cannot be repaired over or trusted', $load() === null && file_get_contents($victim) === 'sentinel');
    unlink($path);
    jw_check('ordinary key fetch recovers after operator repairs path', $load() === $old);
} finally {
    foreach (new DirectoryIterator($directory) as $entry) if (!$entry->isDot()) unlink($entry->getPathname());
    rmdir($directory);
}
echo "trusted JWKS: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
