<?php
/** Dedicated Westy intake. Default is dry-run; only --run can write or send. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('CLI only.');
require_once __DIR__.'/../lib/westy_mail.php';
require_once __DIR__.'/../lib/westy_mail_graph.php';
require_once __DIR__.'/../lib/westy_mail_poll.php';

$run=in_array('--run',$argv,true); $poll=westy_mail_poll_config();
if (!$run || !westy_mail_enabled() || $poll===null) { echo '['.gmdate('c')." westy_mail_poll: dry-run or authority disabled]\n"; exit(0); }
$snapshot=westy_mail_graph_snapshot();
$g=$snapshot['graph']; $poll['mailbox']=$snapshot['sender']; $pdo=db();
if ((int)$pdo->query('SELECT westy_mail_poll_schema_health()')->fetchColumn()!==1) throw new RuntimeException('Mailbox safety controls are unavailable.');
if(!westy_mail_poll_lock($pdo,$poll['tenant_id'],$snapshot['identity_sha256'])) { echo '['.gmdate('c')." westy_mail_poll: mailbox already running]\n";exit(0); }
try { $result=westy_mail_poll_run($pdo,$poll,$snapshot,'westy_mail_graph_get','westy_mail_accept_inbound','westy_mail_hold_inbound',static fn(PDO $p,int $c,int $r):string=>westy_mail_auto_reply($p,$c,$r,'westy_mail_graph_send'));$seen=$result['seen'];$held=$result['held'];
$reconciled=westy_mail_poll_reconcile($pdo,$g,'westy_mail_graph_get',$poll['tenant_id']);
echo '['.gmdate('c').' westy_mail_poll: processed '.$seen.', held '.json_encode($held,JSON_UNESCAPED_SLASHES).', uncertain sent matches '.count($reconciled)."]\n";
} catch(Throwable $e) { echo '['.gmdate('c')." westy_mail_poll: page failed; cursor retained]\n"; exit(1); } finally { westy_mail_poll_unlock($pdo,$poll['tenant_id'],$snapshot['identity_sha256']); }
