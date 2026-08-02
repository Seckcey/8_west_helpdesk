<?php
/**
 * Attachment download — session-authed, tenant-scoped via the ticket join,
 * ALWAYS forced download (never inline: an uploaded HTML file must never
 * execute in our origin).
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/attachments.php';
enforce_https();
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$q = db()->prepare(
    'SELECT a.* FROM attachments a JOIN tickets t ON t.id = a.ticket_id
      WHERE a.id = ? AND t.tenant_id = ?'
);
$q->execute([$id, tenant_id()]);
$att = $q->fetch();
if (!$att) { http_response_code(404); exit('Not found.'); }

$path = att_dir() . '/' . $att['stored_name'];
if (!is_file($path)) { http_response_code(410); exit('The file is no longer on disk.'); }

header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($att['filename']));
readfile($path);
