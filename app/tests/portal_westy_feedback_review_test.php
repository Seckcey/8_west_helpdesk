<?php
/** Closed review requests and scoped retrieval projections without a provider or server. */
declare(strict_types=1);
require_once __DIR__.'/../lib/portal_westy_feedback.php';

$checks=0;
function check(bool $ok,string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    echo 'ok '.++$checks.' - '.$label."\n";
}
$health=['contract'=>PORTAL_FEEDBACK_REVIEW_V2,'action'=>'help_health'];
check(portal_feedback_review_request($health)===$health+['period'=>7],'health defaults to seven days');
foreach ([7,30,90] as $period) {
    $request=$health+['period'=>$period];
    check(portal_feedback_review_request($request)===$request,'accepts integer period '.$period);
}
$list=['filters'=>array_fill_keys(['model','model_version','task','outcome','reaction','from','to'],''),
    'sort'=>'date_desc','page'=>1,'page_size'=>50];
foreach ([PORTAL_FEEDBACK_REVIEW_CONTRACT,PORTAL_FEEDBACK_REVIEW_V2] as $contract) {
    $request=['contract'=>$contract]+$list;
    check(portal_feedback_review_request($request)===$request+['action'=>'list'],'default list for '.$contract);
}
$invalid=[];
foreach ([0,1,8,91,'7',7.0,null,true,[]] as $period) $invalid[]=$health+['period'=>$period];
foreach (['page'=>1,'filters'=>[],'sort'=>'date_desc','customer_ids'=>[],'tenant_id'=>1] as $key=>$value) $invalid[]=$health+[$key=>$value];
$invalid[]=['contract'=>PORTAL_FEEDBACK_REVIEW_CONTRACT,'action'=>'help_health']+$list;
$invalid[]=['contract'=>'unknown','action'=>'help_health'];
$invalid[]=['contract'=>PORTAL_FEEDBACK_REVIEW_V2,'period'=>7]+$list;
$invalid[]=['contract'=>PORTAL_FEEDBACK_REVIEW_V2,'action'=>'export','page'=>2]+$list;
foreach ($invalid as $request) {
    try { portal_feedback_review_request($request); throw new RuntimeException('invalid request accepted'); }
    catch (PortalFeedbackReviewException $e) { check($e->reason==='invalid_request' && $e->status===400,'closed request refused'); }
}

$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE portal_westy_turns (id INTEGER,tenant_id INTEGER,client_id INTEGER);
    CREATE TABLE portal_westy_help_events (id INTEGER PRIMARY KEY,tenant_id INTEGER,client_id INTEGER,turn_id INTEGER,
        kind TEXT,article_id TEXT,expires_at TEXT);
    INSERT INTO portal_westy_turns VALUES (1,1,11),(2,1,11);
    INSERT INTO portal_westy_help_events VALUES
        (1,1,11,1,'cited','z','2099'),(2,1,11,1,'cited','a','2099'),(3,1,11,1,'cited','z','2099'),
        (4,1,12,1,'cited','wrong-client','2099'),(5,2,11,1,'cited','wrong-tenant','2099'),
        (6,1,11,1,'cited','expired','2000'),(7,1,11,NULL,'gap',NULL,'2099'),
        (8,1,11,2,'gap',NULL,'2099')");
$expect=[1=>['sources'=>['z','a'],'help_gap'=>false],2=>['sources'=>[],'help_gap'=>true]];
check(portal_feedback_review_turn_help($pdo,1,[['turn_id'=>1],['turn_id'=>2],['turn_id'=>1]])===$expect,
    'only correctly scoped, retained events join a turn, in first-cited order');
check(portal_feedback_review_turn_help($pdo,1,[['turn_id'=>null]])===[],'detached events cannot enrich a missing turn');
check(portal_feedback_review_help_health($pdo,1,[],7)===['articles'=>[],'gaps'=>[]],'empty scope returns the closed health shape');

// Give SQLite a case-insensitive question column to exercise byte-exact grouping independent of the default collation.
$pdo->exec("ALTER TABLE portal_westy_turns ADD COLUMN scope_key TEXT DEFAULT 'owned';
    ALTER TABLE portal_westy_turns ADD COLUMN input_text TEXT DEFAULT 'question';
    ALTER TABLE portal_westy_turns ADD COLUMN reply_json TEXT DEFAULT '{}';
    ALTER TABLE portal_westy_turns ADD COLUMN expires_at TEXT DEFAULT '2099';
    ALTER TABLE portal_westy_help_events ADD COLUMN created_at TEXT;
    UPDATE portal_westy_help_events SET created_at=datetime('now','-1 day');
    CREATE TABLE customer_portal_bindings(id INTEGER,tenant_id INTEGER,client_id INTEGER,status TEXT);
    INSERT INTO customer_portal_bindings VALUES(5,1,11,'active');
    CREATE TABLE portal_westy_reply_feedback(id INTEGER,turn_id INTEGER,tenant_id INTEGER,client_id INTEGER,binding_id INTEGER,
        scope_key TEXT,response_key TEXT,revision INTEGER,reaction TEXT,created_at TEXT,expires_at TEXT);
    INSERT INTO portal_westy_reply_feedback VALUES
        (1,1,1,11,5,'owned','reply',1,'up',datetime('now','-2 days'),'2099'),
        (2,1,1,11,5,'owned','reply',2,'down',datetime('now','-1 day'),'2099');
    CREATE TABLE portal_guide_votes(tenant_id INTEGER,client_id INTEGER,article_id TEXT,helpful INTEGER,updated_at TEXT,expires_at TEXT);
    INSERT INTO portal_guide_votes VALUES(1,11,'a',1,datetime('now','-1 day'),'2099'),(1,12,'z',0,datetime('now','-1 day'),'2099');
    CREATE TABLE help_gap_questions(tenant_id INTEGER,client_id INTEGER,surface TEXT,question TEXT COLLATE NOCASE,created_at TEXT,expires_at TEXT);
    INSERT INTO help_gap_questions VALUES
        (1,11,'portal','Question',datetime('now','-1 day'),'2099'),(1,11,'companion','Question',datetime('now','-3 days'),'2099'),
        (1,11,'portal','question',datetime('now','-2 days'),'2099'),(1,11,'technician','Hidden',datetime('now'),'2099'),
        (1,12,'portal','Hidden',datetime('now'),'2099'),(2,11,'portal','Hidden',datetime('now'),'2099')");
$stats=portal_feedback_review_help_health($pdo,1,[11=>['binding_id'=>5]],7);
check($stats['articles']===[
    ['article_id'=>'a','cited'=>1,'up'=>0,'down'=>1,'votes_helpful'=>1,'votes_unhelpful'=>0],
    ['article_id'=>'z','cited'=>1,'up'=>0,'down'=>1,'votes_helpful'=>0,'votes_unhelpful'=>0]
], 'article totals use the retained response once per article and ignore foreign votes and obsolete reactions');
check(array_column($stats['gaps'],'question')===['Question','question'] && array_column($stats['gaps'],'count')===[2,1],
    'only scoped customer surfaces contribute, and differently cased questions stay distinct');
$pdo->exec("UPDATE portal_westy_turns SET input_text=NULL, reply_json=NULL WHERE id=1");
check(array_column(portal_feedback_review_help_health($pdo,1,[11=>['binding_id'=>5]],7)['articles'],'down')===[0,0],
    'erasure removes feedback from the aggregates while preserving content-free citation counts');
echo "$checks feedback review checks passed\n";
