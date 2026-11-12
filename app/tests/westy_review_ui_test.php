<?php
/** Real GET controllers with synthetic readers. No POST action or network is executed. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $level, $file, $line); });
function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
require_once __DIR__ . '/../lib/westy_review_ui.php';
function enforce_https(): void {}
function require_login(): array { return ['id'=>7,'tenant_id'=>1,'role'=>$GLOBALS['case']['role']]; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="synthetic-csrf">'; }
function cfg(string $key, mixed $default=null): mixed { return $key==='westy_mail.enabled' ? $GLOBALS['case']['mail'] : $default; }
function westy_email_enabled(): bool { return $GLOBALS['case']['email_enabled'] ?? $GLOBALS['case']['enabled']; }
function westy_mail_enabled(): bool { return $GLOBALS['case']['enabled']; }
function westy_email_review_hash(array $row): string { return 'synthetic-legacy-review'; }
function westy_mail_review_hash(array $row): string { return 'synthetic-mail-review'; }
function palette_data(): array { return []; }
function page_top(array $user, string $title, string $active): void {
    echo '<!doctype html><html lang="en-US" data-theme="light" data-preferences-source="8west-id" data-time-zone="America/Los_Angeles" data-time-format="24h"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.h($title).'</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main>';
}
function page_bottom(array $palette): void { echo '</main><script src="/assets/js/app.js"></script></body></html>'; }
function westy_email_context(mixed $db, int $tenant, int $ticket, int $actor): array {
    if ($GLOBALS['case']['unavailable']) throw new RuntimeException('Synthetic unavailable');
    return ['ticket'=>['client_id'=>10,'subject'=>'Check the computer connection'], 'contact'=>['name'=>'Example Contact','email'=>'contact@example.test'], 'route'=>'endpoint','fingerprint'=>'synthetic-context'];
}
function westy_mail_context(mixed $db, int $tenant, int $ticket, int $actor): array {
    return westy_email_context($db,$tenant,$ticket,$actor)+['graph'=>['sender'=>'help@example.test'],'w'=>['state'=>$GLOBALS['case']['owned']?'human_owned':'ai_owned']];
}
function westy_email_template(array $context): array { return ['subject'=>'Connection advice','body'=>"Hello Example Contact,\n\nPlease check that the cable is connected.\n\nReply if you still need help."]; }
function westy_mail_template(): array { return ['body'=>'Thanks for the update. A teammate will review your reply.']; }
function westy_mail_row(mixed $db, string $sql, array $params): array|false { return fixture_rows($sql)[0]??false; }
final class ReviewQuery {
    public function __construct(private string $sql) {}
    public function execute(array $params): void {}
    public function fetchAll(): array { return fixture_rows($this->sql); }
}
final class ReviewDb {
    private bool $transaction=false;
    public function beginTransaction(): void { $this->transaction=true; }
    public function rollBack(): void { $this->transaction=false; }
    public function inTransaction(): bool { return $this->transaction; }
    public function prepare(string $sql): ReviewQuery { return new ReviewQuery($sql); }
}
function db(): ReviewDb { static $db; return $db??=new ReviewDb(); }
function fixture_rows(string $sql): array {
    $case=$GLOBALS['case'];
    preg_match('/\bFROM\s+([a-z_]+)/i',$sql,$table); $table=$table[1]??'';
    if ($table==='contacts') return [['id'=>11,'name'=>'Example Contact','email'=>'contact@example.test']];
    if ($table==='westy_email_drafts') return $case['legacy'] ? [['id'=>41,'state'=>$case['legacy'],'recipient'=>'contact@example.test','subject'=>'Connection advice','body_text'=>'Saved advice <script>invalid()</script>', 'attempted_at'=>$case['attempted']?'2026-01-15 18:05:00':null,'approved_at'=>$case['attempted']?'2026-01-15 18:04:00':null,'approved_by'=>$case['actor_id'],'provider_http'=>404]] : [];
    if ($table==='westy_mail_conversations') return $case['state'] ? [['id'=>51,'state'=>$case['state'],'sender'=>'help@example.test','recipient'=>'contact@example.test','subject'=>'Connection advice','body_text'=>"Hello Example Contact,\n\nPlease check the cable.\nReply if you still need help.",'attempted_at'=>in_array($case['state'],['draft','approved'],true)?null:'2026-01-15 18:05:00','recipient_alias_json'=>'{"address":"alias@example.test"}']] : [];
    if ($table==='westy_mail_reconciliations') return $case['reconciled'] ? [['id'=>61,'actor_id'=>$case['actor_id'],'created_at'=>'2026-01-15 18:10:00']] : [];
    if ($table==='westy_mail_delegations') return $case['history'] ? [['expires_at'=>'2026-01-22 18:05:00','revoked_reason'=>null]] : [];
    if ($table==='westy_mail_inbound_receipts') return $case['history'] ? [['id'=>71,'received_at'=>'2026-01-15 18:06:00','disposition'=>'verified']] : [];
    if ($table==='westy_mail_auto_attempts') return $case['history'] ? [['state'=>'unknown','attempted_at'=>'2026-01-15 18:07:00']] : [];
    if ($table==='westy_mail_attention_events') return $case['history'] ? [['reason'=>'auto_ack_authority_unavailable','created_at'=>'2026-01-15 18:08:00']] : [];
    if ($table==='westy_mail_sent_reconciliations') return $case['history'] ? [['owner_kind'=>'conversation','created_at'=>'2026-01-15 18:09:00']] : [];
    throw new RuntimeException('Unexpected fixture query');
}
function review_page(string $route, array $overrides=[]): string {
    $GLOBALS['case']=array_replace(['role'=>'admin','enabled'=>true,'email_enabled'=>null,'actor_id'=>7,'mail'=>true,'owned'=>true,'state'=>false,'legacy'=>false,'attempted'=>false,'reconciled'=>false,'history'=>false,'unavailable'=>false],$overrides);
    $_GET=['ticket_id'=>1042]; $_POST=[]; $_SERVER['REQUEST_METHOD']='GET'; $_SESSION=[];
    $source=(string)file_get_contents(__DIR__.'/../public/'.$route.'.php');
    $source=preg_replace('/^require_once .*;\R/m','',$source);
    $source=preg_replace('/^function (we_input|wm_input).*\R/m','',$source);
    $source=preg_replace('/^function wm_review\([\s\S]*?^\}\R/m','',$source);
    ob_start(); try { eval(substr($source,5)); return (string)ob_get_contents(); } finally { ob_end_clean(); }
}
function review_xpath(string $html): DOMXPath { $doc=new DOMDocument(); libxml_use_internal_errors(true); $doc->loadHTML('<?xml encoding="UTF-8">'.$html); libxml_clear_errors(); return new DOMXPath($doc); }
$checks=0;
function review_check(bool $pass,string $message): void { $GLOBALS['checks']++; if (!$pass) throw new RuntimeException($message); }
function review_forms(DOMXPath $xp,string $action): int { return $xp->query('//form[.//*[@name="action" and @value="'.$action.'"]]')->length; }
foreach (['owner','admin','tech','viewer'] as $role) foreach ([true,false] as $enabled) foreach (['draft','approved','claimed','submitted','rejected','unknown','revoked'] as $state) {
    $html=review_page('westy_mail',compact('role','enabled','state')); $xp=review_xpath($html); $admin=in_array($role,['owner','admin'],true);
    review_check(review_forms($xp,'approve_send')===(int)($admin&&$enabled&&$state==='draft'), "$role/$enabled/$state approve gate");
    review_check(review_forms($xp,'send_approved')===(int)($admin&&$enabled&&$state==='approved'), "$role/$enabled/$state resume gate");
    review_check(review_forms($xp,'revoke')===(int)$admin, "$role/$enabled/$state stop gate, including disabled email");
    review_check($xp->query('//textarea')->length===0, 'Saved message is never editable');
    review_check($xp->query('//details[@open]')->length===0, 'History closed by default');
    review_check($xp->query('//form[not(.//input[@name="csrf"])]')->length===0, 'Every form retains CSRF');
    if ($admin&&$enabled&&in_array($state,['draft','approved'],true)) {
        review_check(isset($_SESSION['westy_mail_review'][51])&&$_SESSION['westy_mail_review'][51]['hash']==='synthetic-mail-review', 'Fresh saved-message review retained');
        review_check($xp->query('//input[@name="conversation" and @value="51"]')->length===2&&$xp->query('//input[@name="review"]')->length===1, 'Review identifiers retained');
    }
    if ($admin&&$enabled&&$state==='draft') review_check($xp->query('//input[@name="confirm_send" and @value="yes" and @required]')->length===1&&$xp->query('//input[@name="delegate" and @value="yes"]')->length===1, 'Both approval checkboxes retained');
}
foreach ([true,false] as $owned) {
    $xp=review_xpath(review_page('westy_mail',['state'=>'draft','owned'=>$owned]));
    review_check($xp->query('//input[@name="delegate" and @disabled]')->length===(int)!$owned, 'Acknowledgment requires existing human ownership');
}
foreach (['owner','admin','tech','viewer'] as $role) foreach ([true,false] as $enabled) foreach ([true,false] as $mail) {
    $html=review_page('westy_email',['role'=>$role,'enabled'=>$enabled,'mail'=>$mail,'legacy'=>'draft']); $xp=review_xpath($html); $admin=in_array($role,['owner','admin'],true);
    review_check(review_forms($xp,'contact')===(int)($admin&&$enabled),'Legacy contact gate');
    review_check(review_forms($xp,'draft')===(int)($enabled&&!$mail),'Legacy preparation gate');
    review_check(review_forms($xp,'send')===(int)($admin&&!$mail),'Legacy review gate');
    if ($admin&&!$mail) {
        review_check($xp->query('//button[@value="send" and @disabled]')->length===(int)!$enabled,'Legacy disabled send');
        review_check($xp->query('//input[@name="draft_id" and @value="41"]')->length===1&&$xp->query('//input[@name="review"]')->length===1,'Legacy review identifiers');
        review_check($_SESSION['westy_email_review'][41]['hash']==='synthetic-legacy-review','Legacy fresh review hash');
        review_check($xp->query('//input[@name="confirm_send" and @value="yes"]')->length===1,'Legacy confirmation kept');
    }
    review_check(!str_contains($html,'<script>invalid()'),'Stored text escaped');
}
foreach (['admin','tech'] as $role) foreach ([true,false] as $enabled) foreach ([true,false] as $reconciled) {
    $xp=review_xpath(review_page('westy_mail',compact('role','enabled','reconciled')+['legacy'=>'uncertain','attempted'=>true]));
    review_check(review_forms($xp,'reconcile')===(int)($role==='admin'&&$enabled&&!$reconciled),'Legacy reconciliation gate');
    review_check(review_forms($xp,'draft')===(int)($role==='admin'&&$enabled&&$reconciled),'Replacement preparation gate');
    if ($role==='admin'&&$enabled&&!$reconciled) review_check($xp->query('//input[@name="confirm_reconcile" and @value="yes" and @required]')->length===1&&$xp->query('//input[@name="old_draft" and @value="41"]')->length===1,'Reconciliation confirmation and original ID');
}
foreach (['owner','admin','tech','viewer'] as $role) foreach ([true,false] as $enabled) foreach ([true,false] as $email_enabled) {
    $xp=review_xpath(review_page('westy_mail',compact('role','enabled','email_enabled')));
    review_check($xp->query('//a[contains(@href,"#westy-contact")]')->length===(int)(in_array($role,['owner','admin'],true)&&$email_enabled), 'Contact link matches the destination form gate independently of mailbox transport');
}
foreach ([7,null] as $actor_id) {
    foreach (['draft','uncertain'] as $legacy) {
        $html=review_page('westy_email',compact('actor_id','legacy')+['attempted'=>true]);
        review_check(str_contains($html,$actor_id===null?'No approver was recorded.':'Approved by staff #7'), 'Both legacy attempted paragraphs retain recorded attribution');
        review_check(!str_contains($html,$actor_id===null?'Approved by staff #':'No approver was recorded.'), 'Missing-approver wording only when ID is absent');
    }
    $html=review_page('westy_mail',compact('actor_id')+['reconciled'=>true,'legacy'=>'uncertain','attempted'=>true]);
    review_check(str_contains($html,$actor_id===null?'No reviewer was recorded.':'reviewed by staff #7'), 'Reconciliation retains recorded reviewer or states missing ID');
    review_check(!str_contains($html,$actor_id===null?'reviewed by staff #':'No reviewer was recorded.'), 'Missing-reviewer wording only when ID is absent');
}
$html=review_page('westy_email',['legacy'=>'uncertain','attempted'=>true]); $xp=review_xpath($html);
$history=$xp->query('//details[contains(@class,"westy-review-history")]')->item(0);
review_check($history!==null&&str_contains($history->textContent,'Saved advice <script>invalid()</script>'), 'History preserves stored text literally');
review_check($xp->query('//details[contains(@class,"westy-review-history")]//script')->length===0&&str_contains($html,'Saved advice &lt;script&gt;invalid()&lt;/script&gt;'), 'History escapes stored markup rather than creating script elements');
foreach (['westy_email','westy_mail'] as $route) {
    $html=review_page($route,['unavailable'=>true]);
    review_check(str_contains($html,'role="alert"')&&!str_contains($html,'<form'),'Unavailable context shows error without actions');
}
$html=review_page('westy_mail',['state'=>'unknown','history'=>true]); $xp=review_xpath($html);
review_check($xp->query('//*[@role="alert" and not(ancestor::details)]')->length===1,'Follow-up warning remains visible outside History');
review_check(str_contains($html,'Result unknown — do not resend')&&str_contains($html,'does not prove inbox receipt'),'Unknown result and provider evidence stay honest');
review_check(str_contains($html,'datetime="2026-01-15T18:05:00Z"'),'History exposes UTC instant for display localization');
review_check(westy_review_time(null)==='Not recorded'&&westy_review_time('not-a-date')==='Time unavailable','Absent and malformed timestamps are not invented');
$captures=['mail-prepare'=>['westy_mail',[]], 'mail-draft'=>['westy_mail',['state'=>'draft']], 'mail-approved'=>['westy_mail',['state'=>'approved']], 'mail-history'=>['westy_mail',['state'=>'unknown','history'=>true]], 'mail-viewer'=>['westy_mail',['state'=>'draft','role'=>'viewer']], 'mail-disabled'=>['westy_mail',['state'=>'draft','enabled'=>false]], 'legacy-draft'=>['westy_email',['legacy'=>'draft','mail'=>false]], 'legacy-history'=>['westy_email',['legacy'=>'uncertain','attempted'=>true]], 'mail-reconcile'=>['westy_mail',['legacy'=>'uncertain','attempted'=>true]]];
$capture=getenv('WESTY_REVIEW_CAPTURE_DIR');
if ($capture) { if (!is_dir($capture)&&!mkdir($capture,0777,true)) throw new RuntimeException('Capture directory unavailable'); foreach ($captures as $name=>[$route,$case]) file_put_contents($capture.'/'.$name.'.html',review_page($route,$case)); }
echo "PASS $checks Westy review rendering checks\n";
