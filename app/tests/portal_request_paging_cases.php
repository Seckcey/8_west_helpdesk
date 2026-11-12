<?php
/** Synthetic fixtures shared by SQLite, MySQL and the rendered gallery. */
declare(strict_types=1);
function portal_paging_seed(PDO $pdo): void
{
    $rows = [];
    for ($n = 1; $n <= 65; $n++) $rows[] = [41000+$n,1,11,'Paging record '.$n,$n <= 5 ? 'waiting' : 'open',null,'t9u51','example-client'];
    array_push($rows,
        [41100,1,11,'Paging record other member','open',null,'t9u52','example-client'],
        [41101,1,11,'Paging record other issuer','open',null,'t9u51','other-issuer'],
        [41200,1,12,'Paging record another client','open',null,'t9u51','example-client'],
        [41300,2,21,'Paging record another provider','open',null,'t9u51','example-client'],
        [41400,1,11,'Paging record merged destination','open',null,'t9u51','example-client'],
        [41401,1,11,'Paging record merged source','open',41400,'t9u51','example-client'],
        [41500,1,11,'Paging literal 50%_!','resolved',null,'t9u51','example-client']);
    $ticket = $pdo->prepare("INSERT INTO tickets (id,tenant_id,client_id,subject,status,priority,channel,created_at,updated_at,sla_due_at,merged_into_id)
        VALUES (?,?,?,?,?,'normal','portal','2026-01-15 10:00:00','2026-01-15 10:00:00','2026-01-15 18:00:00',?)");
    $requester = $pdo->prepare('INSERT INTO portal_ticket_requesters (ticket_id,tenant_id,client_id,subject,identity_tenant_slug) VALUES (?,?,?,?,?)');
    foreach ($rows as $row) {
        $ticket->execute(array_slice($row, 0, 6));
        $requester->execute([$row[0],$row[1],$row[2],$row[6],$row[7]]);
    }
    $pdo->exec("INSERT INTO messages(ticket_id,author_name,kind,body,created_at) VALUES (41006,'Example member','client','PUBLIC PAGING CANARY','2026-01-15 10:00:00')");
}
function portal_paging_sqlite(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE clients (id INTEGER, tenant_id INTEGER, name TEXT);
        CREATE TABLE tickets (id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, subject TEXT, status TEXT, priority TEXT, channel TEXT,
            created_at TEXT, updated_at TEXT, resolved_at TEXT, sla_due_at TEXT, merged_into_id INTEGER);
        CREATE TABLE messages (id INTEGER PRIMARY KEY, ticket_id INTEGER, author_name TEXT, kind TEXT, body TEXT, created_at TEXT);
        CREATE TABLE portal_ticket_requesters (ticket_id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, subject TEXT, identity_tenant_slug TEXT);');
    $pdo->exec("INSERT INTO clients VALUES (11,1,'Example client'),(12,1,'Second client'),(21,2,'Other provider client')");
    portal_paging_seed($pdo);
    return $pdo;
}
function portal_paging_cases(PDO $pdo, callable $check): void
{
    $actor = ['tenant_id'=>1,'client_id'=>11,'subject'=>'t9u51','identity_tenant_slug'=>'example-client'];
    foreach (['client_owner','client_admin','client_staff','client_viewer'] as $role) {
        $identity = $actor + ['role'=>$role];
        $admin = in_array($role,['client_owner','client_admin'],true);
        $read = static fn(array $options, int $limit = 50): array => portal_ticket_summary($pdo,1,11,$limit,$identity,$options);
        $first = $read(['q'=>'Paging record']);
        $next = $read(['q'=>'Paging record','page'=>2]);
        $ids = array_map('intval',array_column([...$first['tickets'],...$next['tickets']],'id'));
        $expected = [...range(41005,41001),...($admin ? [41101,41100] : []),...range(41065,41006)];
        $check($role.' exact two-page sequence excludes other clients/providers and both sides of a merge', $ids === $expected);
        $check($role.' counts and ranges cover the matching visible set',
            $first['listing']['total'] === count($expected) && $first['listing']['from'] === 1 && $first['listing']['to'] === 50
            && $next['listing']['from'] === 51 && $next['listing']['to'] === count($expected));
        $check($role.' server caps the requested page size', count($read(['q'=>'Paging record'],100)['tickets']) === 50);
        $check($role.' large valid page clamps to the final page', $read(['q'=>'Paging record','page'=>999999999])['listing']['page'] === 2);
        foreach (['#41006','41006'] as $number) {
            $found = $read(['q'=>$number]);
            $check($role.' finds an old request by its number', array_map('intval',array_column($found['tickets'],'id')) === [41006] && $found['listing']['total'] === 1);
        }
        $waiting = $read(['q'=>'Paging record','status'=>'waiting','page'=>2]);
        $check($role.' status and search filter counts together and clamp stale pages',
            array_map('intval',array_column($waiting['tickets'],'id')) === range(41005,41001)
            && $waiting['counts'] === ['open'=>0,'in_progress'=>0,'waiting'=>5,'resolved'=>0] && $waiting['listing']['page'] === 1);
        $check($role.' percent underscore and escape characters are literal',
            array_map('intval',array_column($read(['q'=>'50%_!'])['tickets'],'id')) === [41500]);
        foreach (["%' OR 1=1 --",'PUBLIC PAGING CANARY','PRIVATE PAGING CANARY','another client','another provider','#41400','#41401'] as $needle) {
            $empty = $read(['q'=>$needle]);
            $check($role.' no forbidden match for '.$needle, $empty['tickets'] === [] && $empty['listing']['total'] === 0
                && $empty['listing']['from'] === 0 && $empty['listing']['to'] === 0 && $empty['listing']['page'] === 1);
        }
        if (!$admin) $check($role.' cannot search another member by number', $read(['q'=>'#41100'])['tickets'] === [] && $read(['q'=>'#41101'])['tickets'] === []);
        $pdo->exec("INSERT INTO messages(ticket_id,author_name,kind,body,created_at) VALUES
            (41006,'Example support','note','PRIVATE PAGING CANARY','2030-01-15 10:00:00'),
            (41006,'Example support','system','SYSTEM PAGING CANARY','2031-01-15 10:00:00')");
        $pdo->exec("UPDATE tickets SET updated_at='2031-01-15 10:00:00' WHERE id=41006");
        $after = $read(['q'=>'Paging record','page'=>2]);
        $check($role.' internal activity leaves paged order and counts unchanged',
            array_column($next['tickets'],'id') === array_column($after['tickets'],'id') && $next['counts'] === $after['counts']);
        $check($role.' searching notes is impossible even after they exist', $read(['q'=>'PRIVATE PAGING CANARY'])['listing']['total'] === 0);
    }
    foreach ([['q'=>[]],['q'=>str_repeat('a',191)],['q'=>"bad\0text"],['q'=>"\xff"],['status'=>[]],['status'=>'closed'],
        ['page'=>[]],['page'=>'0'],['page'=>'-1'],['page'=>'1.5'],['page'=>'1000000000'],['page'=>'1 OR 1=1']] as $invalid) {
        try { portal_request_list_options($invalid); $refused = false; }
        catch (PortalDataValidationException) { $refused = true; }
        $check('malformed filters are rejected', $refused);
    }
}
