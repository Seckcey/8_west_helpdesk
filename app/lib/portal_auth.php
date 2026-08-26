<?php
/**
 * Separate customer-portal OIDC session and fail-closed request middleware.
 *
 * This module intentionally does not include lib/auth.php: the technician
 * suite-cookie session and this client-facing OIDC session are different
 * security surfaces with different roles, cookies, and provisioning rules.
 */
declare(strict_types=1);

require_once __DIR__ . '/eightwestid/eightwestid.php';
require_once __DIR__ . '/portal_data.php';

use EightWest\Id\Client as EightWestIdClient;
use EightWest\Id\ConfigurationException as EightWestIdConfigurationException;
use EightWest\Id\EightWestIdException;
use EightWest\Id\Identity as EightWestIdentity;
use EightWest\Id\PhpSessionAttemptStore;
use EightWest\Id\PolicyException as EightWestIdPolicyException;
use EightWest\Id\RevocationUnavailableException;

const PORTAL_SESSION_NAME = 'safeharbor_portal';
const PORTAL_SESSION_KEY = '_safeharbor_portal_identity';
const PORTAL_CSRF_KEY = '_safeharbor_portal_csrf';
const PORTAL_SESSION_MAX_SECONDS = 28800;
const PORTAL_REVOCATION_REFRESH_SECONDS = 60;
const PORTAL_REVOCATION_MAXIMUM_STALE_SECONDS = 300;
const PORTAL_REQUIRED_PRODUCT = 'safeharbor';
const PORTAL_REQUIRED_ISSUER = 'https://id.8westit.com';

class PortalAuthException extends RuntimeException
{
}

final class PortalAuthConfigurationException extends PortalAuthException
{
}

final class PortalIdentityUnavailableException extends PortalAuthException
{
}

final class PortalAuthenticationRejectedException extends PortalAuthException
{
}

function portal_enabled(): bool
{
    return function_exists('cfg') && cfg('portal.enabled', false) === true;
}

function portal_configured_issuer(): string
{
    if (! function_exists('cfg')) {
        throw new PortalAuthConfigurationException('Portal configuration is unavailable.');
    }
    $issuer = cfg('portal.issuer', PORTAL_REQUIRED_ISSUER);
    if (! is_string($issuer)) {
        throw new PortalAuthConfigurationException('The portal issuer is invalid.');
    }
    $issuer = rtrim(trim($issuer), '/');
    $parts = parse_url($issuer);
    if (! is_array($parts)
        || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        throw new PortalAuthConfigurationException('The portal issuer is invalid.');
    }
    if (cfg('app_env', 'production') !== 'dev'
        && ! hash_equals(PORTAL_REQUIRED_ISSUER, $issuer)) {
        throw new PortalAuthConfigurationException('Production customer sign-in requires the exact 8 West ID issuer.');
    }
    return $issuer;
}

/** @return array<string,mixed> */
function portal_oidc_config(): array
{
    if (! function_exists('cfg')) {
        throw new PortalAuthConfigurationException('Portal configuration is unavailable.');
    }

    $sessionLifetime = cfg('portal.session_lifetime_seconds', PORTAL_SESSION_MAX_SECONDS);
    if (! is_int($sessionLifetime) || $sessionLifetime < 300 || $sessionLifetime > PORTAL_SESSION_MAX_SECONDS) {
        throw new PortalAuthConfigurationException('Portal session lifetime must be between 300 seconds and 8 hours.');
    }
    $requiredProduct = cfg('portal.required_product', PORTAL_REQUIRED_PRODUCT);
    if ($requiredProduct !== PORTAL_REQUIRED_PRODUCT) {
        throw new PortalAuthConfigurationException('The customer portal requires the exact safeharbor entitlement.');
    }
    $cookieSecure = cfg('portal.cookie_secure', true);
    if (! is_bool($cookieSecure)
        || (cfg('app_env', 'production') !== 'dev' && $cookieSecure !== true)) {
        throw new PortalAuthConfigurationException('The production portal session cookie must be Secure.');
    }
    $cacheDirectory = cfg('portal.revocation_cache_dir', '');
    if (! is_string($cacheDirectory) || trim($cacheDirectory) === '' || str_contains($cacheDirectory, "\0")) {
        throw new PortalAuthConfigurationException('A private revocation cache directory is required.');
    }

    return [
        'issuer' => portal_configured_issuer(),
        'client_id' => cfg('portal.client_id', ''),
        'client_secret' => cfg('portal.client_secret', ''),
        'redirect_uri' => cfg('portal.redirect_uri', ''),
        'required_product' => PORTAL_REQUIRED_PRODUCT,
        'mfa_policy_mode' => cfg('portal.mfa_policy_mode', 'report'),
        'mfa_max_age_seconds' => cfg('portal.mfa_max_age_seconds', 2592000),
        'revocation_refresh_seconds' => PORTAL_REVOCATION_REFRESH_SECONDS,
        'revocation_maximum_stale_seconds' => PORTAL_REVOCATION_MAXIMUM_STALE_SECONDS,
        'revocation_cache_dir' => trim($cacheDirectory),
        'session_lifetime_seconds' => $sessionLifetime,
        'cookie_secure' => $cookieSecure,
    ];
}

function portal_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() !== PORTAL_SESSION_NAME) {
            throw new PortalAuthConfigurationException('The customer portal cannot share the technician session.');
        }
        return;
    }
    if (headers_sent()) {
        throw new PortalAuthException('The portal session must start before output.');
    }

    $cookieSecure = function_exists('cfg') ? cfg('portal.cookie_secure', true) : true;
    if (! is_bool($cookieSecure)
        || (function_exists('cfg') && cfg('app_env', 'production') !== 'dev' && $cookieSecure !== true)) {
        throw new PortalAuthConfigurationException('Portal cookie configuration is invalid.');
    }
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_name(PORTAL_SESSION_NAME);
    session_set_cookie_params([
        'path' => '/portal',
        'secure' => $cookieSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (! session_start()) {
        throw new PortalAuthException('The customer portal session could not be started.');
    }
}

function portal_oidc_client(): EightWestIdClient
{
    $config = portal_oidc_config();
    try {
        return new EightWestIdClient([
            'issuer' => $config['issuer'],
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect_uri'],
            'required_product' => $config['required_product'],
            'mfa_policy_mode' => $config['mfa_policy_mode'],
            'mfa_max_age_seconds' => $config['mfa_max_age_seconds'],
            'revocation_refresh_seconds' => $config['revocation_refresh_seconds'],
            'revocation_maximum_stale_seconds' => $config['revocation_maximum_stale_seconds'],
            'revocation_cache_dir' => $config['revocation_cache_dir'],
            'mfa_reporter' => static fn(string $reason): bool => error_log(
                '[safeharbor-portal] oidc_mfa_report reason=' . $reason
            ),
        ], new PhpSessionAttemptStore('_safeharbor_portal_oidc_attempts'));
    } catch (EightWestIdConfigurationException $error) {
        throw new PortalAuthConfigurationException('The customer portal OIDC client is not configured.', 0, $error);
    }
}

function portal_role_allowed(string $role): bool
{
    return in_array($role, PORTAL_CLIENT_ROLES, true);
}

/** @return array<string,mixed> */
function portal_establish_identity(PDO $pdo, EightWestIdentity $identity, ?int $now = null): array
{
    if (! portal_role_allowed($identity->role)) {
        throw new PortalAuthenticationRejectedException('This 8 West ID role is not admitted to the customer portal.');
    }
    if (! in_array(PORTAL_REQUIRED_PRODUCT, $identity->products, true)) {
        throw new PortalAuthenticationRejectedException('The Safeharbor entitlement is required.');
    }
    $slug = portal_identity_tenant_slug($identity->tenant);
    $binding = portal_active_binding_by_identity($pdo, $slug);
    if ($binding === null) {
        throw new PortalAuthenticationRejectedException('No active customer portal binding exists for this identity tenant.');
    }

    $binding = portal_active_binding_recheck(
        $pdo,
        (int)$binding['id'],
        $slug,
        (int)$binding['tenant_id'],
        (int)$binding['client_id'],
    );
    if ($binding === null) {
        throw new PortalAuthenticationRejectedException('The customer portal binding changed during sign-in.');
    }

    portal_session_start();
    if (! session_regenerate_id(true)) {
        throw new PortalAuthException('The customer portal session could not be rotated.');
    }
    $issuedAt = $now ?? time();
    $lifetime = (int)portal_oidc_config()['session_lifetime_seconds'];
    $_SESSION[PORTAL_SESSION_KEY] = [
        'subject' => $identity->subject,
        'session_version' => $identity->sessionVersion,
        'identity_tenant_slug' => $slug,
        'role' => $identity->role,
        'display_name' => $identity->name,
        'binding_id' => (int)$binding['id'],
        'tenant_id' => (int)$binding['tenant_id'],
        'client_id' => (int)$binding['client_id'],
        'issued_at' => $issuedAt,
        'expires_at' => $issuedAt + $lifetime,
    ];
    $_SESSION[PORTAL_CSRF_KEY] = bin2hex(random_bytes(32));
    return $_SESSION[PORTAL_SESSION_KEY];
}

/** @return array<string,mixed>|null */
function portal_local_identity(?int $now = null): ?array
{
    $identity = $_SESSION[PORTAL_SESSION_KEY] ?? null;
    if (! is_array($identity)) return null;
    $now ??= time();

    $stringKeys = [
        'subject', 'session_version', 'identity_tenant_slug', 'role', 'display_name',
    ];
    foreach ($stringKeys as $key) {
        if (! is_string($identity[$key] ?? null) || $identity[$key] === '') return null;
    }
    foreach (['binding_id', 'tenant_id', 'client_id', 'issued_at', 'expires_at'] as $key) {
        if (! is_int($identity[$key] ?? null) || $identity[$key] < 1) return null;
    }
    if (! portal_role_allowed($identity['role'])
        || strlen($identity['display_name']) > 190
        || preg_match('/[\x00-\x1f\x7f]/', $identity['display_name']) === 1
        || ! EightWest\Id\valid_revocation_subject($identity['subject'])
        || ! EightWest\Id\valid_session_version($identity['session_version'])
        || $identity['issued_at'] > $now + 120
        || $identity['expires_at'] <= $now
        || $identity['expires_at'] <= $identity['issued_at']
        || $identity['expires_at'] - $identity['issued_at'] > PORTAL_SESSION_MAX_SECONDS) {
        return null;
    }
    try {
        if (portal_identity_tenant_slug($identity['identity_tenant_slug']) !== $identity['identity_tenant_slug']) {
            return null;
        }
    } catch (PortalDataValidationException) {
        return null;
    }
    return $identity;
}

/**
 * @param callable(string,string):bool|null $revocationCheck
 * @return array{identity:array<string,mixed>,binding:array<string,mixed>}|null
 */
function portal_authenticated_context(
    PDO $pdo,
    ?callable $revocationCheck = null,
    ?int $now = null,
): ?array {
    portal_session_start();
    $identity = portal_local_identity($now);
    if ($identity === null) {
        portal_destroy_session();
        return null;
    }

    try {
        $revoked = $revocationCheck !== null
            ? $revocationCheck($identity['subject'], $identity['session_version'])
            : portal_oidc_client()->isRevoked($identity['subject'], $identity['session_version']);
    } catch (RevocationUnavailableException $error) {
        throw new PortalIdentityUnavailableException(
            'Identity validation is temporarily unavailable. Try again shortly.',
            0,
            $error,
        );
    } catch (EightWestIdException $error) {
        throw new PortalIdentityUnavailableException(
            'Identity validation is temporarily unavailable. Try again shortly.',
            0,
            $error,
        );
    }

    if ($revoked) {
        portal_destroy_session();
        return null;
    }
    $binding = portal_active_binding_recheck(
        $pdo,
        $identity['binding_id'],
        $identity['identity_tenant_slug'],
        $identity['tenant_id'],
        $identity['client_id'],
    );
    if ($binding === null) {
        portal_destroy_session();
        return null;
    }
    return ['identity' => $identity, 'binding' => $binding];
}

function portal_csrf_token(): string
{
    portal_session_start();
    $token = $_SESSION[PORTAL_CSRF_KEY] ?? '';
    if (! is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        $token = bin2hex(random_bytes(32));
        $_SESSION[PORTAL_CSRF_KEY] = $token;
    }
    return $token;
}

function portal_csrf_valid(mixed $sent): bool
{
    $expected = $_SESSION[PORTAL_CSRF_KEY] ?? '';
    return is_string($sent)
        && is_string($expected)
        && $expected !== ''
        && hash_equals($expected, $sent);
}

function portal_destroy_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => '/portal',
            'domain' => '',
            'secure' => (bool)$params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function portal_central_logout_url(): string
{
    return portal_configured_issuer() . '/logout.php';
}

function portal_callback_failure_reason(Throwable $error): string
{
    if ($error instanceof EightWestIdPolicyException) return $error->reason();
    if ($error instanceof PortalAuthenticationRejectedException) return 'local_policy_rejected';
    if ($error instanceof EightWestIdException) return 'oidc_protocol_rejected';
    if ($error instanceof PortalDataException) return 'binding_rejected';
    return 'unexpected_callback_failure';
}
