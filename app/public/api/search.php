<?php
/**
 * GET /api/search.php?q= — full search for the ⌘K palette: a ticket's number
 * first, then subjects (LIKE, catches partial words) + message bodies
 * (FULLTEXT), resolved included, tenant-scoped, capped at 15.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/render.php';

$user = require_login();
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

$results = [];

// A ticket's number finds exactly that ticket at any length, with or without "#".
// Any words after it must be in its subject or client, where a local palette row looks.
if (preg_match('/\A#?([1-9][0-9]{0,9})(?:\s+(.+))?\z/s', $q, $number)) {
    $st = db()->prepare(
        'SELECT t.id, t.subject, t.status, c.name AS client
           FROM tickets t JOIN clients c ON c.id = t.client_id
          WHERE t.tenant_id = ? AND t.id = ?'
    );
    $st->execute([tenant_id(), (int)$number[1]]);
    foreach ($st->fetchAll() as $r) {
        $text = mb_strtolower($r['subject'] . ' ' . $r['client']);
        $words = preg_split('/\s+/', mb_strtolower($number[2] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (!array_filter($words, static fn($word) => !str_contains($text, $word))) $results[(int)$r['id']] = $r;
    }
}

// Text needs 3+ characters
if (mb_strlen($q) >= 3) {
    // Subjects next (partial-word friendly)
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
}

json_out(['ok' => true, 'results' => array_values(array_map(static fn($r) => [
    'id' => (int)$r['id'], 'subject' => $r['subject'],
    'status' => $r['status'], 'client' => $r['client'],
], array_slice($results, 0, 15, true)))]);
