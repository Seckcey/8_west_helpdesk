<?php
/**
 * Server-side AI layer — the trimmed Safeharbor port of Milepost's lib/ai.php
 * (one way of doing things: raw cURL, no composer/vendor, keys server-only).
 * Westy is the only consumer today; the provider seam stays swappable.
 *
 * GUARDRAILS (Milepost parity):
 *   - The API key is read from config on the SERVER only, never sent to the browser.
 *   - The DEV/TEST `stub` provider (canned output) is HARD-GATED behind
 *     `ai.allow_stub`; without it the stub is unusable, so production can
 *     never render canned output as if it were real.
 *   - On unconfigured / refusal / API error we return a clear error — we
 *     never fabricate output.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/westy_credentials.php';

function ai_config(): array
{
    $c = cfg('ai');
    return is_array($c) ? $c : [];
}

/** Is a REAL, usable provider configured? Controls whether Westy renders at all. */
function ai_enabled(): bool
{
    $c = ai_config();
    $provider = (string)($c['provider'] ?? 'anthropic');
    if ($provider === 'stub') return !empty($c['allow_stub']);
    if ($provider === 'anthropic') return westy_anthropic_auth($c, trim((string)($c['api_key'] ?? '')), 'https://api.anthropic.com/v1/messages') !== null;
    return $provider === 'openai' && ($c['auth_mode'] ?? 'api_key') === 'api_key'
        && trim((string)($c['api_key'] ?? '')) !== '';
}

/**
 * One structured completion. Returns ['ok'=>true,'data'=>array] or
 * ['ok'=>false,'error'=>plain,'refusal'?=>true]. $schema is a JSON Schema the
 * reply must satisfy (output_config json_schema — guaranteed-parseable JSON).
 */
function ai_provider_complete(string $system, string $user, array $schema): array
{
    $c = ai_config();

    if (($c['provider'] ?? '') === 'stub') {
        if (empty($c['allow_stub'])) {
            return ['ok' => false, 'error' => 'The stub AI provider is disabled (ai.allow_stub is not set).'];
        }
        return ['ok' => true, 'data' => ['reply' => '[STUB] Canned Westy reply — not from a real AI provider. Local testing only.']];
    }

    $key = trim((string)($c['api_key'] ?? ''));
    if (!ai_enabled()) return ['ok' => false, 'error' => 'AI credentials are unavailable.'];

    $provider = (string)($c['provider'] ?? 'anthropic');
    if ($provider === 'openai') {
        $body = ai_openai_body($c, $system, $user, $schema);
        $r = ai_http_post('https://api.openai.com/v1/chat/completions', [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ], $body, (int)($c['timeout'] ?? 60));
        if (isset($r['error'])) return ['ok' => false, 'error' => $r['error']];
        return ai_parse_openai($r['http'], json_decode((string)$r['body'], true));
    }

    $auth = westy_anthropic_auth($c, $key, 'https://api.anthropic.com/v1/messages');
    if ($auth === null) return ['ok' => false, 'error' => 'Anthropic credentials are unavailable.'];

    // Anthropic. NOTE: no `thinking` param on purpose — current models
    // (claude-opus-5) default to adaptive thinking, and omitting it stays
    // compatible if the model is switched. max_tokens caps thinking + reply.
    $body = [
        'model'         => (string)($c['model'] ?? 'claude-opus-5'),
        'max_tokens'    => (int)($c['max_tokens'] ?? 8000),
        'system'        => $system,
        'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        'messages'      => [['role' => 'user', 'content' => $user]],
    ];
    $r = ai_http_post('https://api.anthropic.com/v1/messages', [
        'Content-Type: application/json',
        $auth,
        'anthropic-version: 2023-06-01',
    ], $body, (int)($c['timeout'] ?? 60));
    if (isset($r['error'])) return ['ok' => false, 'error' => $r['error']];
    return ai_parse_anthropic($r['http'], json_decode((string)$r['body'], true));
}

/** OpenAI chat-completions request body (Milepost's ai_openai_body, trimmed). PURE. */
function ai_openai_body(array $c, string $system, string $user, array $schema): array
{
    return [
        'model'    => (string)($c['model'] ?? 'gpt-5.1'),
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'response_format' => [
            'type'        => 'json_schema',
            'json_schema' => ['name' => 'westy_reply', 'strict' => true, 'schema' => $schema],
        ],
        'max_completion_tokens' => (int)($c['max_tokens'] ?? 8000),
    ];
}

/** Parse an OpenAI chat-completions response (Milepost's ai_parse_openai). PURE. */
function ai_parse_openai(int $http, $j): array
{
    if (!is_array($j)) return ['ok' => false, 'error' => 'AI provider returned an unreadable response (HTTP ' . $http . ').'];
    if ($http !== 200 || isset($j['error'])) {
        $msg = is_array($j['error'] ?? null) ? (string)($j['error']['message'] ?? '') : (string)($j['error'] ?? '');
        return ['ok' => false, 'error' => 'AI provider error: ' . ($msg !== '' ? $msg : ('HTTP ' . $http))];
    }
    $choice = (array)($j['choices'][0] ?? []);
    $msg    = (array)($choice['message'] ?? []);
    if (trim((string)($msg['refusal'] ?? '')) !== '') {
        return ['ok' => false, 'refusal' => true, 'error' => 'The assistant declined this request.'];
    }
    if (($choice['finish_reason'] ?? '') === 'length') {
        return ['ok' => false, 'error' => 'The AI response was truncated — try a shorter question.'];
    }
    $data = json_decode((string)($msg['content'] ?? ''), true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'The AI response was not valid structured output. Try again.'];
    return ['ok' => true, 'data' => $data];
}

/** One place that touches cURL. Returns ['http'=>int,'body'=>string] or ['error'=>string]. */
function ai_http_post(string $url, array $headers, array $body, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
    ]);
    $resp  = curl_exec($ch);
    $errno = curl_errno($ch);
    $cerr  = curl_error($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0) return ['error' => 'Could not reach the AI provider (' . $cerr . ').'];
    return ['http' => $http, 'body' => (string)$resp];
}

/** Parse an Anthropic Messages response into the shared result shape. PURE. */
function ai_parse_anthropic(int $http, $j): array
{
    if (!is_array($j)) return ['ok' => false, 'error' => 'AI provider returned an unreadable response (HTTP ' . $http . ').'];
    if ($http !== 200 || ($j['type'] ?? '') === 'error') {
        return ['ok' => false, 'error' => 'AI provider error (HTTP ' . $http . ').'];
    }
    if (($j['stop_reason'] ?? '') === 'refusal') {
        return ['ok' => false, 'refusal' => true, 'error' => 'The assistant declined this request.'];
    }
    if (($j['stop_reason'] ?? '') === 'max_tokens') {
        return ['ok' => false, 'error' => 'The AI response was truncated — try a shorter question.'];
    }
    $text = '';
    foreach ((array)($j['content'] ?? []) as $blk) {
        if (($blk['type'] ?? '') === 'text') { $text = (string)($blk['text'] ?? ''); break; }   // skip thinking blocks
    }
    $data = json_decode($text, true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'The AI response was not valid structured output. Try again.'];
    return ['ok' => true, 'data' => $data];
}
