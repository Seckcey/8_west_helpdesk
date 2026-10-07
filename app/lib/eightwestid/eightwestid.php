<?php
/**
 * Zero-dependency 8 West ID client for plain PHP and framework adapters.
 *
 * The package validates identity. The host application still owns local user
 * provisioning, tenant-scoped authorization, its session, and local logout.
 */
declare(strict_types=1);

namespace EightWest\Id;

require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/attempt_store.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/revocations.php';

const AUTHORIZATION_TTL_SECONDS = 300;
const OIDC_SCOPES = ['openid', 'profile', 'email'];

final class AuthorizationRequest
{
    public function __construct(
        public readonly string $url,
        public readonly string $state,
        public readonly int $expiresAt,
        public readonly string $cookieName,
        public readonly string $cookiePath,
    ) {
    }

    /** @return array{expires:int,path:string,secure:true,httponly:true,samesite:string} */
    public function cookieOptions(): array
    {
        return [
            'expires' => $this->expiresAt,
            'path' => $this->cookiePath,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}

final class Client
{
    private readonly string $issuer;
    private readonly string $clientId;
    private readonly string $clientSecret;
    private readonly string $redirectUri;
    private readonly string $requiredProduct;
    private readonly string $mfaMode;
    private readonly int $mfaMaxAge;
    private readonly int $clockSkew;
    private readonly string $stateCookieName;
    private readonly string $stateCookiePath;
    private readonly int $revocationRefresh;
    private readonly int $revocationMaximumStale;
    private readonly AttemptStore $attempts;
    private readonly HttpClient $http;
    private readonly RevocationCache $revocationCache;
    /** @var callable():int */
    private $clock;
    /** @var callable(string):void|null */
    private $reporter;
    /** @var array<string,mixed>|null */
    private ?array $metadata = null;
    private ?RevocationChecker $revocationChecker = null;

    /**
     * Required config keys: issuer, client_id, client_secret, redirect_uri,
     * required_product. Everything else has a conservative suite default.
     *
     * @param array<string,mixed> $config
     */
    public function __construct(
        array $config,
        ?AttemptStore $attempts = null,
        ?HttpClient $http = null,
        ?RevocationCache $revocationCache = null,
    ) {
        $this->issuer = rtrim(required_config_string($config, 'issuer'), '/');
        assert_https_url($this->issuer, 'issuer');
        $issuerParts = parse_url($this->issuer);
        if (($issuerParts['query'] ?? '') !== '') {
            throw new ConfigurationException('issuer must not contain a query.');
        }

        $this->clientId = required_config_string($config, 'client_id');
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,95}$/D', $this->clientId) !== 1) {
            throw new ConfigurationException('client_id is invalid.');
        }
        $this->clientSecret = required_config_string($config, 'client_secret');
        if (strlen($this->clientSecret) < 32 || strlen($this->clientSecret) > 512
            || preg_match('/[\x00-\x20\x7f]/', $this->clientSecret) === 1) {
            throw new ConfigurationException('client_secret is invalid.');
        }
        $this->redirectUri = required_config_string($config, 'redirect_uri');
        assert_https_url($this->redirectUri, 'redirect_uri');
        $this->requiredProduct = required_config_string($config, 'required_product');
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $this->requiredProduct) !== 1) {
            throw new ConfigurationException('required_product is invalid.');
        }

        $mode = strtolower(trim(is_string($config['mfa_policy_mode'] ?? null)
            ? $config['mfa_policy_mode']
            : 'report'));
        if (! in_array($mode, ['off', 'report', 'enforce'], true)) {
            throw new ConfigurationException('mfa_policy_mode must be off, report, or enforce.');
        }
        $this->mfaMode = $mode;
        $this->mfaMaxAge = is_int($config['mfa_max_age_seconds'] ?? null)
            ? $config['mfa_max_age_seconds']
            : MAX_MFA_AGE_SECONDS;
        if ($this->mfaMaxAge < 1 || $this->mfaMaxAge > MAX_MFA_AGE_SECONDS) {
            throw new ConfigurationException('mfa_max_age_seconds must be between 1 and 2592000.');
        }
        $this->clockSkew = is_int($config['clock_skew_seconds'] ?? null)
            ? $config['clock_skew_seconds']
            : 120;
        if ($this->clockSkew < 0 || $this->clockSkew > 300) {
            throw new ConfigurationException('clock_skew_seconds must be between 0 and 300.');
        }
        $this->revocationRefresh = is_int($config['revocation_refresh_seconds'] ?? null)
            ? $config['revocation_refresh_seconds']
            : 60;
        $this->revocationMaximumStale = is_int($config['revocation_maximum_stale_seconds'] ?? null)
            ? $config['revocation_maximum_stale_seconds']
            : 300;

        $this->clock = is_callable($config['clock'] ?? null)
            ? $config['clock']
            : static fn(): int => time();
        $this->reporter = is_callable($config['mfa_reporter'] ?? null)
            ? $config['mfa_reporter']
            : null;
        $this->http = $http ?? new NativeHttpClient();
        $this->attempts = $attempts ?? new PhpSessionAttemptStore(
            '_eightwest_id_attempts_' . substr(hash('sha256', $this->clientId), 0, 12),
        );
        if ($revocationCache !== null) {
            $this->revocationCache = $revocationCache;
        } else {
            $cacheDirectory = $config['revocation_cache_dir'] ?? null;
            if (!is_string($cacheDirectory) || trim($cacheDirectory) === '') {
                throw new ConfigurationException('revocation_cache_dir must name a provisioned private cache directory.');
            }
            $this->revocationCache = new FileRevocationCache($cacheDirectory);
        }

        // __Host- makes the browser reject Domain-scoped sibling-host cookies.
        // Its contract requires Secure, no Domain attribute, and Path=/.
        $this->stateCookiePath = '/';
        $this->stateCookieName = '__Host-ewid_oidc_'
            . substr(hash('sha256', $this->clientId), 0, 16);
    }

    public function begin(): AuthorizationRequest
    {
        $metadata = $this->metadata();
        $now = ($this->clock)();
        $attempt = new Attempt(
            random_b64url(32),
            random_b64url(48),
            random_b64url(32),
            $now,
            $now + AUTHORIZATION_TTL_SECONDS,
        );
        $this->attempts->save($attempt);
        $challenge = base64url_encode(hash('sha256', $attempt->verifier, true));
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', OIDC_SCOPES),
            'state' => $attempt->state,
            'nonce' => $attempt->nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        return new AuthorizationRequest(
            $metadata['authorization_endpoint'] . '?' . $query,
            $attempt->state,
            $attempt->expiresAt,
            $this->stateCookieName,
            $this->stateCookiePath,
        );
    }

    public function redirectToAuthorization(): never
    {
        if (headers_sent()) {
            throw new ProtocolException('Login must begin before output.');
        }
        $request = $this->begin();
        if (! setcookie($request->cookieName, $request->state, $request->cookieOptions())) {
            throw new ProtocolException('The OIDC state cookie could not be set.');
        }
        header('Cache-Control: no-store');
        header('Location: ' . $request->url, true, 302);
        exit;
    }

    /**
     * Exchange a callback and return only a fully validated identity.
     * Access tokens and raw ID tokens are intentionally not retained.
     *
     * @param array<string,mixed> $query Usually $_GET.
     */
    public function handleCallback(array $query, string $stateCookie): Identity
    {
        $state = query_value($query, 'state', 256);
        $responseIssuer = query_value($query, 'iss', 2048);
        if ($state === '' || $stateCookie === '' || ! hash_equals($stateCookie, $state)) {
            throw new PolicyException('state_mismatch');
        }
        if ($responseIssuer === '' || ! hash_equals($this->issuer, $responseIssuer)) {
            throw new PolicyException('authorization_issuer_mismatch');
        }
        $attempt = $this->attempts->consume($state);
        $now = ($this->clock)();
        if ($attempt === null || $attempt->expiresAt <= $now || $attempt->issuedAt > $now + $this->clockSkew) {
            throw new PolicyException('authorization_attempt_expired');
        }
        $remoteError = query_value($query, 'error', 128);
        if ($remoteError !== '') {
            throw new PolicyException('authorization_denied');
        }
        $code = query_value($query, 'code', 1024);
        if ($code === '') {
            throw new PolicyException('authorization_code_missing');
        }

        $metadata = $this->metadata();
        $tokenResponse = $this->exchangeCode($metadata['token_endpoint'], $code, $attempt->verifier);
        $verifier = new IdTokenVerifier(
            $this->issuer,
            $this->clientId,
            $this->requiredProduct,
            $metadata['jwks_uri'],
            $this->http,
            $this->mfaMode,
            $this->mfaMaxAge,
            $this->clockSkew,
            $this->reporter,
            $this->clock,
        );
        return $verifier->verify($tokenResponse['id_token'], $attempt->nonce);
    }

    public function stateCookieName(): string
    {
        return $this->stateCookieName;
    }

    /** @return array{expires:int,path:string,secure:true,httponly:true,samesite:string} */
    public function expiredStateCookieOptions(): array
    {
        return [
            'expires' => time() - 42000,
            'path' => $this->stateCookiePath,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public function clearStateCookie(): void
    {
        if (! headers_sent()) {
            setcookie($this->stateCookieName, '', $this->expiredStateCookieOptions());
        }
    }

    public function logoutUrl(): string
    {
        // This remains available even if discovery is temporarily unavailable.
        // The app must destroy its own session before navigating here.
        return $this->issuer . '/logout.php';
    }

    public function isRevoked(string $subject, string $sessionVersion): bool
    {
        if ($this->revocationChecker === null) {
            $endpoint = $this->issuer . '/oauth/revocations.php';
            if ($this->metadata !== null && is_string($this->metadata['8west_revocations_endpoint'] ?? null)) {
                $endpoint = $this->metadata['8west_revocations_endpoint'];
            }
            $this->revocationChecker = new RevocationChecker(
                $endpoint,
                $this->clientId,
                $this->clientSecret,
                $this->http,
                $this->revocationCache,
                $this->revocationRefresh,
                $this->revocationMaximumStale,
                $this->clock,
            );
        }
        return $this->revocationChecker->isRevoked($subject, $sessionVersion);
    }

    /** @return array<string,mixed> */
    private function metadata(): array
    {
        if ($this->metadata !== null) return $this->metadata;
        $url = $this->issuer . '/.well-known/openid-configuration';
        $response = $this->http->send('GET', $url, ['Accept: application/json'], '', 262144);
        if ($response->status !== 200) {
            throw new ProtocolException('8 West ID discovery is unavailable.');
        }
        try {
            $metadata = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProtocolException('8 West ID discovery returned invalid JSON.');
        }
        if (! is_array($metadata) || array_is_list($metadata)
            || ($metadata['issuer'] ?? null) !== $this->issuer) {
            throw new ProtocolException('8 West ID discovery did not match the configured issuer.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $name) {
            $endpoint = $metadata[$name] ?? null;
            if (! is_string($endpoint)) {
                throw new ProtocolException('8 West ID discovery is incomplete.');
            }
            assert_https_url($endpoint, $name);
            if (! same_origin($this->issuer, $endpoint)) {
                throw new ProtocolException('8 West ID discovery contained a cross-origin endpoint.');
            }
        }
        if (! metadata_list_contains($metadata, 'response_types_supported', 'code')
            || ! metadata_list_contains($metadata, 'grant_types_supported', 'authorization_code')
            || ! metadata_list_contains($metadata, 'code_challenge_methods_supported', 'S256')
            || ! metadata_list_contains($metadata, 'id_token_signing_alg_values_supported', 'RS256')
            || ! metadata_list_contains($metadata, 'token_endpoint_auth_methods_supported', 'client_secret_basic')
            || ($metadata['authorization_response_iss_parameter_supported'] ?? null) !== true) {
            throw new ProtocolException('8 West ID discovery does not support the required secure flow.');
        }
        foreach (OIDC_SCOPES as $scope) {
            if (! metadata_list_contains($metadata, 'scopes_supported', $scope)) {
                throw new ProtocolException('8 West ID discovery does not support the required scopes.');
            }
        }
        if (isset($metadata['8west_revocations_endpoint'])) {
            if (! is_string($metadata['8west_revocations_endpoint'])) {
                throw new ProtocolException('8 West ID discovery contained an invalid revocation endpoint.');
            }
            assert_https_url($metadata['8west_revocations_endpoint'], '8west_revocations_endpoint');
            if (! same_origin($this->issuer, $metadata['8west_revocations_endpoint'])) {
                throw new ProtocolException('8 West ID discovery contained a cross-origin revocation endpoint.');
            }
        }
        $this->metadata = $metadata;
        return $this->metadata;
    }

    /** @return array{id_token:string} */
    private function exchangeCode(string $endpoint, string $code, string $verifier): array
    {
        $form = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $verifier,
        ], '', '&', PHP_QUERY_RFC3986);
        $basic = base64_encode(rawurlencode($this->clientId) . ':' . rawurlencode($this->clientSecret));
        $response = $this->http->send('POST', $endpoint, [
            'Accept: application/json',
            'Authorization: Basic ' . $basic,
            'Content-Type: application/x-www-form-urlencoded',
        ], $form, 1048576);
        if ($response->status !== 200) {
            throw new ProtocolException('8 West ID rejected the authorization-code exchange.');
        }
        try {
            $payload = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProtocolException('8 West ID returned an invalid token response.');
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new ProtocolException('8 West ID returned an invalid token response.');
        }
        $accessToken = $payload['access_token'] ?? null;
        $idToken = $payload['id_token'] ?? null;
        $expiresIn = $payload['expires_in'] ?? null;
        if (! is_string($accessToken) || $accessToken === '' || strlen($accessToken) > 4096
            || ! is_string($idToken) || $idToken === ''
            || ! is_string($payload['token_type'] ?? null)
            || strcasecmp($payload['token_type'], 'Bearer') !== 0
            || ! is_int($expiresIn) || $expiresIn < 1 || $expiresIn > 900
            || ! is_string($payload['scope'] ?? null)) {
            throw new ProtocolException('8 West ID returned an invalid token response.');
        }
        $scopes = preg_split('/\s+/', trim($payload['scope']), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($scopes)
            || count($scopes) !== count(OIDC_SCOPES)
            || array_diff($scopes, OIDC_SCOPES) !== []
            || array_diff(OIDC_SCOPES, $scopes) !== []) {
            throw new ProtocolException('8 West ID returned an invalid token scope.');
        }
        return ['id_token' => $idToken];
    }
}

/** @param array<string,mixed> $config */
function required_config_string(array $config, string $key): string
{
    $value = $config[$key] ?? null;
    if (! is_string($value) || trim($value) === '') {
        throw new ConfigurationException("{$key} is required.");
    }
    return trim($value);
}

function random_b64url(int $bytes): string
{
    return base64url_encode(random_bytes($bytes));
}

function base64url_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** @param array<string,mixed> $query */
function query_value(array $query, string $key, int $maxLength): string
{
    $value = $query[$key] ?? '';
    if (! is_string($value) || strlen($value) > $maxLength
        || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
        return '';
    }
    return $value;
}

/** @param array<string,mixed> $metadata */
function metadata_list_contains(array $metadata, string $key, string $value): bool
{
    $list = $metadata[$key] ?? null;
    return is_array($list) && array_is_list($list) && in_array($value, $list, true);
}
