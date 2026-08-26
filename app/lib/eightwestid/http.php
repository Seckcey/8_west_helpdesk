<?php
/** Bounded HTTPS transport used by discovery, token, JWKS, and revocation calls. */
declare(strict_types=1);

namespace EightWest\Id;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }
}

interface HttpClient
{
    /** @param list<string> $headers */
    public function send(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $maxBytes = 1048576,
    ): HttpResponse;
}

final class NativeHttpClient implements HttpClient
{
    public function __construct(private readonly int $timeoutSeconds = 12)
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 30) {
            throw new ConfigurationException('HTTP timeout must be between 1 and 30 seconds.');
        }
    }

    public function send(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $maxBytes = 1048576,
    ): HttpResponse {
        assert_https_url($url, 'HTTP endpoint');
        if (! in_array($method, ['GET', 'POST'], true)) {
            throw new ProtocolException('Unsupported HTTP method.');
        }
        if ($maxBytes < 1 || $maxBytes > 4 * 1024 * 1024) {
            throw new ProtocolException('Invalid HTTP response bound.');
        }
        foreach ($headers as $header) {
            if (! is_string($header) || preg_match('/[\r\n]/', $header) === 1) {
                throw new ProtocolException('Invalid HTTP header.');
            }
        }

        return function_exists('curl_init')
            ? $this->sendWithCurl($method, $url, $headers, $body, $maxBytes)
            : $this->sendWithStreams($method, $url, $headers, $body, $maxBytes);
    }

    /** @param list<string> $headers */
    private function sendWithCurl(
        string $method,
        string $url,
        array $headers,
        string $body,
        int $maxBytes,
    ): HttpResponse {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ProtocolException('Could not initialize the HTTPS request.');
        }

        $buffer = '';
        $tooLarge = false;
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [...$headers, 'User-Agent: 8west-id-php-client/1'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$buffer, &$tooLarge, $maxBytes): int {
                if (strlen($buffer) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($method === 'POST') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($tooLarge) {
            throw new ProtocolException('HTTPS response exceeded its size limit.');
        }
        if ($ok === false || $status < 100) {
            throw new ProtocolException('HTTPS request failed.');
        }
        return new HttpResponse($status, $buffer);
    }

    /** @param list<string> $headers */
    private function sendWithStreams(
        string $method,
        string $url,
        array $headers,
        string $body,
        int $maxBytes,
    ): HttpResponse {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", [...$headers, 'User-Agent: 8west-id-php-client/1']),
                'content' => $method === 'POST' ? $body : '',
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $responseHeaders = [];
        $data = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        if (isset($http_response_header) && is_array($http_response_header)) {
            $responseHeaders = $http_response_header;
        }
        if (! is_string($data) || ! isset($responseHeaders[0])) {
            throw new ProtocolException('HTTPS request failed.');
        }
        if (strlen($data) > $maxBytes) {
            throw new ProtocolException('HTTPS response exceeded its size limit.');
        }
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $responseHeaders[0], $match) !== 1) {
            throw new ProtocolException('HTTPS response status was invalid.');
        }
        return new HttpResponse((int) $match[1], $data);
    }
}

function assert_https_url(string $url, string $name): void
{
    if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        throw new ConfigurationException("{$name} must be an absolute HTTPS URL.");
    }
    $parts = parse_url($url);
    if (! is_array($parts)
        || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])) {
        throw new ConfigurationException("{$name} must be an absolute HTTPS URL without user information or a fragment.");
    }
}

function same_origin(string $left, string $right): bool
{
    $a = parse_url($left);
    $b = parse_url($right);
    if (! is_array($a) || ! is_array($b)) return false;
    $portA = $a['port'] ?? (($a['scheme'] ?? '') === 'https' ? 443 : null);
    $portB = $b['port'] ?? (($b['scheme'] ?? '') === 'https' ? 443 : null);
    return strtolower((string)($a['scheme'] ?? '')) === strtolower((string)($b['scheme'] ?? ''))
        && strtolower((string)($a['host'] ?? '')) === strtolower((string)($b['host'] ?? ''))
        && $portA === $portB;
}
