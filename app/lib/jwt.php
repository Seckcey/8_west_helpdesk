<?php
/**
 * Minimal HS256 JWT codec for the 8 West ID suite token.
 * Phase 1 of docs/suite-sso-contract.md: signed cookie shared across
 * *.8westit.com; claims mirror the OIDC contract so a later upgrade to
 * authorization-code flow does not change app-side session shape.
 */
declare(strict_types=1);

function b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
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

    $expected = b64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    if (!hash_equals($expected, $sig)) return null;

    $payload = json_decode(b64url_decode($body), true);
    if (!is_array($payload)) return null;
    if (($payload['iss'] ?? '') !== $issuer) return null;
    if (($payload['exp'] ?? 0) < time()) return null;
    if (empty($payload['sub']) || empty($payload['email'])) return null;

    return $payload;
}
