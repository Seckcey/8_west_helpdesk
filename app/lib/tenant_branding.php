<?php
/** Branding wire v1. Only media routes call the remote transport; page rendering never does. */
declare(strict_types=1);

const TENANT_BRAND_API = 'https://support.8westit.com/api/svc/tenant-branding.php';
const TENANT_BRAND_PATH = '/api/svc/tenant-branding.php';
const TENANT_BRAND_MAX_RESPONSE = 2800000;

function tenant_brand_hex($value, int $length = 64): bool
{
    return is_string($value) && preg_match('/\A[a-f0-9]{'.$length.'}\z/D', $value) === 1;
}
function tenant_brand_slug($value): bool
{
    return is_string($value) && preg_match('/\A[a-z0-9][a-z0-9-]{0,62}\z/D', $value) === 1
        && !in_array($value, ['8west', 'internal'], true);
}
function tenant_brand_keys(array $value, array $expected): bool
{
    $actual = array_keys($value); sort($actual); sort($expected); return $actual === $expected;
}
/** Linear bounded scan catches duplicate keys that json_decode would silently collapse. */
function tenant_brand_json(string $raw, int $members): ?array
{
    $quoted = false; $escape = false; $count = 0;
    for ($i=0,$n=strlen($raw); $i<$n; ++$i) {
        $c=$raw[$i];
        if ($quoted) {
            if ($escape) $escape=false;
            elseif ($c==='\\') $escape=true;
            elseif ($c==='"') $quoted=false;
        } elseif ($c==='"') $quoted=true;
        elseif ($c===':') ++$count;
    }
    if ($count!==$members) return null;
    try { $value=json_decode($raw,true,8,JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { return null; }
    return is_array($value) && !array_is_list($value) ? $value : null;
}
function tenant_brand_request_sign(string $key,string $app,string $timestamp,string $nonce,string $raw): string
{
    return hash_hmac('sha256',implode("\n",['8west-msp-brand-v1','request',$app,'POST',TENANT_BRAND_PATH,
        $timestamp,$nonce,hash('sha256',$raw)]),$key);
}
function tenant_brand_response_sign(string $key,string $app,int $status,string $timestamp,string $nonce,string $raw): string
{
    return hash_hmac('sha256',implode("\n",['8west-msp-brand-v1','response',$app,(string)$status,
        $timestamp,$nonce,hash('sha256',$raw)]),$key);
}
/** Verify signed context, exact shape, canonical base64 and actual detected raster bytes. */
function tenant_brand_response(array $reply,array $request,string $key): ?array
{
    $raw=$reply['body']??null; $status=$reply['status']??null; $signature=$reply['signature']??null;
    if (!is_string($raw) || strlen($raw)>TENANT_BRAND_MAX_RESPONSE || !is_int($status)
        || !tenant_brand_hex($signature)
        || !hash_equals(tenant_brand_response_sign($key,$request['app'],$status,$request['timestamp'],$request['nonce'],$raw),$signature)) return null;
    if ($status!==200) {
        // Even errors are authenticated and strictly shaped; every failure presents the fixed default.
        $error=tenant_brand_json($raw,2);
        if ($error===null || !tenant_brand_keys($error,['version','error']) || $error['version']!==1) return null;
        return null;
    }
    $value=tenant_brand_json($raw,4);
    if ($value===null) $value=tenant_brand_json($raw,10);
    if ($value===null || !tenant_brand_keys($value,['version','provider_tenant_slug','revision','logo'])
        || $value['version']!==1 || $value['provider_tenant_slug']!==$request['provider']
        || !is_int($value['revision']) || $value['revision']<0 || $value['revision']>9007199254740991) return null;
    if ($value['logo']===null) return null;
    $logo=$value['logo'];
    if (!is_array($logo) || !tenant_brand_keys($logo,['mime','bytes','width','height','sha256','data_base64'])
        || !is_int($logo['bytes']) || $logo['bytes']<1 || $logo['bytes']>2097152
        || !is_int($logo['width']) || !is_int($logo['height'])
        || !tenant_brand_hex($logo['sha256']) || !is_string($logo['data_base64'])
        || strlen($logo['data_base64'])>2796204) return null;
    $bytes=base64_decode($logo['data_base64'],true);
    if (!is_string($bytes) || strlen($bytes)!==$logo['bytes'] || base64_encode($bytes)!==$logo['data_base64']
        || !hash_equals(hash('sha256',$bytes),$logo['sha256'])) return null;
    $image=@getimagesizefromstring($bytes);
    $types=[IMAGETYPE_PNG=>'image/png',IMAGETYPE_JPEG=>'image/jpeg',IMAGETYPE_WEBP=>'image/webp'];
    if (!is_array($image) || ($types[$image[2]]??null)!==$logo['mime']
        || (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)!==$logo['mime']
        || $image[0]!==$logo['width'] || $image[1]!==$logo['height']
        || $image[0]<64 || $image[1]<32 || $image[0]>2400 || $image[1]>1200) return null;
    return ['mime'=>$logo['mime'],'bytes'=>$bytes,'revision'=>$value['revision']];
}
/** Fixed HTTPS transport with streaming cap, TLS verification, zero redirects and a two-second budget. */
function tenant_brand_transport(array $request): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('Branding transport unavailable.');
    $body=''; $signature=null; $signatureCount=0;
    $handle=curl_init(TENANT_BRAND_API);
    if ($handle===false) throw new RuntimeException('Branding transport unavailable.');
    try {
        curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$request['body'],
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json',
                'X-8W-Brand-App: '.$request['app'],'X-8W-Brand-Timestamp: '.$request['timestamp'],
                'X-8W-Brand-Nonce: '.$request['nonce'],'X-8W-Brand-Signature: '.$request['signature']],
            CURLOPT_CONNECTTIMEOUT_MS=>1000,CURLOPT_TIMEOUT_MS=>2000,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body): int {
                if (strlen($body)+strlen($chunk)>TENANT_BRAND_MAX_RESPONSE) return 0;
                $body.=$chunk; return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use (&$signature,&$signatureCount): int {
                if (stripos($line,'X-8W-Brand-Signature:')===0) { ++$signatureCount; $signature=trim(substr($line,21)); }
                return strlen($line);
            }]);
        if (curl_exec($handle)===false || $signatureCount!==1) throw new RuntimeException('Branding response unavailable.');
        return ['status'=>(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE),'body'=>$body,'signature'=>$signature];
    } finally { curl_close($handle); }
}
/** Called once by an independently authenticated media request. No persistent or browser cache. */
function tenant_brand_fetch(string $app,?string $provider,array $config,?callable $transport=null): ?array
{
    $key=$config['service_secret']??null;
    if (!in_array($app,['id','safeharbor','coastmark'],true) || !tenant_brand_slug($provider) || !tenant_brand_hex($key)) return null;
    $request=['app'=>$app,'provider'=>$provider,'timestamp'=>(string)time(),'nonce'=>bin2hex(random_bytes(16)),
        'body'=>json_encode(['version'=>1,'provider_tenant_slug'=>$provider],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
    $request['signature']=tenant_brand_request_sign($key,$app,$request['timestamp'],$request['nonce'],$request['body']);
    try { return tenant_brand_response(($transport??'tenant_brand_transport')($request),$request,$key); }
    catch (Throwable $error) { return null; }
}

/** The selected binding is the only provider source; identity tenant/email are never substitutes. */
function safeharbor_tenant_brand_provider(?array $context): ?string
{
    if ($context===null || !is_array($context['binding']??null)) return null;
    $slug=$context['binding']['provider_tenant_slug']??null;
    return tenant_brand_slug($slug) ? $slug : null;
}
function safeharbor_tenant_brand_default($surface): string
{
    $pair=$_SESSION['desktop_companion_session']??null;
    $companion=(is_string($pair)&&preg_match('/\A[a-f0-9]{32}\z/D',$pair)===1) || $surface==='companion';
    return $companion ? '/assets/brand/westy-companion/westy-horizontal-logo-v1.png' : '/assets/brand/8-west-it-westy.png';
}
