<?php
/** Westy tenant AI protocol v1. Pure helpers; never log arguments or credentials. */
declare(strict_types=1);

const WESTY_TENANT_AI_VERSION = 1;
const WESTY_TENANT_AI_CATALOG = '2026-10-04.1';
const WESTY_TENANT_AI_URL = 'https://id.8westit.com/api/svc/tenant-ai.php';
const WESTY_TENANT_AI_APPS = ['milepost', 'safeharbor', 'coastmark', 'logbook', 'coastline_control_panel'];

/** Reviewed standard-tier rates in micro-USD per million tokens; not an invoice. */
function westy_tenant_ai_catalog(): array
{
    $common = ['max_input_tokens'=>200000, 'max_output_tokens'=>16000,
        'capabilities'=>['text','structured','tools','stream','image','pdf'],
        // This adapter exposes our typed functions plus images. It never enables a
        // vendor's native computer-use tool or selects another model to obtain it.
        'computer_use'=>['typed_functions_with_images'=>true,'native_api'=>false]];
    return [
        'openai'=>['label'=>'OpenAI','models'=>[
            'gpt-6-luna'=>$common + ['label'=>'GPT-6 Luna','efforts'=>['low','medium','high','xhigh','max'],
                'default_effort'=>'low','rates'=>['input'=>100000,'cached_input'=>10000,'cache_write'=>125000,'cache_write_1h'=>0,'output'=>500000]],
            'gpt-6-astra'=>$common + ['label'=>'GPT-6 Astra','efforts'=>['low','medium','high','xhigh','max'],
                'default_effort'=>'medium','rates'=>['input'=>10000000,'cached_input'=>1000000,'cache_write'=>12500000,'cache_write_1h'=>0,'output'=>50000000]],
        ]],
        'anthropic'=>['label'=>'Claude (Anthropic)','models'=>[
            // Reserve the full output allowance inside Haiku's 200k total context.
            'claude-haiku-4-5-20251001'=>['max_input_tokens'=>184000] + $common + ['label'=>'Claude Haiku 4.5','efforts'=>[''],
                'default_effort'=>'','rates'=>['input'=>1000000,'cached_input'=>100000,'cache_write'=>1250000,'cache_write_1h'=>2000000,'output'=>5000000]],
            'claude-sonnet-5-5'=>$common + ['label'=>'Claude Sonnet 5.5','efforts'=>['low','medium','high'],
                'default_effort'=>'medium','rates'=>['input'=>2000000,'cached_input'=>200000,'cache_write'=>2500000,'cache_write_1h'=>4000000,'output'=>10000000]],
            'claude-opus-5-5'=>$common + ['label'=>'Claude Opus 5.5','efforts'=>['low','medium','high'],
                'default_effort'=>'medium','rates'=>['input'=>4000000,'cached_input'=>200000,'cache_write'=>5000000,'cache_write_1h'=>8000000,'output'=>20000000]],
        ]],
    ];
}

function westy_tenant_ai_selection(mixed $provider, mixed $model, mixed $effort): array
{
    if (!is_string($provider) || !is_string($model) || !is_string($effort)) {
        throw new InvalidArgumentException('invalid_selection');
    }
    $entry = westy_tenant_ai_catalog()[$provider]['models'][$model] ?? null;
    if (!is_array($entry) || !in_array($effort,$entry['efforts'],true)) {
        throw new InvalidArgumentException('invalid_selection');
    }
    return ['provider'=>$provider,'model'=>$model,'effort'=>$effort,'catalog'=>WESTY_TENANT_AI_CATALOG];
}

function westy_tenant_ai_local_key(mixed $key): bool
{
    return is_string($key) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D',$key) === 1;
}

function westy_tenant_ai_api_key(string $provider, mixed $key): bool
{
    if (!is_string($key) || strlen($key)<20 || strlen($key)>512 || preg_match('/[^\x21-\x7e]/',$key)) return false;
    return match ($provider) {
        'openai'=>str_starts_with($key,'sk-') && !str_starts_with($key,'sk-ant-'),
        'anthropic'=>str_starts_with($key,'sk-ant-api'),
        default=>false,
    };
}

function westy_tenant_ai_request(string $app, string $localKey, string $action, ?int $revision = null): array
{
    if (!in_array($app,WESTY_TENANT_AI_APPS,true) || !westy_tenant_ai_local_key($localKey)
        || !in_array($action,['status','resolve'],true) || ($revision !== null && $revision<0)) {
        throw new InvalidArgumentException('invalid_request');
    }
    return ['version'=>1,'app'=>$app,'local_tenant_key'=>$localKey,'action'=>$action,'expected_revision'=>$revision];
}

function westy_tenant_ai_signature(string $direction, string $timestamp, string $nonce, string $body, string $secret, int $status = 0): string
{
    if (!in_array($direction,['request','response'],true) || preg_match('/\A[0-9a-f]{64}\z/D',$secret)!==1) {
        throw new InvalidArgumentException('invalid_service_configuration');
    }
    return hash_hmac('sha256',"8west-tenant-ai-v1\n".$direction."\nPOST\n/api/svc/tenant-ai.php\n"
        .$timestamp."\n".$nonce."\n".$status."\n".hash('sha256',$body),hex2bin($secret));
}

function westy_tenant_ai_valid_signature(string $signature, string $direction, string $timestamp, string $nonce,
    string $body, string $secret, int $status = 0, ?int $now = null): bool
{
    if (preg_match('/\A[0-9]{10}\z/D',$timestamp)!==1 || abs(($now ?? time())-(int)$timestamp)>60
        || preg_match('/\A[0-9a-f]{32}\z/D',$nonce)!==1 || preg_match('/\A[0-9a-f]{64}\z/D',$signature)!==1) return false;
    return hash_equals(westy_tenant_ai_signature($direction,$timestamp,$nonce,$body,$secret,$status),$signature);
}

/** A conservative bound: unknown/partial usage keeps the full reservation. */
function westy_tenant_ai_reserve(array $selection, int $inputBound, int $outputBound, int $rounds = 1): int
{
    westy_tenant_ai_selection($selection['provider']??null,$selection['model']??null,$selection['effort']??null);
    $model=westy_tenant_ai_catalog()[$selection['provider']]['models'][$selection['model']];
    if ($inputBound<0 || $inputBound>$model['max_input_tokens'] || $outputBound<1
        || $outputBound>$model['max_output_tokens'] || $rounds<1 || $rounds>8) throw new InvalidArgumentException('invalid_budget');
    return (int)ceil(($inputBound*max($model['rates']['input'],$model['rates']['cache_write'],$model['rates']['cache_write_1h'])
        +$outputBound*$model['rates']['output'])*$rounds/1000000);
}

function westy_tenant_ai_cost(array $selection, array $usage): ?int
{
    westy_tenant_ai_selection($selection['provider']??null,$selection['model']??null,$selection['effort']??null);
    $rates=westy_tenant_ai_catalog()[$selection['provider']]['models'][$selection['model']]['rates'];
    $total=0;
    foreach (['input','cached_input','cache_write','cache_write_1h','output'] as $kind) {
        if (!is_int($usage[$kind]??null) || $usage[$kind]<0 || $usage[$kind]>2000000) return null;
    }
    if ($selection['provider']==='openai' && $usage['cache_write_1h']!==0) return null;
    $input=$usage['input']+$usage['cached_input']+$usage['cache_write']+$usage['cache_write_1h'];
    // Retain accurate receipts even if a caller violated its smaller input budget.
    $long=$selection['provider']==='openai' && $input>272000;
    foreach($rates as $kind=>$rate)$total += $usage[$kind]*$rate*($long?($kind==='output'?1.5:2):1);
    return (int)ceil($total/1000000);
}
