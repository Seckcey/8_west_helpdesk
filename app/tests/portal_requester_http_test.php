<?php
/** Synthetic disposable MySQL + actual HTTP routes; no live identity or provider calls. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
require_once __DIR__.'/portal_westy_fixture.php';
require_once __DIR__.'/../lib/eightwestid/eightwestid.php';
$host = getenv('SAFEHARBOR_WESTY_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_WESTY_TEST_PORT') ?: '3306';
$user = getenv('SAFEHARBOR_WESTY_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_WESTY_TEST_PASS') ?: '';
$database = 'safeharbor_westy_test_requester_http';
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS '.$database);
$pdo->exec('USE '.$database);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) $pdo->exec('DROP TABLE `'.str_replace('`','``',$table).'`');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
portal_westy_fixture_sql($pdo, __DIR__.'/../db/schema.sql');
[$a,$b,$c] = portal_westy_fixture_seed($pdo);
$member = [...$a['identity'], 'role'=>'client_staff'];
$other = [...$member, 'subject'=>'t9u99'];
$own = portal_create_ticket($pdo,1,11,'client_staff','Same name','OWN_REQUEST_CANARY','normal','Own message',$member);
$foreign = portal_create_ticket($pdo,1,11,'client_staff','Same name','OTHER_REQUEST_CANARY','normal','Other message',$other);
$pdo->exec("INSERT INTO tickets(tenant_id,client_id,subject,status,priority,channel,sla_due_at) VALUES(1,11,'LEGACY_REQUEST_CANARY','open','normal','portal',UTC_TIMESTAMP())");
$legacy = (int)$pdo->lastInsertId();
$runtime = sys_get_temp_dir().'/safeharbor-requester-'.bin2hex(random_bytes(8));
foreach (['config','public/portal','public/api','sessions','cache'] as $part) mkdir($runtime.'/'.$part,0700,true);
$copyTree = static function(string $source,string $target): void {
    if (!is_dir($target)) mkdir($target,0700,true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $to=$target.'/'.substr($file->getPathname(),strlen($source)+1);
        $file->isDir() ? (is_dir($to) || mkdir($to,0700,true)) : copy($file->getPathname(),$to);
    }
};
$install = static function(string $app) use ($copyTree,$runtime): void {
    $copyTree($app.'/lib',$runtime.'/lib');
    $copyTree($app.'/public/assets',$runtime.'/public/assets');
    foreach (['portal/requests.php','portal/ticket.php','portal/new.php','portal/reports.php','portal/westy.php','portal/logout.php',
        'portal/devices.php','portal/index.php','portal/login.php','portal/callback.php','attachment.php',
        'api/search.php','ticket.php','reports_export.php','profile.php','users.php'] as $route) copy($app.'/public/'.$route,$runtime.'/public/'.$route);
};
$install(realpath(__DIR__.'/..'));
$config = ['app_env'=>'dev','force_https'=>false,
    'db'=>['host'=>$host,'port'=>(int)$port,'user'=>$user,'pass'=>$pass,'name'=>$database,'charset'=>'utf8mb4'],
    'portal'=>['enabled'=>true,'issuer'=>'https://id.example.test','client_id'=>'fixture','client_secret'=>str_repeat('x',48),
        'redirect_uri'=>'https://example.test/portal/callback.php','cookie_secure'=>false,'revocation_cache_dir'=>$runtime.'/cache']];
$saveConfig = static function(bool $enabled) use (&$config,$runtime): void {
    $config['portal']['enabled']=$enabled;
    file_put_contents($runtime.'/config/config.php','<?php return '.var_export($config,true).';');
};
$saveConfig(true);
$cache = new EightWest\Id\FileRevocationCache($runtime.'/cache');
$feed = static function(bool $allow=true) use ($cache,$member): void {
    $cache->save("https://id.example.test/oauth/revocations.php\0fixture",['fetched_at'=>time(),'generated_at'=>time(),
        'count'=>0,'authorization_count'=>$allow?1:0,'revoked'=>[],'authorizations'=>$allow?[$member['subject']=>'1.1']:[]]);
};
$feed();
$session=bin2hex(random_bytes(16)); $csrf=str_repeat('c',64);
$seed = static function(array $identity) use ($runtime,$session,$csrf): void {
    file_put_contents($runtime.'/sessions/sess_'.$session,'_safeharbor_portal_identity|'.serialize($identity).'_safeharbor_portal_csrf|'.serialize($csrf));
};
$seed($member);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr); $address=stream_socket_get_name($socket,false); fclose($socket);
$process=proc_open([PHP_BINARY,'-d','opcache.enable_cli=0','-d','session.save_path='.$runtime.'/sessions','-S',$address,'-t',$runtime.'/public'],
    [0=>['file','/dev/null','r'],1=>['file',$runtime.'/http.log','a'],2=>['file',$runtime.'/http.log','a']],$pipes);
$checks=0;
$check=static function(bool $ok,string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    echo 'ok '.(++$checks).' - '.$name."\n";
};
try {
    for($n=0;$n<100;$n++) { $socket=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1); if($socket){fclose($socket);break;} usleep(20000); }
    $http=static function(string $route,?array $post=null) use($address,$session): array {
        $curl=curl_init('http://'.$address.$route); $headers=[];
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,
            CURLOPT_HTTPHEADER=>['Cookie: safeharbor_portal='.$session],
            CURLOPT_HEADERFUNCTION=>static function($c,string $h) use(&$headers): int {$headers[]=$h;return strlen($h);}]);
        if($post!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
        $body=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
        return [$status,(string)$body,implode('',$headers)];
    };
    [$status,$body]=$http('/portal/requests.php');
    $check($status===200 && str_contains($body,'OWN_REQUEST_CANARY') && !str_contains($body,'OTHER_REQUEST_CANARY') && !str_contains($body,'LEGACY_REQUEST_CANARY'),'actual member list excludes other employee and historical requests');
    [$status,$body]=$http('/portal/');
    $check($status===200 && str_contains($body,'OWN_REQUEST_CANARY') && !str_contains($body,'OTHER_REQUEST_CANARY') && !str_contains($body,'LEGACY_REQUEST_CANARY'),'actual member Home shows own requests and excludes other employee and historical requests');
    $check(!str_contains($body,'portal-chat-home-slot') && !str_contains($body,'href="/portal/reports.php"') && !str_contains($body,'customer-billing.php'),'actual member Home is not the workspace and offers no client administration');
    [$status,$body]=$http('/portal/?view=westy');
    $check($status===200 && str_contains($body,'id="portal-chat-home-slot"') && !str_contains($body,'CANARY'),'actual Westy route renders the private workspace without request records');
    foreach ([$foreign,$legacy] as $id) {
        [$status,$body]=$http('/portal/ticket.php?id='.$id);
        $check($status===404 && !str_contains($body,'CANARY'),'actual direct detail refuses unavailable request '.$id);
    }
    [$status,$body]=$http('/portal/ticket.php?id='.$own);
    $check($status===200 && str_contains($body,'Own message'),'actual own detail includes public messages');
    preg_match('/name="action_nonce" value="([^"]+)"/',$body,$nonce);
    $check($http('/portal/ticket.php?id='.$own,['csrf'=>$csrf,'action_nonce'=>$nonce[1]??'','body'=>'HTTP own reply'])[0]===303,'actual member reply saves with CSRF and one-use nonce');
    $check($http('/portal/reports.php')[0]===403,'actual member archive route is refused');
    foreach (['/attachment.php?id=1','/api/search.php?q=CANARY','/ticket.php?id='.$foreign,'/reports_export.php','/profile.php','/users.php'] as $route) {
        [$status,$body,$headers]=$http($route);
        $check($status===302 && str_contains($headers,'Location: /login.php') && !str_contains($body,'CANARY'),'portal cookie grants no staff route '.$route);
    }
    $seed([...$member,'role'=>'client_admin']);
    [$status,$body]=$http('/portal/requests.php');
    $check($status===200 && str_contains($body,'OTHER_REQUEST_CANARY') && str_contains($body,'LEGACY_REQUEST_CANARY'),'actual client administrator retains client-wide requests');
    [$status,$body]=$http('/portal/');
    $check($status===200 && str_contains($body,'OWN_REQUEST_CANARY') && str_contains($body,'OTHER_REQUEST_CANARY') && str_contains($body,'LEGACY_REQUEST_CANARY')
        && str_contains($body,'href="/portal/reports.php"'),'actual client administrator Home keeps the client-wide view');
    $seed([...$member,'role'=>'client_viewer']);
    $check($http('/portal/ticket.php?id='.$own,['csrf'=>$csrf,'body'=>'Viewer forbidden'])[0]===403,'actual viewer cannot reply');
    [$status,$body]=$http('/portal/');
    $check($status===200 && str_contains($body,'OWN_REQUEST_CANARY') && !str_contains($body,'OTHER_REQUEST_CANARY') && !str_contains($body,'LEGACY_REQUEST_CANARY')
        && !str_contains($body,'href="/portal/new.php"'),'actual viewer Home is own-only and offers no write action');
    $seed($member); $feed(false);
    [$status,$body]=$http('/portal/ticket.php?id='.$own,['csrf'=>$csrf,'body'=>'Revoked forbidden']);
    $check(!str_contains($body,'Own message') && $status!==303,'revoked session cannot read or mutate the request');
    $feed(); $seed($member);
    $csp=static fn(string $formAction): string => "Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; script-src 'self'; connect-src 'self'; form-action $formAction; base-uri 'none'; frame-ancestors 'none'\r\n";
    [$status,$body,$headers]=$http('/portal/requests.php');
    $check($status===200 && str_contains($body,'action="/portal/logout.php"') && str_contains($headers,$csp("'self' https://id.example.test")),'a page with Sign out lets its form reach the issuer');
    [$status,,$headers]=$http('/portal/logout.php');
    $check($status===405 && str_contains($headers,$csp("'self'")),'a page without Sign out keeps forms on this origin');
    $sessionFile=$runtime.'/sessions/sess_'.$session;
    $sessionLive=static function() use($sessionFile): bool { clearstatcache(); return is_file($sessionFile); };
    $toIssuer="Location: https://id.example.test/logout.php\r\n";
    [$status,,$headers]=$http('/portal/logout.php',['csrf'=>str_repeat('d',64)]);
    $check($status===403 && !str_contains($headers,'Location:') && $sessionLive(),'a live session is not ended without its token');
    [$status,,$headers]=$http('/portal/logout.php',['csrf'=>$csrf]);
    $check($status===302 && str_contains($headers,$toIssuer) && !$sessionLive(),'sign-out ends the local session, then hands off to the issuer');
    [$status,,$headers]=$http('/portal/logout.php',['csrf'=>$csrf]);
    $check($status===302 && str_contains($headers,$toIssuer),'a second post with no session left still reaches the issuer');
    $seed([...$member,'expires_at'=>time()-1]);
    [$status,,$headers]=$http('/portal/logout.php',['csrf'=>str_repeat('d',64)]);
    $check($status===302 && str_contains($headers,$toIssuer) && !$sessionLive(),'an expired session is cleared and handed off whatever token its tab holds');
    $seed($member);
    $saveConfig(false); $holdHash=hash_file('sha256',$runtime.'/config/config.php');
    $holdRoutes=['/portal/requests.php','/portal/ticket.php?id='.$own,'/portal/new.php','/portal/reports.php','/portal/westy.php','/portal/logout.php','/portal/devices.php','/portal/index.php','/portal/login.php','/portal/callback.php'];
    foreach($holdRoutes as $route) $check($http($route)[0]===404,'candidate portal traffic hold denies '.$route);
    $check($http('/portal/?view=westy')[0]===404,'candidate portal traffic hold denies the Westy route');
    $old=getenv('SAFEHARBOR_SETTINGS_OLD_APP');
    if(is_string($old)&&is_dir($old.'/lib')) {
        $install($old);
        $check(hash_equals($holdHash,hash_file('sha256',$runtime.'/config/config.php')),'source inverse preserves server-only portal hold bytes');
        foreach($holdRoutes as $route) $check($http($route)[0]===404,'previous source remains held after inverse '.$route);
    }
    echo "Portal requester HTTP: $checks checks passed\n";
} catch(Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\n".substr(file_get_contents($runtime.'/http.log'),-6000));
    throw $error;
} finally {
    proc_terminate($process);proc_close($process);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($runtime);
    $pdo->exec('DROP DATABASE '.$database);
}
