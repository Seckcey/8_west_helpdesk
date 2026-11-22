<?php
/** No-database checks for the Guide vote surfaces and legacy role boundary. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$checks = 0;
function check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    echo 'ok '.++$checks.' - '.$name."\n";
}

foreach (['portal/guide_vote.php', 'assets/js/portal-guide.js'] as $file) {
    $path = __DIR__.'/../public/'.$file;
    check(is_file($path), $file.' exists');
    $source = file_get_contents($path);
    foreach (['innerHTML','insertAdjacentHTML','eval','console.log'] as $sink) {
        check(!str_contains($source, $sink), $file.' contains no '.$sink);
    }
}

require_once __DIR__.'/../lib/portal_guide_votes.php';
// An unconnected PDO fails if a legacy context ever reaches a database operation.
$pdo = new class extends PDO { public function __construct() {} };
$_GET = $_POST = ['role'=>'client_owner'];
$owner = ['identity'=>['role'=>'client_owner']];
foreach ([
    'technician'=>['identity'=>['role'=>'technician']],
    'delegated'=>['identity'=>['role'=>'client_owner','customer_access'=>['role'=>'client_owner']]],
    'Companion'=>$owner + ['desktop_companion_pair'=>true],
    'Companion session'=>$owner,
] as $name=>$context) {
    if ($name === 'Companion session') $_SESSION['desktop_companion_session'] = 'synthetic';
    try {
        portal_guide_vote_record($pdo, $context, ['article'=>'sh-devices','helpful'=>true]);
        check(false, $name.' vote must be refused');
    } catch (PortalWestyException $error) {
        check($error->reason === 'not_found' && $error->status === 404, $name.' receives 404 before any database access');
    }
    check(portal_guide_vote_current($pdo, $context) === [], $name.' reads no votes');
    unset($_SESSION['desktop_companion_session']);
}
echo "$checks guide vote checks passed\n";
