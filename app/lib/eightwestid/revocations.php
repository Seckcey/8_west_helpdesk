<?php
/** Bounded revocation-feed polling with a small cross-request cache. */
declare(strict_types=1);

namespace EightWest\Id;

require_once __DIR__ . '/private_file_cache.php';

const REVOCATION_REASONS = [
    'account_disabled',
    'email_unverified',
    'business_suspended',
    'subscription_lapsed',
    'product_removed',
    'role_not_allowed',
];
const REVOCATION_MAX_ENTRIES = 100000;

interface RevocationCache
{
    /**
     * @return array{fetched_at:int,generated_at:int,count:int,authorization_count:int,
     *   revoked:array<string,string>,authorizations:array<string,string>}|null
     */
    public function load(string $key): ?array;

    /**
     * @param array{fetched_at:int,generated_at:int,count:int,authorization_count:int,
     *   revoked:array<string,string>,authorizations:array<string,string>} $value
     */
    public function save(string $key, array $value): void;
}

final class MemoryRevocationCache implements RevocationCache
{
    /**
     * @var array<string,array{fetched_at:int,generated_at:int,count:int,authorization_count:int,
     *   revoked:array<string,string>,authorizations:array<string,string>}>
     */
    private array $values = [];

    public function load(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    public function save(string $key, array $value): void
    {
        $validated = valid_revocation_cache_value($value);
        if ($validated === null) {
            throw new ProtocolException('Refusing to cache an invalid revocation response.');
        }
        $this->values[$key] = $validated;
    }
}

final class FileRevocationCache implements RevocationCache
{
    public function __construct(private readonly string $directory)
    {
        if ($directory === '' || !str_starts_with($directory, '/') || str_contains($directory, "\0")) {
            throw new ConfigurationException('Invalid revocation-cache directory.');
        }
    }

    public function load(string $key): ?array
    {
        $path = $this->path($key);
        $raw = Security\PrivateFileCache::read($path, 2097152);
        if ($raw === null) return null;
        try {
            $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return valid_revocation_cache_value($value);
    }

    public function save(string $key, array $value): void
    {
        $validated = valid_revocation_cache_value($value);
        if ($validated === null) {
            throw new ProtocolException('Refusing to cache an invalid revocation response.');
        }
        $path = $this->path($key);
        $json = json_encode($validated, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (! Security\PrivateFileCache::write($path, $json, 2097152)) {
            throw new ProtocolException('The revocation cache could not be written.');
        }
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR
            . hash('sha256', $key) . '.json';
    }
}

final class RevocationChecker
{
    /** @var callable():int */
    private $clock;
    private readonly string $cacheKey;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly HttpClient $http,
        private readonly RevocationCache $cache,
        private readonly int $refreshEvery = 60,
        private readonly int $maximumStale = 300,
        ?callable $clock = null,
    ) {
        assert_https_url($endpoint, 'Revocation endpoint');
        if ($refreshEvery < 10 || $refreshEvery > 300
            || $maximumStale < $refreshEvery || $maximumStale > 300) {
            throw new ConfigurationException('Invalid revocation-cache timing.');
        }
        $this->clock = $clock ?? static fn(): int => time();
        $this->cacheKey = $endpoint . "\0" . $clientId;
    }

    public function isRevoked(string $subject, string $sessionVersion): bool
    {
        if (! valid_revocation_subject($subject)) {
            throw new PolicyException('subject_missing');
        }
        if (! valid_session_version($sessionVersion)) {
            throw new PolicyException('session_version_invalid');
        }
        $now = ($this->clock)();
        $loaded = $this->cache->load($this->cacheKey);
        $cached = $loaded === null ? null : valid_revocation_cache_value($loaded);
        if ($cached !== null
            && ($cached['fetched_at'] > $now + 60 || $cached['generated_at'] > $now + 60)) {
            $cached = null;
        }
        if ($cached !== null
            && $now - $cached['fetched_at'] < $this->refreshEvery
            && $now - $cached['generated_at'] <= $this->maximumStale) {
            return $this->snapshotRevokes($cached, $subject, $sessionVersion);
        }

        try {
            $fresh = $this->fetch($now);
            $this->cache->save($this->cacheKey, $fresh);
            return $this->snapshotRevokes($fresh, $subject, $sessionVersion);
        } catch (\Throwable) {
            if ($cached !== null
                && $now - $cached['fetched_at'] <= $this->maximumStale
                && $now - $cached['generated_at'] <= $this->maximumStale) {
                return $this->snapshotRevokes($cached, $subject, $sessionVersion);
            }
            throw new RevocationUnavailableException('8 West ID revocation status is unavailable.');
        }
    }

    /**
     * @param array{revoked:array<string,string>,authorizations:array<string,string>} $snapshot
     */
    private function snapshotRevokes(array $snapshot, string $subject, string $sessionVersion): bool
    {
        // An explicit issuer denial always wins, even if the authorization
        // inventory also carries the subject while a state change is settling.
        if (array_key_exists($subject, $snapshot['revoked'])) {
            return true;
        }
        $currentVersion = $snapshot['authorizations'][$subject] ?? null;
        return ! is_string($currentVersion) || ! hash_equals($currentVersion, $sessionVersion);
    }

    /**
     * @return array{fetched_at:int,generated_at:int,count:int,authorization_count:int,
     *   revoked:array<string,string>,authorizations:array<string,string>}
     */
    private function fetch(int $now): array
    {
        $body = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->http->send('POST', $this->endpoint, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ], $body, 2097152);
        if ($response->status !== 200) {
            throw new ProtocolException('The revocation endpoint rejected the client.');
        }
        try {
            $shape = json_decode($response->body, false, 16, JSON_THROW_ON_ERROR);
            $payload = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProtocolException('The revocation response was invalid.');
        }
        if (! $shape instanceof \stdClass
            || ! is_array($payload)
            || array_is_list($payload)
            || ! property_exists($shape, 'revoked')
            || ! is_array($shape->revoked)
            || ! property_exists($shape, 'authorizations')
            || ! is_array($shape->authorizations)
            || ! is_string($payload['generated_at'] ?? null)
            || ! is_int($payload['count'] ?? null)
            || $payload['count'] < 0
            || ! is_array($payload['revoked'] ?? null)
            || ! array_is_list($payload['revoked'])
            || count($payload['revoked']) > REVOCATION_MAX_ENTRIES
            || ! is_int($payload['authorization_count'] ?? null)
            || $payload['authorization_count'] < 0
            || ! is_array($payload['authorizations'] ?? null)
            || ! array_is_list($payload['authorizations'])
            || count($payload['authorizations']) > REVOCATION_MAX_ENTRIES
            || $payload['count'] !== count($payload['revoked'])
            || $payload['authorization_count'] !== count($payload['authorizations'])) {
            throw new ProtocolException('The revocation response was invalid.');
        }
        $generated = parse_revocation_time($payload['generated_at'], true);
        if ($generated === null) {
            throw new ProtocolException('The revocation response timestamp was invalid.');
        }
        if ($generated < $now - $this->maximumStale || $generated > $now + 60) {
            throw new ProtocolException('The revocation response was stale.');
        }

        $revoked = [];
        foreach ($payload['revoked'] as $index => $row) {
            if (! (($shape->revoked[$index] ?? null) instanceof \stdClass)
                || ! is_array($row)
                || array_is_list($row)
                || ! valid_revocation_subject($row['sub'] ?? null)
                || ! is_string($row['since'] ?? null)
                || $row['since'] === ''
                || strlen($row['since']) > 64
                || ($since = parse_revocation_time($row['since'])) === null
                || $since > $now + 60
                || ! is_string($row['reason'] ?? null)
                || ! in_array($row['reason'], REVOCATION_REASONS, true)
                || array_key_exists($row['sub'], $revoked)) {
                throw new ProtocolException('The revocation response contained an invalid entry.');
            }
            $revoked[$row['sub']] = $row['reason'];
        }

        $authorizations = [];
        foreach ($payload['authorizations'] as $index => $row) {
            if (! (($shape->authorizations[$index] ?? null) instanceof \stdClass)
                || ! is_array($row)
                || array_is_list($row)
                || ! valid_revocation_subject($row['sub'] ?? null)
                || ! valid_session_version($row['session_version'] ?? null)
                || array_key_exists($row['sub'], $authorizations)) {
                throw new ProtocolException('The revocation response contained an invalid authorization.');
            }
            $authorizations[$row['sub']] = $row['session_version'];
        }
        return [
            'fetched_at' => $now,
            'generated_at' => $generated,
            'count' => $payload['count'],
            'authorization_count' => $payload['authorization_count'],
            'revoked' => $revoked,
            'authorizations' => $authorizations,
        ];
    }
}

function valid_revocation_subject(mixed $subject): bool
{
    return is_string($subject)
        && preg_match('/^t[1-9][0-9]*u[1-9][0-9]*$/D', $subject) === 1;
}

function parse_revocation_time(string $value, bool $rfc3339Only = false): ?int
{
    $formats = $rfc3339Only
        ? ['!Y-m-d\TH:i:sP']
        : ['!Y-m-d\TH:i:sP', '!Y-m-d H:i:s'];
    foreach ($formats as $format) {
        $parsed = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $parsed->getTimestamp();
        }
    }
    return null;
}

/**
 * @return array{fetched_at:int,generated_at:int,count:int,authorization_count:int,
 *   revoked:array<string,string>,authorizations:array<string,string>}|null
 */
function valid_revocation_cache_value(mixed $value): ?array
{
    if (! is_array($value)
        || ! is_int($value['fetched_at'] ?? null)
        || $value['fetched_at'] < 1
        || ! is_int($value['generated_at'] ?? null)
        || $value['generated_at'] < 1
        || ! is_int($value['count'] ?? null)
        || $value['count'] < 0
        || ! is_int($value['authorization_count'] ?? null)
        || $value['authorization_count'] < 0
        || ! is_array($value['revoked'] ?? null)
        || count($value['revoked']) > REVOCATION_MAX_ENTRIES
        || ! is_array($value['authorizations'] ?? null)
        || count($value['authorizations']) > REVOCATION_MAX_ENTRIES
        || $value['count'] !== count($value['revoked'])
        || $value['authorization_count'] !== count($value['authorizations'])) {
        return null;
    }
    $revoked = [];
    foreach ($value['revoked'] as $subject => $reason) {
        if (! valid_revocation_subject($subject)
            || ! is_string($reason)
            || ! in_array($reason, REVOCATION_REASONS, true)) {
            return null;
        }
        $revoked[$subject] = $reason;
    }
    $authorizations = [];
    foreach ($value['authorizations'] as $subject => $sessionVersion) {
        if (! valid_revocation_subject($subject)
            || ! valid_session_version($sessionVersion)) {
            return null;
        }
        $authorizations[$subject] = $sessionVersion;
    }
    return [
        'fetched_at' => $value['fetched_at'],
        'generated_at' => $value['generated_at'],
        'count' => $value['count'],
        'authorization_count' => $value['authorization_count'],
        'revoked' => $revoked,
        'authorizations' => $authorizations,
    ];
}
