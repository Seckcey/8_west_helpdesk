<?php
/** No-database checks for the Guide vote surfaces and page-set boundary. */
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
// An unconnected PDO fails if an ineligible vote ever reaches storage.
$pdo = new class extends PDO { public function __construct() {} };
$_GET = $_POST = ['role'=>'client_owner'];
['anchors'=>$expected, 'cases'=>$cases] = json_decode(file_get_contents(__DIR__.'/fixtures/portal_guide_cases.json'), true, 8, JSON_THROW_ON_ERROR);
$cases['Companion session'] = [$cases['owner'][0], 'legacy'];
foreach ($cases as $name => [$context, $role]) {
    if ($name === 'Companion session') $_SESSION['desktop_companion_session'] = 'synthetic';
    $page = portal_guide_articles($context);
    check(array_keys($page) === $expected[$role], $name.' sees its expected page anchors');
    $votable = $role === 'legacy' ? [] : array_column($page, 'catalog_id');
    $outside = array_diff(array_column(help_catalog_index()['articles'], 'id'), $votable);
    check(in_array('id-passkey-add', $outside, true), $name.' excludes the ID passkey article from voting');
    foreach ($outside as $id) {
        try {
            portal_guide_vote_record($pdo, $context, ['article'=>$id, 'helpful'=>true]);
            check(false, $name.' vote must be refused for '.$id);
        } catch (PortalWestyException $error) {
            check($error->reason === 'not_found' && $error->status === 404, $name.' receives 404 before any database access for '.$id);
        }
    }
    if ($role === 'legacy') check(portal_guide_vote_current($pdo, $context) === [], $name.' reads no votes');
    unset($_SESSION['desktop_companion_session']);
}
echo "$checks guide vote checks passed\n";
