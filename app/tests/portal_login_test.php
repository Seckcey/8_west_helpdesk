<?php
/** Synthetic OIDC transactions: real client, signatures and session attempt store. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
ob_start(); // Session rotation/cookies must remain possible throughout the assertions.

function cfg(string $key, mixed $default = null): mixed
{
    return [
        'app_env' => 'dev', 'portal.cookie_secure' => true,
        'portal.issuer' => 'https://id.example.test',
        'portal.client_id' => 'safeharbor-test', 'portal.client_secret' => str_repeat('s', 48),
        'portal.redirect_uri' => 'https://safeharbor.example.test/portal/callback.php',
        'portal.revocation_cache_dir' => sys_get_temp_dir() . '/safeharbor-login-test',
    ][$key] ?? $default;
}
require_once __DIR__ . '/../lib/portal_auth.php';
require_once __DIR__ . '/../lib/portal_render.php';

use EightWest\Id\Client;
use EightWest\Id\HttpClient;
use EightWest\Id\HttpResponse;
use EightWest\Id\MemoryRevocationCache;
use EightWest\Id\PhpSessionAttemptStore;
use EightWest\Id\PolicyException;
use function EightWest\Id\base64url_encode;

$checks = 0;
function login_check(string $name, bool $ok): void
{
    global $checks;
    $checks++;
    if (! $ok) throw new RuntimeException('FAIL: ' . $name);
    echo "ok {$checks} - {$name}\n";
}
function login_rejected(string $name, callable $call, string $reason): void
{
    try { $call(); } catch (PolicyException $error) {
        login_check($name, $error->reason() === $reason);
        return;
    }
    throw new RuntimeException('Not rejected: ' . $name);
}

final class LoginIssuer implements HttpClient
{
    public array $authorization = [];
    public array $overrides = [];
    public bool $badSignature = false;
    public int $tokenCalls = 0;
    private OpenSSLAsymmetricKey $key;
    public function __construct() { $this->key = openssl_pkey_new(['private_key_bits' => 2048]); }
    public function send(string $method, string $url, array $headers = [], string $body = '', int $maxBytes = 1048576): HttpResponse
    {
        $issuer = 'https://id.example.test';
        if ($url === $issuer . '/.well-known/openid-configuration') {
            $payload = [
                'issuer' => $issuer, 'authorization_endpoint' => $issuer . '/oauth/authorize.php',
                'token_endpoint' => $issuer . '/oauth/token.php', 'jwks_uri' => $issuer . '/.well-known/jwks.json',
                'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code'],
                'code_challenge_methods_supported' => ['S256'], 'id_token_signing_alg_values_supported' => ['RS256'],
                'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
                'authorization_response_iss_parameter_supported' => true,
                'scopes_supported' => ['openid', 'profile', 'email'],
            ];
        } elseif ($url === $issuer . '/.well-known/jwks.json') {
            $rsa = openssl_pkey_get_details($this->key)['rsa'];
            $payload = ['keys' => [['kty' => 'RSA', 'kid' => 'test-key', 'alg' => 'RS256', 'use' => 'sig',
                'n' => base64url_encode($rsa['n']), 'e' => base64url_encode($rsa['e'])]]];
        } elseif ($url === $issuer . '/oauth/token.php') {
            $this->tokenCalls++;
            parse_str($body, $form);
            login_check('exchange preserves PKCE, registered callback and client authentication',
                $method === 'POST' && $form['code'] === 'synthetic-code'
                && $form['redirect_uri'] === cfg('portal.redirect_uri')
                && base64url_encode(hash('sha256', $form['code_verifier'], true)) === $this->authorization['code_challenge']
                && in_array('Authorization: Basic ' . base64_encode('safeharbor-test:' . str_repeat('s', 48)), $headers, true));
            $now = time();
            $claims = array_replace([
                'iss' => $issuer, 'aud' => 'safeharbor-test', 'iat' => $now, 'exp' => $now + 300,
                'nonce' => $this->authorization['nonce'], 'sub' => 't9u4',
                'email' => 'customer@example.test', 'email_verified' => true, 'name' => 'Synthetic Customer',
                '8west:tenant' => 'acme-id', '8west:tenant_id' => '9', '8west:session_version' => '1.1',
                '8west:products' => ['safeharbor'], '8west:role_contract' => 'suite_roles_v1',
                '8west:role' => 'client_owner', '8west:capability_role' => 'owner',
                '8west:mfa' => true, '8west:mfa_authenticated' => true, '8west:mfa_time' => $now,
                '8west:auth_policy' => 'suite-mfa-v1', 'auth_time' => $now, 'amr' => ['pwd', 'otp'],
            ], $this->overrides);
            $data = base64url_encode(json_encode(['alg' => 'RS256', 'kid' => 'test-key', 'typ' => 'JWT']))
                . '.' . base64url_encode(json_encode($claims));
            openssl_sign($data, $signature, $this->key, OPENSSL_ALGO_SHA256);
            if ($this->badSignature) $signature[0] = chr(ord($signature[0]) ^ 1);
            $payload = ['access_token' => 'synthetic-unused', 'id_token' => $data . '.' . base64url_encode($signature),
                'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'openid profile email'];
        } else {
            throw new RuntimeException('Unexpected transport endpoint');
        }
        return new HttpResponse(200, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}

portal_session_start();
$http = new LoginIssuer();
// array replacement is intentional: exercise enforced MFA even if deployment reports it.
$client = new Client(array_replace(portal_oidc_config(), ['mfa_policy_mode' => 'enforce']),
    new PhpSessionAttemptStore('_login_test_attempts'), $http, new MemoryRevocationCache());

foreach (['/portal/', '/portal/ticket.php?id=42', '/portal/reports.php?id=7',
    '/portal/mobile.php?platform=ios&ownership=company', '/portal/security.php?device=opaque-ref'] as $path) {
    login_check('customer return accepted ' . $path, portal_safe_return_path($path) === $path);
}
foreach ([null, [], '', 'https://evil.test/portal/', '//evil.test/portal/', '/\\evil.test',
    '/portal/../login.php', '/portal/%2e%2e/login.php', '/portal/%252e%252e/login.php',
    '/portal/callback.php?code=bad', '/portal/login.php', '/portal/logout.php', '/portal/westy.php',
    '/login.php', '/portal/;https://evil.test', '/portal/?q=%0d%0aLocation:evil',
    '/portal/?q=%250d%250a', '/portal/?q=%5c', '/portal/?q=ok#bad', '/portal/?q=' . str_repeat('x', 2050),
    '/portal/?q=%252525252F'] as $path) {
    login_check('unsafe/auth/non-page return falls back ' . $checks, portal_safe_return_path($path) === '/portal/');
}
login_check('signed-out entry starts OIDC automatically', portal_login_can_start_automatically('GET', []));
login_check('shared ID cookie still requires OIDC', portal_login_can_start_automatically('GET', ['ewid_token' => 'not-a-credential']));
login_check('staff cookie still requires customer OIDC', portal_login_can_start_automatically('GET', ['safeharbor' => 'staff-session']));
login_check('pending retry guard stops redirect loop', ! portal_login_can_start_automatically('GET', [PORTAL_LOGIN_GUARD_COOKIE => '1']));
foreach (['POST', 'PUT', 'DELETE', 'HEAD'] as $method) {
    login_check('never redirect/replay ' . $method, ! portal_login_can_start_automatically($method, []));
}
$options = portal_login_guard_options(time() + 300);
login_check('retry guard has host-only secure HttpOnly Lax portal scope',
    $options['path'] === '/portal' && $options['secure'] && $options['httponly']
    && $options['samesite'] === 'Lax' && ! isset($options['domain']));

$start = static function (string $path = '/portal/ticket.php?id=42') use ($client, $http): array {
    $request = portal_prepare_login($client, $path);
    parse_str(parse_url($request->url, PHP_URL_QUERY), $http->authorization);
    return ['state' => $request->state, 'iss' => 'https://id.example.test', 'code' => 'synthetic-code'];
};
$query = $start();
login_check('authorization uses exact callback, state, nonce and S256',
    $http->authorization['redirect_uri'] === cfg('portal.redirect_uri')
    && $http->authorization['code_challenge_method'] === 'S256'
    && strlen($http->authorization['nonce']) >= 43 && $http->authorization['state'] === $query['state']);
$identity = $client->handleCallback($query, $query['state']);
login_check('signed customer callback succeeds', $identity->subject === 't9u4' && $identity->role === 'client_owner');
login_check('validated callback resumes original deep link', portal_take_login_return($query['state']) === '/portal/ticket.php?id=42');
login_check('return consumed only once', portal_take_login_return($query['state']) === '/portal/');
login_rejected('callback replay rejected', fn() => $client->handleCallback($query, $query['state']), 'authorization_attempt_expired');
$query = $start();
login_check('different transaction cannot take a deep link', portal_take_login_return(str_repeat('x', 43)) === '/portal/');
$query = $start();
login_check('expired return is discarded', portal_take_login_return($query['state'], time() + 301) === '/portal/');
$query = $start();
$_SESSION[PORTAL_LOGIN_RETURN_KEY]['path'] = '//evil.test';
login_check('stored return revalidated on consumption', portal_take_login_return($query['state']) === '/portal/');

$query = $start();
$before = $http->tokenCalls;
login_rejected('wrong state cookie rejected before exchange', fn() => $client->handleCallback($query, 'bad'), 'state_mismatch');
login_rejected('wrong callback issuer rejected before exchange', fn() => $client->handleCallback(array_replace($query, ['iss' => 'https://evil.test']), $query['state']), 'authorization_issuer_mismatch');
login_check('bad state/issuer never exchanged code', $http->tokenCalls === $before);
login_rejected('denied authentication stops', fn() => $client->handleCallback($query + ['error' => 'access_denied'], $query['state']), 'authorization_denied');
$query = $start();
$_SESSION['_login_test_attempts'][$query['state']]['issued_at'] = time() - 400;
$_SESSION['_login_test_attempts'][$query['state']]['expires_at'] = time() - 100;
login_rejected('expired OIDC transaction rejected', fn() => $client->handleCallback($query, $query['state']), 'authorization_attempt_expired');
foreach ([
    [['iat' => time() - 1000, 'exp' => time() - 700], 'token_time_invalid'],
    [['nonce' => 'wrong-nonce'], 'nonce_mismatch'],
    [['aud' => 'other-app'], 'audience_mismatch'],
    [['8west:products' => ['milepost']], 'required_product_missing'],
    [['8west:tenant_id' => '99'], 'subject_tenant_mismatch'],
    [['8west:mfa_authenticated' => false], 'mfa_not_authenticated'],
] as [$overrides, $reason]) {
    $http->overrides = $overrides;
    $query = $start();
    login_rejected('signed invalid identity refused: ' . $reason, fn() => $client->handleCallback($query, $query['state']), $reason);
}
$http->overrides = [];
$http->badSignature = true;
$query = $start();
login_rejected('invalid signature refused', fn() => $client->handleCallback($query, $query['state']), 'token_signature_invalid');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/portal/ticket.php?id=42';
$_COOKIE[PORTAL_LOGIN_GUARD_COOKIE] = '1';
ob_start();
portal_require_sign_in();
$html = ob_get_clean();
login_check('loop guard renders explicit safe deep-link retry', str_contains($html, '/portal/login.php?next=%2Fportal%2Fticket.php%3Fid%3D42'));
unset($_COOKIE[PORTAL_LOGIN_GUARD_COOKIE]);
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
portal_require_sign_in('Please sign in again.');
$html = ob_get_clean();
login_check('expired POST renders retry without replaying body', str_contains($html, 'Please sign in again.') && str_contains($html, 'Continue with 8 West ID'));
portal_destroy_session();
echo "Portal login: {$checks} checks, 0 failures\n";
ob_end_flush();
