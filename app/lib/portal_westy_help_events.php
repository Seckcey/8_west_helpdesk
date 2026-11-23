<?php
/** Help retrieval statistics. Only gap questions carry scrubbed, short-lived text. */
declare(strict_types=1);
require_once __DIR__.'/help_catalog.php';

function portal_westy_gap_text(string $text): string
{
    // Milepost's endpoint_shell_scrub() and westy_case_text(). Remove whole fields,
    // URLs and paths before replacing tokens inside them or cutting to the byte limit.
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    $patterns = [
        '/(?!\s)\p{Cc}/u' => '',
        '/```.*?(?:```|\z)/s' => '[omitted code block]',
        '/-----BEGIN [^-]*PRIVATE KEY-----.*?(?:-----END [^-]*PRIVATE KEY-----|\z)/s' => '[redacted private key]',
        '/\b(?:password|passwd|secret|token|(?:access|refresh)[_ -]?token|api[_ -]?key|authorization|client[_ -]?secret)["\x27]?\s*[:=]\s*[^\r\n]+/i' => '[redacted credential field]',
        '~https?://[^\s<>"\x27]+~i' => '[redacted URL]',
        '~(?:[a-z]:[\\\\/]|\\\\\\\\)[^\r\n\t<>"\x27|]*~i' => '[redacted path]',
        '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i' => '[redacted email]',
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '[redacted address]',
        '/\bBearer\s+\S+/i' => '[redacted token]',
        '/\b(?:sk-(?:ant-)?[A-Za-z0-9_-]{12,}|eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)\b/' => '[redacted credential]',
        '~[A-Za-z0-9+_/-]{40,}={0,2}~' => '[redacted token]',
        '/\s+/u' => ' ',
    ];
    $clean = preg_replace(array_keys($patterns), array_values($patterns), $text);
    return mb_strcut(trim($clean ?? ''), 0, 200, 'UTF-8');
}

function portal_westy_help_missing_table(PDOException $error): bool
{
    return ($error->errorInfo[1] ?? null) === 1146
        || (($error->errorInfo[1] ?? null) === 1 && str_starts_with($error->errorInfo[2] ?? '', 'no such table:'));
}

function portal_westy_help_tables_installed(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM portal_westy_help_events, help_gap_questions LIMIT 0');
        return true;
    } catch (PDOException $error) {
        if (portal_westy_help_missing_table($error)) return false;
        throw $error;
    }
}

/** A reopened desktop turn can invalidate a previously final gap. The two rows shared a UTC timestamp. */
function portal_westy_help_withdraw_gap(PDO $pdo, array $turn, array $event, string $lock): void
{
    // With identical customer, time, surface and scrubbed text, either anonymous copy is equivalent.
    $find = $pdo->prepare('SELECT id FROM help_gap_questions WHERE tenant_id=? AND client_id=?
        AND created_at=? AND surface=? AND question=? ORDER BY id LIMIT 1'.$lock);
    $find->execute([$turn['tenant_id'], $turn['client_id'], $event['created_at'], $event['surface'],
        portal_westy_gap_text($turn['input_text'])]);
    $questionId = $find->fetchColumn();
    if ($questionId !== false) {
        $pdo->prepare('DELETE FROM help_gap_questions WHERE id=? AND tenant_id=? AND client_id=?')
            ->execute([$questionId, $turn['tenant_id'], $turn['client_id']]);
    }
    $pdo->prepare('DELETE FROM portal_westy_help_events WHERE id=? AND turn_id=? AND tenant_id=? AND client_id=?')
        ->execute([$event['id'], $turn['id'], $turn['tenant_id'], $turn['client_id']]);
}

/** Called after reply accounting, in its own transaction. Replays serialize on the owned turn. */
function portal_westy_help_events_record(PDO $pdo, array $scope, int $turnId, int $attemptId, string $surface, array $articles, bool $gap): void
{
    try {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $waits = $mysql ? $pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout, @@SESSION.lock_wait_timeout')->fetch(PDO::FETCH_NUM) : null;
        $started = false;
        try {
            if ($mysql) {
                // Bound both InnoDB row waits and metadata waits, including the migration probe.
                $pdo->exec('SET SESSION innodb_lock_wait_timeout='.min(2, (int)$waits[0]).', SESSION lock_wait_timeout='.min(2, (int)$waits[1]));
            }
            if (!portal_westy_help_tables_installed($pdo)) return;
            $version = help_catalog_index()['version'];
            $lock = $mysql ? ' FOR UPDATE NOWAIT' : '';
            $pdo->beginTransaction();
            $started = true;
            $query = $pdo->prepare('SELECT t.id,t.tenant_id,t.client_id,t.input_text,
                (SELECT a.id FROM portal_westy_ai_attempts a WHERE a.turn_id=t.id ORDER BY a.sequence DESC LIMIT 1) AS latest_attempt
                FROM portal_westy_turns t WHERE t.id=? AND t.tenant_id=? AND t.client_id=? AND t.scope_key=? AND t.input_text IS NOT NULL'.$lock);
            $query->execute([$turnId, $scope['tenant'], $scope['client'], $scope['key']]);
            $turn = $query->fetch(PDO::FETCH_ASSOC);
            if (!$turn) return;

            $previous = $pdo->prepare('SELECT id,kind,article_id,created_at,surface FROM portal_westy_help_events
                WHERE turn_id=? AND tenant_id=? AND client_id=?');
            $previous->execute([$turnId, $turn['tenant_id'], $turn['client_id']]);
            $saved = $previous->fetchAll(PDO::FETCH_ASSOC);
            $now = time();
            $created = gmdate('Y-m-d H:i:s', $now);
            $eventArgs = [$version, $surface, $created, gmdate('Y-m-d H:i:s', $now + 400 * 86400)];
            $insert = $pdo->prepare('INSERT INTO portal_westy_help_events
                (tenant_id,client_id,turn_id,kind,article_id,catalog_version,surface,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach (array_diff(array_unique($articles), array_column($saved, 'article_id')) as $article) {
                $insert->execute([$turn['tenant_id'], $turn['client_id'], $turnId, 'cited', $article, ...$eventArgs]);
            }
            // Only the newest attempt may decide a gap. Earlier attempts may still contribute citations.
            if ((int)$turn['latest_attempt'] === $attemptId) {
                if ($gap && !$articles && !$saved) {
                    $insert->execute([$turn['tenant_id'], $turn['client_id'], $turnId, 'gap', null, ...$eventArgs]);
                    $question = $pdo->prepare('INSERT INTO help_gap_questions
                        (tenant_id,client_id,surface,question,created_at,expires_at) VALUES (?,?,?,?,?,?)');
                    $question->execute([$turn['tenant_id'], $turn['client_id'], $surface, portal_westy_gap_text($turn['input_text']),
                        $created, gmdate('Y-m-d H:i:s', $now + 90 * 86400)]);
                } elseif (!$gap) {
                    foreach ($saved as $event) {
                        if ($event['kind'] === 'gap') portal_westy_help_withdraw_gap($pdo, $turn, $event, $lock);
                    }
                }
            }
            $pdo->commit();
        } finally {
            try {
                if ($started && $pdo->inTransaction()) $pdo->rollBack();
            } finally {
                if ($mysql) $pdo->exec('SET SESSION innodb_lock_wait_timeout='.(int)$waits[0].', SESSION lock_wait_timeout='.(int)$waits[1]);
            }
        }
    } catch (Throwable $error) {
        if ($error instanceof PDOException && portal_westy_help_missing_table($error)) return;
        error_log('[portal-westy-help-events] record_failed type='.$error::class);
    }
}
