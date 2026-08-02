<?php
/**
 * Attachments — uploads on replies/notes + inbound email files. Bytes live
 * OUTSIDE the deploy tree (deploy.sh re-chmods current/ on every release);
 * the DB row maps a random stored_name back to the real filename. Serving
 * is always forced-download (never inline) via public/attachment.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const ATT_MAX_BYTES = 15 * 1024 * 1024;   // per file, uploads
const ATT_MAX_FILES = 5;                  // per message

function att_dir(): string
{
    return rtrim((string)(cfg('storage.attachments_dir') ?: '/srv/8west/apps/safeharbor/shared/attachments'), '/');
}

/** Store raw bytes as an attachment row + file. Returns id or null (full disk, bad dir…). */
function att_store(int $ticketId, ?int $messageId, string $filename, string $mime, string $bytes): ?int
{
    $dir = att_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) return null;
    $stored = bin2hex(random_bytes(20));
    if (@file_put_contents($dir . '/' . $stored, $bytes) === false) return null;
    $filename = mb_substr(utf8_clean(basename($filename)) ?: 'file', 0, 190);
    $mime = preg_match('#^[\w.+-]+/[\w.+-]+$#', $mime) ? mb_substr($mime, 0, 100) : 'application/octet-stream';
    db()->prepare('INSERT INTO attachments (ticket_id, message_id, filename, mime, size_bytes, stored_name) VALUES (?,?,?,?,?,?)')
        ->execute([$ticketId, $messageId, $filename, $mime, strlen($bytes), $stored]);
    return (int)db()->lastInsertId();
}

/** Store the composer's $_FILES uploads against a message. Returns stored count. */
function att_store_uploads(int $ticketId, int $messageId, array $files): int
{
    $stored = 0;
    $names = (array)($files['name'] ?? []);
    foreach (array_slice(array_keys($names), 0, ATT_MAX_FILES) as $i) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $tmp = (string)$files['tmp_name'][$i];
        if (!is_uploaded_file($tmp) || (int)$files['size'][$i] > ATT_MAX_BYTES) continue;
        $bytes = (string)file_get_contents($tmp);
        if ($bytes === '') continue;
        if (att_store($ticketId, $messageId, (string)$names[$i], (string)($files['type'][$i] ?? ''), $bytes) !== null) $stored++;
    }
    return $stored;
}

/** All attachments for a ticket, grouped by message_id ('' key = ticket-level). */
function att_for_ticket(int $ticketId): array
{
    $q = db()->prepare('SELECT * FROM attachments WHERE ticket_id = ? ORDER BY id');
    $q->execute([$ticketId]);
    $out = [];
    foreach ($q->fetchAll() as $a) $out[(string)($a['message_id'] ?? '')][] = $a;
    return $out;
}

/** Render the chips row for one message's attachments. */
function att_chips(array $atts): string
{
    if (!$atts) return '';
    $out = '<div class="att-row">';
    foreach ($atts as $a) {
        $kb = max(1, (int)round((int)$a['size_bytes'] / 1024));
        $out .= '<a class="att-chip" href="/attachment.php?id=' . (int)$a['id'] . '" title="Download">'
              . '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M13 7.5 8.6 12a3.1 3.1 0 0 1-4.5-4.4l5-5a2.1 2.1 0 0 1 3 3l-5 5a1.1 1.1 0 0 1-1.6-1.5l4.5-4.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'
              . h($a['filename']) . ' <span class="att-size">' . $kb . ' KB</span></a>';
    }
    return $out . '</div>';
}
