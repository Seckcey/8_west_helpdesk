<?php
/** Provider SSE framing and visible text only; reasoning/replay records stay server-side. */
declare(strict_types=1);
require_once __DIR__.'/tenant_ai_provider.php';

final class WestyTenantAiStream
{
    private string $buffer='';
    private int $bytes=0;
    private string $visible='';
    private ?array $final=null;
    private array $message=[];
    private array $arguments=[];
    public function __construct(private array $selection,private Closure $delta,private Closure $alive){}

    public function feed(string $chunk):void
    {
        $this->bytes+=strlen($chunk);
        if($this->bytes>4194304)throw new RuntimeException('provider_limit');
        $this->buffer.=$chunk;
        while(preg_match('/\r?\n\r?\n/',$this->buffer,$separator,PREG_OFFSET_CAPTURE)===1){
            $at=$separator[0][1];$frame=substr($this->buffer,0,$at);$this->buffer=substr($this->buffer,$at+strlen($separator[0][0]));
            $parts=[];
            foreach(explode("\n",$frame) as $line)if(str_starts_with($line,'data:'))$parts[]=rtrim(ltrim(substr($line,5),' '),"\r");
            if($parts===[])continue;$raw=implode("\n",$parts);if($raw==='[DONE]')continue;
            $event=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            $original=json_decode($raw,false,64,JSON_THROW_ON_ERROR);
            if(!is_array($event) || !is_object($original))throw new RuntimeException('provider_invalid');$this->event($event,$original);
        }
    }

    private function visible(mixed $text):void
    {
        if(!is_string($text) || !mb_check_encoding($text,'UTF-8') || strlen($this->visible)+strlen($text)>262144
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$text))throw new RuntimeException('provider_invalid');
        $this->visible.=$text;($this->delta)($text);
    }

    private function event(array $event,object $original):void
    {
        ($this->alive)();$type=$event['type']??'';
        if($this->final!==null)throw new RuntimeException('provider_invalid');
        if($this->selection['provider']==='openai'){
            if($type==='response.output_text.delta')$this->visible($event['delta']??null);
            elseif(in_array($type,['response.completed','response.failed','response.incomplete'],true)){
                $response=$event['response']??[];
                if(!is_array($response))throw new RuntimeException('provider_invalid');
                // An incomplete/failed terminal event can contain a paid receipt,
                // but never grants delivery even if its status is inconsistent.
                if($type!=='response.completed')$response['status']=substr($type,9);
                $output=$original->response->output??null;
                $this->final=westy_tenant_ai_parse($this->selection,200,$response,is_array($output)?$output:null);
            }
            elseif(in_array($type,['response.refusal.delta','error'],true))throw new RuntimeException('provider_unavailable');
            return;
        }
        switch($type){
            case 'message_start':
                if($this->message!==[] || !is_array($event['message']??null))throw new RuntimeException('provider_invalid');
                $this->message=$event['message'];$this->message['content']=[];break;
            case 'content_block_start':
                $i=$event['index']??null;$block=$event['content_block']??null;
                if(!is_int($i) || $i<0 || $i>63 || isset($this->message['content'][$i]) || !is_array($block))throw new RuntimeException('provider_invalid');
                if(($block['type']??null)==='tool_use' && property_exists($original->content_block,'input'))$block['input']=$original->content_block->input;
                $this->message['content'][$i]=$block;break;
            case 'content_block_delta':
                $i=$event['index']??null;$delta=$event['delta']??[];
                if(!is_int($i) || !isset($this->message['content'][$i]))throw new RuntimeException('provider_invalid');
                $block=&$this->message['content'][$i];
                if(($delta['type']??'')==='text_delta' && ($block['type']??'')==='text'){
                    $this->visible($delta['text']??null);$block['text'].=$delta['text'];
                }elseif(($delta['type']??'')==='input_json_delta' && ($block['type']??'')==='tool_use'){
                    if(!is_string($delta['partial_json']??null))throw new RuntimeException('provider_invalid');
                    $this->arguments[$i]=($this->arguments[$i]??'').$delta['partial_json'];
                    if(strlen($this->arguments[$i])>65536)throw new RuntimeException('provider_limit');
                }elseif(($delta['type']??'')==='thinking_delta' && ($block['type']??'')==='thinking'){
                    $block['thinking']=($block['thinking']??'').($delta['thinking']??'');
                }elseif(($delta['type']??'')==='signature_delta' && ($block['type']??'')==='thinking'){
                    $block['signature']=($block['signature']??'').($delta['signature']??'');
                }else throw new RuntimeException('provider_invalid');
                unset($block);break;
            case 'content_block_stop':
                $i=$event['index']??null;
                if(!is_int($i) || !isset($this->message['content'][$i]))throw new RuntimeException('provider_invalid');
                if(isset($this->arguments[$i]))$this->message['content'][$i]['input']=json_decode($this->arguments[$i],false,32,JSON_THROW_ON_ERROR);
                break;
            case 'message_delta':
                if($this->message===[] || !is_array($event['delta']??null) || !is_array($event['usage']??null))throw new RuntimeException('provider_invalid');
                $this->message=array_replace($this->message,$event['delta']);
                $this->message['usage']=array_replace($this->message['usage']??[],$event['usage']);break;
            case 'message_stop':
                $this->message['content']=array_values($this->message['content']??[]);
                $this->final=westy_tenant_ai_parse($this->selection,200,$this->message);break;
            case 'error':throw new RuntimeException('provider_unavailable');
            case 'ping':break;
            default:throw new RuntimeException('provider_invalid');
        }
    }

    public function result():array
    {
        if($this->final===null || trim($this->buffer)!=='')return ['ok'=>false,'reason'=>'provider_incomplete','usage'=>null];
        if(($this->final['ok']??false) && $this->final['text']!==$this->visible)return ['ok'=>false,'reason'=>'provider_invalid','usage'=>$this->final['usage']];
        return $this->final;
    }
}

function westy_tenant_ai_stream(array $selection,string $system,array $messages,array $options,callable $delta,callable $alive):array
{
    if(($selection['status']??null)!=='active' || !westy_tenant_ai_api_key($selection['provider']??'',$selection['api_key']??null))
        return ['ok'=>false,'reason'=>'needs_setup','usage'=>null];
    $body=westy_tenant_ai_body($selection,$system,$messages,array_replace($options,['stream'=>true]));
    westy_tenant_ai_input_bound($selection,$body,$options['count_transport']??null,$options['max_input_tokens']??200000);
    $alive();
    $openai=$selection['provider']==='openai';$cancelled=null;$invalid=false;
    $guard=static function()use($alive,&$cancelled):void{try{$alive();}catch(Throwable $e){$cancelled=$e;throw $e;}};
    $visible=static function(string $text)use($delta,&$cancelled):void{try{$delta($text);}catch(Throwable $e){$cancelled=$e;throw $e;}};
    $parser=new WestyTenantAiStream($selection,$visible,$guard);
    $curl=curl_init($openai?'https://api.openai.com/v1/responses':'https://api.anthropic.com/v1/messages');
    $headers=['Content-Type: application/json'];
    if($openai)$headers[]='Authorization: Bearer '.$selection['api_key'];
    else{$headers[]='x-api-key: '.$selection['api_key'];$headers[]='anthropic-version: 2023-06-01';}
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>90,
        CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        CURLOPT_NOPROGRESS=>false,CURLOPT_XFERINFOFUNCTION=>static function()use($alive,&$cancelled):int{
            try{$alive();return 0;}catch(Throwable $e){$cancelled=$e;return 1;}
        },
        CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use($parser,&$invalid):int{
            try{$parser->feed($chunk);return strlen($chunk);}catch(Throwable){$invalid=true;return 0;}
        }]);
    try{$ok=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);}finally{curl_close($curl);}
    if($cancelled!==null)throw $cancelled;
    if($ok===false || $invalid || $http!==200)return ['ok'=>false,'reason'=>$http===429?'quota_unavailable':'provider_unavailable','usage'=>null];
    return $parser->result();
}
