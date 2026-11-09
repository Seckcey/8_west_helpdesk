<?php
/** Active customer-stream text buffering and shared device instructions. */
declare(strict_types=1);
require_once __DIR__.'/portal_westy_device_instructions.php';

/** Whole UTF-8 callbacks only; queued text never carries delivery authority. */
final class PortalWestyTextBuffer
{
    private const MAX_BYTES = 128;
    private const MAX_NANOSECONDS = 100000000;
    private string $pending = '';
    private int $queuedAt = 0;
    private bool $first = true;
    private Closure $clock;

    public function __construct(private Closure $emit, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
    }

    public function append(string $text): void
    {
        if ($text === '') return;
        // Never split a provider callback, including a multibyte character.
        // An oversized callback passes straight through without being queued.
        if ($this->first || strlen($text) >= self::MAX_BYTES) {
            $this->flush();
            $this->first = false;
            ($this->emit)($text);
            return;
        }
        if (strlen($this->pending) + strlen($text) > self::MAX_BYTES) $this->flush();
        if ($this->pending === '') $this->queuedAt = ($this->clock)();
        $this->pending .= $text;
        if (strlen($this->pending) >= self::MAX_BYTES) $this->flush();
        else $this->flushDue();
    }

    /** True only when fresh output authorization ran; otherwise heartbeat may throttle. */
    public function flushDue(): bool
    {
        if ($this->pending === '' || ($this->clock)() - $this->queuedAt < self::MAX_NANOSECONDS) return false;
        $this->flush();
        return true;
    }

    public function flush(): void
    {
        if ($this->pending === '') return;
        $text = $this->pending;
        $this->discard(); // A refused flush must never be replayed during recovery.
        ($this->emit)($text);
    }

    public function discard(): void
    {
        $this->pending = '';
        $this->queuedAt = 0;
    }
}
