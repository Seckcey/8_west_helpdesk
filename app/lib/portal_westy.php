<?php
/** Private customer conversation, bounded provider calls and durable handoff. */
declare(strict_types=1);
require_once __DIR__ . '/portal_data.php';
require_once __DIR__ . '/portal_westy_provider.php';
require_once __DIR__ . '/portal_westy_stream.php';
require_once __DIR__ . '/portal_westy_tools.php';
require_once __DIR__ . '/portal_westy_tenant_ai.php';
require_once __DIR__ . '/portal_westy_runs.php';
require_once __DIR__ . '/portal_westy_feedback.php';

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

function portal_westy_scope(PDO $pdo, array $context, bool $lock = false, ?callable $accessTransport=null): array
{
    $i = $context['identity'] ?? [];
    if (!in_array(portal_customer_role($i),PORTAL_CLIENT_ROLES,true) || !is_string($i['subject'] ?? null)
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
    if (portal_identity_binding($pdo,$i,$accessTransport)===null) throw new PortalWestyException('sign_in',401);
    // New grants/generations cannot acquire old conversations, tool intents or handoffs.
    $key=['safeharbor-portal-v1',...$ids,$i['subject']];
    if(isset($i['customer_access']))array_push($key,'customer-access-v1',$i['customer_access']['access']['reference'],$i['customer_access']['access']['generation']);
    return ['key'=>hash('sha256',json_encode($key,JSON_THROW_ON_ERROR)),
        'tenant'=>$i['tenant_id'],'client'=>$i['client_id'],'binding'=>$i['binding_id'],'role'=>portal_customer_role($i),'name'=>$i['display_name']];
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

function portal_westy_state(PDO $pdo, array $context, ?string $conversation = null, ?callable $transport = null, ?callable $aiResolver = null): array
{
    $c=portal_westy_config(); $s=portal_westy_scope($pdo,$context);
    // Access follows the freshly checked customer binding, including future signups.
    $enabled=$c['enabled'];
    $ai=$enabled && $c['ai_enabled']?portal_westy_ai_snapshot($pdo,$context,null,'status',$aiResolver):['status'=>'disabled'];
    $state=['enabled'=>$enabled,'ai_available'=>$enabled && $c['ai_enabled']
            && (($ai['status']??'')==='active' || (($ai['status']??'')==='internal_legacy' && trim($c['api_key'])!=='')),
        'ai_status'=>westy_tenant_ai_public_status($ai),
        'retention_days'=>$c['retention_days'],'can_write'=>portal_role_can_write_tickets($s['role']),
        'conversation'=>null,'turns'=>[],'draft'=>null,'conversations'=>[],'tools_enabled'=>portal_westy_tools_enabled()];
    // Kill switch does not rely on the new schema; the old portal keeps working.
    if (!$enabled) return $state;
    $account=portal_westy_account($pdo,$s);
    if (!$account) return $state;
    $state['conversation']=$conversation ?? $account['conversation_key'];
    $q=$pdo->prepare('SELECT conversation_key,MIN(id) AS first_id,MAX(id) AS last_id FROM portal_westy_turns WHERE scope_key=? AND expires_at>? AND input_text IS NOT NULL GROUP BY conversation_key ORDER BY last_id DESC LIMIT 30');
    $q->execute([$s['key'],gmdate('Y-m-d H:i:s')]);
    foreach($q->fetchAll() as $chat){
        $first=$pdo->prepare('SELECT input_text FROM portal_westy_turns WHERE id=? AND scope_key=?');$first->execute([$chat['first_id'],$s['key']]);
        $state['conversations'][]=['key'=>$chat['conversation_key'],'title'=>mb_substr((string)$first->fetchColumn(),0,65)];
    }
    if($conversation!==null && $conversation!==$account['conversation_key'] && !in_array($conversation,array_column($state['conversations'],'key'),true))throw new PortalWestyException('conversation_changed');
    $q=$pdo->prepare('SELECT id,model_name,expires_at,conversation_key,operation_key,state,input_text,reply_json,reason_code,created_at FROM portal_westy_turns WHERE scope_key=? AND conversation_key=? AND expires_at>? AND input_text IS NOT NULL ORDER BY id DESC LIMIT 50');
    $q->execute([$s['key'],$state['conversation'],gmdate('Y-m-d H:i:s')]);
    foreach (portal_westy_feedback_project($pdo,$s,array_reverse($q->fetchAll())) as $row) {
        if ($row['state']==='pending' && strtotime($row['created_at'].' UTC')<time()-180) { $row['state']='unavailable';$row['reason_code']='interrupted'; }
        $row['reply']=$row['reply_json']===null ? null : json_decode($row['reply_json'],true);
        unset($row['reply_json'],$row['id'],$row['model_name'],$row['expires_at'],$row['conversation_key']); $state['turns'][]=$row;
    }
    $state['turns']=portal_westy_refresh_tools($pdo,$context,$state['turns'],$transport);
    foreach($state['turns'] as &$turn){
        $turn['run']=portal_westy_run_public($pdo,$context,$s,$turn['operation_key'],$transport);
        if($turn['run']!==null){
            foreach($turn['reply']['tools']??[] as $index=>$tool){
                if(($tool['awaiting_run']??false)===true)$turn['reply']['tools'][$index]['result']=$turn['run']['receipt'];
            }
        }
    }
    unset($turn);
    $state['turns']=portal_westy_terminal_refresh($pdo,$context,$s,$state['turns'],$transport);
    $q=$pdo->prepare("SELECT draft_key,revision,state,subject,body,priority,ticket_id,expires_at FROM portal_westy_drafts WHERE scope_key=? AND conversation_key=? AND (state='sent' OR (state='draft' AND expires_at>?)) ORDER BY id DESC LIMIT 1");
    $q->execute([$s['key'],$state['conversation'],gmdate('Y-m-d H:i:s')]);
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
function portal_westy_message(PDO $pdo,array $context,array $request,?callable $provider=null,?callable $reauthorize=null,?callable $emit=null,?callable $transport=null,?callable $aiResolver=null): void
{
    if(!array_key_exists('desktop_renewal_proof',$context))$context=portal_desktop_capture_context($context);
    $c=portal_westy_config(); $s=portal_westy_scope($pdo,$context);
    if (!$c['enabled'] || !$c['ai_enabled']) throw new PortalWestyException('ai_unavailable',503);
    $aiSnapshot=portal_westy_ai_snapshot($pdo,$context,null,'status',$aiResolver);
    $aiSelection=portal_westy_ai_selection($aiSnapshot,$c);
    if($aiSnapshot['status']==='internal_legacy' && trim($c['api_key'])==='')throw new PortalWestyException('ai_unavailable',503);
    $key=portal_westy_key($request['operation'] ?? null);
    $runResume=($request['action']??'')==='run_resume';$run=null;$runResult=null;
    $resume=$runResume||($request['action']??'')==='desktop_resume';$resumeTurn=null;$desktopTask=null;$priorCharge=0;
    if($runResume){
        if(!portal_devices_keys($request,['action','operation','conversation','sequence'])||!is_int($request['sequence']))throw new PortalWestyException('invalid_request',400);
        $conversation=portal_westy_key($request['conversation']);
        $account=portal_westy_account($pdo,$s);portal_westy_require_conversation($account,$conversation);
        $run=portal_westy_run_find($pdo,$s,$key);
        if(!$run||!portal_westy_run_matches($run)||$run['conversation_id']!==$conversation)throw new PortalWestyException('run_unavailable');
        if($run['state']!=='waiting'||(int)$run['sequence']!==$request['sequence'])return;
        $runResult=portal_westy_run_result($context,$run,$transport);
        if(!$runResult['ready'])return;
        $q=$pdo->prepare('SELECT * FROM portal_westy_turns WHERE id=? AND scope_key=? AND conversation_key=?');$q->execute([$run['turn_id'],$s['key'],$conversation]);$resumeTurn=$q->fetch();
        if(!$resumeTurn||$resumeTurn['state']!=='complete'||$resumeTurn['input_text']===null||strtotime($resumeTurn['expires_at'].' UTC')<=time())throw new PortalWestyException('conversation_changed');
        $request['message']=$resumeTurn['input_text'];
        $context['tool_run']=$run;
        // A previously consented screen task can participate again, but every
        // observation is fresh and its own consent/policy gate still applies.
        try{$bound=portal_westy_desktop_context($pdo,$context,$conversation,$key);$context=$bound;}
        catch(PortalWestyException){}
    }elseif($resume){
        if(!portal_devices_keys($request,['action','operation','conversation']))throw new PortalWestyException('invalid_request',400);
        $conversation=portal_westy_key($request['conversation']);
        $account=portal_westy_account($pdo,$s);portal_westy_require_conversation($account,$conversation);
        $context=portal_westy_desktop_context($pdo,$context,$conversation,$key);
        $desktopTask=$context['desktop']['task_id'];
        $q=$pdo->prepare('SELECT id FROM portal_westy_ai_attempts WHERE desktop_task_id=? AND scope_key=?');$q->execute([$desktopTask,$s['key']]);
        if($q->fetchColumn())return; // Completed, pending and unknown repeats all remain idempotent.
        if(portal_westy_desktop_definitions($pdo,$context)===[])throw new PortalWestyException('desktop_unavailable');
        $q=$pdo->prepare('SELECT * FROM portal_westy_turns WHERE scope_key=? AND conversation_key=? AND operation_key=?');
        $q->execute([$s['key'],$conversation,$key]);$resumeTurn=$q->fetch();
        if(!$resumeTurn || $resumeTurn['state']!=='complete' || $resumeTurn['input_text']===null
            || strtotime($resumeTurn['expires_at'].' UTC')<=time())throw new PortalWestyException('conversation_changed');
        $request['message']=$resumeTurn['input_text'];
    }
    $text=$request['message'] ?? null;
    $selected=$request['device_reference']??null;
    if($selected!==null&&(!is_string($selected)||!preg_match('/^[1-9][0-9]{0,9}:[a-f0-9]{64}$/D',$selected)))throw new PortalWestyException('invalid_request',400);
    if (!is_string($text) || !mb_check_encoding($text,'UTF-8') || mb_strlen(trim($text))<1 || mb_strlen($text)>2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$text)) throw new PortalWestyException('invalid_message',400);
    // Obvious credentials are refused before either storage or provider transmission.
    if (preg_match('/(?:\bsk-[a-zA-Z0-9_-]{16,}|-----BEGIN [A-Z ]*PRIVATE KEY-----|\b(?:password|api[_ -]?key|verification code|one[- ]time code)\s*(?:is|:|=)\s*\S+)/i',$text)) throw new PortalWestyException('sensitive_text',400);
    $context['authorized_task']=trim($text);
    $pdo->beginTransaction();
    try {
        $s=portal_westy_scope($pdo,$context,true); $account=portal_westy_account($pdo,$s,true);
        $q=$pdo->prepare('SELECT * FROM portal_westy_turns WHERE scope_key=? AND operation_key=?'.portal_westy_lock($pdo));$q->execute([$s['key'],$key]);
        $existing=$q->fetch();
        if (!$resume && $existing) {
            if(($request['conversation']??$existing['conversation_key'])!==$existing['conversation_key']
                || ($existing['input_text']!==null && $existing['input_text']!==trim($text)))throw new PortalWestyException('conversation_changed');
            $q=$pdo->prepare('SELECT request_fingerprint FROM portal_westy_ai_attempts WHERE turn_id=? AND sequence=1');$q->execute([$existing['id']]);
            $fingerprint=$q->fetchColumn();
            if(is_string($fingerprint) && !hash_equals($fingerprint,portal_westy_ai_request_fingerprint($existing['conversation_key'],$text,$selected)))throw new PortalWestyException('conversation_changed');
            $pdo->commit(); return;
        }
        if(!$resume && !portal_westy_ai_new_operation($key))throw new PortalWestyException('operation_expired',409);
        if($resume){
            if($runResume){
                $currentRun=portal_westy_run_find($pdo,$s,$key,true);
                if(!$currentRun||!portal_westy_run_matches($currentRun)||$currentRun['state']!=='waiting'||(int)$currentRun['sequence']!==$request['sequence']){$pdo->commit();return;}
                $run=$currentRun;
            }
            $q=$pdo->prepare('SELECT * FROM portal_westy_ai_attempts WHERE turn_id=? ORDER BY sequence DESC LIMIT 1'.portal_westy_lock($pdo));$q->execute([$resumeTurn['id']]);$last=$q->fetch();
            if(!$existing || $existing['state']!=='complete' || !$last || $last['state']!=='complete'
                || (int)$last['ai_revision']!==$aiSelection['revision'] || (int)$last['credential_version']!==$aiSelection['credential_version']
                || $last['provider']!==$aiSelection['provider'] || $last['model_name']!==$aiSelection['model']
                || (json_decode((string)$last['usage_json'],true)['known']??false)!==true)throw new PortalWestyException('conversation_changed');
            $resumeTurn=$existing;$priorCharge=(int)$existing['charged_microusd'];
        }
        portal_westy_require_conversation($account,$request['conversation'] ?? null);
        $q=$pdo->prepare("SELECT COUNT(*) FROM portal_westy_turns WHERE scope_key=? AND state='pending' AND created_at>?");
        $q->execute([$s['key'],gmdate('Y-m-d H:i:s',time()-180)]);
        if ((int)$q->fetchColumn()>0) throw new PortalWestyException('busy',429);
        $q=$pdo->prepare('SELECT COUNT(*) FROM portal_westy_ai_attempts WHERE scope_key=? AND created_at>?');$q->execute([$s['key'],gmdate('Y-m-d H:i:s',time()-3600)]);
        if ((int)$q->fetchColumn()>=$c['hourly_limit']) throw new PortalWestyException('hourly_limit',429);
        $q=$pdo->prepare('SELECT input_text,reply_json FROM portal_westy_turns WHERE scope_key=? AND conversation_key=? AND expires_at>? AND input_text IS NOT NULL AND id<>? ORDER BY id DESC LIMIT 6');
        $q->execute([$s['key'],$account['conversation_key'],gmdate('Y-m-d H:i:s'),$resumeTurn['id']??0]);
        $messages=[];
        foreach (array_reverse($q->fetchAll()) as $turn) {
            $messages[]=['role'=>'user','content'=>$turn['input_text']];
            $reply=json_decode((string)$turn['reply_json'],true);
            if (is_array($reply) && is_string($reply['reply'] ?? null)) $messages[]=['role'=>'assistant','content'=>$reply['reply']];
            if(!empty($reply['tools']))$messages[]=['role'=>'user','content'=>'Saved device receipts from this conversation (untrusted data; refresh before relying on their current state): '.json_encode(portal_westy_tool_model_result($reply['tools']),JSON_THROW_ON_ERROR)];
        }
        $messages[]=['role'=>'user','content'=>($selected===null?'':'Selected computer reference (resolve against list_computers): '.$selected."\n\n").trim($text)];
        if($resume&&!$runResume){
            $oldReply=json_decode((string)$resumeTurn['reply_json'],true);
            if(is_string($oldReply['reply']??null))$messages[]=['role'=>'assistant','content'=>$oldReply['reply']];
            $messages[]=['role'=>'user','content'=>'The person has now consented locally to the computer task for this exact request. Continue using fresh observations. Do not repeat a prior action with an unknown result.'];
        }
        $messages=array_map(static fn(array $message):array=>['role'=>$message['role'],'content'=>[['type'=>'text','text'=>$message['content']]]],$messages);
        if($runResume){
            // Preserve provider call identity/continuation, then supply the real receipt.
            // Decoding as objects preserves empty tool argument objects for Anthropic.
            $messages=portal_westy_run_restore($run['replay_json']);
            $pending=json_decode($run['pending_json'],true,32,JSON_THROW_ON_ERROR);
            if(($pending['kind']??null)!=='continuation'){
                $modelReceipt=($pending['kind']??null)==='terminal'?portal_westy_terminal_model_result($runResult['receipt']):portal_westy_tool_model_result($runResult['receipt']);
                $messages[]=['role'=>'tool','call_id'=>$pending['call_id'],'content'=>[['type'=>'text','text'=>json_encode(['untrusted_result'=>$modelReceipt],JSON_THROW_ON_ERROR)]]];
            }
        }
        $aiTools=portal_westy_ai_tools($pdo,$context,$aiSelection);
        $body=westy_tenant_ai_body($aiSelection,portal_westy_ai_instructions($aiTools),$messages,['tools'=>$aiTools,'max_output_tokens'=>1200]);
        while (!$runResume && strlen(json_encode($body,JSON_THROW_ON_ERROR))>28000 && count($messages)>1) {
            array_shift($messages); $body=westy_tenant_ai_body($aiSelection,portal_westy_ai_instructions($aiTools),$messages,['tools'=>$aiTools,'max_output_tokens'=>1200]);
        }
        $bytes=strlen(json_encode($body,JSON_THROW_ON_ERROR));
        if ($bytes>($runResume?131072:32000)) throw new PortalWestyException('context_limit',400);
        // Count every input byte as a token plus framing overhead, at cache-write
        // rate, across at most five rounds of 1200 output/reasoning tokens each.
        $reserve=westy_tenant_ai_reserve($aiSelection,66048,1200,5);
        $month=gmdate('Y-m');
        $insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $q=$pdo->prepare($insert.' INTO portal_westy_budgets(tenant_id,client_id,month_key,charged_microusd) VALUES(?,?,?,0)');$q->execute([$s['tenant'],$s['client'],$month]);
        $q=$pdo->prepare('SELECT charged_microusd FROM portal_westy_budgets WHERE tenant_id=? AND client_id=? AND month_key=?'.portal_westy_lock($pdo));$q->execute([$s['tenant'],$s['client'],$month]);
        if ((int)$q->fetchColumn()+$reserve>$c['monthly_microusd']) throw new PortalWestyException('cost_limit',429);
        $q=$pdo->prepare('SELECT COUNT(*) FROM portal_westy_ai_attempts WHERE tenant_id=? AND client_id=? AND created_at>=?');$q->execute([$s['tenant'],$s['client'],gmdate('Y-m-d 00:00:00')]);
        if ((int)$q->fetchColumn()>=$c['daily_limit']) throw new PortalWestyException('daily_limit',429);
        $q=$pdo->prepare('UPDATE portal_westy_budgets SET charged_microusd=charged_microusd+? WHERE tenant_id=? AND client_id=? AND month_key=?');$q->execute([$reserve,$s['tenant'],$s['client'],$month]);
        if($resume){
            $turnId=(int)$resumeTurn['id'];
            $q=$pdo->prepare("UPDATE portal_westy_turns SET state='pending',finished_at=NULL,reserve_microusd=reserve_microusd+?,charged_microusd=charged_microusd+? WHERE id=? AND scope_key=? AND state='complete'");
            $q->execute([$reserve,$reserve,$turnId,$s['key']]);if($q->rowCount()!==1)throw new PortalWestyException('conversation_changed');
        }else{
            $q=$pdo->prepare("INSERT INTO portal_westy_turns(tenant_id,client_id,scope_key,conversation_key,operation_key,state,input_text,model_name,reserve_microusd,charged_microusd,created_at,expires_at) VALUES(?,?,?,?,?,'pending',?,?,?,?,?,?)");
            $q->execute([$s['tenant'],$s['client'],$s['key'],$account['conversation_key'],$key,trim($text),$aiSelection['model'],$reserve,$reserve,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+$c['retention_days']*86400)]);
            $turnId=(int)$pdo->lastInsertId();
        }
        $attemptId=portal_westy_ai_attempt($pdo,$turnId,$s,$aiSelection,$reserve,$desktopTask,
            portal_westy_ai_request_fingerprint($account['conversation_key'],$text,$selected));
        if($runResume){
            $q=$pdo->prepare("UPDATE portal_westy_tool_runs SET state='running',pending_json=NULL,replay_json=NULL WHERE turn_id=? AND scope_key=? AND state='waiting' AND sequence=?");$q->execute([$turnId,$s['key'],$request['sequence']]);
            if($q->rowCount()!==1)throw new PortalWestyException('run_unavailable');
        }elseif(portal_westy_tools_enabled()&&portal_westy_runs_installed($pdo)){
            $existingRun=portal_westy_run_find($pdo,$s,$key,true);
            if($existingRun){
                if(!portal_westy_run_matches($existingRun)||$existingRun['state']!=='complete')throw new PortalWestyException('run_unavailable');
                $q=$pdo->prepare("UPDATE portal_westy_tool_runs SET state='running' WHERE turn_id=? AND scope_key=? AND state='complete'");$q->execute([$turnId,$s['key']]);
                $context['tool_run']=$existingRun;
            }else $context['tool_run']=portal_westy_run_create($pdo,$s,$turnId,$account['conversation_key'],$key);
        }
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    $partial=['reply'=>'','sources'=>[],'draft_subject'=>'','draft_body'=>'','tools'=>[]];
    if($resume){$saved=json_decode((string)$resumeTurn['reply_json'],true);if(is_array($saved))$partial=array_intersect_key($saved,$partial)+$partial;$partial['reply'].="\n\n";
        if($runResume)foreach($partial['tools'] as &$tool)if(($tool['awaiting_run']??false)===true){$tool['awaiting_run']=false;$tool['result']=$runResult['receipt'];$tool['state']=$runResult['receipt']['state']??'complete';}unset($tool);
    }
    // Once this attempt loses its MSP AI authority it cannot regain delivery
    // authority, even if the connection is restored before accounting finishes.
    $aiLost=false;
    $requireAi=static function()use($pdo,$context,$aiSnapshot,$aiResolver,&$aiLost):void{
        if(!$aiLost){
            try{$fresh=portal_westy_ai_snapshot($pdo,$context,$aiSnapshot['revision'],'status',$aiResolver);
                $aiLost=!portal_westy_ai_same_selection($aiSnapshot,$fresh);}
            catch(Throwable){$aiLost=true;}
        }
        if($aiLost)throw new PortalWestyException('ai_changed',503);
    };
    $lastCheck=0.0;$deadline=microtime(true)+150;
    $alive=static function(bool $force=false)use($pdo,$context,$s,$turnId,$reauthorize,$requireAi,&$lastCheck,$deadline):void{
        if(microtime(true)>$deadline)throw new PortalWestyException('interrupted');
        if(!$force && microtime(true)-$lastCheck<1)return;
        $lastCheck=microtime(true);
        if(!portal_westy_config()['enabled']||!portal_westy_config()['ai_enabled'])throw new PortalWestyException('ai_unavailable');
        if($reauthorize){$fresh=$reauthorize();if(!is_array($fresh)||!portal_desktop_same_context($context,$fresh)||portal_westy_scope($pdo,$fresh)['key']!==$s['key'])throw new PortalWestyException('sign_in',401);}
        $requireAi();
        $q=$pdo->prepare('SELECT state FROM portal_westy_turns WHERE id=? AND scope_key=?');$q->execute([$turnId,$s['key']]);
        if($q->fetchColumn()!=='pending')throw new PortalWestyException('stopped');
    };
    $save=static function()use($pdo,$turnId,$s,&$partial,$alive):void{
        $alive(true);
        $q=$pdo->prepare("UPDATE portal_westy_turns SET reply_json=? WHERE id=? AND scope_key=? AND state='pending'");
        $q->execute([json_encode($partial,JSON_THROW_ON_ERROR),$turnId,$s['key']]);
    };
    $output=static function(string $event,array $value)use(&$partial,$save,$emit,$key,$alive):void{
        $alive(true);
        if($event==='delta'){$partial['reply'].=$value['text'];$save();}
        if($emit){$alive(true);$emit($event,['operation'=>$key]+$value);}
    };
    if($emit)$emit('accepted',['operation'=>$key,'conversation'=>$account['conversation_key']]);
    $result=portal_westy_ai_run($pdo,$context,$aiSnapshot,$messages,$key,$partial,$alive,$output,$save,$provider,$transport,$aiResolver);
    $aiLost=$aiLost || ($result['reason']??null)==='ai_changed';
    $result['data']=$partial;
    // A paid receipt survives loss of authority. Revalidation controls delivery,
    // never whether an already reserved attempt can finish its accounting.
    $authorized=false;
    $deliveryAuthority=static function()use($pdo,$context,$s,$reauthorize,$requireAi,&$authorized):bool{
        $authorized=false;
        if ($reauthorize) {
            $fresh=$reauthorize();
            if(!is_array($fresh) || !portal_desktop_same_context($context,$fresh))return false;
        }
        if(!portal_westy_config()['enabled'] || !portal_westy_config()['ai_enabled']
            || portal_westy_scope($pdo,$context,true)['key']!==$s['key'])return false;
        $requireAi();
        return $authorized=true;
    };
    try{
        portal_westy_ai_finish($pdo,$s,$turnId,$attemptId,$account['conversation_key'],$month,$result,$deliveryAuthority);
    }catch(Throwable $finishError){
        // A failed accounting transaction is still a failed task. Release only
        // its already bound control, without retrying inference or input and
        // without rewriting/refunding the uncertain paid attempt. Cleanup keeps
        // an unconfirmed task identity; it must not replace the original error.
        try{portal_westy_run_end($pdo,$s,$key,'stopped',$context,$transport);}catch(Throwable){}
        throw $finishError;
    }
    if(($result['waiting']??false)!==true)portal_westy_run_end($pdo,$s,$key,($result['ok']??false)?'complete':'stopped',$context,$transport);
    if(!$authorized)throw new PortalWestyException($aiLost?'ai_changed':'sign_in',$aiLost?503:401);
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
        portal_westy_require_conversation($account,$request['conversation']??null);
        $q=$pdo->prepare('UPDATE portal_westy_accounts SET conversation_key=? WHERE scope_key=?');$q->execute([$next,$s['key']]);$pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function portal_westy_select_chat(PDO $pdo,array $context,array $request): void
{
    $key=portal_westy_key($request['conversation']??null);
    $state=portal_westy_state($pdo,$context,$key);
    $s=portal_westy_scope($pdo,$context);
    $q=$pdo->prepare('UPDATE portal_westy_accounts SET conversation_key=? WHERE scope_key=?');$q->execute([$state['conversation'],$s['key']]);
}

function portal_westy_stop(PDO $pdo,array $context,array $request): void
{
    $s=portal_westy_scope($pdo,$context);$key=portal_westy_key($request['operation']??null);
    portal_westy_run_stop($pdo,$context,$s,$key);
    $q=$pdo->prepare("UPDATE portal_westy_turns SET state='unavailable',reason_code='stopped',finished_at=? WHERE scope_key=? AND operation_key=? AND state='pending'");
    $q->execute([gmdate('Y-m-d H:i:s'),$s['key'],$key]);
    $path=__DIR__.'/portal_desktop_sessions.php';
    if(!is_file($path))return;
    require_once $path;
    $q=$pdo->prepare('SELECT conversation_key FROM portal_westy_turns WHERE scope_key=? AND operation_key=?');
    $q->execute([$s['key'],$key]);$conversation=$q->fetchColumn();
    if(!is_string($conversation))return;
    try{$bound=portal_desktop_context($pdo,$context,$conversation,$key);}
    catch(PDOException $error){
        // The optional native schema is not a prerequisite for stopping plain
        // chat. Never conceal a missing schema when this turn used a native task.
        if(($error->errorInfo[1]??null)===1146){
            $q=$pdo->prepare('SELECT 1 FROM portal_westy_ai_attempts a JOIN portal_westy_turns t ON t.id=a.turn_id WHERE t.scope_key=? AND t.operation_key=? AND a.desktop_task_id IS NOT NULL LIMIT 1');
            $q->execute([$s['key'],$key]);
            if($q->fetchColumn()===false)return;
        }
        throw new PortalWestyException('desktop_unavailable',409);
    }
    catch(Throwable){throw new PortalWestyException('desktop_unavailable',409);}
    if(!isset($bound['desktop']))return;
    if(($bound['desktop']['operation_key']??null)!==$key)throw new PortalWestyException('desktop_unavailable');
    $task=$bound['desktop'];
    try{portal_desktop_request($bound,'stop',['session_id'=>$task['session_id'],'task_id'=>$task['task_id']]);}
    catch(Throwable){throw new PortalWestyException('desktop_unavailable',503);}
}
