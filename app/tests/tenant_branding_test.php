<?php
/** Executed wire fixtures; injected transport never contacts an external service. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(2);
require_once __DIR__.'/../lib/tenant_branding.php';
$checks=0;
function brand_check(bool $ok,string $name):void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);++$checks;}
$chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
$bytes="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',64,32,8,6,0,0,0))
    .$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\xff\0\0\xff",64),32))).$chunk('IEND','');
$logo=['mime'=>'image/png','bytes'=>strlen($bytes),'width'=>64,'height'=>32,'sha256'=>hash('sha256',$bytes),'data_base64'=>base64_encode($bytes)];
$key=str_repeat('ab',32);$config=['service_secret'=>$key];
$body='{"version":1,"provider_tenant_slug":"alpha"}';
brand_check(tenant_brand_request_sign($key,'id','1800000000','0123456789abcdef0123456789abcdef',$body)
    ==='fe813598f083099472e6beeee9952f8e9c5877bae7fcbc4470bb413fe97a32d5','literal ASCII key known vector');
$requests=[];$revision=1;
$transport=static function(array $request)use($key,$logo,&$requests,&$revision):array {
    $requests[]=$request;
    brand_check($request['signature']===tenant_brand_request_sign($key,$request['app'],$request['timestamp'],$request['nonce'],$request['body']),'outgoing signature matches');
    $raw=json_encode(['version'=>1,'provider_tenant_slug'=>$request['provider'],'revision'=>$revision,'logo'=>$logo],JSON_UNESCAPED_SLASHES);
    return ['status'=>200,'body'=>$raw,'signature'=>tenant_brand_response_sign($key,$request['app'],200,$request['timestamp'],$request['nonce'],$raw)];
};
$found=tenant_brand_fetch('id','alpha',$config,$transport);
brand_check($found===['mime'=>'image/png','bytes'=>$bytes,'revision'=>1],'signed current raster accepted');
$revision=2;$found=tenant_brand_fetch('id','beta',$config,$transport);
brand_check($found['revision']===2 && $requests[1]['provider']==='beta' && $requests[1]['nonce']!==$requests[0]['nonce'],'client switch uses fresh exact provider and nonce');
$before=count($requests);
foreach ([[],['service_secret'=>''],['service_secret'=>str_repeat('A',64)]] as $bad) brand_check(tenant_brand_fetch('id','alpha',$bad,$transport)===null,'unset or malformed key defaults');
foreach ([null,'8west','internal','bad/slug'] as $provider) brand_check(tenant_brand_fetch('id',$provider,$config,$transport)===null,'unverified or house provider defaults');
brand_check(count($requests)===$before,'defaults make no remote call');
$request=$requests[0];
$reply=static function(array $value,int $status=200,?string $nonce=null)use($request,$key):array {
    $raw=json_encode($value,JSON_UNESCAPED_SLASHES);
    return ['status'=>$status,'body'=>$raw,'signature'=>tenant_brand_response_sign($key,'id',$status,$request['timestamp'],$nonce??$request['nonce'],$raw)];
};
$valid=['version'=>1,'provider_tenant_slug'=>'alpha','revision'=>1,'logo'=>$logo];
brand_check(tenant_brand_response($reply($valid),$request,$key)!==null,'strict valid response');
foreach (['version'=>'1','provider_tenant_slug'=>'beta','revision'=>'1','extra'=>'x'] as $keyName=>$value) {
    $bad=$valid;$bad[$keyName]=$value;brand_check(tenant_brand_response($reply($bad),$request,$key)===null,'invalid response field '.$keyName);
}
foreach (['mime'=>'image/svg+xml','bytes'=>strlen($bytes)+1,'width'=>65,'height'=>33,'sha256'=>str_repeat('0',64),
    'data_base64'=>base64_encode($bytes)."\n",'extra'=>'x'] as $keyName=>$value) {
    $bad=$valid;$bad['logo'][$keyName]=$value;brand_check(tenant_brand_response($reply($bad),$request,$key)===null,'invalid logo field '.$keyName);
}
$null=$valid;$null['revision']=3;$null['logo']=null;
brand_check(tenant_brand_response($reply($null),$request,$key)===null,'reset presents default immediately');
brand_check(tenant_brand_response($reply($valid,200,str_repeat('f',32)),$request,$key)===null,'old different nonce response refuses');
$bad=$reply($valid);$bad['body']=substr($bad['body'],0,-1).' ';
brand_check(tenant_brand_response($bad,$request,$key)===null,'tampered body refuses');
$raw=str_replace('{"version":1,','{"version":1,"version":1,',$reply($valid)['body']);
$duplicate=['status'=>200,'body'=>$raw,'signature'=>tenant_brand_response_sign($key,'id',200,$request['timestamp'],$request['nonce'],$raw)];
brand_check(tenant_brand_response($duplicate,$request,$key)===null,'signed duplicate top-level JSON refuses');
$raw=str_replace('"mime":"image/png",','"mime":"image/png","mime":"image/png",',$reply($valid)['body']);
$duplicate=['status'=>200,'body'=>$raw,'signature'=>tenant_brand_response_sign($key,'id',200,$request['timestamp'],$request['nonce'],$raw)];
brand_check(tenant_brand_response($duplicate,$request,$key)===null,'signed duplicate nested JSON refuses');
brand_check(tenant_brand_response($reply(['version'=>1,'error'=>'unavailable'],503),$request,$key)===null,'signed service error defaults');
brand_check(tenant_brand_response(['status'=>200,'body'=>str_repeat('x',2800001),'signature'=>str_repeat('a',64)],$request,$key)===null,'raw response cap enforced');
brand_check(tenant_brand_fetch('id','alpha',$config,static function(){throw new RuntimeException('timeout');})===null,'transport timeout defaults');
echo "$checks passed, 0 failed\n";
