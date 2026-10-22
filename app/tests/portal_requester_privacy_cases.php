<?php
/** Shared synthetic behavior matrix, invoked by SQLite and MySQL fixtures. */
declare(strict_types=1);

function portal_requester_test_identity(int $tenantId, int $clientId, string $role = 'client_owner'): array
{
    return ['tenant_id'=>$tenantId, 'client_id'=>$clientId, 'subject'=>'t9u4',
        'identity_tenant_slug'=>'acme-id', 'role'=>$role];
}

function portal_requester_privacy_cases(PDO $pdo, callable $check, callable $expect): void
{
    $actor = static fn(int $tenant, int $client, string $subject, string $role): array => [
        'tenant_id'=>$tenant, 'client_id'=>$client, 'subject'=>$subject,
        'identity_tenant_slug'=>'synthetic-client-'.$client, 'role'=>$role,
    ];
    $a = $actor(1, 11, 't9u51', 'client_staff');
    $b = $actor(1, 11, 't9u52', 'client_staff');
    $ids = [];
    foreach ([$a, $b, $actor(1, 12, 't9u51', 'client_staff'), $actor(2, 21, 't9u51', 'client_staff')] as $i) {
        $ids[] = portal_create_ticket($pdo, $i['tenant_id'], $i['client_id'], $i['role'],
            'Same display name', 'Synthetic private request', 'normal', 'Synthetic private message', $i);
    }
    // A historical ticket remains unattributed even with identical visible text.
    $legacy = $pdo->prepare("INSERT INTO tickets (tenant_id,client_id,subject,status,priority,channel,sla_due_at,created_at,updated_at)
        VALUES (1,11,'Synthetic private request','open','normal','portal',?,?,?)");
    $now = gmdate('Y-m-d H:i:s'); $legacy->execute([$now, $now, $now]);
    $old = (int)$pdo->lastInsertId();
    foreach (['client_staff', 'client_viewer', 'client_admin', 'client_owner'] as $role) {
        $i = [...$a, 'role'=>$role];
        $admin = in_array($role, ['client_admin', 'client_owner'], true);
        $summary = portal_ticket_summary($pdo, 1, 11, 100, $i);
        $visible = array_map('intval', array_column($summary['tickets'], 'id'));
        $check($role.' list and counts follow immutable requester attribution',
            in_array($ids[0], $visible, true) && in_array($ids[1], $visible, true) === $admin
            && in_array($old, $visible, true) === $admin
            && !in_array($ids[2], $visible, true) && !in_array($ids[3], $visible, true)
            && array_sum($summary['counts']) === count($visible));
        $check($role.' own detail includes the public conversation',
            portal_ticket_detail($pdo, 1, 11, $ids[0], $i)['messages'][0]['body'] === 'Synthetic private message');
        foreach ([$ids[1], $old, $ids[2], $ids[3]] as $ticket) {
            if ($admin && in_array($ticket, [$ids[1], $old], true)) {
                $check($role.' can read client-wide detail '.$ticket,
                    (int)portal_ticket_detail($pdo, 1, 11, $ticket, $i)['ticket']['id'] === $ticket);
            } else {
                $expect($role.' cannot read another requester/client/MSP '.$ticket, PortalDataNotFoundException::class,
                    fn()=>portal_ticket_detail($pdo, 1, 11, $ticket, $i));
            }
        }
        if (!$admin) {
            $expect($role.' cannot read archived client summaries', PortalDataForbiddenException::class,
                fn()=>portal_report_archives($pdo, 1, 11, 3, $i));
            $expect($role.' cannot read a direct archived summary', PortalDataForbiddenException::class,
                fn()=>portal_report_archive($pdo, 1, 11, 1, $i));
        }
        $before = (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
        if ($role === 'client_viewer') {
            $expect('viewer cannot reply to own request', PortalDataForbiddenException::class,
                fn()=>portal_reply_to_ticket($pdo, 1, 11, $ids[0], $role, 'Same display name', 'No write', $i));
        } elseif (!$admin) {
            $expect('member cannot reply to another requester', PortalDataNotFoundException::class,
                fn()=>portal_reply_to_ticket($pdo, 1, 11, $ids[1], $role, 'Same display name', 'No write', $i));
        }
        $check($role.' denied operations add no messages', $before === (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn());
    }
    $check('member can reply to own request', portal_reply_to_ticket($pdo, 1, 11, $ids[0], 'client_staff', 'Renamed person', 'Own reply', $a) > 0);
    $expect('missing identity never defaults to client-wide access', PortalDataForbiddenException::class,
        fn()=>portal_ticket_summary($pdo, 1, 11));
    $expect('target identifiers cannot replace the authenticated client', PortalDataForbiddenException::class,
        fn()=>portal_ticket_detail($pdo, 1, 12, $ids[2], $a));
    $expect('stale write role cannot override effective viewer', PortalDataForbiddenException::class,
        fn()=>portal_reply_to_ticket($pdo, 1, 11, $ids[0], 'client_staff', 'Name', 'No write', [...$a, 'role'=>'client_viewer']));
    $delegated = [...$a, 'role'=>'admin', 'customer_access'=>['role'=>'client_staff']];
    $check('MSP principal selecting member access does not inherit client-wide visibility',
        count(portal_ticket_summary($pdo, 1, 11, 100, $delegated)['tickets']) === 1);
    $check('same subject in a different identity tenant cannot inherit a request',
        portal_ticket_summary($pdo, 1, 11, 100, [...$a, 'identity_tenant_slug'=>'another-identity'])['tickets'] === []);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        foreach (["UPDATE portal_ticket_requesters SET subject='t9u99' WHERE ticket_id={$ids[0]}",
            "DELETE FROM portal_ticket_requesters WHERE ticket_id={$ids[0]}"] as $sql) {
            $expect('database preserves immutable requester attribution', PDOException::class, fn()=>$pdo->exec($sql));
        }
    } else {
        $pdo->exec('ALTER TABLE portal_ticket_requesters RENAME TO held_requesters');
        try {
            $expect('missing additive schema never opens member access', PDOException::class,
                fn()=>portal_ticket_summary($pdo, 1, 11, 100, $a));
            $count = (int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
            $expect('missing schema rolls back new portal ticket and message', PDOException::class,
                fn()=>portal_create_ticket($pdo, 1, 11, 'client_staff', 'Name', 'Missing schema', 'normal', 'Body', $a));
            $check('failed attribution leaves no orphan request', $count === (int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn());
        } finally { $pdo->exec('ALTER TABLE held_requesters RENAME TO portal_ticket_requesters'); }
    }
}
