<?php
/** Actual portal HTTP entry, session/revocation middleware and ledger. Only provider is synthetic. */
declare(strict_types=1);
require __DIR__.'/portal_devices_mysql_test.php';
require_once __DIR__.'/../lib/eightwestid/eightwestid.php';
echo 'Portal HTTP test PHP '.PHP_VERSION."\n";
$runtime=sys_get_temp_dir().'/safeharbor-stream-'.bin2hex(random_bytes(8));
$apache=getenv('SAFEHARBOR_STREAM_APACHE_TEST')==='1';
foreach(['lib','config','public/portal','sessions','cache'] as $part)mkdir($runtime.'/'.$part,0700,true);
$source=realpath(__DIR__.'/../lib');
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $file){$path=$runtime.'/lib/'.substr($file->getPathname(),strlen($source)+1);$file->isDir()?mkdir($path,0700,true):copy($file->getPathname(),$path);}
copy(__DIR__.'/../public/portal/westy.php',$runtime.'/public/portal/westy.php');
$config=['app_env'=>'dev','force_https'=>false,'db'=>['host'=>$host,'port'=>(int)$port,'user'=>$user,'pass'=>$pass,'name'=>$database,'charset'=>'utf8mb4'],
    'portal_westy'=>['enabled'=>true,'ai_enabled'=>true,'tools_enabled'=>false,'api_key'=>'synthetic-no-network'],
    'portal'=>['enabled'=>true,'issuer'=>'https://id.example.test','client_id'=>'fixture','client_secret'=>str_repeat('x',48),'redirect_uri'=>'https://example.test/portal/callback.php','cookie_secure'=>false,'revocation_cache_dir'=>$runtime.'/cache']];
file_put_contents($runtime.'/config/config.php','<?php return '.var_export($config,true).';');
$cache=new EightWest\Id\FileRevocationCache($runtime.'/cache');
$refreshFeed=static function(bool $allow=true)use($cache,$a,$apache,$runtime):void{$cache->save("https://id.example.test/oauth/revocations.php\0fixture",['fetched_at'=>time(),'generated_at'=>time(),'count'=>0,'authorization_count'=>$allow?1:0,'revoked'=>[],'authorizations'=>$allow?[$a['identity']['subject']=>'1.1']:[]]);if($apache)foreach(glob($runtime.'/cache/*') as $file)chown($file,'www-data');};
$refreshFeed();$session=bin2hex(random_bytes(16));$csrf=str_repeat('c',64);
$seedSession=static function()use($runtime,$session,$csrf,$a,$apache):void{$file=$runtime.'/sessions/sess_'.$session;file_put_contents($file,'_safeharbor_portal_identity|'.serialize($a['identity']).'_safeharbor_portal_csrf|'.serialize($csrf));if($apache)chown($file,'www-data');};
$seedSession();
$stream=file_get_contents($runtime.'/lib/portal_westy_stream.php');
$start=strpos($stream,'function portal_westy_provider_stream(');if($start===false)throw new RuntimeException('Provider seam unavailable');
$stub=<<<'PHP'
function portal_westy_provider_stream(array $body,string $key,callable $emit,callable $alive):array {
    $alive(true);$emit('delta',['text'=>'First visible chunk.']);
    for($n=0;$n<20;$n++){usleep(100000);$alive(true);}
    $emit('delta',['text'=>' Final chunk.']);
    return ['ok'=>true,'output'=>[],'input_tokens'=>20,'output_tokens'=>10];
}
PHP;
file_put_contents($runtime.'/lib/portal_westy_stream.php',substr($stream,0,$start).$stub);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
$command=['setsid',PHP_BINARY,'-d','session.save_path='.$runtime.'/sessions','-S',$address,'-t',$runtime.'/public'];
if($apache){
    chmod($runtime,0755);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $file)if($file->isDir())chmod($file->getPathname(),0755);
    foreach(['cache','sessions'] as $dir){chown($runtime.'/'.$dir,'www-data');chmod($runtime.'/'.$dir,0700);}
    $conf="ServerRoot /etc/apache2\nIncludeOptional /etc/apache2/mods-enabled/*.load\nIncludeOptional /etc/apache2/mods-enabled/*.conf\nDefaultRuntimeDir $runtime\nPidFile $runtime/apache.pid\nListen $address\nServerName 127.0.0.1\nUser www-data\nGroup www-data\nErrorLog $runtime/http.log\nDocumentRoot $runtime/public\n<Directory $runtime/public>\nRequire all granted\nAllowOverride None\n</Directory>\nphp_value session.save_path $runtime/sessions\n";
    file_put_contents($runtime.'/apache.conf',$conf);$command=['setsid','/usr/sbin/apache2','-f',$runtime.'/apache.conf','-DFOREGROUND'];
}
$process=proc_open($command,[0=>['file','/dev/null','r'],1=>['file',$runtime.'/http.log','a'],2=>['file',$runtime.'/http.log','a']],$pipes,null,getenv()+['PHP_CLI_SERVER_WORKERS'=>'4','APACHE_RUN_DIR'=>$runtime]);
if(!is_resource($process))throw new RuntimeException('HTTP server unavailable');$pid=proc_get_status($process)['pid'];
try{
    for($n=0;$n<100;$n++){$socket=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1);if($socket){fclose($socket);break;}usleep(20000);}
    $json=static function(?array $payload=null,?string $csrfOverride=null)use($address,$session,$csrf):array{
        $c=curl_init('http://'.$address.'/portal/westy.php');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_HTTPHEADER=>['Cookie: safeharbor_portal='.$session,'Content-Type: application/json','X-Portal-CSRF: '.($csrfOverride??$csrf)]]);
        if($payload!==null){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($payload));}$raw=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,json_decode((string)$raw,true)];
    };
    check($json()[0]===200,'real HTTP authenticates synthetic cookie through actual revocation and binding middleware');
    check($json(['action'=>'message','operation'=>str_repeat('e',32),'message'=>'Never stored.','conversation'=>null],'wrong')[0]===403,'real streaming route requires CSRF before reserving a turn');
    foreach(['complete','stop','logout','revoke'] as $scenario){
        $seedSession();$refreshFeed();$state=$json()[1]['state'];$operation=bin2hex(random_bytes(16));$bytes='';$firstAt=null;$doneAt=null;$intervened=false;$startAt=microtime(true);$headers=[];
        $curl=curl_init('http://'.$address.'/portal/westy.php');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>['Cookie: safeharbor_portal='.$session,'Content-Type: application/json','Accept: text/event-stream','X-Portal-CSRF: '.$csrf],
            CURLOPT_POSTFIELDS=>json_encode(['action'=>'message','operation'=>$operation,'message'=>'Synthetic stream test.','conversation'=>$state['conversation']]),
            CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$headers){$headers[]=$line;return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$bytes,&$firstAt,&$doneAt,&$intervened,$scenario,$json,$operation,$runtime,$session,$refreshFeed):int{
                $bytes.=$chunk;if(str_contains($bytes,'First visible chunk.')&&$firstAt===null)$firstAt=microtime(true);
                if(str_contains($bytes,'event: done'))$doneAt=microtime(true);
                if($firstAt!==null&&!$intervened){$intervened=true;$at=microtime(true);
                    if($scenario==='stop'){check($json(['action'=>'stop','operation'=>$operation])[0]===200,'separate stop request runs during streaming without a session lock');check(microtime(true)-$at<1.5,'stop response precedes provider completion');}
                    elseif($scenario==='logout')unlink($runtime.'/sessions/sess_'.$session);
                    elseif($scenario==='revoke')$refreshFeed(false);
                }
                return strlen($chunk);
            }]);
        $ok=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($ok===false||$http!==200||$firstAt===null||str_contains($bytes,'Warning:')||str_contains($bytes,'Fatal error')||!str_contains(implode('',$headers),'text/event-stream'))fwrite(STDERR,'Synthetic HTTP diagnostic: '.json_encode(['php'=>PHP_VERSION,'http'=>$http,'headers'=>$headers,'body'=>substr(trim($bytes),0,12000),'log'=>substr(file_get_contents($runtime.'/http.log'),-8000)])."\n");
        check($ok!==false&&$http===200&&$firstAt!==null,'actual HTTP produces a visible delta for '.$scenario);
        check(str_contains(implode('',$headers),'text/event-stream')&&!str_contains($bytes,'Warning:')&&!str_contains($bytes,'Fatal error'),'SSE headers and post-header session reads remain clean for '.$scenario);
        if($scenario==='complete')check($doneAt!==null&&$doneAt-$firstAt>1.5&&str_contains($bytes,'Final chunk.'),'first chunk arrives before generation completes, not buffered full-response playback');
        else{
            preg_match_all('/event: delta\ndata: ([^\n]+)/',$bytes,$deltas);
            check(!str_contains(implode('',$deltas[1]),'Final chunk.'),'stop/logout/revocation prevents subsequent visible content for '.$scenario);
            // Let the following independent scenario start without waiting for the
            // normal 180-second interrupted-turn window in this disposable ledger.
            $pdo->prepare("UPDATE portal_westy_turns SET created_at=created_at-INTERVAL 4 MINUTE WHERE operation_key=? AND state='pending'")->execute([$operation]);
        }
    }
    echo 'PASS real portal '.($apache?'Apache':'PHP HTTP').' streaming, concurrent stop, logout and revocation'."\n";
}finally{
    posix_kill(-$pid,SIGTERM);proc_close($process);
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($runtime);
}
