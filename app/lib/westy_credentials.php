<?php
/** Shared server-only Anthropic credential contract, version 1. */
declare(strict_types=1);

/** Returns a complete HTTP header, or null. Never returns diagnostics containing credentials. */
function westy_anthropic_auth(array $config, string $key, string $endpoint): ?string
{
    $mode = $config['auth_mode'] ?? 'api_key';
    if ($mode === 'api_key') {
        return $key !== '' && !preg_match('/[\r\n]/', $key) ? 'x-api-key: '.$key : null;
    }
    if ($mode !== 'federation' || $key !== '' || $endpoint !== 'https://api.anthropic.com/v1/messages') {
        return null;
    }
    $path = $config['credential_file'] ?? '';
    $app = $config['credential_app'] ?? '';
    $environment = $config['credential_environment'] ?? '';
    if (!is_string($path) || !str_starts_with($path, '/') || str_contains($path, '://')
        || !is_string($app) || $app === '' || !is_string($environment) || $environment === '') {
        return null;
    }
    clearstatcache(true, $path);
    $before = @lstat($path);
    if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000
        || ($before['mode'] & 0027) !== 0 || $before['size'] > 16384) {
        return null;
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) return null;
    try {
        $stat = fstat($handle);
        if ($stat === false || $stat['ino'] !== $before['ino'] || $stat['dev'] !== $before['dev']
            || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0027) !== 0) return null;
        $raw = stream_get_contents($handle, 16385);
    } finally {
        fclose($handle);
    }
    if (!is_string($raw) || strlen($raw) > 16384) return null;
    $value = json_decode($raw, true, 8);
    if (!is_array($value) || ($value['version'] ?? null) !== 1
        || ($value['app'] ?? null) !== $app || ($value['environment'] ?? null) !== $environment
        || ($value['token_type'] ?? null) !== 'Bearer' || !is_int($value['expires_at'] ?? null)
        || $value['expires_at'] <= time() + 30 || $value['expires_at'] > time() + 3600
        || !is_string($value['access_token'] ?? null)
        || strlen($value['access_token']) > 8192
        || !preg_match('/\Ask-ant-oat01-[A-Za-z0-9._~+\/-]{20,}=*\z/D', $value['access_token'])) return null;
    return 'Authorization: Bearer '.$value['access_token'];
}
