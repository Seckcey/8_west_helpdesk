<?php
/** Private customer conversation, bounded provider calls and durable handoff. */
declare(strict_types=1);
require_once __DIR__ . '/portal_data.php';
require_once __DIR__ . '/portal_westy_provider.php';

final class PortalWestyException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 409)
    { parent::__construct($reason); }
}

function portal_westy_config(): array
{
    $read = static fn(string $key, mixed $default): mixed => function_exists('cfg') ? cfg('portal_westy.'.$key,$default) : $default;
    $c = ['enabled'=>$read('enabled',false), 'ai_enabled'=>$read('ai_enabled',false),
        'api_key'=>$read('api_key',''),
        'retention_days'=>$read('retention_days',30), 'hourly_limit'=>$read('hourly_limit',30),
        'daily_limit'=>$read('daily_limit',500), 'monthly_microusd'=>$read('monthly_microusd',5000000)];
    if (!is_bool($c['enabled']) || !is_bool($c['ai_enabled']) || !is_string($c['api_key'])) throw new PortalWestyException('configuration',503);
    foreach (['retention_days'=>90,'hourly_limit'=>100,'daily_limit'=>2000,'monthly_microusd'=>20000000] as $key=>$max) {
        if (!is_int($c[$key]) || $c[$key]<1 || $c[$key]>$max) throw new PortalWestyException('configuration',503);
    }
    return $c;
}

function portal_westy_scope(PDO $pdo, array $context, bool $lock = false): array
{
    $i = $context['identity'] ?? [];
    if (!in_array($i['role'] ?? '',PORTAL_CLIENT_ROLES,true) || !is_string($i['subject'] ?? null)
        || preg_match('/^t[1-9][0-9]*u[1-9][0-9]*$/D',$i['subject'])!==1) throw new PortalWestyException('sign_in',401);
    $ids = [];
    foreach (['tenant_id','client_id','binding_id'] as $name) {
        if (!is_int($i[$name] ?? null) || $i[$name]<1) throw new PortalWestyException('sign_in',401);
        $ids[]=$i[$name];
    }
    if ($lock) {
        // Serialize with managed-customer suspension before locking portal state.
        if (!managed_customer_operational($pdo,$i['tenant_id'],$i['client_id'],true)) throw new PortalWestyException('sign_in',401);
        $q=$pdo->prepare('SELECT id FROM customer_portal_bindings WHERE tenant_id=? AND client_id=? AND id=?'.portal_westy_lock($pdo));
        $q->execute($ids);
        if (!$q->fetchColumn()) throw new PortalWestyException('sign_in',401);
    }
    if (portal_active_binding_recheck($pdo,$i['binding_id'],(string)($i['identity_tenant_slug'] ?? ''),$i['tenant_id'],$i['client_id'])===null) throw new PortalWestyException('sign_in',401);
    return ['key'=>hash('sha256',json_encode(['safeharbor-portal-v1',...$ids,$i['subject']],JSON_THROW_ON_ERROR)),
        'tenant'=>$i['tenant_id'],'client'=>$i['client_id'],'binding'=>$i['binding_id'],'role'=>$i['role'],'name'=>$i['display_name']];
}

function portal_westy_lock(PDO $pdo): string
{ return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' FOR UPDATE' : ''; }

function portal_westy_key(mixed $key): string
{
    if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D',$key)!==1) throw new PortalWestyException('invalid_request',400);
    return $key;
}

function portal_westy_account(PDO $pdo, array $s, bool $create = false): ?array
{
    $created=false;
    if ($create) {
        $insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $q=$pdo->prepare($insert.' INTO portal_westy_accounts(scope_key,tenant_id,client_id,binding_id,conversation_key,created_at) VALUES(?,?,?,?,?,?)');
        $q->execute([$s['key'],$s['tenant'],$s['client'],$s['binding'],bin2hex(random_bytes(16)),gmdate('Y-m-d H:i:s')]);
        $created=$q->rowCount()===1;
    }
    $q=$pdo->prepare('SELECT * FROM portal_westy_accounts WHERE scope_key=? AND tenant_id=? AND client_id=? AND binding_id=?'.($create ? portal_westy_lock($pdo) : ''));
    $q->execute([$s['key'],$s['tenant'],$s['client'],$s['binding']]);
    $row=$q->fetch();
    return $row ? [...$row,'_created'=>$created] : null;
}

function portal_westy_state(PDO $pdo, array $context): array
{
    $c=portal_westy_config(); $s=portal_westy_scope($pdo,$context);
    // Access follows the freshly checked customer binding, including future signups.
    $enabled=$c['enabled'];
    $state=['enabled'=>$enabled,'ai_available'=>$enabled && $c['ai_enabled'] && trim($c['api_key'])!=='',
        'retention_days'=>$c['retention_days'],'can_write'=>portal_role_can_write_tickets($s['role']),
        'conversation'=>null,'turns'=>[],'draft'=>null];
    // Kill switch does not rely on the new schema; the old portal keeps working.
    if (!$enabled) return $state;
    $account=portal_westy_account($pdo,$s);
    if (!$account) return $state;
    $state['conversation']=$account['conversation_key'];
    $q=$pdo->prepare('SELECT operation_key,state,input_text,reply_json,reason_code,created_at FROM portal_westy_turns WHERE scope_key=? AND conversation_key=? AND expires_at>? AND input_text IS NOT NULL ORDER BY id DESC LIMIT 50');
    $q->execute([$s['key'],$account['conversation_key'],gmdate('Y-m-d H:i:s')]);
    foreach (array_reverse($q->fetchAll()) as $row) {
        if ($row['state']==='pending' && strtotime($row['created_at'].' UTC')<time()-30) { $row['state']='unavailable';$row['reason_code']='interrupted'; }
        $row['reply']=$row['reply_json']===null ? null : json_decode($row['reply_json'],true);
        unset($row['reply_json']); $state['turns'][]=$row;
    }
    $q=$pdo->prepare("SELECT draft_key,revision,state,subject,body,priority,ticket_id,expires_at FROM portal_westy_drafts WHERE scope_key=? AND conversation_key=? AND (state='sent' OR (state='draft' AND expires_at>?)) ORDER BY id DESC LIMIT 1");
    $q->execute([$s['key'],$account['conversation_key'],gmdate('Y-m-d H:i:s')]);
    $state['draft']=$q->fetch() ?: null;
    if ($state['draft'] && $state['draft']['state']==='sent') $state['draft']['ticket_url']=portal_westy_receipt_url($pdo,$s,(int)$state['draft']['ticket_id']);
    return $state;
}

function portal_westy_receipt_url(PDO $pdo,array $s,int $ticket): ?string
{
    try { portal_ticket_detail($pdo,$s['tenant'],$s['client'],$ticket); return '/portal/ticket.php?id='.$ticket; }
    catch (PortalDataNotFoundException) { return null; }
}

/** Recover a particular committed handoff even if another tab started a chat. */
function portal_westy_receipt(PDO $pdo,array $context,mixed $key): ?array
{
    $key=portal_westy_key($key);$s=portal_westy_scope($pdo,$context);
    $q=$pdo->prepare("SELECT draft_key,revision,state,ticket_id FROM portal_westy_drafts WHERE draft_key=? AND scope_key=? AND state='sent'");
    $q->execute([$key,$s['key']]);$receipt=$q->fetch();
    if(!$receipt)return null;
    $receipt['ticket_url']=portal_westy_receipt_url($pdo,$s,(int)$receipt['ticket_id']);
    return $receipt;
}

function portal_westy_require_conversation(?array $account,mixed $expected): void
{
    if ($expected===null && !($account['_created']??false)) throw new PortalWestyException('conversation_changed');
    if ($expected !== null && (!is_string($expected) || !hash_equals((string)($account['conversation_key'] ?? ''),$expected))) throw new PortalWestyException('conversation_changed');
}

/** Reserves once before network I/O. Pending/ambiguous attempts are never retried. */
function portal_westy_message(PDO $pdo,array $context,array $request,?callable $provider=null,?callable $reauthorize=null): void
{
    $c=portal_westy_config(); $s=portal_westy_scope($pdo,$context);
    if (!$c['enabled'] || !$c['ai_enabled'] || trim($c['api_key'])==='') throw new PortalWestyException('ai_unavailable',503);
    $key=portal_westy_key($request['operation'] ?? null);
    $text=$request['message'] ?? null;
    if (!is_string($text) || !mb_check_encoding($text,'UTF-8') || mb_strlen(trim($text))<1 || mb_strlen($text)>2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$text)) throw new PortalWestyException('invalid_message',400);
    // Obvious credentials are refused before either storage or provider transmission.
    if (preg_match('/(?:\bsk-[a-zA-Z0-9_-]{16,}|-----BEGIN [A-Z ]*PRIVATE KEY-----|\b(?:password|api[_ -]?key|verification code|one[- ]time code)\s*(?:is|:|=)\s*\S+)/i',$text)) throw new PortalWestyException('sensitive_text',400);
    $pdo->beginTransaction();
    try {
        $s=portal_westy_scope($pdo,$context,true); $account=portal_westy_account($pdo,$s,true);
        $q=$pdo->prepare('SELECT id FROM portal_westy_turns WHERE scope_key=? AND operation_key=?');$q->execute([$s['key'],$key]);
        if ($q->fetchColumn()) { $pdo->commit(); return; }
        portal_westy_require_conversation($account,$request['conversation'] ?? null);
        $q=$pdo->prepare("SELECT COUNT(*) FROM portal_westy_turns WHERE scope_key=? AND state='pending' AND created_at>?");
        $q->execute([$s['key'],gmdate('Y-m-d H:i:s',time()-30)]);
        if ((int)$q->fetchColumn()>0) throw new PortalWestyException('busy',429);
        $q=$pdo->prepare('SELECT COUNT(*) FROM portal_westy_turns WHERE scope_key=? AND created_at>?');$q->execute([$s['key'],gmdate('Y-m-d H:i:s',time()-3600)]);
        if ((int)$q->fetchColumn()>=$c['hourly_limit']) throw new PortalWestyException('hourly_limit',429);
        $q=$pdo->prepare('SELECT input_text,reply_json FROM portal_westy_turns WHERE scope_key=? AND conversation_key=? AND expires_at>? AND input_text IS NOT NULL ORDER BY id DESC LIMIT 6');
        $q->execute([$s['key'],$account['conversation_key'],gmdate('Y-m-d H:i:s')]);
        $messages=[];
        foreach (array_reverse($q->fetchAll()) as $turn) {
            $messages[]=['role'=>'user','content'=>$turn['input_text']];
            $reply=json_decode((string)$turn['reply_json'],true);
            if (is_array($reply) && is_string($reply['reply'] ?? null)) $messages[]=['role'=>'assistant','content'=>$reply['reply']];
        }
        $messages[]=['role'=>'user','content'=>trim($text)];
        $body=portal_westy_provider_body($messages);
        while (strlen(json_encode($body,JSON_THROW_ON_ERROR))>28000 && count($messages)>1) {
            array_shift($messages); $body=portal_westy_provider_body($messages);
        }
        $bytes=strlen(json_encode($body,JSON_THROW_ON_ERROR));
        if ($bytes>32000) throw new PortalWestyException('context_limit',400);
        // Count every input byte as a token plus framing overhead, at cache-write
        // rate, and all 1200 possible output/reasoning tokens. No cache discount.
        $reserve=(int)ceil(($bytes+2048)*0.125+1200*0.5);
        $month=gmdate('Y-m');
        $insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $q=$pdo->prepare($insert.' INTO portal_westy_budgets(tenant_id,client_id,month_key,charged_microusd) VALUES(?,?,?,0)');$q->execute([$s['tenant'],$s['client'],$month]);
        $q=$pdo->prepare('SELECT charged_microusd FROM portal_westy_budgets WHERE tenant_id=? AND client_id=? AND month_key=?'.portal_westy_lock($pdo));$q->execute([$s['tenant'],$s['client'],$month]);
        if ((int)$q->fetchColumn()+$reserve>$c['monthly_microusd']) throw new PortalWestyException('cost_limit',429);
        $q=$pdo->prepare('SELECT COUNT(*) FROM portal_westy_turns WHERE tenant_id=? AND client_id=? AND created_at>=?');$q->execute([$s['tenant'],$s['client'],gmdate('Y-m-d 00:00:00')]);
        if ((int)$q->fetchColumn()>=$c['daily_limit']) throw new PortalWestyException('daily_limit',429);
        $q=$pdo->prepare('UPDATE portal_westy_budgets SET charged_microusd=charged_microusd+? WHERE tenant_id=? AND client_id=? AND month_key=?');$q->execute([$reserve,$s['tenant'],$s['client'],$month]);
        $q=$pdo->prepare("INSERT INTO portal_westy_turns(tenant_id,client_id,scope_key,conversation_key,operation_key,state,input_text,model_name,reserve_microusd,charged_microusd,created_at,expires_at) VALUES(?,?,?,?,?,'pending',?,'gpt-6-luna',?,?,?,?)");
        $q->execute([$s['tenant'],$s['client'],$s['key'],$account['conversation_key'],$key,trim($text),$reserve,$reserve,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+$c['retention_days']*86400)]);
        $turnId=(int)$pdo->lastInsertId(); $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    try { $result=$provider ? $provider($body,$c['api_key']) : portal_westy_provider($body,$c['api_key']); }
    catch(Throwable) { $result=['ok'=>false,'reason'=>'provider_unavailable']; }
    // Verify identity again after the slow call. Logout, revocation or a changed
    // binding must not deliver a previously authorized response.
    if ($reauthorize) {
        $fresh=$reauthorize();
        if (!is_array($fresh) || portal_westy_scope($pdo,$fresh)['key']!==$s['key']) throw new PortalWestyException('sign_in',401);
    }
    $pdo->beginTransaction();
    try {
        portal_westy_scope($pdo,$context,true); $current=portal_westy_account($pdo,$s,true);
        $available=($result['ok'] ?? false)===true && $current['conversation_key']===$account['conversation_key'];
        $input=$result['input_tokens'] ?? null; $output=$result['output_tokens'] ?? null;
        $charge=$reserve;
        if (is_int($input) && is_int($output) && $input>=0 && $output>=0 && $input<=$bytes+2048 && $output<=1200) $charge=min($reserve,(int)ceil($input*0.125+$output*0.5));
        $q=$pdo->prepare("UPDATE portal_westy_turns SET state=?,reply_json=?,reason_code=?,charged_microusd=?,input_tokens=?,output_tokens=?,finished_at=? WHERE id=? AND scope_key=? AND state='pending'");
        $q->execute([$available?'complete':'unavailable',$available?json_encode($result['data'],JSON_THROW_ON_ERROR):null,$available?'':(string)($result['reason'] ?? 'conversation_changed'),$charge,is_int($input)?$input:null,is_int($output)?$output:null,gmdate('Y-m-d H:i:s'),$turnId,$s['key']]);
        if ($q->rowCount()!==1) throw new PortalWestyException('interrupted');
        $q=$pdo->prepare('UPDATE portal_westy_budgets SET charged_microusd=charged_microusd-? WHERE tenant_id=? AND client_id=? AND month_key=?');$q->execute([$reserve-$charge,$s['tenant'],$s['client'],$month]);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}

/** User-edited draft saved before the separate, explicit audience review. */
function portal_westy_save_draft(PDO $pdo,array $context,array $request): void
{
    $c=portal_westy_config();$s=portal_westy_scope($pdo,$context);
    if (!$c['enabled']) throw new PortalWestyException('ai_unavailable',503);
    if (!portal_role_can_write_tickets($s['role'])) throw new PortalWestyException('read_only',403);
    $key=portal_westy_key($request['draft_key'] ?? null);
    $subject=portal_ticket_subject($request['subject'] ?? null);$body=portal_ticket_body($request['body'] ?? null);$priority=portal_ticket_priority($request['priority'] ?? null);
    $revision=$request['revision'] ?? null;
    if (!is_int($revision) || $revision<0) throw new PortalWestyException('invalid_request',400);
    $pdo->beginTransaction();
    try {
        $s=portal_westy_scope($pdo,$context,true);$account=portal_westy_account($pdo,$s,true);
        portal_westy_require_conversation($account,$request['conversation'] ?? null);
        $q=$pdo->prepare('SELECT * FROM portal_westy_drafts WHERE draft_key=? AND scope_key=?'.portal_westy_lock($pdo));$q->execute([$key,$s['key']]);$draft=$q->fetch();
        if ($draft) {
            if ($draft['state']!=='draft' || $draft['expires_at']<=gmdate('Y-m-d H:i:s') || $draft['conversation_key']!==$account['conversation_key']) throw new PortalWestyException('draft_changed');
            if ($subject===$draft['subject'] && $body===$draft['body'] && $priority===$draft['priority']) { $pdo->commit();return; }
            if ((int)$draft['revision']!==$revision) throw new PortalWestyException('draft_changed');
            $q=$pdo->prepare('UPDATE portal_westy_drafts SET subject=?,body=?,priority=?,revision=revision+1 WHERE draft_key=? AND scope_key=?');$q->execute([$subject,$body,$priority,$key,$s['key']]);
        } else {
            if($revision!==0)throw new PortalWestyException('draft_changed');
            $q=$pdo->prepare('SELECT COUNT(*) FROM portal_westy_drafts WHERE scope_key=? AND created_at>?');$q->execute([$s['key'],gmdate('Y-m-d H:i:s',time()-86400)]);
            if ((int)$q->fetchColumn()>=30)throw new PortalWestyException('draft_limit',429);
            $q=$pdo->prepare('INSERT INTO portal_westy_drafts(draft_key,tenant_id,client_id,scope_key,conversation_key,subject,body,priority,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$key,$s['tenant'],$s['client'],$s['key'],$account['conversation_key'],$subject,$body,$priority,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+$c['retention_days']*86400)]);
        }
        $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Same customer writer, one transaction for the ticket AND permanent receipt. */
function portal_westy_handoff(PDO $pdo,array $context,array $request): int
{
    $s=portal_westy_scope($pdo,$context);
    if(!portal_role_can_write_tickets($s['role']))throw new PortalWestyException('read_only',403);
    $key=portal_westy_key($request['draft_key'] ?? null);
    if(($request['reviewed'] ?? false)!==true || !is_int($request['revision'] ?? null))throw new PortalWestyException('review_required',400);
    $pdo->beginTransaction();
    try {
        $s=portal_westy_scope($pdo,$context,true);$account=portal_westy_account($pdo,$s,true);
        $q=$pdo->prepare('SELECT * FROM portal_westy_drafts WHERE draft_key=? AND scope_key=?'.portal_westy_lock($pdo));$q->execute([$key,$s['key']]);$draft=$q->fetch();
        if(!$draft)throw new PortalWestyException('draft_changed');
        if($draft['state']==='sent'){ $pdo->commit();return (int)$draft['ticket_id']; }
        if(!portal_westy_config()['enabled'])throw new PortalWestyException('ai_unavailable',503);
        if($draft['state']!=='draft' || (int)$draft['revision']!==$request['revision'] || $draft['expires_at']<=gmdate('Y-m-d H:i:s') || $draft['conversation_key']!==$account['conversation_key'])throw new PortalWestyException('draft_changed');
        $ticket=portal_create_ticket($pdo,$s['tenant'],$s['client'],$s['role'],$s['name'],$draft['subject'],$draft['priority'],$draft['body']);
        $q=$pdo->prepare("UPDATE portal_westy_drafts SET state='sent',ticket_id=?,sent_at=?,subject=NULL,body=NULL WHERE draft_key=? AND scope_key=? AND state='draft'");$q->execute([$ticket,gmdate('Y-m-d H:i:s'),$key,$s['key']]);
        if($q->rowCount()!==1)throw new PortalWestyException('draft_changed');
        $pdo->commit();return $ticket;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function portal_westy_new_chat(PDO $pdo,array $context,array $request): void
{
    $s=portal_westy_scope($pdo,$context);
    if(!portal_westy_config()['enabled'])throw new PortalWestyException('ai_unavailable',503);
    $next=portal_westy_key($request['next_conversation'] ?? null);
    $pdo->beginTransaction();
    try {
        $s=portal_westy_scope($pdo,$context,true);$account=portal_westy_account($pdo,$s,true);
        if($account['conversation_key']===$next){$pdo->commit();return;}
        if(!is_string($request['conversation'] ?? null))throw new PortalWestyException('conversation_changed');
        portal_westy_require_conversation($account,$request['conversation']);
        $q=$pdo->prepare('UPDATE portal_westy_accounts SET conversation_key=? WHERE scope_key=?');$q->execute([$next,$s['key']]);$pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
