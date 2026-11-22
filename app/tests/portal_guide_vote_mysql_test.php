<?php
/** Guide votes in an isolated MySQL database with synthetic portal identities. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('SAFEHARBOR_WESTY_TEST_DISPOSABLE_SERVER')!=='1')exit(2);
$base=getenv('SAFEHARBOR_WESTY_TEST_DB')?:'safeharbor_westy_test';
if(!preg_match('/^safeharbor_westy_test(?:_[a-z0-9_]+)?$/D',$base))exit(2);
$host=getenv('SAFEHARBOR_WESTY_TEST_HOST')?:'127.0.0.1';$port=getenv('SAFEHARBOR_WESTY_TEST_PORT')?:'3306';
$user=getenv('SAFEHARBOR_WESTY_TEST_USER')?:'root';$pass=getenv('SAFEHARBOR_WESTY_TEST_PASS')?:'';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$admin=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$options);
$database=$base.'_votes_'.bin2hex(random_bytes(4));
if(!preg_match('/^safeharbor_westy_test[a-z0-9_]*_votes_[a-f0-9]{8}$/D',$database))exit(2);
$connect=static fn()=>new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",$user,$pass,$options);
function cfg(string $key,mixed $default=null):mixed{
    return match($key){'portal_westy.enabled'=>true,'portal_westy.ai_enabled'=>false,default=>$default};
}
require __DIR__.'/portal_westy_fixture.php';
require_once __DIR__.'/../lib/portal_guide_votes.php';
require_once __DIR__.'/../lib/portal_westy_maintenance.php';

$checks = 0;
function check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    echo 'ok '.++$checks.' - '.$name."\n";
}
function denied(callable $call, string $name, string $reason, int $status): void
{
    try { $call(); }
    catch (PortalWestyException $error) {
        check($error->reason === $reason && $error->status === $status, $name);
        return;
    }
    check(false, $name);
}

try {
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');$pdo=$connect();
    $pdo->exec("SET time_zone='+00:00'");
    portal_westy_fixture_sql($pdo,__DIR__.'/../db/schema.sql');
    $migration = __DIR__.'/../db/migrations/portal_guide_votes_v1.sql';
    $ddl = static fn() => $pdo->query('SHOW CREATE TABLE portal_guide_votes')->fetch(PDO::FETCH_NUM)[1];
    $fresh = $ddl();
    $pdo->exec('DROP TABLE portal_guide_votes');
    portal_westy_fixture_sql($pdo, $migration);
    check($ddl() === $fresh, 'migration and fresh schema agree');
    portal_westy_fixture_sql($pdo, $migration);
    check($ddl() === $fresh && portal_guide_votes_installed($pdo), 'migration replay preserves the table');

    [$owner, $otherClientOwner, $otherTenant] = portal_westy_fixture_seed($pdo);
    $otherClientOwner['identity']['subject'] = $owner['identity']['subject'];
    $employee = $owner;
    $employee['identity']['role'] = 'client_staff';
    $employee['identity']['subject'] = 't9u999';
    $ownerScope = portal_westy_scope($pdo, $owner);
    $yes = ['article'=>'sh-requests', 'helpful'=>true];
    $no = ['article'=>'sh-requests', 'helpful'=>false];
    $start = gmdate('Y-m-d H:i:s');
    check(portal_guide_vote_record($pdo, $owner, $yes) === $yes, 'vote saved');
    $stored = static fn() => $pdo->query('SELECT * FROM portal_guide_votes')->fetch();
    $first = $stored();
    $expiry = static fn(string $at): string => (new DateTimeImmutable($at, new DateTimeZone('UTC')))
        ->modify('+400 days')->format('Y-m-d H:i:s');
    check((int)$first['tenant_id'] === $ownerScope['tenant'] && (int)$first['client_id'] === $ownerScope['client']
        && $first['scope_key'] === $ownerScope['key'], 'authenticated scope stored');
    check($first['article_id'] === $yes['article'] && (int)$first['helpful'] === 1
        && $first['catalog_version'] === help_catalog_index()['version'], 'catalog ID, released version and Yes stored');
    check($first['created_at'] === $first['updated_at'] && $first['updated_at'] >= $start
        && $first['updated_at'] <= gmdate('Y-m-d H:i:s') && $first['expires_at'] === $expiry($first['updated_at']),
        'first vote uses one UTC instant and expires in 400 days');
    // Simulate a previous release and an old row without waiting for a clock tick.
    $historical = '2000-01-01 00:00:00';
    $pdo->prepare("UPDATE portal_guide_votes SET catalog_version='0.0.0',created_at=?,updated_at=?,expires_at=? WHERE id=?")
        ->execute([$historical, $historical, $expiry($historical), $first['id']]);
    check(portal_guide_vote_record($pdo, $owner, array_reverse($no, true)) === $no, 'changed vote saved with keys in either order');
    $changed = $stored();
    check((int)$pdo->query('SELECT COUNT(*) FROM portal_guide_votes')->fetchColumn() === 1 && $changed['id'] === $first['id'],
        'second vote updates in place');
    check($changed['created_at'] === $historical && $changed['updated_at'] >= $start && $changed['updated_at'] <= gmdate('Y-m-d H:i:s')
        && $changed['expires_at'] === $expiry($changed['updated_at']) && $changed['catalog_version'] === help_catalog_index()['version']
        && (int)$changed['helpful'] === 0, 'changing the vote preserves creation and refreshes the version and expiry');
    check(portal_guide_vote_current($pdo, $owner) === ['sh-requests'=>false], 'current vote read back as No');
    foreach ([$employee, $otherClientOwner, $otherTenant] as $other)
        check(portal_guide_vote_current($pdo, $other) === [], 'votes are private to the person, client and tenant');

    $_GET = $_POST = ['role'=>'client_owner'];
    $ownerOnly = ['article'=>'sh-give-computer-access', 'helpful'=>true];
    denied(fn()=>portal_guide_vote_record($pdo,$employee,$ownerOnly), 'employee cannot vote on owner article', 'not_found', 404);
    denied(fn()=>portal_guide_vote_record($pdo,$owner,['article'=>'sh-requests-brief','helpful'=>true]),
        'shared anchor cannot select a hidden catalog ID', 'not_found', 404);
    foreach ([$yes+['role'=>'x'], ['article'=>'sh-requests'], ['helpful'=>true], ['article'=>'sh-requests','helpful'=>'yes'],
        ['article'=>'sh-requests','helpful'=>0], ['article'=>str_repeat('a',65),'helpful'=>true], ['article'=>'sh--bad','helpful'=>true]] as $bad) {
        denied(fn()=>portal_guide_vote_record($pdo,$owner,$bad), 'invalid vote shape, value or ID refused', 'invalid_request', 400);
    }
    foreach (['technician','delegated','companion','companion session'] as $kind) {
        $legacy = $owner;
        if ($kind === 'technician') $legacy['identity']['role'] = 'technician';
        if ($kind === 'delegated') $legacy['identity']['customer_access'] = ['role'=>'client_owner'];
        if ($kind === 'companion') $legacy['desktop_companion_pair'] = true;
        if ($kind === 'companion session') $_SESSION['desktop_companion_session'] = 'synthetic';
        denied(fn()=>portal_guide_vote_record($pdo,$legacy,$yes), $kind.' cannot vote', 'not_found', 404);
        check(portal_guide_vote_current($pdo,$legacy) === [], $kind.' cannot read votes');
        unset($_SESSION['desktop_companion_session']);
    }
    help_catalog_use_dir(__DIR__.'/fixtures');
    try {
        denied(fn()=>portal_guide_vote_record($pdo,$owner,$yes), 'invalid catalog hides voting', 'help_feedback_unavailable', 503);
    } finally { help_catalog_use_dir(null); }

    portal_guide_vote_record($pdo, $owner, $ownerOnly);
    $downgraded = $owner;
    $downgraded['identity']['role'] = 'client_staff';
    check(portal_guide_vote_current($pdo, $downgraded) === ['sh-requests'=>false], 'current votes follow the visible articles after a role change');
    portal_guide_vote_record($pdo, $employee, $yes);
    portal_guide_vote_record($pdo, $otherClientOwner, $yes);
    portal_guide_vote_record($pdo, $otherTenant, $no);
    portal_guide_vote_record($pdo, $owner, ['article'=>'sh-devices','helpful'=>true]);
    $pdo->prepare("UPDATE portal_guide_votes SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE scope_key=? AND article_id='sh-devices'")
        ->execute([$ownerScope['key']]);
    check(!array_key_exists('sh-devices', portal_guide_vote_current($pdo, $owner)), 'expiry hides the vote before cleanup');
    $rowCount = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM portal_guide_votes')->fetchColumn();
    check(portal_westy_maintain($pdo,false)['vote_content'] === 1 && $rowCount() === 6, 'scheduled dry run counts only expired votes');
    check(portal_westy_maintain($pdo,true)['vote_content'] === 1 && $rowCount() === 5, 'scheduled cleanup removes only the expired vote');
    check(portal_westy_account($pdo, $ownerScope) === null, 'voting does not start a Westy conversation');
    foreach ([['tenant'=>2], ['client'=>12]] as $wrong) {
        $wrongScope = array_replace($ownerScope, $wrong);
        check(portal_westy_maintain($pdo,false,$wrongScope)['vote_content'] === 0, 'scope key alone does not identify a vote for erasure');
        try {
            portal_westy_maintain($pdo,true,$wrongScope);
            check(false, 'mismatched scope must be refused');
        } catch (RuntimeException $error) {
            check($error->getMessage() === 'Exact account not found' && $rowCount() === 5, 'mismatched exact scope erases nothing');
        }
    }
    check(portal_westy_maintain($pdo,false,$ownerScope)['vote_content'] === 2 && $rowCount() === 5, 'exact dry run counts every vote in the scope');
    check(portal_westy_maintain($pdo,true,$ownerScope)['vote_content'] === 2 && portal_guide_vote_current($pdo,$owner) === [],
        'exact erasure removes all votes even for a person with no chat account');
    check($rowCount() === 3 && portal_guide_vote_current($pdo,$employee) === ['sh-requests'=>true]
        && portal_guide_vote_current($pdo,$otherClientOwner) === ['sh-requests'=>true]
        && portal_guide_vote_current($pdo,$otherTenant) === ['sh-requests'=>false], 'exact erasure preserves every other scope');

    foreach (['client_staff','client_viewer','client_owner','client_admin'] as $role) {
        $voter = $owner;
        $voter['identity']['role'] = $role;
        foreach (['co-connect','co-chat','co-sign-out'] as $id) {
            $vote = ['article'=>$id, 'helpful'=>true];
            check(portal_guide_vote_record($pdo, $voter, $vote) === $vote && (portal_guide_vote_current($pdo, $voter)[$id] ?? null) === true,
                $role.' can save and read back a vote on '.$id);
        }
    }

    $pdo->exec('DROP TABLE portal_guide_votes');
    check(!portal_guide_votes_installed($pdo) && portal_guide_vote_current($pdo,$otherClientOwner) === [], 'absent table has no current votes');
    denied(fn()=>portal_guide_vote_record($pdo,$owner,$yes), 'absent table is unavailable', 'help_feedback_unavailable', 503);
    check(portal_westy_maintain($pdo,false)['vote_content'] === 0 && portal_westy_maintain($pdo,true)['vote_content'] === 0,
        'maintenance works before the migration');
    echo "$checks guide vote MySQL checks passed\n";
} finally {
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    $admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');
}
