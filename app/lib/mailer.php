<?php
/**
 * Outbound mail — same pattern as Milepost's lib/mailer.php:
 * producers queue rows in mail_queue; cron/mail_dispatch.php sends them.
 * SMTP via STARTTLS/SSL sockets; falls back to PHP mail() when no host.
 */
declare(strict_types=1);

if (!function_exists('cfg')) require_once __DIR__ . '/bootstrap.php';

/** Queue an email. Returns the queue id. */
function mail_queue(string $to, string $subject, string $bodyText, ?int $ticketId = null): int
{
    db()->prepare('INSERT INTO mail_queue (to_addr, subject, body_text, ticket_id) VALUES (?,?,?,?)')
        ->execute([$to, $subject, $bodyText, $ticketId]);
    return (int)db()->lastInsertId();
}

/* ------------------------------------------------------------------ */
/* Transport 1: Microsoft Graph sendMail (Entra app, client            */
/* credentials — same pattern as Milepost; works with Entra security   */
/* defaults ON and survives Microsoft's basic-auth retirement).        */
/* ------------------------------------------------------------------ */

/** The configured mail.graph block, or null unless ALL fields are set. */
function mailer_graph_config(): ?array
{
    $g = cfg('mail.graph');
    if (!is_array($g)) return null;
    $out = [];
    foreach (['tenant_id', 'client_id', 'client_secret', 'sender'] as $k) {
        $v = trim((string)($g[$k] ?? ''));
        if ($v === '') return null;
        $out[$k] = $v;
    }
    return $out;
}

function graph_http_post(string $url, array $headers, string $payload, int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $resp  = curl_exec($ch);
    $errno = curl_errno($ch);
    $cerr  = curl_error($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0) return ['error' => "could not reach Microsoft ({$cerr})"];
    return ['http' => $http, 'body' => (string)$resp];
}

function graph_http_get(string $url, string $token, int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    ]);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $cerr = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0) return ['error' => "could not reach Microsoft ({$cerr})"];
    return ['http' => $http, 'body' => (string)$resp];
}

/** Keep only a real provider HTTP status. Zero and malformed values are unknown. */
function graph_provider_http(array $response): ?int
{
    $http = $response['http'] ?? null;
    return is_int($http) && $http >= 100 && $http <= 599 ? $http : null;
}

/**
 * Request an app-only token and return bounded evidence plus the in-process
 * token. The token is transient and must never be persisted or logged.
 *
 * @param null|callable(string,array,string,int):array<string,mixed> $httpPost
 * @return array{token:string|null,provider_http:int|null,outcome_code:string}
 */
function graph_token_result(array $g, ?callable $httpPost = null): array
{
    static $cache = null; // [tenant_id, client_id, expires_at, token]
    $now = time();
    $useCache = $httpPost === null;
    if ($useCache && is_array($cache)
        && $cache[0] === $g['tenant_id']
        && $cache[1] === $g['client_id']
        && $cache[2] > $now + 60
    ) {
        return ['token' => $cache[3], 'provider_http' => 200, 'outcome_code' => 'graph_token_acquired'];
    }

    $post = $httpPost ?? 'graph_http_post';
    try {
        $r = $post(
            'https://login.microsoftonline.com/' . rawurlencode($g['tenant_id']) . '/oauth2/v2.0/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $g['client_id'],
                'client_secret' => $g['client_secret'],
                'scope'         => 'https://graph.microsoft.com/.default',
            ]),
            20,
        );
    } catch (Throwable) {
        return ['token' => null, 'provider_http' => null, 'outcome_code' => 'graph_token_transport_error'];
    }

    if (!is_array($r)) {
        return ['token' => null, 'provider_http' => null, 'outcome_code' => 'graph_token_unknown_response'];
    }
    if (array_key_exists('error', $r)) {
        return ['token' => null, 'provider_http' => null, 'outcome_code' => 'graph_token_transport_error'];
    }
    $http = graph_provider_http($r);
    if ($http === null) {
        return ['token' => null, 'provider_http' => null, 'outcome_code' => 'graph_token_unknown_response'];
    }
    if ($http !== 200) {
        return ['token' => null, 'provider_http' => $http, 'outcome_code' => 'graph_token_rejected'];
    }

    $j = json_decode((string)($r['body'] ?? ''), true);
    $rawToken = is_array($j) ? ($j['access_token'] ?? null) : null;
    $token = is_string($rawToken) ? trim($rawToken) : '';
    if ($token === '') {
        return ['token' => null, 'provider_http' => 200, 'outcome_code' => 'graph_token_invalid_response'];
    }
    if ($useCache) {
        $expiresIn = is_int($j['expires_in'] ?? null) ? $j['expires_in'] : 3600;
        $cache = [
            $g['tenant_id'],
            $g['client_id'],
            $now + max(60, $expiresIn),
            $token,
        ];
    }
    return ['token' => $token, 'provider_http' => 200, 'outcome_code' => 'graph_token_acquired'];
}

/** Turn bounded evidence into a safe legacy error string for mail_queue. */
function graph_result_error(array $result): string
{
    $labels = [
        'graph_token_rejected' => 'Microsoft Graph token request was rejected',
        'graph_token_invalid_response' => 'Microsoft Graph token response was invalid',
        'graph_token_transport_error' => 'Microsoft Graph token service could not be reached',
        'graph_token_unknown_response' => 'Microsoft Graph token response was unknown',
        'graph_send_rejected' => 'Microsoft Graph sendMail request was rejected',
        'graph_send_transport_error' => 'Microsoft Graph sendMail service could not be reached',
        'graph_send_unknown_response' => 'Microsoft Graph sendMail response was unknown',
        'graph_payload_invalid' => 'Microsoft Graph message could not be encoded',
    ];
    $code = is_string($result['outcome_code'] ?? null) ? $result['outcome_code'] : '';
    $message = $labels[$code] ?? 'Microsoft Graph mail submission was uncertain';
    $http = $result['provider_http'] ?? null;
    if (is_int($http) && $http >= 100 && $http <= 599) $message .= " (HTTP {$http})";
    return $message . '.';
}

/** App-only token, cached in-process (cron sends batches per run). */
function graph_token(array $g, ?string &$err = null, ?callable $httpPost = null): ?string
{
    $result = graph_token_result($g, $httpPost);
    if ($result['token'] !== null) {
        $err = null;
        return $result['token'];
    }
    $err = graph_result_error($result);
    return null;
}

/**
 * Send through Microsoft Graph and return only approval-safe evidence.
 * Response bodies, addresses, tokens, credentials, and exception text never
 * leave this function in its result.
 *
 * @param null|callable(string,array,string,int):array<string,mixed> $httpPost
 * @return array{outcome:string,provider_http:int|null,outcome_code:string}
 */
function mailer_send_graph_result(
    array $g,
    string $to,
    string $subject,
    string $body,
    ?callable $httpPost = null,
    ?string $replyTo = null,
): array {
    if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        return ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => 'graph_payload_invalid'];
    }
    $tokenResult = graph_token_result($g, $httpPost);
    $token = $tokenResult['token'];
    if ($token === null) {
        return [
            'outcome' => 'uncertain',
            'provider_http' => $tokenResult['provider_http'],
            'outcome_code' => $tokenResult['outcome_code'],
        ];
    }
    $msg = [
        'message' => [
            'subject' => $subject,
            'body'    => ['contentType' => 'Text', 'content' => $body],
            'toRecipients' => [['emailAddress' => ['address' => $to]]],
        ],
        'saveToSentItems' => false,
    ];
    if ($replyTo !== null) $msg['message']['replyTo'] = [['emailAddress' => ['address' => $replyTo]]];

    try {
        $payload = json_encode(
            $msg,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    } catch (Throwable) {
        return ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => 'graph_payload_invalid'];
    }

    $post = $httpPost ?? 'graph_http_post';
    try {
        $r = $post(
            'https://graph.microsoft.com/v1.0/users/' . rawurlencode($g['sender']) . '/sendMail',
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            $payload,
            20,
        );
    } catch (Throwable) {
        return ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => 'graph_send_transport_error'];
    }

    if (!is_array($r)) {
        return ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => 'graph_send_unknown_response'];
    }
    if (array_key_exists('error', $r)) {
        return ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => 'graph_send_transport_error'];
    }
    $http = graph_provider_http($r);
    if ($http === null) {
        return ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => 'graph_send_unknown_response'];
    }
    if ($http !== 202) {
        return ['outcome' => 'uncertain', 'provider_http' => $http, 'outcome_code' => 'graph_send_rejected'];
    }
    return ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted'];
}

function mailer_send_graph(
    array $g,
    string $to,
    string $subject,
    string $body,
    ?string &$err = null,
    ?callable $httpPost = null,
    ?string $replyTo = null,
): bool {
    $result = mailer_send_graph_result($g, $to, $subject, $body, $httpPost, $replyTo);
    $accepted = $result['outcome'] === 'submitted'
        && $result['provider_http'] === 202
        && $result['outcome_code'] === 'graph_accepted';
    $err = $accepted ? null : graph_result_error($result);
    return $accepted;
}

/** Send one email NOW (used by the dispatch cron). Returns true on success. */
function mail_send(string $to, string $subject, string $bodyText, ?string &$error = null, ?string $replyTo = null): bool
{
    if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) { $error = 'Invalid reply address'; return false; }
    // Transport order (Milepost parity): Graph → SMTP → PHP mail()
    $g = mailer_graph_config();
    if ($g !== null) return mailer_send_graph($g, $to, $subject, $bodyText, $error, null, $replyTo);

    $smtp = cfg('mail.smtp') ?? [];
    $host = trim((string)($smtp['host'] ?? ''));
    if ($host === '') {
        // PHP mail() fallback — fine for a quick start, like Milepost.
        $headers = 'From: ' . mail_from_header() . "\r\n" . 'Content-Type: text/plain; charset=utf-8';
        if ($replyTo !== null) $headers .= "\r\nReply-To: <" . $replyTo . '>';
        $ok = mail($to, mail_subject($subject), $bodyText, $headers);
        if (!$ok) $error = 'PHP mail() rejected the message';
        return $ok;
    }

    $port    = (int)($smtp['port'] ?? 587);
    $secure  = (string)($smtp['secure'] ?? 'tls'); // 'tls' | 'ssl' | ''
    $user    = (string)($smtp['user'] ?? '');
    $pass    = (string)($smtp['pass'] ?? '');
    $timeout = 20;

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $sock = @stream_socket_client($remote, $errno, $errstr, $timeout);
    if (!$sock) { $error = "connect: $errstr ($errno)"; return false; }
    stream_set_timeout($sock, $timeout);

    $read = static function () use ($sock): string {
        $data = '';
        while (($line = fgets($sock, 512)) !== false) {
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break; // last line of reply
        }
        return $data;
    };
    $cmd = static function (string $c) use ($sock, $read): string {
        fwrite($sock, $c . "\r\n");
        return $read();
    };

    $read(); // banner
    $ehlo = gethostname() ?: 'safeharbor';
    $cmd("EHLO $ehlo");
    if ($secure === 'tls') {
        $r = $cmd('STARTTLS');
        if (!str_starts_with($r, '220')) { fclose($sock); $error = 'STARTTLS refused'; return false; }
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($sock); $error = 'TLS handshake failed'; return false;
        }
        $cmd("EHLO $ehlo");
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($user));
        $r = $cmd(base64_encode($pass));
        if (!str_starts_with($r, '235')) { fclose($sock); $error = 'SMTP auth failed'; return false; }
    }

    $from = cfg('mail.from') ?? ('safeharbor@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $cmd("MAIL FROM:<$from>");
    $cmd("RCPT TO:<$to>");
    $r = $cmd('DATA');
    if (!str_starts_with($r, '354')) { fclose($sock); $error = 'DATA refused: ' . trim($r); return false; }

    $headers = implode("\r\n", [
        'From: ' . mail_from_header(),
        "To: <$to>",
        'Subject: ' . mail_subject($subject),
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($_SERVER['HTTP_HOST'] ?? 'safeharbor') . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    if ($replyTo !== null) $headers .= "\r\nReply-To: <" . $replyTo . '>';
    // dot-stuff + CRLF-normalize
    $body = preg_replace('/\r?\n/', "\r\n", $bodyText);
    $body = preg_replace('/^\./m', '..', $body);
    fwrite($sock, $headers . "\r\n\r\n" . $body . "\r\n.\r\n");
    $r = $read();
    $cmd('QUIT');
    fclose($sock);

    if (!str_starts_with($r, '250')) { $error = 'send refused: ' . trim($r); return false; }
    return true;
}

function mail_from_header(): string
{
    $from = cfg('mail.from') ?? 'safeharbor@localhost';
    $name = cfg('mail.from_name') ?? 'Safeharbor';
    return sprintf('%s <%s>', mb_encode_mimeheader($name, 'UTF-8'), $from);
}

/** RFC2047-encode a subject when it contains non-ASCII. */
function mail_subject(string $subject): string
{
    return preg_match('/[^\x20-\x7E]/', $subject) ? mb_encode_mimeheader($subject, 'UTF-8') : $subject;
}

/** Convenience: standard reply notification to a ticket's contact. */
function mail_notify_reply(array $ticket, string $contactEmail, string $authorName, string $replyBody): void
{
    // Never email machine senders (loop protection — see intake_is_auto_mail)
    if (preg_match('/^(mailer-daemon|postmaster|no-?reply|donotreply|bounce[s]?|autoreply)@/i', $contactEmail)) return;
    $subject = '[#' . (int)$ticket['id'] . '] ' . $ticket['subject'];
    $body = "Hi — {$authorName} replied to your ticket:\n\n"
          . wordwrap($replyBody, 78) . "\n\n"
          . "Reply to this email to add to the thread, or open the portal (coming soon).\n"
          . "— Safeharbor by 8 West IT";
    mail_queue($contactEmail, $subject, $body, (int)$ticket['id']);
}
