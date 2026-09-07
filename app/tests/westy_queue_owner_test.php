<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/westy_workflow.php';
$checks = 0;
function owner_check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
class OwnerTestPDO extends PDO {
    public int $prepares = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false { $this->prepares++; return parent::prepare($query, $options); }
}
$pdo = new OwnerTestPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$base = ['id'=>1,'tenant_id'=>1,'client_id'=>11,'status'=>'in_progress','assignee_id'=>null,'westy_owned'=>true,
    'subject'=>'Synthetic alert','priority'=>'normal','channel'=>'alert','client_name'=>'Fixture client','created_at'=>'2026-09-07 00:00:00'];
owner_check(westy_workflow_ticket_owners($pdo,1,[$base])[0]['westy_owned']===false,'pre-migration list remains usable and clears untrusted presentation flags');
$pdo->exec("CREATE TABLE tickets(id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,status TEXT,assignee_id INTEGER);
CREATE TABLE westy_workflows(ticket_id INTEGER,tenant_id INTEGER,client_id INTEGER,state TEXT);
INSERT INTO tickets VALUES(1,1,11,'in_progress',NULL),(2,1,11,'waiting',NULL),(3,1,11,'in_progress',8),(4,2,22,'in_progress',NULL),(5,1,11,'in_progress',NULL),(6,1,11,'in_progress',NULL),(7,1,11,'in_progress',NULL);
INSERT INTO westy_workflows VALUES(1,1,11,'working'),(2,1,11,'working'),(3,1,11,'working'),(4,2,22,'working'),(5,1,11,'resolved'),(6,1,12,'working'),(7,1,11,'human_owned');");
$rows = array_map(static fn($row)=>array_replace($base,$row),$pdo->query('SELECT * FROM tickets ORDER BY id')->fetchAll());
$pdo->prepares = 0;
$decorated = westy_workflow_ticket_owners($pdo,1,$rows);
owner_check(array_column($decorated,'westy_owned')===[true,false,false,false,false,false,false],'only the current matching tenant/client, active unassigned ticket and working workflow show Westy');
owner_check($pdo->prepares===1,'queue enriches all eligible rows with one bounded read');
owner_check(count($decorated)===count($rows),'all existing queue rows and order remain');
owner_check(westy_workflow_ticket_owners($pdo,2,[$rows[3]])[0]['westy_owned']===true,'second tenant independently sees its own service owner');
$changed = array_replace($base,['client_id'=>12]);
owner_check(westy_workflow_ticket_owners($pdo,1,[$changed])[0]['westy_owned']===false,'stale or mismatched client identity is not decorated');
$pdo->exec('UPDATE tickets SET assignee_id=8 WHERE id=1');
owner_check(westy_workflow_ticket_owners($pdo,1,[$base])[0]['westy_owned']===false,'human takeover in the database overrides an earlier queue snapshot');
$pdo->exec('UPDATE tickets SET assignee_id=NULL WHERE id=1');
$large = [];
for ($id=100; $id<601; $id++) $large[] = array_replace($base,['id'=>$id]);
$pdo->prepares=0;
owner_check(count(westy_workflow_ticket_owners($pdo,1,$large))===501 && $pdo->prepares===2,'large queue uses batches of at most 500 rather than per-ticket reads');
$pdo->prepares=0;
owner_check(westy_workflow_ticket_owners($pdo,1,[])===[] && $pdo->prepares===0,'empty list makes no query');
$broken = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$broken->exec('CREATE TABLE tickets(id INTEGER); CREATE TABLE westy_workflows(ticket_id INTEGER)');
$refused=false;
try { westy_workflow_ticket_owners($broken,1,[$base]); } catch (PDOException $e) { $refused=true; }
owner_check($refused,'unexpected schema failures are not mistaken for a missing migration');

// Load the real renderer with isolated dependency edges; no application config is loaded.
$temp=sys_get_temp_dir().'/safeharbor-owner-render-'.bin2hex(random_bytes(8));
mkdir($temp,0700);
try {
    copy(__DIR__.'/../lib/render.php',$temp.'/render.php');
    file_put_contents($temp.'/auth.php','<?php function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,"UTF-8"); } function sla_info($t): array { return ["state"=>"on_track","label"=>"On track"]; } function rel_time($v): string { return "Just now"; }');
    file_put_contents($temp.'/westy.php','<?php');
    require $temp.'/render.php';
    $owned=$decorated[0];
    $html=ticket_row($owned);
    owner_check(str_contains($html,'aria-label="Assigned to Westy"') && !str_contains($html,'title="Unassigned"'),'actual queue row names the service owner accessibly');
    owner_check(str_contains(ticket_owner_avatar($owned,22),'width:22px') && str_contains(ticket_owner_avatar($owned,22),'title="Westy"'),'ticket detail shares the same owner avatar');
    $human=array_replace($base,['id'=>3,'assignee_id'=>8,'assignee_name'=>'Fixture <technician>','assignee_initials'=>'FT','assignee_color'=>'var(--gold)']);
    owner_check(str_contains(ticket_owner_avatar($human),'Fixture &lt;technician&gt;') && !str_contains(ticket_owner_avatar($human),'Assigned to Westy'),'human owner wins over stale Westy flag and stays escaped');
    $unassigned=array_replace($base,['id'=>2,'westy_owned'=>false]);
    owner_check(str_contains(ticket_owner_avatar($unassigned),'title="Unassigned"'),'genuinely unassigned ticket remains distinct');
    owner_check(str_contains(ticket_owner_avatar(array_replace($owned,['status'=>'resolved'])),'Unassigned'),'closed ticket does not display an active service owner');
    if (($argv[1]??'')==='--render') echo '<div class="card list">'.$html.ticket_row($human).ticket_row($unassigned).'</div>';
    else echo "Westy queue owner: $checks passed.\n";
} finally {
    $actual=realpath($temp); $parent=realpath(sys_get_temp_dir());
    if (!$actual || !$parent || !str_starts_with($actual,$parent.DIRECTORY_SEPARATOR) || !str_starts_with(basename($actual),'safeharbor-owner-render-')) throw new RuntimeException('Fixture cleanup scope changed');
    foreach (['render.php','auth.php','westy.php'] as $file) unlink($actual.'/'.$file);
    rmdir($actual);
}
