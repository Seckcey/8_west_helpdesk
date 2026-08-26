<?php
/** Transaction-bound state, PKCE verifier, and nonce storage. */
declare(strict_types=1);

namespace EightWest\Id;

const ATTEMPT_MAX_TTL_SECONDS = 300;

final class Attempt
{
    public function __construct(
        public readonly string $state,
        public readonly string $verifier,
        public readonly string $nonce,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
    ) {
        if (! valid_attempt_value($state, 43, 256)
            || ! valid_attempt_value($verifier, 43, 128)
            || ! valid_attempt_value($nonce, 43, 256)
            || $issuedAt < 1
            || $expiresAt <= $issuedAt
            || $expiresAt - $issuedAt > ATTEMPT_MAX_TTL_SECONDS) {
            throw new ProtocolException('Invalid authorization attempt.');
        }
    }

    /** @return array{state:string,verifier:string,nonce:string,issued_at:int,expires_at:int} */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'verifier' => $this->verifier,
            'nonce' => $this->nonce,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
        ];
    }

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)
            || ! is_string($value['state'] ?? null)
            || ! is_string($value['verifier'] ?? null)
            || ! is_string($value['nonce'] ?? null)
            || ! is_int($value['issued_at'] ?? null)
            || ! is_int($value['expires_at'] ?? null)) {
            return null;
        }
        try {
            return new self(
                $value['state'],
                $value['verifier'],
                $value['nonce'],
                $value['issued_at'],
                $value['expires_at'],
            );
        } catch (ProtocolException) {
            return null;
        }
    }
}

function valid_attempt_value(string $value, int $minimum, int $maximum): bool
{
    $length = strlen($value);
    return $length >= $minimum
        && $length <= $maximum
        && preg_match('/^[A-Za-z0-9._~-]+$/D', $value) === 1;
}

interface AttemptStore
{
    public function save(Attempt $attempt): void;

    /** Atomically remove and return the attempt, or null when it does not exist. */
    public function consume(string $state): ?Attempt;
}

final class PhpSessionAttemptStore implements AttemptStore
{
    public function __construct(
        private readonly string $sessionKey = '_eightwest_id_attempts',
        private readonly int $maxAttempts = 8,
    ) {
        if ($sessionKey === '' || $maxAttempts < 1 || $maxAttempts > 32) {
            throw new ConfigurationException('Invalid PHP session attempt-store configuration.');
        }
    }

    public function save(Attempt $attempt): void
    {
        $this->ensureSession();
        $now = time();
        $attempts = is_array($_SESSION[$this->sessionKey] ?? null)
            ? $_SESSION[$this->sessionKey]
            : [];
        foreach ($attempts as $state => $stored) {
            $candidate = Attempt::fromArray($stored);
            if ($candidate === null || $candidate->expiresAt <= $now) {
                unset($attempts[$state]);
            }
        }
        $attempts[$attempt->state] = $attempt->toArray();
        if (count($attempts) > $this->maxAttempts) {
            uasort($attempts, static fn(mixed $a, mixed $b): int =>
                (int)($a['issued_at'] ?? 0) <=> (int)($b['issued_at'] ?? 0));
            $attempts = array_slice($attempts, -$this->maxAttempts, null, true);
        }
        $_SESSION[$this->sessionKey] = $attempts;
    }

    public function consume(string $state): ?Attempt
    {
        $this->ensureSession();
        $attempts = is_array($_SESSION[$this->sessionKey] ?? null)
            ? $_SESSION[$this->sessionKey]
            : [];
        $value = $attempts[$state] ?? null;
        unset($attempts[$state]);
        $_SESSION[$this->sessionKey] = $attempts;
        return Attempt::fromArray($value);
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        if (headers_sent()) {
            throw new ProtocolException('The PHP session must start before output.');
        }
        if (! session_start()) {
            throw new ProtocolException('The PHP session could not be started.');
        }
    }
}
