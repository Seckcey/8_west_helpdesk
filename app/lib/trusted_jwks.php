<?php
/** Authenticated, source-bound JWKS cache. No adoption of legacy temp files. */
declare(strict_types=1);

namespace EightWest\Id\Security;

require_once __DIR__ . '/eightwestid/private_file_cache.php';

final class TrustedJwks
{
    private const MAX_BYTES = 262144;
    private const MAX_CACHE_BYTES = 524288;

    public static function sameOrigin(string $url, string $issuer): bool
    {
        $source = self::url($url);
        $origin = self::url($issuer);
        return $source !== null && $origin !== null
            && strcasecmp($source['host'], $origin['host']) === 0
            && ($source['port'] ?? 443) === ($origin['port'] ?? 443);
    }

    /** The injectable transport is for hermetic tests; production supplies none. */
    public static function load(
        string $url,
        string $path,
        int $ttl = 3600,
        bool $force = false,
        ?callable $transport = null,
        ?int $now = null,
    ): ?array {
        $now ??= time();
        if (self::url($url) === null || PrivateFileCache::directory($path) === null) return null;
        $cached = self::cached($url, $path, $now);
        if (!$force && $cached !== null && $now - $cached['fetched_at'] <= max(0, min(3600, $ttl))) {
            return $cached['jwks'];
        }
        try {
            $response = $transport !== null ? $transport($url) : self::fetch($url);
        } catch (\Throwable) {
            $response = null;
        }
        if (is_array($response) && ($response['status'] ?? null) === 200
            && is_string($response['body'] ?? null) && strlen($response['body']) <= self::MAX_BYTES) {
            $keys = json_decode($response['body'], true, 16);
            if (self::keys($keys)) {
                $encoded = json_encode([
                    'schema_version' => 1,
                    'source' => $url,
                    'fetched_at' => $now,
                    'jwks' => $keys,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                return PrivateFileCache::write($path, $encoded, self::MAX_CACHE_BYTES) ? $keys : null;
            }
        }
        // Preserve the existing bounded outage grace, but only for a private,
        // validated source-bound envelope. File mtime is never authority.
        return $cached !== null ? $cached['jwks'] : null;
    }

    private static function url(string $url): ?array
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) return null;
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) return null;
        return $parts;
    }

    private static function keys(mixed $value): bool
    {
        if (!is_array($value) || !is_array($value['keys'] ?? null)
            || !array_is_list($value['keys']) || count($value['keys']) < 1 || count($value['keys']) > 64) return false;
        $seen = [];
        foreach ($value['keys'] as $key) {
            if (!is_array($key) || ($key['kty'] ?? null) !== 'RSA'
                || ($key['alg'] ?? 'RS256') !== 'RS256' || ($key['use'] ?? 'sig') !== 'sig'
                || !is_string($key['kid'] ?? null) || $key['kid'] === '' || strlen($key['kid']) > 256
                || isset($seen[$key['kid']])
                || !is_string($key['n'] ?? null) || strlen($key['n']) > 2048
                || preg_match('/^[A-Za-z0-9_-]+$/D', $key['n']) !== 1
                || !is_string($key['e'] ?? null) || strlen($key['e']) > 16
                || preg_match('/^[A-Za-z0-9_-]+$/D', $key['e']) !== 1) return false;
            $seen[$key['kid']] = true;
        }
        return true;
    }

    private static function cached(string $url, string $path, int $now): ?array
    {
        $raw = PrivateFileCache::read($path, self::MAX_CACHE_BYTES);
        $value = is_string($raw) ? json_decode($raw, true, 20) : null;
        if (!is_array($value)
            || array_keys($value) !== ['schema_version', 'source', 'fetched_at', 'jwks']
            || $value['schema_version'] !== 1 || $value['source'] !== $url
            || !is_int($value['fetched_at']) || $value['fetched_at'] < 1
            || $value['fetched_at'] > $now || $now - $value['fetched_at'] > 86400
            || !self::keys($value['jwks'])) return null;
        return $value;
    }

    /** @return array{status:int,body:string}|null */
    private static function fetch(string $url): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 3, 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 0,
            'header' => "Accept: application/json\r\nConnection: close\r\n",
        ], 'ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
        ]]);
        $body = @file_get_contents($url, false, $context, 0, self::MAX_BYTES + 1);
        if (!is_string($body) || strlen($body) > self::MAX_BYTES) return null;
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+([0-9]{3})(?:\s|$)/D', $line, $match) === 1) $status = (int)$match[1];
        }
        return ['status' => $status, 'body' => $body];
    }
}
