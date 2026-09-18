<?php
/** Bounded, dedicated Westy mailbox polling.  No generic intake queue. */
declare(strict_types=1);

const WESTY_MAIL_POLL_MAX_MESSAGES = 10;
const WESTY_MAIL_POLL_MAX_PAGES = 2;
const WESTY_MAIL_POLL_PAGE_SIZE = 5;
const WESTY_MAIL_POLL_LOOKBACK_SECONDS = 86400;

function westy_mail_poll_config(?array $raw = null): ?array
{
    $raw ??= cfg('westy_mail.poll');
    if (!is_array($raw) || ($raw['enabled'] ?? false) !== true
        || ($raw['connector_audited'] ?? false) !== true) return null;
    $tenant=$raw['tenant_id'] ?? null;
    if (!is_int($tenant) || $tenant < 1 || $tenant > 4294967295) return null;
    $auditedAt=$raw['connector_audited_at'] ?? null;
    if (!is_string($auditedAt) || strlen($auditedAt)>64) return null;
    try { $auditTime=(new DateTimeImmutable($auditedAt))->getTimestamp(); } catch (Throwable) { return null; }
    if ($auditTime > time()+300) return null;
    $approved = $raw['internal_senders'] ?? [];
    if (!is_array($approved) || count($approved) < 1 || count($approved) > 5) return null;
    $senders = [];
    foreach ($approved as $address) {
        try { $address = westy_mail_poll_address((string)$address); } catch (Throwable) { return null; }
        $senders[$address] = true;
    }
    $activated=$raw['activated_at'] ?? null;
    if (!is_string($activated) || strlen($activated)>64) return null;
    try { $activatedAt=(new DateTimeImmutable($activated))->setTimezone(new DateTimeZone('UTC')); } catch(Throwable) { return null; }
    if ($activatedAt->getTimestamp()>time()) return null;
    return ['tenant_id'=>$tenant, 'internal_senders' => array_keys($senders), 'lookback_seconds' => WESTY_MAIL_POLL_LOOKBACK_SECONDS, 'activated_at'=>$activatedAt->format('Y-m-d H:i:s'), 'auto_ack_enabled'=>($raw['auto_ack_enabled']??false)===true];
}

/** Pure conversion.  Raw Graph header data never becomes authenticated by itself. */
function westy_mail_poll_candidate(array $message, array $poll): array
{
    $id = trim((string)($message['id'] ?? ''));
    if ($id === '' || strlen($id) > 512 || preg_match('/[\x00-\x20\x7f]/', $id)) return ['state'=>'held','reason'=>'provider_id_invalid'];
    $from = (string)($message['from']['emailAddress']['address'] ?? '');
    $sender = (string)($message['sender']['emailAddress']['address'] ?? $from);
    $reply = $message['replyTo'] ?? [];
    $replyAddress = count($reply) === 0 ? $from : (string)($reply[0]['emailAddress']['address'] ?? '');
    try { $from=westy_mail_poll_address($from); $sender=westy_mail_poll_address($sender); $replyAddress=westy_mail_poll_address($replyAddress); }
    catch (Throwable) { return ['state'=>'held','reason'=>'address_invalid']; }
    if ($from !== $sender) return ['state'=>'held','reason'=>'sender_mismatch'];
    if ($replyAddress !== $from || count($reply) > 1) return ['state'=>'held','reason'=>'reply_to_mismatch'];
    $to=$message['toRecipients'] ?? []; $mailbox=(string)($poll['mailbox']??'');
    if (!is_array($to) || count($to)!==1 || $mailbox==='') return ['state'=>'held','reason'=>'destination_invalid'];
    try { $destination=westy_mail_poll_address((string)($to[0]['emailAddress']['address']??'')); } catch(Throwable) { return ['state'=>'held','reason'=>'destination_invalid']; }
    if (!hash_equals($mailbox,$destination)) return ['state'=>'held','reason'=>'destination_mismatch'];
    if (!in_array($from, $poll['internal_senders'], true)) return ['state'=>'held','reason'=>'sender_not_granted'];
    $headers = westy_mail_poll_headers($message['internetMessageHeaders'] ?? null);
    if ($headers === null) return ['state'=>'held','reason'=>'headers_invalid'];
    $authAs = $headers['x-ms-exchange-organization-authas'] ?? [];
    if (count($authAs) !== 1 || !hash_equals('internal', strtolower(trim($authAs[0])))) return ['state'=>'held','reason'=>'exchange_internal_unverified'];
    $ar = $headers['authentication-results'] ?? [];
    if (count($ar)>1) return ['state'=>'held','reason'=>'compauth_unverified'];
    if (count($ar)===1) {
        preg_match_all('/\bcompauth\s*=\s*([a-z]+)/i',$ar[0],$composite);
        if (count($composite[1])>1 || (count($composite[1])===1 && strtolower($composite[1][0])!=='pass') || preg_match('/\breason\s*=\s*130\b/i',$ar[0])===1) return ['state'=>'held','reason'=>'compauth_unverified'];
    }
    if (!westy_mail_poll_same_tenant_internal($headers,(string)($poll['exchange_tenant_id']??''))) return ['state'=>'held','reason'=>'exchange_tenant_unverified'];
    $body = (string)($message['body']['content'] ?? '');
    if (strlen($body) > 12000) return ['state'=>'held','reason'=>'body_oversize'];
    if (strtolower((string)($message['body']['contentType'] ?? 'text')) === 'html') $body = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (function_exists('mb_check_encoding') && !mb_check_encoding($body, 'UTF-8')) {
        if (!function_exists('utf8_clean')) return ['state'=>'held','reason'=>'body_encoding_invalid'];
        $body=utf8_clean($body);
    }
    $subject=(string)($message['subject'] ?? '');
    $auto=preg_match('/^(?:out of office|automatic reply|undeliverable|delivery status)/i',$subject)===1
        || preg_match('/^(?:mailer-daemon|postmaster|no-?reply|bounce|autoreply)@/i',$from)===1
        || isset($headers['auto-submitted']) || isset($headers['x-autoreply']) || isset($headers['x-autorespond']);
    return ['state'=>'candidate','input'=>[
        'provider_message_id'=>$id, 'internet_message_id'=>(string)($message['internetMessageId'] ?? ''),
        // Core currently names the recipient-side correlation field reply_to;
        // preserve the sender-side value separately until that API is renamed.
        'from'=>$from, 'reply_to'=>$destination, 'source_reply_to'=>$replyAddress, 'destination'=>$destination, 'subject'=>$subject, 'body'=>$body,
        'auto'=>$auto, 'auth_verified'=>true, 'auth_reason'=>'exchange_same_tenant_internal',
    ]];
}

function westy_mail_poll_hold_input(array $message): array
{
    return [
        'provider_message_id'=>(string)($message['id']??''), 'internet_message_id'=>(string)($message['internetMessageId']??''),
        'from'=>(string)($message['from']['emailAddress']['address']??''),
        'reply_to'=>(string)($message['replyTo'][0]['emailAddress']['address']??''),
        'auth_verified'=>false,
    ];
}
function westy_mail_poll_attention(PDO $p,int $conversation,int $receipt,string $reason): void
{
    if($conversation<1||$receipt<1||preg_match('/\A[a-z0-9_]{3,64}\z/D',$reason)!==1) throw new RuntimeException('attention_invalid');
    $verb=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';$p->prepare($verb.' INTO westy_mail_attention_events(conversation_id,receipt_id,reason) VALUES(?,?,?)')->execute([$conversation,$receipt,$reason]);
}

function westy_mail_poll_address(string $address): string
{
    if (function_exists('westy_mail_address')) return westy_mail_address($address);
    $address = strtolower(trim($address));
    if (strlen($address) > 190 || !filter_var($address, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $address)) throw new RuntimeException('address_invalid');
    return $address;
}

function westy_mail_poll_headers(mixed $headers): ?array
{
    if (!is_array($headers) || count($headers) < 1 || count($headers) > 200) return null;
    $out=[];
    foreach ($headers as $header) {
        if (!is_array($header) || !is_string($header['name'] ?? null) || !is_string($header['value'] ?? null)) return null;
        $name=strtolower(trim($header['name'])); $value=trim($header['value']);
        if ($name==='' || strlen($name)>200 || strlen($value)>8192 || preg_match('/[\x00\r\n]/',$name.$value)) return null;
        $out[$name][]=$value;
    }
    return $out;
}

/** Exchange stamps these on hosted internal mail; Internet compauth may be absent.
 * The driver binds the expected tenant to the dedicated Graph application.
 * The existing connector audit excludes external-as-internal mail routing.
 */
function westy_mail_poll_same_tenant_internal(array $headers,string $tenant): bool
{
    if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/Di',$tenant)!==1) return false;
    foreach (['x-ms-exchange-crosstenant-authas'=>'internal','x-ms-exchange-crosstenant-id'=>strtolower($tenant),'x-ms-exchange-crosstenant-fromentityheader'=>'hosted'] as $name=>$expected) {
        $values=$headers[$name]??[];
        if (count($values)!==1 || !hash_equals($expected,strtolower(trim($values[0])))) return false;
    }
    return true;
}

function westy_mail_poll_inbox_path(int $lookbackSeconds): string
{
    $lookback=max(300,min(WESTY_MAIL_POLL_LOOKBACK_SECONDS,$lookbackSeconds));
    $since=gmdate('Y-m-d\\TH:i:s\\Z', time()-$lookback);
    return 'mailFolders/inbox/messages?$top='.WESTY_MAIL_POLL_PAGE_SIZE
        .'&$select=id,internetMessageId,subject,from,sender,replyTo,toRecipients,body,receivedDateTime,internetMessageHeaders'
        .'&$filter='.rawurlencode('receivedDateTime ge '.$since).'&$orderby=receivedDateTime%20desc';
}

function westy_mail_poll_window_path(string $start,string $end): string
{
    if (!preg_match('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\z/D',$start) || !preg_match('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\z/D',$end)) throw new RuntimeException('cursor_window_invalid');
    $filter='receivedDateTime ge '.str_replace(' ','T',$start).'Z and receivedDateTime lt '.str_replace(' ','T',$end).'Z';
    return 'mailFolders/inbox/messages?$top='.WESTY_MAIL_POLL_PAGE_SIZE
        .'&$select=id,internetMessageId,subject,from,sender,replyTo,toRecipients,body,receivedDateTime,internetMessageHeaders'
        .'&$filter='.rawurlencode($filter).'&$orderby=receivedDateTime%20asc';
}

function westy_mail_poll_lock(PDO $p,int $tenant,string $identity): bool
{
    $q=$p->prepare('SELECT GET_LOCK(?,0)'); $q->execute([westy_mail_poll_lock_name($p,$tenant,$identity)]); return (int)$q->fetchColumn()===1;
}
function westy_mail_poll_lock_name(PDO $p,int $tenant,string $identity): string { return 'wmp:'.substr(hash('sha256',$p->query('SELECT DATABASE()')->fetchColumn().'|'.$tenant.'|'.$identity),0,56); }
function westy_mail_poll_unlock(PDO $p,int $tenant,string $identity): void { $q=$p->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([westy_mail_poll_lock_name($p,$tenant,$identity)]); }
function westy_mail_poll_cursor(PDO $p,array $poll,array $snapshot): array
{
    $identity=$snapshot['identity_sha256']; $tenant=$poll['tenant_id'];
    $p->beginTransaction(); try { $q=$p->prepare('SELECT * FROM westy_mail_poll_cursors WHERE tenant_id=? AND mail_identity_sha256=?'.($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE'));$q->execute([$tenant,$identity]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row){$now=gmdate('Y-m-d H:i:s');$end=max($now,$poll['activated_at']);$p->prepare('INSERT INTO westy_mail_poll_cursors(tenant_id,mail_identity_sha256,window_start_at,window_end_at) VALUES(?,?,?,?)')->execute([$tenant,$identity,$poll['activated_at'],$end]);$row=['tenant_id'=>$tenant,'mail_identity_sha256'=>$identity,'window_start_at'=>$poll['activated_at'],'window_end_at'=>$end,'next_path'=>null];}
        $p->commit();return $row;
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
function westy_mail_poll_cursor_commit(PDO $p,array $cursor,?string $next): void
{
    if($next!==null && (strlen($next)>4096 || preg_match('/[\x00-\x20\x7f\\\\]/',$next) || preg_match('/\Amailfolders\/inbox\/messages\?/',strtolower($next))!==1)) throw new RuntimeException('cursor_path_invalid');
    $p->beginTransaction();try { if($next!==null){$q=$p->prepare('UPDATE westy_mail_poll_cursors SET next_path=? WHERE tenant_id=? AND mail_identity_sha256=? AND window_start_at=? AND window_end_at=?');$q->execute([$next,$cursor['tenant_id'],$cursor['mail_identity_sha256'],$cursor['window_start_at'],$cursor['window_end_at']]);}
        else {$nextEnd=gmdate('Y-m-d H:i:s');$q=$p->prepare('UPDATE westy_mail_poll_cursors SET window_start_at=window_end_at,window_end_at=?,next_path=NULL WHERE tenant_id=? AND mail_identity_sha256=? AND window_start_at=? AND window_end_at=?');$q->execute([$nextEnd,$cursor['tenant_id'],$cursor['mail_identity_sha256'],$cursor['window_start_at'],$cursor['window_end_at']]);}
        if($q->rowCount()!==1) throw new RuntimeException('cursor_changed');$p->commit();
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}

/** Actual bounded intake sequencing. Callers must hold westy_mail_poll_lock(). */
function westy_mail_poll_run(PDO $p,array $poll,array $snapshot,callable $get,callable $accept,callable $hold,callable $ack): array
{
    // Never take the Exchange tenant from a message or independently configured allowlist.
    $poll['exchange_tenant_id']=$snapshot['graph']['tenant_id']??'';
    $cursor=westy_mail_poll_cursor($p,$poll,$snapshot);$page=$cursor['next_path']?:westy_mail_poll_window_path($cursor['window_start_at'],$cursor['window_end_at']);$seen=0;$held=[];
    for($pageNo=0;$pageNo<WESTY_MAIL_POLL_MAX_PAGES && $page!==null;$pageNo++) {
        $r=$get($snapshot['graph'],$page);if(($r['outcome']??'')!=='ok')throw new RuntimeException('graph_page_unavailable');$items=$r['data']['value']??null;if(!is_array($items)||count($items)>WESTY_MAIL_POLL_PAGE_SIZE)throw new RuntimeException('graph_page_bound_invalid');
        foreach($items as $message){if(!is_array($message)||++$seen>WESTY_MAIL_POLL_MAX_MESSAGES)throw new RuntimeException('graph_message_bound_invalid');$candidate=westy_mail_poll_candidate($message,$poll);
            if($candidate['state']!=='candidate'){$reason=(string)$candidate['reason'];$held[$reason]=($held[$reason]??0)+1;$hold($p,$poll['tenant_id'],westy_mail_poll_hold_input($message),$reason);continue;}
            $accepted=$accept($p,$poll['tenant_id'],$candidate['input']);
            if(in_array(($accepted['state']??''),['accepted','duplicate'],true)&&$poll['auto_ack_enabled']&&isset($accepted['conversation_id'],$accepted['receipt_id'])){$conversation=(int)$accepted['conversation_id'];$receipt=(int)$accepted['receipt_id'];try{$result=$ack($p,$conversation,$receipt);if(in_array($result,['claimed','unknown','rejected'],true))westy_mail_poll_attention($p,$conversation,$receipt,'auto_ack_result_unknown');}catch(Throwable){westy_mail_poll_attention($p,$conversation,$receipt,'auto_ack_authority_unavailable');$held['auto_ack_authority_or_owner']=($held['auto_ack_authority_or_owner']??0)+1;}}
        }
        $next=$r['next_path']??null;westy_mail_poll_cursor_commit($p,$cursor,$next);if($next===null)break;$cursor['next_path']=$next;$page=$next;
    }
    return ['seen'=>$seen,'held'=>$held];
}

function westy_mail_poll_sent_path(): string
{
    return 'mailFolders/sentitems/messages?$top='.WESTY_MAIL_POLL_MAX_MESSAGES
        .'&$select=id,internetMessageId,subject,toRecipients,internetMessageHeaders,sentDateTime'
        .'&$orderby=sentDateTime%20desc';
}

/** Bounded page collector; Graph transport already validates every nextLink. */
function westy_mail_poll_fetch_pages(array $g,string $first,callable $get): array
{
    $page=$first; $messages=[];
    for($n=0;$n<WESTY_MAIL_POLL_MAX_PAGES && $page!==null && count($messages)<WESTY_MAIL_POLL_MAX_MESSAGES;$n++) {
        $result=$get($g,$page); if(($result['outcome']??'')!=='ok') return ['outcome'=>'unavailable','messages'=>[]];
        $items=$result['data']['value']??null; if(!is_array($items)||count($items)>WESTY_MAIL_POLL_PAGE_SIZE) return ['outcome'=>'invalid','messages'=>[]];
        foreach($items as $message) { if(!is_array($message)) return ['outcome'=>'invalid','messages'=>[]]; $messages[]=$message; if(count($messages)>=WESTY_MAIL_POLL_MAX_MESSAGES) break; }
        $page=$result['next_path']??null;
    }
    return ['outcome'=>'ok','messages'=>$messages];
}

/** Read-only reconciliation evidence; unknown attempts are never retried or rewound. */
function westy_mail_poll_reconcile(PDO $p, array $g, callable $get,int $tenant): array
{
    $q=$p->prepare("SELECT id,message_key,recipient,subject,'conversation' AS kind FROM westy_mail_conversations WHERE tenant_id=? AND sender=? AND state IN ('claimed','unknown') UNION ALL SELECT a.id,a.message_key,c.recipient,c.subject,'auto' AS kind FROM westy_mail_auto_attempts a JOIN westy_mail_delegations d ON d.id=a.delegation_id JOIN westy_mail_conversations c ON c.id=d.conversation_id WHERE c.tenant_id=? AND c.sender=? AND a.state IN ('claimed','unknown') ORDER BY id DESC LIMIT ".WESTY_MAIL_POLL_MAX_MESSAGES);$q->execute([$tenant,$g['sender'],$tenant,$g['sender']]);
    $unknown=[]; foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) $unknown[(string)$row['message_key']]=['kind'=>(string)$row['kind'],'id'=>(int)$row['id'],'recipient'=>(string)$row['recipient'],'subject'=>(string)$row['subject']]; if (!$unknown) return [];
    $result=$get($g,westy_mail_poll_sent_path()); if (($result['outcome']??'')!=='ok') return [];
    $found=[]; foreach ((array)($result['data']['value']??[]) as $message) {
        $headers=westy_mail_poll_headers($message['internetMessageHeaders']??null); if ($headers===null) continue;
        foreach (($headers['x-westy-message-key']??[]) as $key) {
            $owner=$unknown[$key]??null;$recipient=(string)($message['toRecipients'][0]['emailAddress']['address']??'');$subject=(string)($message['subject']??'');$graphId=(string)($message['id']??'');
            if($owner!==null && $graphId!=='' && count((array)($message['toRecipients']??[]))===1 && hash_equals($owner['recipient'],strtolower($recipient)) && hash_equals($owner['subject'],$subject)) { $verb=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE'; $p->prepare($verb.' INTO westy_mail_sent_reconciliations(owner_kind,owner_id,message_key,graph_message_sha256,recipient_sha256,subject_sha256) VALUES(?,?,?,?,?,?)')->execute([$owner['kind'],$owner['id'],$key,hash('sha256',$graphId),hash('sha256',$recipient),hash('sha256',$subject)]);$found[]=$owner; }
        }
    }
    return $found;
}
