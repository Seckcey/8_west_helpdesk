<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$configPath = __DIR__.'/../config/config.php';
if (file_exists($configPath)) { fwrite(STDERR, "Run only in a disposable checkout without config.php.\n"); exit(1); }
$path = tempnam(sys_get_temp_dir(), 'safeharbor-wif-');
file_put_contents($configPath, '<?php return [];');
register_shutdown_function(static function () use ($configPath, $path): void { unlink($configPath); unlink($path); });
require_once __DIR__.'/../lib/ai.php';
$checks = 0;
function af_check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
$token = 'sk-ant-oat01-'.str_repeat('synthetic', 5);
$document = ['version'=>1, 'app'=>'safeharbor', 'environment'=>'testing', 'token_type'=>'Bearer', 'access_token'=>$token, 'expires_at'=>time()+600];
file_put_contents($path, json_encode($document)); chmod($path, 0640);
$GLOBALS['CONFIG']['ai'] = ['provider'=>'anthropic', 'auth_mode'=>'federation', 'api_key'=>'', 'credential_file'=>$path, 'credential_app'=>'safeharbor', 'credential_environment'=>'testing'];
af_check(ai_enabled(), 'keyless readiness');
$GLOBALS['CONFIG']['ai']['api_key'] = 'old-key';
af_check(!ai_enabled(), 'no static fallback');
$GLOBALS['CONFIG']['ai']['api_key'] = '';
$GLOBALS['CONFIG']['ai']['provider'] = 'openai';
af_check(!ai_enabled(), 'provider cannot bypass federation');
$GLOBALS['CONFIG']['ai']['provider'] = 'anthropic';
$document['expires_at'] = time()-1;
file_put_contents($path, json_encode($document));
af_check(!ai_enabled(), 'expired credential');
af_check(ai_provider_complete('system', 'user', [])['ok'] === false, 'expired transport refused');
af_check(!str_contains(ai_parse_anthropic(401, ['error'=>['message'=>$token]])['error'], $token), 'redacted provider failure');
echo "Safeharbor federation: $checks checks passed\n";
