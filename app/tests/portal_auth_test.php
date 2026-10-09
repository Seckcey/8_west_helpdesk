<?php
/** Hermetic customer-portal OIDC/session/revocation contract tests. */
declare(strict_types=1);

$PORTAL_TEST_CONFIG = [
    'app_env' => 'dev',
    'portal' => [
        'enabled' => false,
        'issuer' => 'https://id.example.test',
        'client_id' => 'safeharbor-portal-test',
        'client_secret' => str_repeat('s', 48),
        'redirect_uri' => 'https://safeharbor.example.test/portal/callback.php',
        'required_product' => 'safeharbor',
        'mfa_policy_mode' => 'report',
        'mfa_max_age_seconds' => 2592000,
        'session_lifetime_seconds' => 28800,
        'cookie_secure' => true,
        'revocation_cache_dir' => sys_get_temp_dir() . '/safeharbor-portal-auth-test',
        'reserved_identity_tenant_slugs' => [],
    ],
];

function cfg(string $key, mixed $default = null): mixed
{
    global $PORTAL_TEST_CONFIG;
    $value = $PORTAL_TEST_CONFIG;
    foreach (explode('.', $key) as $part) {
        if (! is_array($value) || ! array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

require_once __DIR__ . '/../lib/portal_auth.php';

use EightWest\Id\HttpClient;
use EightWest\Id\HttpResponse;
use EightWest\Id\MemoryRevocationCache;
use EightWest\Id\RevocationChecker;
use EightWest\Id\RevocationUnavailableException;

$checks = 0;
$failures = 0;

function portal_auth_check(string $name, bool $condition): void
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

function portal_auth_expect(string $name, string $class, callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        portal_auth_check($name, $error instanceof $class);
        return;
    }
    portal_auth_check($name, false);
}

final class PortalAuthFakeHttp implements HttpClient
{
    public int $calls = 0;
    public bool $fail = false;
    public ?string $rawBody = null;
    /** @var array<string,mixed> */
    public array $payload = [];

    public function send(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $maxBytes = 1048576,
    ): HttpResponse {
        $this->calls++;
        if ($this->fail) throw new RuntimeException('simulated outage');
        return new HttpResponse(
            200,
            $this->rawBody ?? json_encode($this->payload, JSON_THROW_ON_ERROR),
        );
    }
}

portal_session_start();
portal_auth_check('portal is dark by default', portal_enabled() === false);
$PORTAL_TEST_CONFIG['portal']['enabled'] = true;
$oidc = portal_oidc_config();
portal_auth_check('portal pins exact Safeharbor product', $oidc['required_product'] === 'safeharbor');
portal_auth_check('session is capped at eight hours', $oidc['session_lifetime_seconds'] === 28800);
portal_auth_check('revocation feed refresh is pinned to sixty seconds',
    $oidc['revocation_refresh_seconds'] === 60);
portal_auth_check('revocation fallback is pinned to five minutes',
    $oidc['revocation_maximum_stale_seconds'] === 300);
portal_auth_check('configured OIDC client is confidential-kit compatible',
    portal_oidc_client() instanceof EightWest\Id\Client);
portal_auth_check('vendored oidc_v1 treats passkey as known MFA evidence',
    in_array('passkey', \EightWest\Id\KNOWN_AMR, true)
    && in_array('passkey', \EightWest\Id\MFA_METHODS, true));

$PORTAL_TEST_CONFIG['portal']['required_product'] = 'milepost';
portal_auth_expect('different product cannot weaken the entitlement gate',
    PortalAuthConfigurationException::class, fn() => portal_oidc_config());
$PORTAL_TEST_CONFIG['portal']['required_product'] = 'safeharbor';
$PORTAL_TEST_CONFIG['app_env'] = 'production';
$PORTAL_TEST_CONFIG['portal']['issuer'] = 'https://id.example.test';
portal_auth_expect('production refuses any issuer other than exact 8 West ID',
    PortalAuthConfigurationException::class, fn() => portal_oidc_config());
$PORTAL_TEST_CONFIG['portal']['issuer'] = PORTAL_REQUIRED_ISSUER;
$PORTAL_TEST_CONFIG['portal']['cookie_secure'] = false;
portal_auth_expect('production refuses an insecure portal cookie',
    PortalAuthConfigurationException::class, fn() => portal_oidc_config());
$PORTAL_TEST_CONFIG['app_env'] = 'prod';
$PORTAL_TEST_CONFIG['portal']['issuer'] = 'https://id.example.test';
$PORTAL_TEST_CONFIG['portal']['cookie_secure'] = true;
portal_auth_expect('unknown app environment cannot relax the production issuer',
    PortalAuthConfigurationException::class, fn() => portal_oidc_config());
$PORTAL_TEST_CONFIG['portal']['issuer'] = PORTAL_REQUIRED_ISSUER;
$PORTAL_TEST_CONFIG['portal']['cookie_secure'] = false;
portal_auth_expect('unknown app environment cannot relax the Secure cookie',
    PortalAuthConfigurationException::class, fn() => portal_oidc_config());
$PORTAL_TEST_CONFIG['app_env'] = 'dev';
$PORTAL_TEST_CONFIG['portal']['issuer'] = 'https://id.example.test';
$PORTAL_TEST_CONFIG['portal']['cookie_secure'] = true;

foreach (PORTAL_CLIENT_ROLES as $role) {
    portal_auth_check("{$role} is admitted", portal_role_allowed($role));
}
foreach (['owner', 'admin', 'tech', 'readonly', 'custodian', 'msp_owner', 'msp_admin', 'msp_tech', 'msp_viewer'] as $role) {
    portal_auth_check("{$role} is not a customer-portal role", ! portal_role_allowed($role));
}
portal_auth_check('normalized identity slug is accepted', portal_identity_tenant_slug('acme-co') === 'acme-co');
foreach (['', 'Acme', 'acme_co', '-acme', '8west', 'internal'] as $slug) {
    portal_auth_expect("invalid or reserved slug {$slug} is refused",
        PortalDataValidationException::class,
        fn() => portal_identity_tenant_slug($slug));
}
$PORTAL_TEST_CONFIG['portal']['reserved_identity_tenant_slugs'] = ['future-reserved'];
portal_auth_expect('future centrally configured reservation is refused',
    PortalDataValidationException::class,
    fn() => portal_identity_tenant_slug('future-reserved'));
$PORTAL_TEST_CONFIG['portal']['reserved_identity_tenant_slugs'] = [];

$now = 2000000000;
$validSession = [
    'subject' => 't9u4',
    'session_version' => '2.7',
    'identity_tenant_slug' => 'acme-co',
    'role' => 'client_viewer',
    'display_name' => 'Client Viewer',
    'binding_id' => 14,
    'tenant_id' => 3,
    'client_id' => 25,
    'issued_at' => $now - 60,
    'expires_at' => $now + 3600,
];
$_SESSION = [PORTAL_SESSION_KEY => $validSession, PORTAL_CSRF_KEY => str_repeat('a', 64)];
portal_auth_check('exact subject/session-version local session validates',
    portal_local_identity($now) === $validSession);
portal_auth_check('portal CSRF validates exact token', portal_csrf_valid(str_repeat('a', 64)));
portal_auth_check('portal CSRF rejects a different token', ! portal_csrf_valid(str_repeat('b', 64)));
$createNonce = portal_action_nonce('ticket:create', $now);
portal_auth_check('portal action nonce is valid once',
    portal_action_nonce_consume('ticket:create', $createNonce, $now));
portal_auth_check('portal action nonce replay is refused',
    ! portal_action_nonce_consume('ticket:create', $createNonce, $now));
$expiredNonce = portal_action_nonce('ticket:reply:42', $now - PORTAL_ACTION_NONCE_MAX_AGE_SECONDS - 1);
portal_auth_check('expired portal action nonce is refused',
    ! portal_action_nonce_consume('ticket:reply:42', $expiredNonce, $now));
portal_auth_expect('malformed action purpose is refused', PortalAuthException::class,
    fn() => portal_action_nonce('../ticket', $now));

$_SESSION[PORTAL_SESSION_KEY]['expires_at'] = $now;
portal_auth_check('expired local session is refused', portal_local_identity($now) === null);
$_SESSION[PORTAL_SESSION_KEY] = array_replace($validSession, [
    'expires_at' => $validSession['issued_at'] + PORTAL_SESSION_MAX_SECONDS + 1,
]);
portal_auth_check('local session over eight hours is refused', portal_local_identity($now) === null);
$_SESSION[PORTAL_SESSION_KEY] = array_replace($validSession, ['role' => 'msp_viewer']);
portal_auth_check('non-client role cannot be planted in local session', portal_local_identity($now) === null);
$_SESSION[PORTAL_SESSION_KEY] = array_replace($validSession, ['session_version' => '7']);
portal_auth_check('malformed authorization generation is refused', portal_local_identity($now) === null);

$clock = $now;
$http = new PortalAuthFakeHttp();
$http->payload = [
    'generated_at' => gmdate('Y-m-d\TH:i:sP', $clock),
    'count' => 0,
    'revoked' => [],
    'authorization_count' => 1,
    'authorizations' => [['sub' => 't9u4', 'session_version' => '2.7']],
];
$checker = new RevocationChecker(
    'https://id.example.test/oauth/revocations.php',
    'safeharbor-portal-test',
    str_repeat('s', 48),
    $http,
    new MemoryRevocationCache(),
    60,
    300,
    static function () use (&$clock): int { return $clock; },
);
portal_auth_check('current subject/version is admitted by authorization inventory',
    $checker->isRevoked('t9u4', '2.7') === false);
portal_auth_check('fresh revocation inventory is reused for sixty seconds',
    $checker->isRevoked('t9u4', '2.7') === false && $http->calls === 1);
portal_auth_check('missing subject is revoked', $checker->isRevoked('t9u5', '2.7') === true);
portal_auth_check('session-version mismatch is revoked', $checker->isRevoked('t9u4', '2.8') === true);

$clock += 61;
$http->fail = true;
portal_auth_check('short revocation outage uses bounded cached authorization',
    $checker->isRevoked('t9u4', '2.7') === false);
$clock = $now + 301;
portal_auth_expect('over-stale revocation state fails closed',
    RevocationUnavailableException::class,
    fn() => $checker->isRevoked('t9u4', '2.7'));

$revokedHttp = new PortalAuthFakeHttp();
$revokedHttp->payload = [
    'generated_at' => gmdate('Y-m-d\TH:i:sP', $now),
    'count' => 1,
    'revoked' => [[
        'sub' => 't9u4',
        'since' => gmdate('Y-m-d\TH:i:sP', $now - 10),
        'reason' => 'role_not_allowed',
    ]],
    'authorization_count' => 1,
    'authorizations' => [['sub' => 't9u4', 'session_version' => '2.7']],
];
$revokedChecker = new RevocationChecker(
    'https://id.example.test/oauth/revocations.php',
    'safeharbor-portal-test',
    str_repeat('s', 48),
    $revokedHttp,
    new MemoryRevocationCache(),
    60,
    300,
    static fn(): int => $now,
);
portal_auth_check('explicit revocation wins over matching inventory',
    $revokedChecker->isRevoked('t9u4', '2.7') === true);

$objectAuthorizationHttp = new PortalAuthFakeHttp();
$objectAuthorizationPayload = (object) [
    'generated_at' => gmdate('Y-m-d\TH:i:sP', $now),
    'count' => 0,
    'revoked' => [],
    'authorization_count' => 1,
    'authorizations' => [],
];
$objectAuthorizationPayload->authorizations = (object) [
    '0' => (object) ['sub' => 't9u4', 'session_version' => '2.7'],
];
$objectAuthorizationHttp->rawBody = json_encode(
    $objectAuthorizationPayload,
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);
$objectAuthorizationChecker = new RevocationChecker(
    'https://id.example.test/oauth/revocations.php',
    'safeharbor-portal-test-object-authorizations',
    str_repeat('s', 48),
    $objectAuthorizationHttp,
    new MemoryRevocationCache(),
    60,
    300,
    static fn(): int => $now,
);
portal_auth_expect(
    'numeric-key authorization object cannot masquerade as the response array',
    RevocationUnavailableException::class,
    fn() => $objectAuthorizationChecker->isRevoked('t9u4', '2.7'),
);

$objectRevokedHttp = new PortalAuthFakeHttp();
$objectRevokedPayload = (object) [
    'generated_at' => gmdate('Y-m-d\TH:i:sP', $now),
    'count' => 0,
    'revoked' => [],
    'authorization_count' => 1,
    'authorizations' => [(object) ['sub' => 't9u4', 'session_version' => '2.7']],
];
$objectRevokedPayload->revoked = (object) [];
$objectRevokedHttp->rawBody = json_encode(
    $objectRevokedPayload,
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);
$objectRevokedChecker = new RevocationChecker(
    'https://id.example.test/oauth/revocations.php',
    'safeharbor-portal-test-object-revoked',
    str_repeat('s', 48),
    $objectRevokedHttp,
    new MemoryRevocationCache(),
    60,
    300,
    static fn(): int => $now,
);
portal_auth_expect(
    'empty revoked object cannot masquerade as the response array',
    RevocationUnavailableException::class,
    fn() => $objectRevokedChecker->isRevoked('t9u4', '2.7'),
);

$kitBlobs = [
    'attempt_store.php' => 'be9d617a7836927396530672e2ceb57491375ef9',
    'eightwestid.php' => 'e3ed851232efe57da37ace71dc002a311f107c89',
    'errors.php' => '86ed368cada6aa6ac7778e519fcba665b5dbe024',
    'http.php' => 'b553cc7ab69aad3d763ce122f781f18ed39763cf',
    'jwt.php' => 'a7a68e2c9e37f620f568d51c759daa647321c0c7',
    'policy.php' => 'a2bebc9a5926e6b2c6270110e15718160dce66b2',
    'private_file_cache.php' => 'aa9ef0fe1e62d6130e8fe66d04f60efb27f8671c',
    'revocations.php' => '6d9b007198d170d481d68996f59e1c1e79438813',
];
foreach ($kitBlobs as $file => $expected) {
    $content = file_get_contents(__DIR__ . '/../lib/eightwestid/' . $file);
    // core.autocrlf may materialize CRLF on Windows; Git's canonical blob is LF.
    $canonical = is_string($content) ? str_replace("\r\n", "\n", $content) : '';
    $actual = $canonical !== ''
        ? hash('sha1', 'blob ' . strlen($canonical) . "\0" . $canonical)
        : '';
    portal_auth_check("reviewed oidc_v1 blob {$file} is pinned", hash_equals($expected, $actual));
}

$authSource = file_get_contents(__DIR__ . '/../lib/portal_auth.php');
portal_auth_check('portal session stores exact signed revocation pair',
    is_string($authSource)
    && str_contains($authSource, "'subject' => \$identity->subject")
    && str_contains($authSource, "'session_version' => \$identity->sessionVersion"));
portal_auth_check('local portal mapping and authorization ignore presentation and informational claims',
    is_string($authSource)
    && !str_contains($authSource, '$identity->preferences')
    && !str_contains($authSource, '$identity->theme')
    && !str_contains($authSource, '$identity->avatar')
    && !str_contains($authSource, '$identity->email')
    && !str_contains($authSource, '$identity->tenantId'));

$indexSource = file_get_contents(__DIR__ . '/../public/portal/index.php');
portal_auth_check('default-off gate runs before session and data access',
    is_string($indexSource)
    && strpos($indexSource, 'if (! portal_enabled())') < strpos($indexSource, 'portal_authenticated_context'));
portal_auth_check('help-center index is GET-only',
    is_string($indexSource)
    && str_contains($indexSource, "REQUEST_METHOD'] ?? 'GET') !== 'GET'")
    && str_contains($indexSource, "header('Allow: GET')"));
$newTicketSource = file_get_contents(__DIR__ . '/../public/portal/new.php');
$ticketDetailSource = file_get_contents(__DIR__ . '/../public/portal/ticket.php');
foreach (['new ticket' => $newTicketSource, 'ticket detail' => $ticketDetailSource] as $route => $source) {
    portal_auth_check("{$route} default-off gate runs before request/session handling",
        is_string($source)
        && strpos($source, 'if (! portal_enabled())') < strpos($source, "REQUEST_METHOD'] ?? 'GET'")
        && strpos($source, 'if (! portal_enabled())') < strpos($source, 'portal_authenticated_context'));
    portal_auth_check("{$route} mutation requires CSRF plus one-use action nonce",
        is_string($source)
        && str_contains($source, 'portal_csrf_valid(')
        && str_contains($source, 'portal_action_nonce_consume(')
        && str_contains($source, "true, 303"));
    $mutationMarker = $route === 'new ticket' ? 'portal_create_ticket(' : 'portal_reply_to_ticket(';
    portal_auth_check("{$route} persists the consumed nonce before mutation",
        is_string($source)
        && strpos($source, 'portal_action_nonce_consume(') < strpos($source, 'session_write_close()')
        && strpos($source, 'session_write_close()') < strpos($source, $mutationMarker));
}
$logoutSource = file_get_contents(__DIR__ . '/../public/portal/logout.php');
$logoutPortalGatePosition = is_string($logoutSource) ? strpos($logoutSource, 'if (! portal_enabled())') : false;
$logoutMethodPosition = is_string($logoutSource) ? strpos($logoutSource, "REQUEST_METHOD'] ?? 'GET'") : false;
$logoutSessionPosition = is_string($logoutSource) ? strpos($logoutSource, 'portal_session_start()') : false;
portal_auth_check('logout default-off gate runs before method and session handling',
    is_string($logoutSource)
    && $logoutPortalGatePosition !== false
    && $logoutMethodPosition !== false
    && $logoutSessionPosition !== false
    && $logoutPortalGatePosition < $logoutMethodPosition
    && $logoutPortalGatePosition < $logoutSessionPosition);
portal_auth_check('logout is POST plus CSRF and destroys local state before redirect',
    is_string($logoutSource)
    && str_contains($logoutSource, "REQUEST_METHOD'] ?? 'GET') !== 'POST'")
    && str_contains($logoutSource, 'portal_csrf_valid(')
    && strpos($logoutSource, 'portal_destroy_session();') < strpos($logoutSource, "header('Location: '"));
$callbackSource = file_get_contents(__DIR__ . '/../public/portal/callback.php');
portal_auth_check('callback state cookie clears before any failure response body',
    is_string($callbackSource)
    && strpos($callbackSource, '$client->clearStateCookie()')
        < strpos($callbackSource, "portal_render_error(401"));

echo "Portal auth: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
