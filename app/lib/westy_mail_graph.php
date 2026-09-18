<?php
/**
 * Dedicated Microsoft Graph transport for human-approved Westy email.
 *
 * This transport deliberately does not use mail.graph or the business-report
 * sender. It authenticates with a certificate held outside the release tree,
 * sends once, and returns only bounded evidence suitable for persistence.
 */
declare(strict_types=1);

if (!function_exists('cfg')) require_once __DIR__ . '/bootstrap.php';

const WESTY_MAIL_GRAPH_TIMEOUT_SECONDS = 20;
const WESTY_MAIL_GRAPH_MAX_BODY_BYTES = 200000;
const WESTY_MAIL_GRAPH_MAX_GET_BYTES = 2097152;

/** Return a fully validated dedicated configuration, or null when unavailable. */
function westy_mail_graph_config(): ?array
{
    $raw = cfg('westy_email.graph');
    if (!is_array($raw) || ($raw['enabled'] ?? false) !== true) return null;
    return westy_mail_graph_normalize_config($raw);
}

/**
 * Return stable, nonsecret inputs that identify the exact Graph transport.
 * Paths and private-key material are intentionally excluded.
 */
function westy_mail_graph_identity(array $g): array
{
    $g = westy_mail_graph_normalize_config($g);
    if ($g === null) return [];
    return [
        'tenant_id' => $g['tenant_id'],
        'client_id' => $g['client_id'],
        'mailbox' => $g['mailbox'],
        'sender' => $g['sender'],
        'certificate_sha256' => $g['certificate_sha256'],
    ];
}

/**
 * Submit one exact approved email. The injected HTTP callback receives:
 *   method, URL, headers, body, timeout
 * and returns an array containing http, body and optional response headers.
 * There are no transport retries in this function.
 */
function westy_mail_graph_send(array $g, array $mail, ?callable $http = null): array
{
    $g = westy_mail_graph_normalize_config($g);
    if ($g === null) return westy_mail_graph_result('not_submitted', null, 'graph_config_invalid', null);

    $mail = westy_mail_graph_validate_mail($mail);
    if ($mail === null) return westy_mail_graph_result('not_submitted', null, 'mail_invalid', null);

    $http ??= 'westy_mail_graph_http';
    $token = westy_mail_graph_token($g, $http);
    if ($token['token'] === null) {
        return westy_mail_graph_result(
            'not_submitted',
            $token['provider_http'],
            $token['outcome_code'],
            $token['provider_request_id'],
        );
    }

    $headers = [[
        'name' => 'X-Westy-Message-Key',
        'value' => $mail['message_key'],
    ]];
    if ($mail['reply_message_id'] !== null) {
        // Graph /reply honors an original Reply-To and can therefore change the
        // recipient. Keep the exact approved recipient and correlate the case
        // at application level instead.
        $headers[] = [
            'name' => 'X-Westy-Reply-Message-Id',
            'value' => $mail['reply_message_id'],
        ];
    }
    $message = [
        'message' => [
            'subject' => $mail['subject'],
            'body' => ['contentType' => 'Text', 'content' => $mail['body_text']],
            'toRecipients' => [[
                'emailAddress' => ['address' => $mail['recipient']],
            ]],
            'internetMessageHeaders' => $headers,
        ],
        // Graph saves to Sent Items by default. Microsoft documents this flag
        // as necessary only when false, so omit it rather than weaken the default.
    ];
    try {
        $payload = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable) {
        return westy_mail_graph_result('not_submitted', null, 'mail_encoding_failed', null);
    }

    $clientRequestId = westy_mail_graph_uuid();
    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($g['mailbox']) . '/sendMail';
    try {
        $response = $http(
            'POST',
            $url,
            [
                'Authorization: Bearer ' . $token['token'],
                'Content-Type: application/json',
                'client-request-id: ' . $clientRequestId,
                'return-client-request-id: true',
            ],
            $payload,
            $g['timeout_seconds'],
        );
    } catch (Throwable) {
        return westy_mail_graph_result('uncertain', null, 'send_transport_error', $clientRequestId);
    }
    if (!is_array($response) || array_key_exists('transport_error', $response) || array_key_exists('error', $response)) {
        return westy_mail_graph_result('uncertain', null, 'send_transport_error', $clientRequestId);
    }

    $providerHttp = westy_mail_graph_provider_http($response);
    $requestId = westy_mail_graph_request_id($response) ?? $clientRequestId;
    if ($providerHttp === 202) {
        return westy_mail_graph_result('submitted', 202, 'send_accepted', $requestId);
    }
    if ($providerHttp !== null && westy_mail_graph_definitive_rejection($providerHttp)) {
        return westy_mail_graph_result('rejected', $providerHttp, 'send_rejected', $requestId);
    }
    return westy_mail_graph_result('uncertain', $providerHttp, 'send_uncertain', $requestId);
}

/**
 * Perform one read-only request inside the configured mailbox.
 *
 * Successful calls expose decoded Graph data for the downstream reconciler.
 * Failure results never expose provider bodies. Safe absolute Graph nextLink
 * values are accepted and normalized back to mailbox-relative paths.
 */
function westy_mail_graph_get(array $g, string $relativePath, ?callable $http = null): array
{
    $g = westy_mail_graph_normalize_config($g);
    if ($g === null) return westy_mail_graph_get_result('not_attempted', null, 'graph_config_invalid', null);

    $safe = westy_mail_graph_safe_mailbox_url($g, $relativePath);
    if ($safe === null) return westy_mail_graph_get_result('not_attempted', null, 'get_path_invalid', null);

    $http ??= 'westy_mail_graph_http';
    $token = westy_mail_graph_token($g, $http);
    if ($token['token'] === null) {
        return westy_mail_graph_get_result(
            'not_attempted',
            $token['provider_http'],
            $token['outcome_code'],
            $token['provider_request_id'],
        );
    }

    $clientRequestId = westy_mail_graph_uuid();
    try {
        $response = $http(
            'GET',
            $safe['url'],
            [
                'Authorization: Bearer ' . $token['token'],
                'Accept: application/json',
                'client-request-id: ' . $clientRequestId,
                'return-client-request-id: true',
            ],
            '',
            $g['timeout_seconds'],
        );
    } catch (Throwable) {
        return westy_mail_graph_get_result('uncertain', null, 'get_transport_error', $clientRequestId);
    }
    if (!is_array($response) || array_key_exists('transport_error', $response) || array_key_exists('error', $response)) {
        return westy_mail_graph_get_result('uncertain', null, 'get_transport_error', $clientRequestId);
    }

    $providerHttp = westy_mail_graph_provider_http($response);
    $requestId = westy_mail_graph_request_id($response) ?? $clientRequestId;
    if ($providerHttp !== 200) {
        $outcome = $providerHttp !== null && westy_mail_graph_definitive_rejection($providerHttp)
            ? 'rejected'
            : 'uncertain';
        return westy_mail_graph_get_result($outcome, $providerHttp, 'get_' . $outcome, $requestId);
    }

    $body = $response['body'] ?? null;
    if (!is_string($body) || strlen($body) > WESTY_MAIL_GRAPH_MAX_GET_BYTES) {
        return westy_mail_graph_get_result('uncertain', 200, 'get_invalid_response', $requestId);
    }
    try {
        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return westy_mail_graph_get_result('uncertain', 200, 'get_invalid_response', $requestId);
    }
    if (!is_array($data)) {
        return westy_mail_graph_get_result('uncertain', 200, 'get_invalid_response', $requestId);
    }

    $nextPath = null;
    if (array_key_exists('@odata.nextLink', $data)) {
        if (!is_string($data['@odata.nextLink'])) {
            return westy_mail_graph_get_result('uncertain', 200, 'get_untrusted_next_link', $requestId);
        }
        $next = westy_mail_graph_safe_mailbox_url($g, $data['@odata.nextLink']);
        if ($next === null) {
            return westy_mail_graph_get_result('uncertain', 200, 'get_untrusted_next_link', $requestId);
        }
        $nextPath = $next['relative_path'];
    }

    $result = westy_mail_graph_get_result('ok', 200, 'get_ok', $requestId);
    $result['data'] = $data;
    $result['next_path'] = $nextPath;
    return $result;
}

/** @return array<string,mixed>|null */
function westy_mail_graph_normalize_config(array $g): ?array
{
    $ids = [];
    foreach (['tenant_id', 'client_id'] as $key) {
        $value = $g[$key] ?? null;
        if (!is_string($value) || $value !== trim($value)
            || preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/D', $value) !== 1
        ) return null;
        $ids[$key] = strtolower($value);
    }

    $mailbox = westy_mail_graph_email($g['mailbox'] ?? null);
    $sender = westy_mail_graph_email($g['sender'] ?? null);
    // This transport owns one mailbox. A different sender would add delegated
    // Send As behavior and make the reviewed identity ambiguous.
    if ($mailbox === null || $sender === null || $mailbox !== $sender) return null;

    $privateKeyPath = westy_mail_graph_external_file($g['private_key_path'] ?? null, true);
    $certificatePath = westy_mail_graph_external_file($g['certificate_path'] ?? null, false);
    if ($privateKeyPath === null || $certificatePath === null || $privateKeyPath === $certificatePath) return null;

    $material = westy_mail_graph_certificate_material($privateKeyPath, $certificatePath);
    if ($material === null) return null;

    $timeout = $g['timeout_seconds'] ?? WESTY_MAIL_GRAPH_TIMEOUT_SECONDS;
    if (!is_int($timeout) || $timeout < 5 || $timeout > 30) return null;

    return [
        'tenant_id' => $ids['tenant_id'],
        'client_id' => $ids['client_id'],
        'mailbox' => $mailbox,
        'sender' => $sender,
        'private_key_path' => $privateKeyPath,
        'certificate_path' => $certificatePath,
        'certificate_sha256' => $material['sha256'],
        'certificate_x5t' => $material['x5t'],
        'timeout_seconds' => $timeout,
    ];
}

function westy_mail_graph_email(mixed $value): ?string
{
    if (!is_string($value) || $value !== trim($value) || strlen($value) > 254
        || preg_match('/[\x00-\x20\x7f]/', $value) === 1
        || filter_var($value, FILTER_VALIDATE_EMAIL) === false
    ) return null;
    return strtolower($value);
}

function westy_mail_graph_external_file(mixed $value, bool $private): ?string
{
    if (!is_string($value) || $value === '' || $value !== trim($value) || str_contains($value, "\0")) return null;
    $absolute = str_starts_with($value, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/D', $value) === 1;
    if (!$absolute || !is_file($value) || !is_readable($value)) return null;
    $real = realpath($value);
    if (!is_string($real) || $real === '') return null;

    if (defined('APP_ROOT')) {
        $root = realpath((string)APP_ROOT);
        if (is_string($root)) {
            $prefix = rtrim(str_replace('\\', '/', strtolower($root)), '/') . '/';
            $candidate = str_replace('\\', '/', strtolower($real));
            if (str_starts_with($candidate . '/', $prefix)) return null;
        }
    }
    if ($private && DIRECTORY_SEPARATOR !== '\\') {
        $permissions = @fileperms($real);
        if (!is_int($permissions) || ($permissions & 0077) !== 0) return null;
    }
    return $real;
}

/** @return array{sha256:string,x5t:string}|null */
function westy_mail_graph_certificate_material(string $privateKeyPath, string $certificatePath): ?array
{
    $keyPem = @file_get_contents($privateKeyPath);
    $certPem = @file_get_contents($certificatePath);
    if (!is_string($keyPem) || !is_string($certPem)
        || strlen($keyPem) > 32768 || strlen($certPem) > 65536
    ) return null;

    $key = @openssl_pkey_get_private($keyPem);
    $cert = @openssl_x509_read($certPem);
    if ($key === false || $cert === false || !@openssl_x509_check_private_key($cert, $key)) {
        westy_mail_graph_clear_openssl_errors();
        return null;
    }
    $details = @openssl_pkey_get_details($key);
    $parsed = @openssl_x509_parse($cert, false);
    $now = time();
    if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
        || (int)($details['bits'] ?? 0) < 2048
        || !is_array($parsed)
        || !is_int($parsed['validFrom_time_t'] ?? null)
        || !is_int($parsed['validTo_time_t'] ?? null)
        || $parsed['validFrom_time_t'] > $now + 300
        || $parsed['validTo_time_t'] <= $now
    ) {
        westy_mail_graph_clear_openssl_errors();
        return null;
    }
    $der = westy_mail_graph_certificate_der($certPem);
    if ($der === null) return null;
    westy_mail_graph_clear_openssl_errors();
    return [
        'sha256' => hash('sha256', $der),
        // Microsoft's documented RS256 client assertion uses the legacy x5t.
        'x5t' => westy_mail_graph_base64url(hash('sha1', $der, true)),
    ];
}

function westy_mail_graph_certificate_der(string $pem): ?string
{
    if (preg_match('/-----BEGIN CERTIFICATE-----\s*(.*?)\s*-----END CERTIFICATE-----/s', $pem, $match) !== 1) return null;
    $base64 = preg_replace('/\s+/', '', $match[1]);
    if (!is_string($base64) || $base64 === '') return null;
    $der = base64_decode($base64, true);
    return is_string($der) && $der !== '' ? $der : null;
}

/** @return array<string,mixed>|null */
function westy_mail_graph_validate_mail(array $mail): ?array
{
    $allowed = ['recipient', 'subject', 'body_text', 'message_key', 'reply_message_id'];
    foreach (array_keys($mail) as $key) if (!in_array($key, $allowed, true)) return null;
    foreach (['recipient', 'subject', 'body_text', 'message_key'] as $key) {
        if (!array_key_exists($key, $mail) || !is_string($mail[$key])) return null;
    }

    $recipient = westy_mail_graph_email($mail['recipient']);
    $subject = $mail['subject'];
    $body = $mail['body_text'];
    $messageKey = $mail['message_key'];
    if ($recipient === null || $subject === '' || strlen($subject) > 255
        || preg_match('/[\r\n\x00-\x1f\x7f]/', $subject) === 1
        || $body === '' || strlen($body) > WESTY_MAIL_GRAPH_MAX_BODY_BYTES
        || str_contains($body, "\0")
        || (function_exists('mb_check_encoding') && !mb_check_encoding($subject . $body, 'UTF-8'))
        || preg_match('/\A[a-f0-9]{32}\z/D', $messageKey) !== 1
    ) return null;

    $replyMessageId = $mail['reply_message_id'] ?? null;
    if ($replyMessageId !== null
        && (!is_string($replyMessageId)
            || preg_match('/\A[A-Za-z0-9._~+\/=\-]{1,512}\z/D', $replyMessageId) !== 1)
    ) return null;
    return [
        'recipient' => $recipient,
        'subject' => $subject,
        'body_text' => $body,
        'message_key' => $messageKey,
        'reply_message_id' => $replyMessageId,
    ];
}

/** @return array{token:?string,provider_http:?int,outcome_code:string,provider_request_id:?string} */
function westy_mail_graph_token(array $g, callable $http): array
{
    $assertion = westy_mail_graph_assertion($g);
    if ($assertion === null) {
        return ['token' => null, 'provider_http' => null, 'outcome_code' => 'token_assertion_invalid', 'provider_request_id' => null];
    }

    $url = 'https://login.microsoftonline.com/' . rawurlencode($g['tenant_id']) . '/oauth2/v2.0/token';
    $clientRequestId = westy_mail_graph_uuid();
    $body = http_build_query([
        'client_id' => $g['client_id'],
        'scope' => 'https://graph.microsoft.com/.default',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion' => $assertion,
        'grant_type' => 'client_credentials',
    ], '', '&', PHP_QUERY_RFC3986);
    try {
        $response = $http(
            'POST',
            $url,
            [
                'Content-Type: application/x-www-form-urlencoded',
                'client-request-id: ' . $clientRequestId,
                'return-client-request-id: true',
            ],
            $body,
            $g['timeout_seconds'],
        );
    } catch (Throwable) {
        return ['token' => null, 'provider_http' => null, 'outcome_code' => 'token_transport_error', 'provider_request_id' => $clientRequestId];
    }
    if (!is_array($response) || array_key_exists('transport_error', $response) || array_key_exists('error', $response)) {
        return ['token' => null, 'provider_http' => null, 'outcome_code' => 'token_transport_error', 'provider_request_id' => $clientRequestId];
    }

    $providerHttp = westy_mail_graph_provider_http($response);
    $requestId = westy_mail_graph_request_id($response) ?? $clientRequestId;
    if ($providerHttp !== 200) {
        return [
            'token' => null,
            'provider_http' => $providerHttp,
            'outcome_code' => $providerHttp !== null && $providerHttp >= 400 && $providerHttp < 500
                ? 'token_rejected'
                : 'token_unavailable',
            'provider_request_id' => $requestId,
        ];
    }
    $responseBody = $response['body'] ?? null;
    if (!is_string($responseBody) || strlen($responseBody) > 1048576) {
        return ['token' => null, 'provider_http' => 200, 'outcome_code' => 'token_invalid_response', 'provider_request_id' => $requestId];
    }
    try {
        $decoded = json_decode($responseBody, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decoded = null;
    }
    $token = is_array($decoded) && is_string($decoded['access_token'] ?? null)
        ? trim($decoded['access_token'])
        : '';
    if ($token === '' || strlen($token) > 16384 || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
        return ['token' => null, 'provider_http' => 200, 'outcome_code' => 'token_invalid_response', 'provider_request_id' => $requestId];
    }
    return ['token' => $token, 'provider_http' => 200, 'outcome_code' => 'token_acquired', 'provider_request_id' => $requestId];
}

function westy_mail_graph_assertion(array $g): ?string
{
    $keyPem = @file_get_contents($g['private_key_path']);
    if (!is_string($keyPem) || strlen($keyPem) > 32768) return null;
    $key = @openssl_pkey_get_private($keyPem);
    if ($key === false) {
        westy_mail_graph_clear_openssl_errors();
        return null;
    }
    $now = time();
    $audience = 'https://login.microsoftonline.com/' . $g['tenant_id'] . '/oauth2/v2.0/token';
    try {
        $header = westy_mail_graph_base64url(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'x5t' => $g['certificate_x5t'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $claims = westy_mail_graph_base64url(json_encode([
            'aud' => $audience,
            'exp' => $now + 300,
            'iss' => $g['client_id'],
            'jti' => westy_mail_graph_uuid(),
            'nbf' => $now,
            'sub' => $g['client_id'],
            'iat' => $now,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    } catch (Throwable) {
        return null;
    }
    $input = $header . '.' . $claims;
    $signature = '';
    if (!@openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
        westy_mail_graph_clear_openssl_errors();
        return null;
    }
    westy_mail_graph_clear_openssl_errors();
    return $input . '.' . westy_mail_graph_base64url($signature);
}

/** @return array{url:string,relative_path:string}|null */
function westy_mail_graph_safe_mailbox_url(array $g, string $input): ?array
{
    if ($input === '' || strlen($input) > 4096 || str_contains($input, '#')
        || preg_match('/[\x00-\x20\x7f\\\\]/', $input) === 1
    ) return null;
    $basePath = '/v1.0/users/' . rawurlencode($g['mailbox']) . '/';
    $relative = $input;
    if (str_starts_with(strtolower($input), 'https://') || str_contains($input, '://')) {
        $parts = parse_url($input);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'graph.microsoft.com'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !is_string($parts['path'] ?? null) || !str_starts_with($parts['path'], $basePath)
        ) return null;
        $relative = substr($parts['path'], strlen($basePath));
        if (isset($parts['query'])) $relative .= '?' . $parts['query'];
    } elseif (str_starts_with($input, '/') || str_starts_with($input, '//')) {
        return null;
    }

    [$path, $query] = array_pad(explode('?', $relative, 2), 2, '');
    if ($path === '' || str_contains(strtolower($path), '%2f') || str_contains(strtolower($path), '%5c')) return null;
    foreach (explode('/', $path) as $segment) {
        $decoded = rawurldecode($segment);
        if ($decoded === '' || $decoded === '.' || $decoded === '..'
            || preg_match('/[\x00-\x20\x7f\\\\\/]/', $decoded) === 1
        ) return null;
    }
    if (preg_match('/\A(?:messages(?:\/[^\/]+)?|mailFolders\/[^\/]+\/messages(?:\/[^\/]+)?)\z/D', $path) !== 1) return null;
    if (!westy_mail_graph_safe_query($query)) return null;
    $relativePath = $path . ($query !== '' ? '?' . $query : '');
    return [
        'url' => 'https://graph.microsoft.com' . $basePath . $relativePath,
        'relative_path' => $relativePath,
    ];
}

function westy_mail_graph_safe_query(string $query): bool
{
    if ($query === '') return true;
    if (strlen($query) > 3072 || str_contains($query, '#')) return false;
    $allowed = ['$select', '$filter', '$orderby', '$top', '$skip', '$skiptoken', '$expand', '$search', '$count'];
    foreach (explode('&', $query) as $pair) {
        if ($pair === '') return false;
        [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
        $key = rawurldecode($rawKey);
        $value = rawurldecode($rawValue);
        if (!in_array($key, $allowed, true) || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) return false;
        if ($key === '$top' && (preg_match('/\A[0-9]{1,2}\z/D', $value) !== 1 || (int)$value < 1 || (int)$value > 50)) return false;
    }
    return true;
}

function westy_mail_graph_http(string $method, string $url, array $headers, string $body, int $timeout): array
{
    if (!in_array($method, ['GET', 'POST'], true) || !str_starts_with($url, 'https://')) return ['transport_error' => true];
    $responseHeaders = [];
    $responseBody = '';
    $tooLarge = false;
    $ch = curl_init($url);
    if ($ch === false) return ['transport_error' => true];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $position = strpos($line, ':');
            if ($position !== false) {
                $name = strtolower(trim(substr($line, 0, $position)));
                if (in_array($name, ['request-id', 'client-request-id'], true)) {
                    $responseHeaders[$name] = trim(substr($line, $position + 1));
                }
            }
            return $length;
        },
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, &$tooLarge): int {
            if (strlen($responseBody) + strlen($chunk) > WESTY_MAIL_GRAPH_MAX_GET_BYTES) {
                $tooLarge = true;
                return 0;
            }
            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $ok = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($ok === false || $errno !== 0 || $tooLarge) return ['transport_error' => true];
    return ['http' => $http, 'body' => $responseBody, 'headers' => $responseHeaders];
}

function westy_mail_graph_provider_http(array $response): ?int
{
    $http = $response['http'] ?? null;
    return is_int($http) && $http >= 100 && $http <= 599 ? $http : null;
}

function westy_mail_graph_request_id(array $response): ?string
{
    $headers = $response['headers'] ?? null;
    if (!is_array($headers)) return null;
    foreach ($headers as $key => $value) {
        if (is_string($key) && in_array(strtolower($key), ['request-id', 'client-request-id'], true)) {
            $candidate = is_array($value) ? end($value) : $value;
            if (is_string($candidate) && preg_match('/\A[A-Za-z0-9._:\-]{1,128}\z/D', $candidate) === 1) return $candidate;
        }
        if (is_int($key) && is_string($value) && preg_match('/\A(?:request-id|client-request-id):\s*([^\s]{1,128})\s*\z/Di', $value, $match) === 1
            && preg_match('/\A[A-Za-z0-9._:\-]{1,128}\z/D', $match[1]) === 1
        ) return $match[1];
    }
    return null;
}

function westy_mail_graph_definitive_rejection(int $http): bool
{
    return $http >= 400 && $http < 500 && !in_array($http, [408, 409, 425, 429], true);
}

function westy_mail_graph_result(string $outcome, ?int $http, string $code, ?string $requestId): array
{
    return ['outcome' => $outcome, 'provider_http' => $http, 'outcome_code' => $code, 'provider_request_id' => $requestId];
}

function westy_mail_graph_get_result(string $outcome, ?int $http, string $code, ?string $requestId): array
{
    return westy_mail_graph_result($outcome, $http, $code, $requestId);
}

function westy_mail_graph_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function westy_mail_graph_base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function westy_mail_graph_clear_openssl_errors(): void
{
    while (openssl_error_string() !== false) {
        // Drain OpenSSL's per-thread error queue without logging key details.
    }
}
