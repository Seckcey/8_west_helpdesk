<?php
/** Staff sign-in presentation with stubbed identity; no real account, session or provider. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../lib/client_operational_context.php';
function enforce_https(): void {}
function current_user(): ?array { return null; }
function suite_sso_attempt(): bool { return false; }
function suite_sso_claims(): ?array { return null; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="synthetic-csrf">'; }
function csrf_check(): void { $GLOBALS['csrfCalls']++; }
function attempt_login(string $email, string $password): bool { $GLOBALS['loginCalls']++; return false; }
function cfg(string $key, mixed $default = null): mixed { return $default; }
function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function signin_display(string $state): string {
    $_GET=$state==='reauth'?['reauth'=>'1']:['next'=>'/ticket.php?id=1042'];
    $_POST=$state==='failed'?['email'=>'teammate@example.test','password'=>'synthetic-input','csrf'=>'synthetic-csrf','next'=>'/ticket.php?id=1042']:[];
    $_SERVER['REQUEST_METHOD']=$state==='failed'?'POST':'GET';
    $GLOBALS['csrfCalls']=0; $GLOBALS['loginCalls']=0;
    $source=preg_replace('/^require_once .*;\R/m','',(string)file_get_contents(__DIR__.'/../public/login.php'));
    ob_start(); try { eval(substr($source,5)); return (string)ob_get_contents(); } finally { ob_end_clean(); }
}
$checks=0;
function signin_check(bool $pass,string $message): void { $GLOBALS['checks']++; if (!$pass) throw new RuntimeException($message); }
$capture=getenv('SIGNIN_DISPLAY_CAPTURE_DIR');
if ($capture&&!is_dir($capture)) mkdir($capture,0777,true);
foreach (['guest','failed','reauth'] as $state) {
    $html=signin_display($state); $doc=new DOMDocument(); libxml_use_internal_errors(true); $doc->loadHTML('<?xml encoding="UTF-8">'.$html); libxml_clear_errors(); $xp=new DOMXPath($doc);
    signin_check($xp->query('//details[contains(@class,"login-backup") and @open]')->length===(int)($state==='failed'), "$state disclosure opens only after failed password submission");
    signin_check($xp->query('//details//form[@method="post" and @action="/login.php"]')->length===1,'Existing form method and route retained');
    signin_check($xp->query('//input[@name="csrf" and @value="synthetic-csrf"]')->length===1,'Existing CSRF field retained');
    signin_check($xp->query('//input[@name="next"]')->length===1,'Existing next field retained');
    signin_check($xp->query('//input[@name="password" and @required and not(@placeholder)]')->length===1,'Password remains required without bullet placeholder');
    signin_check($xp->query('//h1/img[@alt="Safeharbor — 8 West IT 365"]')->length===1,'Logo supplies the brand heading and alt fallback without repeated text');
    signin_check($xp->query('//p[@class="login-foot"]')->length===1,'One footer');
    signin_check($GLOBALS['loginCalls']===(int)($state==='failed')&&$GLOBALS['csrfCalls']===(int)($state==='failed'),'Disclosure changes no login or CSRF call');
    if ($state==='failed') {
        signin_check(str_contains($html,'That email and password didn’t match. Try again.'),'Failure wording unchanged');
        signin_check($xp->query('//input[@name="email" and @value="teammate@example.test"]')->length===1,'Failure retains email');
        signin_check($xp->query('//input[@name="next" and @value="/ticket.php?id=1042"]')->length===1,'Failure retains safe next');
    }
    if ($capture) file_put_contents($capture.'/'.$state.'.html',$html);
}
echo "PASS $checks sign-in display checks\n";
