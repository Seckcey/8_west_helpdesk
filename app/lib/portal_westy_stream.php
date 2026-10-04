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

function portal_westy_workspace_body(array $messages, bool $tools): array
{
    return [
        'model'=>'gpt-6-luna', 'service_tier'=>'default', 'store'=>false, 'background'=>false,
        'stream'=>true, 'reasoning'=>['effort'=>'low'], 'max_output_tokens'=>1200,
        'include'=>['reasoning.encrypted_content'],
        'instructions'=>portal_westy_device_instructions(),
        'input'=>$messages,
        'tools'=>$tools ? portal_westy_tool_definitions() : [],
        'parallel_tool_calls'=>false,
    ];
}

/** Streaming callback returns false/throws to stop generation; no provider retry. */
function portal_westy_provider_stream(array $body, string $key, callable $emit, callable $alive): array
{
    $parser = new PortalWestySseParser(); $result = null; $text = ''; $exception = null;
    $ch = curl_init('https://api.openai.com/v1/responses');
    $dispatch = static function(array $event) use (&$result,&$text,$emit,$alive): void {
        $alive();
        switch ($event['type'] ?? '') {
            case 'response.output_text.delta':
                $delta = $event['delta'] ?? null;
                if (!is_string($delta) || !mb_check_encoding($delta,'UTF-8') || strlen($text.$delta)>16000
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$delta)) throw new RuntimeException('provider_invalid');
                $text .= $delta; $emit('delta',['text'=>$delta]); break;
            case 'response.completed':
                $r = $event['response'] ?? [];
                if (($r['status'] ?? '') !== 'completed' || !is_array($r['output'] ?? null)) throw new RuntimeException('provider_invalid');
                $result=['ok'=>true,'reply'=>$text,'output'=>$r['output'], 'input_tokens'=>$r['usage']['input_tokens']??null,'output_tokens'=>$r['usage']['output_tokens']??null]; break;
            case 'response.failed': case 'response.incomplete': case 'error':
                throw new RuntimeException('provider_unavailable');
            case 'response.refusal.delta': throw new RuntimeException('provider_refused');
        }
    };
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],
        CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        CURLOPT_NOPROGRESS=>false,
        CURLOPT_XFERINFOFUNCTION=>static function()use($alive,&$exception):int {
            try {$alive();return 0;}catch(Throwable $e){$exception=$e;return 1;}
        },
        CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use($parser,$dispatch,&$exception):int {
            try {$parser->feed($chunk,$dispatch);return strlen($chunk);}catch(Throwable $e){$exception=$e;return 0;}
        },
    ]);
    try {$ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);} finally {curl_close($ch);}
    if ($exception instanceof PortalWestyException) throw $exception;
    if ($ok===false || $http!==200 || $result===null) return ['ok'=>false,'reason'=>$http===429?'provider_rate_limit':'provider_unavailable'];
    return $result;
}
