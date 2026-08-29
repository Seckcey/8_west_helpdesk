<?php
/** Strict RS256 ID-token verification against the issuer's JWKS. */
declare(strict_types=1);

namespace EightWest\Id;

final class IdTokenVerifier
{
    /** @var array<string,mixed>|null */
    private ?array $jwks = null;
    /** @var callable(string):void|null */
    private $reporter;
    /** @var callable():int */
    private $clock;

    public function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly string $requiredProduct,
        private readonly string $jwksUri,
        private readonly HttpClient $http,
        private readonly string $mfaMode = 'report',
        private readonly int $mfaMaxAge = MAX_MFA_AGE_SECONDS,
        private readonly int $clockSkew = 120,
        ?callable $reporter = null,
        ?callable $clock = null,
    ) {
        $this->reporter = $reporter;
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function verify(string $token, string $expectedNonce): Identity
    {
        if ($token === '' || strlen($token) > 32768) {
            throw new PolicyException('token_invalid');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new PolicyException('token_invalid');
        }
        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
        [$header, $headerShape] = decode_json_segment_with_shape($encodedHeader, 'token_header_invalid');
        [$claims, $claimsShape] = decode_json_segment_with_shape($encodedClaims, 'token_claims_invalid');
        $signature = strict_b64url_decode($encodedSignature, 'token_signature_invalid');

        if (($header['alg'] ?? null) !== 'RS256'
            || (isset($header['typ']) && $header['typ'] !== 'JWT')
            || isset($header['jku'])
            || isset($header['jwk'])
            || isset($header['x5u'])
            || (property_exists($headerShape, 'crit')
                && (! is_array($headerShape->crit) || $header['crit'] !== []))) {
            throw new PolicyException('token_algorithm_invalid');
        }
        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || $kid === '' || strlen($kid) > 128
            || preg_match('/^[A-Za-z0-9._~-]+$/D', $kid) !== 1) {
            throw new PolicyException('token_key_invalid');
        }

        $publicKey = $this->publicKeyForKid($kid, false);
        if ($publicKey === null) {
            // A signing-key rotation can introduce a kid before the normal
            // cache expires. Refresh exactly once; never accept a random key.
            $publicKey = $this->publicKeyForKid($kid, true);
        }
        if ($publicKey === null) {
            throw new PolicyException('token_key_unknown');
        }
        if (! function_exists('openssl_verify')) {
            throw new ProtocolException('The PHP OpenSSL extension is required to verify ID tokens.');
        }
        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedClaims,
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );
        if ($verified !== 1) {
            throw new PolicyException('token_signature_invalid');
        }

        return $this->identityFromClaims($claims, $claimsShape, $expectedNonce);
    }

    /** @param array<string,mixed> $claims */
    private function identityFromClaims(array $claims, \stdClass $claimsShape, string $expectedNonce): Identity
    {
        $now = ($this->clock)();
        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new PolicyException('issuer_mismatch');
        }
        $audience = $claims['aud'] ?? null;
        $audienceValid = $audience === $this->clientId
            || (property_exists($claimsShape, 'aud')
                && is_array($claimsShape->aud)
                && is_array($audience)
                && array_is_list($audience)
                && count($audience) === 1
                && $audience[0] === $this->clientId);
        if (! $audienceValid || (isset($claims['azp']) && $claims['azp'] !== $this->clientId)) {
            throw new PolicyException('audience_mismatch');
        }
        $issuedAt = $claims['iat'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        if (! is_int($issuedAt) || ! is_int($expiresAt)
            || $issuedAt <= 0
            || $issuedAt > $now + $this->clockSkew
            || $expiresAt <= $now - $this->clockSkew
            || $expiresAt <= $issuedAt
            || $expiresAt - $issuedAt > 900) {
            throw new PolicyException('token_time_invalid');
        }
        if (isset($claims['nbf'])
            && (! is_int($claims['nbf']) || $claims['nbf'] > $now + $this->clockSkew)) {
            throw new PolicyException('token_time_invalid');
        }
        $nonce = $claims['nonce'] ?? null;
        if (! is_string($nonce) || $nonce === '' || ! hash_equals($expectedNonce, $nonce)) {
            throw new PolicyException('nonce_mismatch');
        }

        $subject = bounded_claim_string($claims, 'sub', 190, 'subject_missing');
        $email = bounded_claim_string($claims, 'email', 320, 'email_missing');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ($claims['email_verified'] ?? null) !== true) {
            throw new PolicyException('email_unverified');
        }
        $name = bounded_claim_string($claims, 'name', 190, 'name_missing');
        $tenant = bounded_claim_string($claims, '8west:tenant', 128, 'tenant_missing');
        $tenantId = bounded_claim_string($claims, '8west:tenant_id', 128, 'tenant_missing');
        $subjectParts = [];
        if (preg_match('/^t([1-9][0-9]*)u([1-9][0-9]*)$/D', $subject, $subjectParts) !== 1
            || ! hash_equals($tenantId, $subjectParts[1])
            || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $tenant) !== 1) {
            throw new PolicyException('subject_tenant_mismatch');
        }
        $sessionVersion = $claims['8west:session_version'] ?? null;
        if (! valid_session_version($sessionVersion)) {
            throw new PolicyException('session_version_invalid');
        }

        if (! property_exists($claimsShape, '8west:products')
            || ! is_array($claimsShape->{'8west:products'})) {
            throw new PolicyException('products_invalid');
        }
        $products = validated_string_list(
            $claims['8west:products'] ?? null,
            'products_invalid',
            64,
            '/^[a-z][a-z0-9_]{0,31}$/D',
        );
        if (! in_array($this->requiredProduct, $products, true)) {
            throw new PolicyException('required_product_missing');
        }
        if (($claims['8west:role_contract'] ?? null) !== ROLE_CONTRACT) {
            throw new PolicyException('role_contract_invalid');
        }
        $role = $claims['8west:role'] ?? null;
        if (! is_string($role) || ! array_key_exists($role, KNOWN_ROLES)) {
            throw new PolicyException('role_unknown');
        }
        $capabilityRole = $claims['8west:capability_role'] ?? null;
        if (! is_string($capabilityRole) || $capabilityRole !== KNOWN_ROLES[$role]) {
            throw new PolicyException('capability_role_invalid');
        }

        if (property_exists($claimsShape, 'amr') && ! is_array($claimsShape->amr)) {
            throw new PolicyException('amr_invalid');
        }
        $methodsRaw = $claims['amr'] ?? [];
        $methods = validated_string_list($methodsRaw, 'amr_invalid', 10);
        foreach ($methods as $method) {
            if (! in_array($method, KNOWN_AMR, true)) {
                throw new PolicyException('amr_invalid');
            }
        }
        $authTime = is_int($claims['auth_time'] ?? null) ? $claims['auth_time'] : 0;
        $mfaTimeValue = $claims['8west:mfa_time'] ?? null;
        if ($mfaTimeValue !== null && ! is_int($mfaTimeValue)) {
            throw new PolicyException('mfa_time_invalid');
        }
        $mfaEnrolled = $claims['8west:mfa'] ?? false;
        $mfaAuthenticated = $claims['8west:mfa_authenticated'] ?? false;
        if (! is_bool($mfaEnrolled) || ! is_bool($mfaAuthenticated)) {
            throw new PolicyException('mfa_claim_invalid');
        }
        $authPolicy = is_string($claims['8west:auth_policy'] ?? null)
            ? $claims['8west:auth_policy']
            : '';
        $mfa = evaluate_mfa_policy($claims, $now, $this->mfaMaxAge, $this->clockSkew);
        if (! $mfa->compliant && $this->mfaMode === 'enforce') {
            throw new PolicyException($mfa->reason);
        }
        if (! $mfa->compliant && $this->mfaMode === 'report') {
            if ($this->reporter !== null) {
                ($this->reporter)($mfa->reason);
            } else {
                error_log('[8west-id] mfa_policy_report reason=' . $mfa->reason);
            }
        }

        $theme = $claims['8west:theme'] ?? 'system';
        if (! is_string($theme) || ! in_array($theme, ['dark', 'light', 'system'], true)) {
            throw new PolicyException('theme_invalid');
        }
        $avatar = $claims['8west:avatar'] ?? null;
        if ($avatar !== null) {
            $avatarParts = is_string($avatar) ? parse_url($avatar) : false;
            if (! is_string($avatar)
                || strlen($avatar) > 2048
                || filter_var($avatar, FILTER_VALIDATE_URL) === false
                || ! is_array($avatarParts)
                || ($avatarParts['scheme'] ?? null) !== 'https'
                || ! is_string($avatarParts['host'] ?? null)
                || $avatarParts['host'] === ''
                || isset($avatarParts['user'])
                || isset($avatarParts['pass'])
                || isset($avatarParts['fragment'])) {
                throw new PolicyException('avatar_invalid');
            }
        }
        $preferences = validated_preferences_claim(
            $claims['8west:preferences'] ?? null,
            $subject,
            $name,
            $theme,
            $avatar,
        );

        return new Identity(
            $subject,
            $email,
            $name,
            $tenant,
            $tenantId,
            $sessionVersion,
            $products,
            $role,
            $capabilityRole,
            $theme,
            $avatar,
            $preferences,
            $mfaEnrolled,
            $mfaAuthenticated,
            $mfaTimeValue,
            $authTime,
            $methods,
            $authPolicy,
            $mfa,
        );
    }

    private function publicKeyForKid(string $kid, bool $forceRefresh): ?string
    {
        $jwks = $this->loadJwks($forceRefresh);
        $matches = [];
        foreach ($jwks['keys'] as $jwk) {
            if (is_array($jwk) && ($jwk['kid'] ?? null) === $kid) {
                $matches[] = $jwk;
            }
        }
        if (count($matches) > 1) {
            throw new PolicyException('token_key_ambiguous');
        }
        return count($matches) === 1 ? rsa_jwk_to_pem($matches[0]) : null;
    }

    /** @return array{keys:list<array<string,mixed>>} */
    private function loadJwks(bool $forceRefresh): array
    {
        if (! $forceRefresh && $this->jwks !== null) {
            return $this->jwks;
        }
        $response = $this->http->send('GET', $this->jwksUri, ['Accept: application/json'], '', 262144);
        if ($response->status !== 200) {
            throw new ProtocolException('The 8 West ID signing keys are unavailable.');
        }
        try {
            $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProtocolException('The 8 West ID signing keys were invalid.');
        }
        if (! is_array($decoded)
            || ! is_array($decoded['keys'] ?? null)
            || ! array_is_list($decoded['keys'])
            || count($decoded['keys']) > 20) {
            throw new ProtocolException('The 8 West ID signing keys were invalid.');
        }
        $this->jwks = $decoded;
        return $this->jwks;
    }
}

/** @return array<string,mixed> */
function decode_json_segment(string $encoded, string $reason): array
{
    return decode_json_segment_with_shape($encoded, $reason)[0];
}

/** @return array{0:array<string,mixed>,1:\stdClass} */
function decode_json_segment_with_shape(string $encoded, string $reason): array
{
    $raw = strict_b64url_decode($encoded, $reason);
    try {
        $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $shape = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        throw new PolicyException($reason);
    }
    if (! is_array($value) || array_is_list($value) || ! $shape instanceof \stdClass) {
        throw new PolicyException($reason);
    }
    return [$value, $shape];
}

function strict_b64url_decode(string $encoded, string $reason): string
{
    if ($encoded === ''
        || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1
        || strlen($encoded) % 4 === 1) {
        throw new PolicyException($reason);
    }
    $padding = (4 - strlen($encoded) % 4) % 4;
    $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);
    if (! is_string($decoded)) {
        throw new PolicyException($reason);
    }
    $canonical = rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=');
    if (! hash_equals($canonical, $encoded)) {
        throw new PolicyException($reason);
    }
    return $decoded;
}

function rsa_jwk_to_pem(array $jwk): string
{
    if (($jwk['kty'] ?? null) !== 'RSA'
        || (isset($jwk['use']) && $jwk['use'] !== 'sig')
        || (isset($jwk['alg']) && $jwk['alg'] !== 'RS256')
        || ! is_string($jwk['n'] ?? null)
        || ! is_string($jwk['e'] ?? null)) {
        throw new PolicyException('token_key_invalid');
    }
    $modulus = strict_b64url_decode($jwk['n'], 'token_key_invalid');
    $exponent = strict_b64url_decode($jwk['e'], 'token_key_invalid');
    $modulus = ltrim($modulus, "\x00");
    if (strlen($modulus) < 256 || strlen($modulus) > 1024
        || (strlen($modulus) === 256 && ord($modulus[0]) < 0x80)
        || strlen($exponent) < 1 || strlen($exponent) > 8
        || $exponent[0] === "\x00"
        || $exponent === "\x01"
        || (ord($exponent[strlen($exponent) - 1]) & 1) !== 1) {
        throw new PolicyException('token_key_invalid');
    }

    $rsa = der_wrap(0x30, der_integer($modulus) . der_integer($exponent));
    $algorithm = der_wrap(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
    $spki = der_wrap(0x30, $algorithm . der_wrap(0x03, "\x00" . $rsa));
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($spki), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function der_integer(string $bytes): string
{
    $bytes = ltrim($bytes, "\x00");
    if ($bytes === '') $bytes = "\x00";
    if ((ord($bytes[0]) & 0x80) !== 0) $bytes = "\x00" . $bytes;
    return der_wrap(0x02, $bytes);
}

function der_wrap(int $tag, string $value): string
{
    $length = strlen($value);
    if ($length < 128) {
        $encodedLength = chr($length);
    } else {
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        $encodedLength = chr(0x80 | strlen($bytes)) . $bytes;
    }
    return chr($tag) . $encodedLength . $value;
}
