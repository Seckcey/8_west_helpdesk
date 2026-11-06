<?php
/** Responses SSE transport. Only visible output deltas leave this adapter. */
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

final class PortalWestySseParser
{
    private string $buffer = '';
    private int $bytes = 0;
    public function feed(string $chunk, callable $event): void
    {
        $this->bytes += strlen($chunk);
        if ($this->bytes > 524288) throw new RuntimeException('provider_limit');
        $this->buffer .= $chunk;
        while (preg_match('/\r?\n\r?\n/',$this->buffer,$separator,PREG_OFFSET_CAPTURE)===1) {
            $end=$separator[0][1];
            $frame = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + strlen($separator[0][0]));
            $data = [];
            foreach (explode("\n", $frame) as $line) {
                if (str_starts_with($line, 'data:')) $data[] = rtrim(ltrim(substr($line, 5), ' '),"\r");
            }
            if ($data === []) continue;
            $json = implode("\n", $data);
            if ($json === '[DONE]') continue;
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($value)) throw new RuntimeException('provider_invalid');
            $event($value);
        }
    }
}

function portal_westy_workspace_body(array $messages, bool $tools,?array $context=null): array
{
    return [
        'model'=>'gpt-6-luna', 'service_tier'=>'default', 'store'=>false, 'background'=>false,
        'stream'=>true, 'reasoning'=>['effort'=>'low'], 'max_output_tokens'=>1200,
        'include'=>['reasoning.encrypted_content'],
        'instructions'=>portal_westy_device_instructions(context:$context),
        'input'=>$messages,
        'tools'=>$tools ? portal_westy_tool_definitions() : [],
        'parallel_tool_calls'=>false,
    ];
}
