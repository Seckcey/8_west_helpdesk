<?php
/** Actual Sign out from a Companion window over HTTP. Synthetic disposable MySQL; no live identity or Milepost calls. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER') !== '1') exit(2);
require_once __DIR__.'/portal_westy_fixture.php';
require_once __DIR__.'/../lib/eightwestid/eightwestid.php';
$host = getenv('SAFEHARBOR_WESTY_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_WESTY_TEST_PORT') ?: '3306';
$user = getenv('SAFEHARBOR_WESTY_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_WESTY_TEST_PASS') ?: '';
$database = 'safeharbor_westy_test_companion_signout';
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS '.$database);
$pdo->exec('CREATE DATABASE '.$database);
$pdo->exec('USE '.$database);
portal_westy_fixture_sql($pdo, __DIR__.'/../db/schema.sql');
[$a] = portal_westy_fixture_seed($pdo);
$identity = $a['identity'];
$name = $identity['display_name'];

$runtime = sys_get_temp_dir().'/safeharbor-companion-signout-'.bin2hex(random_bytes(8));
foreach (['config','public/portal','sessions','cache'] as $part) mkdir($runtime.'/'.$part,0700,true);
$copyTree = static function(string $source,string $target): void {
    if (!is_dir($target)) mkdir($target,0700,true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $to=$target.'/'.substr($file->getPathname(),strlen($source)+1);
        $file->isDir() ? (is_dir($to) || mkdir($to,0700,true)) : copy($file->getPathname(),$to);
    }
};
$app = realpath(__DIR__.'/..');
$copyTree($app.'/lib',$runtime.'/lib');
$copyTree($app.'/public/assets',$runtime.'/public/assets');
foreach (['index.php','logout.php','signed-out.php'] as $route) copy($app.'/public/portal/'.$route,$runtime.'/public/portal/'.$route);
// No desktop_companion block: Milepost is out of reach, so every request stops before any network call.
$config = ['app_env'=>'dev','force_https'=>false,
    'db'=>['host'=>$host,'port'=>(int)$port,'user'=>$user,'pass'=>$pass,'name'=>$database,'charset'=>'utf8mb4'],
    'portal'=>['enabled'=>true,'issuer'=>'https://id.example.test','client_id'=>'fixture','client_secret'=>str_repeat('x',48),
        'redirect_uri'=>'https://example.test/portal/callback.php','cookie_secure'=>false,'revocation_cache_dir'=>$runtime.'/cache']];
$saveConfig = static function(bool $enabled) use (&$config,$runtime): void {
    $config['portal']['enabled']=$enabled;
    file_put_contents($runtime.'/config/config.php','<?php return '.var_export($config,true).';');
};
$saveConfig(true);
(new EightWest\Id\FileRevocationCache($runtime.'/cache'))->save("https://id.example.test/oauth/revocations.php\0fixture",['fetched_at'=>time(),'generated_at'=>time(),
    'count'=>0,'authorization_count'=>1,'revoked'=>[],'authorizations'=>[$identity['subject']=>'1.1']]);
$session=bin2hex(random_bytes(16)); $csrf=str_repeat('c',64); $sessionFile=$runtime.'/sessions/sess_'.$session;
$seed = static function(?string $pair) use ($sessionFile,$identity,$csrf): void {
    file_put_contents($sessionFile,'_safeharbor_portal_identity|'.serialize($identity).'_safeharbor_portal_csrf|'.serialize($csrf)
        .($pair===null?'':'desktop_companion_session|'.serialize($pair)));
};
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr); $address=stream_socket_get_name($socket,false); fclose($socket);
$process=proc_open([PHP_BINARY,'-d','opcache.enable_cli=0','-d','session.save_path='.$runtime.'/sessions','-S',$address,'-t',$runtime.'/public'],
    [0=>['file','/dev/null','r'],1=>['file',$runtime.'/http.log','a'],2=>['file',$runtime.'/http.log','a']],$pipes);
$checks=0;
$check=static function(bool $ok,string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    echo 'ok '.(++$checks).' - '.$label."\n";
};
try {
    for($n=0;$n<100;$n++) { $socket=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1); if($socket){fclose($socket);break;} usleep(20000); }
    $http=static function(string $route,?array $post=null) use($address,$session): array {
        $curl=curl_init('http://'.$address.$route); $headers=[];
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,
            CURLOPT_HTTPHEADER=>['Cookie: safeharbor_portal='.$session],
            CURLOPT_HEADERFUNCTION=>static function($c,string $h) use(&$headers): int {$headers[]=$h;return strlen($h);}]);
        if($post!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
        $body=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
        return [$status,(string)$body,implode('',$headers)];
    };

    $seed(null);
    [$status,$body]=$http('/portal/?view=westy');
    $check($status===200 && str_contains($body,$name) && !str_contains($body,'name="window"'),'a browser session has the ordinary sign-out form');
    [$status,,$headers]=$http('/portal/logout.php',['csrf'=>$csrf]);
    $check($status===302 && str_contains($headers,'Location: https://id.example.test/logout.php') && !is_file($sessionFile),'a browser session still hands off to 8 West ID');

    $pair=str_repeat('a',32);
    $seed($pair);
    [$status,$body]=$http('/portal/?view=westy');
    $check($status===200 && str_contains($body,$name) && str_contains($body,'name="window" value="companion"'),'a Companion session marks its sign-out form');
    [$status,$body]=$http('/portal/logout.php',['csrf'=>str_repeat('f',64),'window'=>'companion']);
    $check($status===403 && is_file($sessionFile),'a wrong token signs nobody out of the window');
    [$status,$body,$headers]=$http('/portal/logout.php',['csrf'=>$csrf,'window'=>'companion']);
    $check($status===302 && str_contains($headers,'Location: /portal/signed-out.php?connection=open') && !str_contains($headers,'id.example.test'),
        'Sign out stays on this origin, and says so when the connection could not be confirmed closed');
    $check(str_contains($headers,'safeharbor_portal=deleted') && str_contains($headers,'Cache-Control: no-store') && !is_file($sessionFile),'the window really is signed out');
    [$status,$body]=$http('/portal/?view=westy');
    $check(!str_contains($body,$name),'going back to the portal shows nothing private');
    [$status,$body,$headers]=$http('/portal/logout.php',['csrf'=>$csrf,'window'=>'companion']);
    $check(!str_contains($headers,'id.example.test') && !str_contains($body,$name),'a double-click\'s second post never tries to leave the window');

    [$status,$body,$headers]=$http('/portal/signed-out.php');
    $check($status===200 && str_contains($body,'signed out of Westy') && str_contains($body,'no longer connected') && !str_contains($body,'could not confirm'),'the signed-out page says what ended');
    $check(str_contains($body,'· Westy Companion</title>') && !str_contains($body,$name),'it keeps Westy branding and shows nobody\'s name');
    $check(str_contains($body,'target="_blank"') && str_contains($body,'href="/portal/"') && str_contains($body,'noopener'),'it links to the portal in the customer\'s own browser');
    $check(str_contains($headers,'Cache-Control: no-store') && str_contains($headers,"form-action 'self';") && stripos($headers,'Set-Cookie:')===false,'it is not cached and starts no session');
    [$status,$body]=$http('/portal/signed-out.php?connection=open');
    $check($status===200 && str_contains($body,'could not confirm') && str_contains($body,'within 30 minutes') && !str_contains($body,'no longer connected'),'an unconfirmed connection is reported honestly');
    [$status,,$headers]=$http('/portal/signed-out.php',[]);
    $check($status===405 && str_contains($headers,'Allow: GET'),'the signed-out page refuses anything but GET');
    $saveConfig(false);
    $check($http('/portal/signed-out.php')[0]===404,'the default-off portal hides the page too');
    echo "Companion sign-out HTTP: $checks checks passed\n";
} catch(Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\n".substr((string)file_get_contents($runtime.'/http.log'),-6000));
    throw $error;
} finally {
    proc_terminate($process);proc_close($process);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($runtime);
    $pdo->exec('DROP DATABASE '.$database);
}
