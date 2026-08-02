<?php
/**
 * GET /api/search.php?q= — full search for the ⌘K palette: subjects (LIKE,
 * catches partial words) + message bodies (FULLTEXT), resolved included,
 * tenant-scoped, capped at 15.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/render.php';

$user = require_login();
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 3) json_out(['ok' => true, 'results' => []]);
$q = mb_substr($q, 0, 100);

$results = [];

// Subjects first (partial-word friendly)
$st = db()->prepare(
    'SELECT t.id, t.subject, t.status, c.name AS client
       FROM tickets t JOIN clients c ON c.id = t.client_id
      WHERE t.tenant_id = ? AND t.subject LIKE ?
      ORDER BY t.updated_at DESC LIMIT 15'
);
$st->execute([tenant_id(), '%' . $q . '%']);
foreach ($st->fetchAll() as $r) $results[(int)$r['id']] = $r;

// Then bodies (FULLTEXT natural language)
if (count($results) < 15) {
    try {
        $st = db()->prepare(
            'SELECT DISTINCT t.id, t.subject, t.status, t.updated_at, c.name AS client
               FROM messages m
               JOIN tickets t ON t.id = m.ticket_id
               JOIN clients c ON c.id = t.client_id
              WHERE t.tenant_id = ? AND MATCH(m.body) AGAINST (? IN NATURAL LANGUAGE MODE)
              ORDER BY t.updated_at DESC LIMIT 15'
        );
        $st->execute([tenant_id(), $q]);
        foreach ($st->fetchAll() as $r) {
            if (!isset($results[(int)$r['id']])) $results[(int)$r['id']] = $r;
        }
    } catch (Throwable $e) {
        // FULLTEXT index missing (pre-migration) — subject results still serve
    }
}

json_out(['ok' => true, 'results' => array_values(array_map(static fn($r) => [
    'id' => (int)$r['id'], 'subject' => $r['subject'],
    'status' => $r['status'], 'client' => $r['client'],
], array_slice($results, 0, 15, true)))]);
