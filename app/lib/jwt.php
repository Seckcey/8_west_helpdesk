<?php
/**
 * Minimal HS256 JWT codec for the 8 West ID suite token.
 * Phase 1 of docs/suite-sso-contract.md: signed cookie shared across
 * *.8westit.com; claims mirror the OIDC contract so a later upgrade to
 * authorization-code flow does not change app-side session shape.
 */
declare(strict_types=1);

require_once __DIR__ . '/suite_auth_policy.php';

function b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

/** @return array{0: array<string,mixed>|null, 1: string|null} */
function jwt_decode_header_reason(string $encodedHeader, ?string $expectedAlgorithm = null): array
{
    $json = b64url_decode($encodedHeader);
    $shape = json_decode($json);
    $header = json_decode($json, true);
    if (! $shape instanceof stdClass
        || ! is_array($header)
        || ($expectedAlgorithm !== null && ($header['alg'] ?? null) !== $expectedAlgorithm)
        || (property_exists($shape, 'crit')
            && (! is_array($shape->crit) || ($header['crit'] ?? null) !== []))) {
        return [null, 'unexpected_algorithm'];
    }
    return [$header, null];
}

/** @return array{0: array<string,mixed>|null, 1: string|null} */
function jwt_decode_claims_reason(string $encodedBody): array
{
    $json = b64url_decode($encodedBody);
    $shape = json_decode($json);
    $payload = json_decode($json, true);
    if (! $shape instanceof stdClass || ! is_array($payload) || array_is_list($payload)) {
        return [null, 'bad_payload'];
    }
    // The shared suite cookie deliberately has no OIDC audience, authorized
    // party, or nonce. Their presence marks a different token type even when
    // the issuer and signature are otherwise valid.
    if (array_key_exists('aud', $payload)
        || array_key_exists('nonce', $payload)
        || array_key_exists('azp', $payload)) {
        return [null, 'token_type_invalid'];
    }
    // Associative decoding collapses {"0":"passkey"} into the same PHP
    // array as ["passkey"]. Inspect the original JSON containers first.
    if ((property_exists($shape, 'amr') && ! is_array($shape->amr))
        || (array_key_exists('amr', $payload) && ! suite_amr_valid($payload['amr']))) {
        return [null, 'amr_invalid'];
    }
    if ((property_exists($shape, '8west:products') && ! is_array($shape->{'8west:products'}))
        || (array_key_exists('8west:products', $payload)
            && ! suite_products_valid($payload['8west:products']))) {
        return [null, 'products_invalid'];
    }
    return [$payload, null];
}

function jwt_encode(array $payload, string $secret): string
{
    $header  = b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body    = b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig     = b64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    return "$header.$body.$sig";
}

/** Returns the payload array, or null on any failure (bad sig, expired, wrong issuer). */
function jwt_verify(string $token, string $secret, string $issuer): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;

    [$decodedHeader] = jwt_decode_header_reason($header, 'HS256');
    if ($decodedHeader === null) return null;

    $expected = b64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    if (!hash_equals($expected, $sig)) return null;

    [$payload] = jwt_decode_claims_reason($body);
    if ($payload === null) return null;
    if (($payload['iss'] ?? '') !== $issuer) return null;
    if (($payload['exp'] ?? 0) < time()) return null;
    if (empty($payload['sub']) || empty($payload['email'])) return null;

    return $payload;
}

/**
 * Same verification as jwt_verify(), but says WHY it refused.
 *
 * CLAIMS_CONTRACT_V1 4.2 requires every deny to be audited, and a bare null
 * cannot be audited usefully - "sign-in failed" reads identically whether the
 * cookie was absent, forged, or simply stale. Returns [payload, null] on
 * success or [null, reason] on failure. Reasons are fixed strings, safe to
 * log: they name the check, never the claim values.
 *
 * @return array{0: array<string, mixed>|null, 1: string|null}
 */
function jwt_verify_reason(string $token, string $secret, string $issuer): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return [null, 'malformed_token'];
    [$header, $body, $sig] = $parts;

    [$decodedHeader, $headerReason] = jwt_decode_header_reason($header, 'HS256');
    if ($decodedHeader === null) return [null, $headerReason];

    $expected = b64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    if (!hash_equals($expected, $sig)) return [null, 'bad_signature'];

    [$payload, $payloadReason] = jwt_decode_claims_reason($body);
    if ($payload === null) return [null, $payloadReason];
    if (($payload['iss'] ?? '') !== $issuer) return [null, 'wrong_issuer'];
    if (($payload['exp'] ?? 0) < time()) return [null, 'expired_token'];
    if (empty($payload['sub']) || empty($payload['email'])) return [null, 'missing_claims'];

    return [$payload, null];
}

function jwt_asn1_length(int $length): string
{
    if ($length < 128) return chr($length);
    $bytes = ltrim(pack('N', $length), "\0");
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function jwt_asn1_integer(string $bytes): string
{
    $bytes = ltrim($bytes, "\0") ?: "\0";
    if ((ord($bytes[0]) & 0x80) !== 0) $bytes = "\0" . $bytes;
    return "\x02" . jwt_asn1_length(strlen($bytes)) . $bytes;
}

/** Convert an RSA JWK to a PEM SubjectPublicKeyInfo document. */
function jwt_jwk_public_key(array $jwk): ?string
{
    if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) return null;
    $modulus = b64url_decode((string) $jwk['n']);
    $exponent = b64url_decode((string) $jwk['e']);
    if ($modulus === '' || $exponent === '') return null;
    $rsa = jwt_asn1_integer($modulus) . jwt_asn1_integer($exponent);
    $rsa = "\x30" . jwt_asn1_length(strlen($rsa)) . $rsa;
    $algorithm = hex2bin('300d06092a864886f70d0101010500');
    $bitString = "\x03" . jwt_asn1_length(strlen($rsa) + 1) . "\0" . $rsa;
    $spki = $algorithm . $bitString;
    $spki = "\x30" . jwt_asn1_length(strlen($spki)) . $spki;
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($spki), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/** @return array{0: array<string,mixed>|null,1:string|null} */
function jwt_verify_rs256_reason(string $token, array $jwks, string $issuer): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return [null, 'malformed_token'];
    [$header, $body, $sig] = $parts;
    [$decodedHeader, $headerReason] = jwt_decode_header_reason($header, 'RS256');
    if ($decodedHeader === null) return [null, $headerReason];
    $kid = (string) ($decodedHeader['kid'] ?? '');
    if ($kid === '') return [null, 'missing_kid'];
    $match = null;
    foreach ((array) ($jwks['keys'] ?? []) as $jwk) {
        if (is_array($jwk) && hash_equals((string) ($jwk['kid'] ?? ''), $kid)) {
            $match = $jwk;
            break;
        }
    }
    if ($match === null || ($match['alg'] ?? 'RS256') !== 'RS256') return [null, 'unknown_kid'];
    $publicKey = jwt_jwk_public_key($match);
    if ($publicKey === null) return [null, 'bad_jwk'];
    if (openssl_verify($header . '.' . $body, b64url_decode($sig), $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
        return [null, 'bad_signature'];
    }
    [$payload, $payloadReason] = jwt_decode_claims_reason($body);
    if ($payload === null) return [null, $payloadReason];
    if (($payload['iss'] ?? '') !== $issuer) return [null, 'wrong_issuer'];
    if (($payload['exp'] ?? 0) < time()) return [null, 'expired_token'];
    if (empty($payload['sub']) || empty($payload['email'])) return [null, 'missing_claims'];
    return [$payload, null];
}

function jwt_load_jwks(string $url, string $cachePath, int $ttl = 3600, bool $forceRefresh = false): ?array
{
    $read = static function (string $path): ?array {
        $decoded = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        return is_array($decoded) && is_array($decoded['keys'] ?? null) ? $decoded : null;
    };
    if (! $forceRefresh && is_file($cachePath) && filemtime($cachePath) >= time() - $ttl) return $read($cachePath);
    $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => false]]);
    $body = @file_get_contents($url, false, $context);
    $decoded = is_string($body) ? json_decode($body, true) : null;
    if (is_array($decoded) && is_array($decoded['keys'] ?? null)) {
        $temp = $cachePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temp, $body, LOCK_EX) !== false) @rename($temp, $cachePath);
        @unlink($temp);
        return $decoded;
    }
    return is_file($cachePath) && filemtime($cachePath) >= time() - 86400 ? $read($cachePath) : null;
}

/** @return array{0: array<string,mixed>|null,1:string|null} */
function jwt_verify_suite_reason(string $token, array $suite): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return [null, 'malformed_token'];
    [$header, $headerReason] = jwt_decode_header_reason($parts[0]);
    if ($header === null) return [null, $headerReason ?? 'malformed_token'];
    $alg = (string) ($header['alg'] ?? '');
    $allowed = (array) ($suite['token_algorithms'] ?? ['HS256']);
    $issuer = (string) ($suite['issuer'] ?? 'https://id.8westit.com');
    if (! in_array($alg, $allowed, true)) return [null, 'unexpected_algorithm'];
    if ($alg === 'HS256') return jwt_verify_reason($token, (string) ($suite['sso_secret'] ?? ''), $issuer);
    if ($alg !== 'RS256') return [null, 'unexpected_algorithm'];
    $url = (string) ($suite['jwks_url'] ?? rtrim($issuer, '/') . '/.well-known/jwks.json');
    $cachePath = (string) ($suite['jwks_cache_path'] ?? sys_get_temp_dir() . '/safeharbor-ewid-jwks.json');
    $jwks = jwt_load_jwks($url, $cachePath);
    if ($jwks === null) return [null, 'jwks_unavailable'];
    $result = jwt_verify_rs256_reason($token, $jwks, $issuer);
    if ($result[1] === 'unknown_kid') {
        $refreshed = jwt_load_jwks($url, $cachePath, 3600, true);
        if ($refreshed !== null) $result = jwt_verify_rs256_reason($token, $refreshed, $issuer);
    }
    return $result;
}
